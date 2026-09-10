<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Reporter;

use Tiden\PHPUnitReporter\Core\Exception\TidenException;
use Tiden\PHPUnitReporter\Core\Logging\Logger;
use Tiden\PHPUnitReporter\Core\Model\TestResult;
use Tiden\PHPUnitReporter\Core\Transform\ResultTransformer;

/**
 * Mode "report": writes the exact wire payload to disk instead of sending it.
 *
 * This is what makes the reporter testable end to end without a server, and it
 * is the mode the golden integration test runs in. One file per result, named
 * by result id, so ParaTest workers never collide.
 */
final class FileReporter implements InternalReporter
{
    public function __construct(
        private readonly string $path,
        private readonly ResultTransformer $transformer,
        private readonly Logger $logger,
    ) {}

    public function startRun(): void
    {
        if (! is_dir($this->path) && ! @mkdir($this->path, 0o775, true) && ! is_dir($this->path)) {
            throw new TidenException(sprintf('Could not create the report directory "%s".', $this->path));
        }
    }

    public function addResult(TestResult $result): void
    {
        $this->startRun();

        $file = rtrim($this->path, '/').'/'.$result->id.'.json';
        $json = json_encode($this->transformer->toResultCreate($result), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (@file_put_contents($file, $json) === false) {
            $this->logger->error(sprintf('could not write result to "%s"', $file));
        }
    }

    public function complete(): void {}
}
