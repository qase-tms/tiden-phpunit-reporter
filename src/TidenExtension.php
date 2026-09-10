<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter;

use PHPUnit\Event\Test\PreparationErroredSubscriber as PHPUnitPreparationErroredSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use Tiden\PHPUnitReporter\Attribute\AttributeReader;
use Tiden\PHPUnitReporter\Core\Config\ConfigResolver;
use Tiden\PHPUnitReporter\Core\Config\EnvLoader;
use Tiden\PHPUnitReporter\Core\Exception\TidenException;
use Tiden\PHPUnitReporter\Core\Identity\FilePathResolver;
use Tiden\PHPUnitReporter\Core\Logging\Logger;
use Tiden\PHPUnitReporter\Core\Reporter\ReporterFactory;

/**
 * Register in phpunit.xml:
 *
 *   <extensions>
 *       <bootstrap class="Tiden\PHPUnitReporter\TidenExtension"/>
 *   </extensions>
 *
 * Inert unless configured. With no TIDEN_* variable set and no tiden.config.json
 * the mode is "off", nothing is sent, nothing is printed, and a developer who
 * has never heard of Tiden sees no difference.
 */
final class TidenExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        try {
            $this->register($facade, $parameters);
        } catch (TidenException $e) {
            // A misconfigured reporter must never stop the suite from running:
            // the tests are the thing under test, not us.
            (new Logger)->error($e->getMessage());
        }
    }

    private function register(Facade $facade, ParameterCollection $parameters): void
    {
        $env = [];

        foreach (EnvLoader::NAMES as $name) {
            $value = getenv($name);

            if (is_string($value)) {
                $env[$name] = $value;
            }
        }

        $resolution = (new ConfigResolver)->resolve($env, $this->parameterLayer($parameters));
        $config = $resolution->config;

        $logger = Logger::fromConfig($config);
        $filePaths = new FilePathResolver($config->rootDir, $logger);
        $internal = (new ReporterFactory)->create($resolution, $logger, $filePaths);

        $reporter = Reporter::create($internal, new AttributeReader($logger), $filePaths, $logger, $config->rootSuite);

        $subscribers = [
            new Event\TestRunnerStartedSubscriber($reporter),
            new Event\TestPreparedSubscriber($reporter),
            new Event\TestPassedSubscriber($reporter),
            new Event\TestFailedSubscriber($reporter),
            new Event\TestErroredSubscriber($reporter),
            new Event\TestSkippedSubscriber($reporter),
            new Event\TestMarkedIncompleteSubscriber($reporter),
            new Event\TestConsideredRiskySubscriber($reporter),
            new Event\TestWarningTriggeredSubscriber($reporter),
            new Event\TestFinishedSubscriber($reporter),
            new Event\TestRunnerFinishedSubscriber($reporter),
        ];

        // PHPUnit 11.4+ and 12 only.
        if (interface_exists(PHPUnitPreparationErroredSubscriber::class)) {
            $subscribers[] = new Event\TestPreparationErroredSubscriber($reporter);
        }

        $facade->registerSubscribers(...$subscribers);
    }

    /**
     * <parameter> elements, read under the same names as the environment
     * variables. Lowest precedence: env, then tiden.config.json, then these.
     *
     * @return array<string, mixed>
     */
    private function parameterLayer(ParameterCollection $parameters): array
    {
        $raw = [];

        foreach (EnvLoader::NAMES as $name) {
            if ($parameters->has($name)) {
                $raw[$name] = $parameters->get($name);
            }
        }

        return $raw === [] ? [] : (new EnvLoader)->load($raw);
    }
}
