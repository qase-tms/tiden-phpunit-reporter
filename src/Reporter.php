<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter;

use PHPUnit\Event\Code\TestMethod;
use Tiden\PHPUnitReporter\Attribute\AttributeReader;
use Tiden\PHPUnitReporter\Core\Exception\SignatureException;
use Tiden\PHPUnitReporter\Core\Identity\FilePathResolver;
use Tiden\PHPUnitReporter\Core\Identity\Signature;
use Tiden\PHPUnitReporter\Core\Identity\SuitePath;
use Tiden\PHPUnitReporter\Core\Logging\Logger;
use Tiden\PHPUnitReporter\Core\Model\Status;
use Tiden\PHPUnitReporter\Core\Model\TestResult;
use Tiden\PHPUnitReporter\Core\Reporter\InternalReporter;
use Tiden\PHPUnitReporter\Core\Uuid;

/**
 * Translates PHPUnit's event stream into Tiden results.
 *
 * A process-wide singleton because PHPUnit's subscribers are constructed
 * independently of each other and the static Tiden:: helpers need to reach the
 * currently running test.
 *
 * Results are keyed on TestMethod::id(), which PHPUnit builds from readonly
 * fields as "Class::method#dataset". It is identical in every event for the
 * same test — unlike a key built from the line number, which the reporter this
 * package replaces found could differ between Prepared and Finished under
 * ParaTest with coverage enabled, dereferencing a null result.
 */
final class Reporter
{
    /** Data-set renderings can be arbitrarily large; a params value should not be. */
    private const MAX_PARAM_LENGTH = 255;

    private static ?self $instance = null;

    /** @var array<string, TestResult> */
    private array $results = [];

    /** @var array<string, true> */
    private array $flushed = [];

    private ?string $currentKey = null;

    private bool $runStarted = false;

    private function __construct(
        private readonly InternalReporter $internal,
        private readonly AttributeReader $attributes,
        private readonly FilePathResolver $filePaths,
        private readonly Logger $logger,
        private readonly ?string $rootSuite,
    ) {}

    public static function create(
        InternalReporter $internal,
        AttributeReader $attributes,
        FilePathResolver $filePaths,
        Logger $logger,
        ?string $rootSuite,
    ): self {
        return self::$instance = new self($internal, $attributes, $filePaths, $logger, $rootSuite);
    }

    /** Null when the extension never bootstrapped, which is what makes Tiden:: helpers safe. */
    public static function instance(): ?self
    {
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function startTestRun(): void
    {
        if ($this->runStarted) {
            return;
        }

        $this->runStarted = true;
        $this->internal->startRun();
    }

    public function completeTestRun(): void
    {
        // Sweep anything that never saw a Finished event — a test killed by a
        // fatal error still happened, and dropping it would quietly shrink the
        // run rather than showing the gap.
        foreach ($this->results as $key => $result) {
            $this->flushResult($key, $result);
        }

        $this->internal->complete();
    }

    public function startTest(TestMethod $test): void
    {
        $key = $test->id();
        $this->currentKey = $key;

        if (isset($this->results[$key]) || isset($this->flushed[$key])) {
            return;
        }

        $result = $this->buildResult($test);

        if ($result !== null) {
            $this->results[$key] = $result;
        }
    }

    public function updateStatus(TestMethod $test, Status $status, ?string $message = null, ?string $stacktrace = null): void
    {
        $result = $this->resultFor($test);

        if ($result === null) {
            return;
        }

        $result->status = $status;

        if ($message !== null && $message !== '') {
            $result->appendMessage($message);
        }

        if ($stacktrace !== null && $stacktrace !== '') {
            $result->stacktrace = $stacktrace;
        }
    }

    public function completeTest(TestMethod $test): void
    {
        $key = $test->id();
        $result = $this->results[$key] ?? null;

        if ($result === null) {
            return;
        }

        $this->flushResult($key, $result);
        $this->currentKey = null;
    }

    /** Backs Tiden::title(). */
    public function setCurrentTitle(string $title): void
    {
        $result = $this->current();

        if ($result !== null) {
            $result->title = $title;
        }
    }

    /** Backs Tiden::comment(). */
    public function addCurrentComment(string $comment): void
    {
        $this->current()?->appendMessage($comment);
    }

    private function current(): ?TestResult
    {
        return $this->currentKey === null ? null : ($this->results[$this->currentKey] ?? null);
    }

    /**
     * Outcome events can arrive for a test that never emitted Prepared — a
     * preparation error is the common case — so the result is created on
     * demand rather than assumed to exist.
     */
    private function resultFor(TestMethod $test): ?TestResult
    {
        $key = $test->id();

        if (isset($this->flushed[$key])) {
            return null;
        }

        return $this->results[$key] ??= $this->buildResult($test);
    }

    private function buildResult(TestMethod $test): ?TestResult
    {
        try {
            $signature = Signature::forMethod($test->className(), $test->methodName());
        } catch (SignatureException $e) {
            // Reporting a case with no usable identity is worse than not
            // reporting it: it would be born unreachable by ingest.
            $this->logger->warning($e->getMessage());

            return null;
        }

        $metadata = $this->attributes->read($test->className(), $test->methodName());

        $suitePath = $metadata->suites !== []
            ? SuitePath::forClass(implode('\\', $metadata->suites), $this->rootSuite)
            : SuitePath::forClass($test->className(), $this->rootSuite);

        $result = new TestResult(
            id: Uuid::v4(),
            signature: $signature,
            title: $metadata->title ?? $test->methodName(),
            suitePath: $suitePath,
            startTime: microtime(true),
            filePath: $this->filePaths->relative($test->file()),
            thread: self::thread(),
        );

        $result->fields = $metadata->fields;
        $result->tags = $metadata->tags;
        $result->params = [...$this->dataProviderParams($test), ...$metadata->parameters];

        return $result;
    }

    /**
     * Data-provider identity, from PHPUnit's public API only.
     *
     * The reporter this package replaces re-invoked provider methods by
     * reflection and read TestDataCollection's private state to recover the
     * original values — the documented source of its ParaTest bug. The dataset
     * name and PHPUnit's own exported rendering are enough, and they are stable.
     *
     * @return array<string, string>
     */
    private function dataProviderParams(TestMethod $test): array
    {
        $testData = $test->testData();

        if (! $testData->hasDataFromDataProvider()) {
            return [];
        }

        $fromProvider = $testData->dataFromDataProvider();
        $params = ['dataset' => (string) $fromProvider->dataSetName()];

        $data = self::normalizeDataRendering($fromProvider->data());

        if ($data !== '') {
            $params['data'] = $data;
        }

        return $params;
    }

    /**
     * PHPUnit's own rendering of a data set, made fit for a params map entry.
     *
     * The rendering is not stable across PHPUnit majors — 10.5 exports the full
     * structure ("Array &0 [\n 0 => 3,\n]") where 12 gives "3, 6" — and params
     * are displayed as a single-line key/value map. So it is collapsed to one
     * line and bounded, which keeps the value readable and small on every
     * supported version.
     *
     * This is presentation only. It cannot affect which case a row belongs to,
     * because the signature is param-free.
     */
    private static function normalizeDataRendering(string $data): string
    {
        $collapsed = trim((string) preg_replace('/\s+/', ' ', $data));

        if (strlen($collapsed) <= self::MAX_PARAM_LENGTH) {
            return $collapsed;
        }

        return substr($collapsed, 0, self::MAX_PARAM_LENGTH - 1).'…';
    }

    /** ParaTest labels its workers with TEST_TOKEN. */
    private static function thread(): ?string
    {
        $token = getenv('TEST_TOKEN');

        return is_string($token) && trim($token) !== '' ? 'worker-'.trim($token) : null;
    }

    private function flushResult(string $key, TestResult $result): void
    {
        if (isset($this->flushed[$key])) {
            return;
        }

        $result->endTime ??= microtime(true);
        $this->flushed[$key] = true;
        unset($this->results[$key]);

        $this->internal->addResult($result);
    }
}
