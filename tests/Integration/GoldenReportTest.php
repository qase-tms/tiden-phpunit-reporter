<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The end-to-end check: run a real PHPUnit process with the extension loaded
 * and compare everything it emits against a checked-in expectation.
 *
 * Unit tests cover each piece; only this proves the pieces are wired to each
 * other — that the extension bootstraps, that subscribers receive events, that
 * a PHPUnit outcome becomes the right status, and that a data provider's rows
 * arrive as two attempts on ONE case.
 */
#[Group('integration')]
final class GoldenReportTest extends TestCase
{
    private const GOLDEN = __DIR__.'/../golden/example-report.json';

    private string $output;

    protected function setUp(): void
    {
        $this->output = sys_get_temp_dir().'/tiden-report-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->output.'/*.json') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->output);
    }

    public function test_the_example_suite_produces_the_expected_report(): void
    {
        $this->runExampleSuite();

        $this->assertJsonStringEqualsJsonFile(
            self::GOLDEN,
            self::encode($this->collect()),
            'The reporter now emits something different for the example suite. If the change is '.
            'intentional, regenerate the fixture; if a signature changed, see SignatureTest first.',
        );
    }

    /**
     * The param-free rule, observed rather than asserted in isolation: two data
     * rows, one signature, told apart only by their params.
     */
    public function test_data_provider_rows_share_one_case_identity(): void
    {
        $this->runExampleSuite();

        $rows = array_values(array_filter(
            $this->collectRaw(),
            static fn (array $r): bool => str_ends_with((string) $r['signature'], '::test_it_runs_once_per_row'),
        ));

        $this->assertCount(2, $rows);
        $this->assertSame($rows[0]['signature'], $rows[1]['signature']);
        $this->assertNotSame($rows[0]['params'], $rows[1]['params']);
        $this->assertNotSame($rows[0]['id'], $rows[1]['id'], 'each attempt still needs its own idempotency key');
    }

    /**
     * Whatever PHPUnit's rendering of a data set looks like on this major, the
     * value that reaches a params map must be one bounded line.
     */
    public function test_data_provider_values_are_single_line_and_bounded(): void
    {
        $this->runExampleSuite();

        $seen = 0;

        foreach ($this->collectRaw() as $result) {
            $data = $result['params']['data'] ?? null;

            if ($data === null) {
                continue;
            }

            $seen++;
            $this->assertIsString($data);
            $this->assertStringNotContainsString("\n", $data);
            $this->assertLessThanOrEqual(255, strlen($data));
        }

        $this->assertGreaterThan(0, $seen, 'the example suite has a data provider');
    }

    /** Every reported case must carry the requirement<->test join key. */
    public function test_every_result_carries_a_repo_relative_file_path(): void
    {
        $this->runExampleSuite();

        foreach ($this->collectRaw() as $result) {
            $this->assertSame('examples/ExampleTest.php', $result['fields']['file_path'] ?? null);
        }
    }

    private function runExampleSuite(): void
    {
        $root = dirname(__DIR__, 2);

        $command = sprintf(
            'TIDEN_MODE=report TIDEN_REPORT_CONNECTION_PATH=%s TIDEN_ROOT_DIR=%s %s %s -c %s --no-coverage 2>&1',
            escapeshellarg($this->output),
            escapeshellarg($root),
            escapeshellarg(PHP_BINARY),
            escapeshellarg($root.'/vendor/bin/phpunit'),
            escapeshellarg($root.'/examples/phpunit.xml'),
        );

        exec($command, $lines, $exitCode);

        // The example suite deliberately contains a failure and an error, so a
        // non-zero exit is the expected outcome; only a crash is not.
        $this->assertLessThan(3, $exitCode, "phpunit did not run:\n".implode("\n", $lines));
        $this->assertDirectoryExists($this->output, "no results were written:\n".implode("\n", $lines));
    }

    /**
     * Everything that legitimately differs run to run is replaced by a
     * placeholder, so the fixture records shape and content rather than a
     * timestamp.
     *
     * @return list<array<string, mixed>>
     */
    private function collect(): array
    {
        $results = [];

        foreach ($this->collectRaw() as $decoded) {
            /** @var array<string, mixed> $execution */
            $execution = $decoded['execution'];
            $execution['startTime'] = '<time>';
            $execution['endTime'] = '<time>';
            $execution['duration'] = '<ms>';

            if (isset($execution['stacktrace'])) {
                $execution['stacktrace'] = '<stacktrace>';
            }

            $decoded['execution'] = $execution;
            $decoded['id'] = '<uuid>';

            // PHPUnit renders a data set differently across majors (10.5 dumps
            // the whole structure, 12 gives "3, 6"), so the golden records that
            // the key is present, and test_data_provider_values_are_single_line
            // locks the property that actually matters.
            if (isset($decoded['params']['data'])) {
                $decoded['params']['data'] = '<data>';
            }

            $results[] = $decoded;
        }

        usort($results, static fn (array $a, array $b): int => [$a['signature'], json_encode($a['params'] ?? [])]
            <=> [$b['signature'], json_encode($b['params'] ?? [])]);

        return $results;
    }

    /**
     * The results exactly as the reporter wrote them.
     *
     * @return list<array<string, mixed>>
     */
    private function collectRaw(): array
    {
        $results = [];

        foreach (glob($this->output.'/*.json') ?: [] as $file) {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(
                basename($file, '.json'),
                $decoded['id'],
                'results are filed under their own id, so ParaTest workers cannot overwrite each other',
            );

            $results[] = $decoded;
        }

        return $results;
    }

    /** @param list<array<string, mixed>> $results */
    private static function encode(array $results): string
    {
        return (string) json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
