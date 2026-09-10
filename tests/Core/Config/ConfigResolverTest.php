<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Tests\Core\Config;

use PHPUnit\Framework\TestCase;
use Tiden\PHPUnitReporter\Core\Config\ConfigResolver;
use Tiden\PHPUnitReporter\Core\Config\EnvLoader;
use Tiden\PHPUnitReporter\Core\Config\Mode;

final class ConfigResolverTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/tiden-config-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        @unlink($this->directory.'/tiden.config.json');
        @rmdir($this->directory);
    }

    public function test_defaults_to_off_with_no_configuration_at_all(): void
    {
        $resolution = (new ConfigResolver)->resolve([], [], $this->directory);

        $this->assertSame(Mode::Off, $resolution->config->mode);
        $this->assertFalse($resolution->isEnabled());
    }

    /**
     * The quiet case: a developer with no interest in Tiden runs the suite and
     * hears nothing. Announcing here would print one line per ParaTest worker
     * on every local run.
     */
    public function test_stays_silent_when_the_user_has_set_no_tiden_variable(): void
    {
        $this->assertFalse((new ConfigResolver)->resolve([], [], $this->directory)->announce);
    }

    /**
     * The loud case: the user set something, so they meant to report, and a
     * silently inert reporter is how people lose an afternoon.
     */
    public function test_announces_when_the_user_showed_intent(): void
    {
        $resolution = (new ConfigResolver)->resolve(['TIDEN_MODE' => 'tiden'], [], $this->directory);

        $this->assertTrue($resolution->announce);
        $this->assertNotNull($resolution->disabled);
        $this->assertStringContainsString('TIDEN_API_TOKEN', $resolution->disabled->message());
    }

    public function test_names_the_missing_settings_in_the_order_they_must_be_fixed(): void
    {
        $env = ['TIDEN_MODE' => 'tiden'];
        $resolver = new ConfigResolver;

        $this->assertStringContainsString('tiden.api.token', $resolver->resolve($env, [], $this->directory)->disabled?->message() ?? '');

        $env['TIDEN_API_TOKEN'] = 'tfy_x';
        $this->assertStringContainsString('tiden.product', $resolver->resolve($env, [], $this->directory)->disabled?->message() ?? '');

        $env['TIDEN_PRODUCT_ID'] = 'p1';
        $this->assertStringContainsString('tiden.api.baseUrl', $resolver->resolve($env, [], $this->directory)->disabled?->message() ?? '');

        $env['TIDEN_BASE_URL'] = 'https://api.tiden.ai';
        $this->assertTrue($resolver->resolve($env, [], $this->directory)->isEnabled());
    }

    public function test_environment_beats_the_config_file_which_beats_phpunit_parameters(): void
    {
        file_put_contents($this->directory.'/tiden.config.json', json_encode([
            'rootSuite' => 'from-file',
            'environment' => 'from-file',
            'tiden' => ['product' => 'file-product'],
        ]));

        $resolution = (new ConfigResolver)->resolve(
            ['TIDEN_ROOT_SUITE' => 'from-env'],
            (new EnvLoader)->load(['TIDEN_ROOT_SUITE' => 'from-params', 'TIDEN_ROOT_DIR' => 'from-params']),
            $this->directory,
        );

        $this->assertSame('from-env', $resolution->config->rootSuite);
        $this->assertSame('from-file', $resolution->config->environment, 'the file still wins over parameters');
        $this->assertSame('from-params', $resolution->config->rootDir, 'parameters fill in what nothing above defines');
        $this->assertSame('file-product', $resolution->config->productId);
    }

    /** A later layer that does not define a value must not erase an earlier one. */
    public function test_an_undefined_value_never_clobbers_a_defined_one(): void
    {
        file_put_contents($this->directory.'/tiden.config.json', json_encode(['rootSuite' => 'kept']));

        $resolution = (new ConfigResolver)->resolve(['TIDEN_MODE' => 'off'], [], $this->directory);

        $this->assertSame('kept', $resolution->config->rootSuite);
    }

    /**
     * Undefined means complete. Only an explicit false hands completion to an
     * orchestrator — the sharded-CI contract commons uses.
     */
    public function test_run_completion_defaults_to_true_and_only_explicit_false_disables_it(): void
    {
        $resolver = new ConfigResolver;

        $this->assertTrue($resolver->resolve([], [], $this->directory)->config->run->complete);
        $this->assertTrue($resolver->resolve(['TIDEN_RUN_COMPLETE' => 'true'], [], $this->directory)->config->run->complete);
        $this->assertFalse($resolver->resolve(['TIDEN_RUN_COMPLETE' => 'false'], [], $this->directory)->config->run->complete);
        $this->assertFalse($resolver->resolve(['TIDEN_RUN_COMPLETE' => '0'], [], $this->directory)->config->run->complete);
    }

    public function test_batch_size_is_clamped_to_the_api_limit(): void
    {
        $resolver = new ConfigResolver;

        $this->assertSame(200, $resolver->resolve([], [], $this->directory)->config->batch->size);
        $this->assertSame(2000, $resolver->resolve(['TIDEN_BATCH_SIZE' => '99999'], [], $this->directory)->config->batch->size);
        $this->assertSame(1, $resolver->resolve(['TIDEN_BATCH_SIZE' => '0'], [], $this->directory)->config->batch->size);
        $this->assertSame(50, $resolver->resolve(['TIDEN_BATCH_SIZE' => '50'], [], $this->directory)->config->batch->size);
    }

    public function test_status_mapping_is_parsed_from_pairs(): void
    {
        $config = (new ConfigResolver)->resolve(['TIDEN_STATUS_MAPPING' => 'invalid=failed, blocked=skipped'], [], $this->directory)->config;

        $this->assertSame(['invalid' => 'failed', 'blocked' => 'skipped'], $config->statusMapping);
    }

    public function test_report_mode_needs_a_path(): void
    {
        $resolver = new ConfigResolver;

        $this->assertStringContainsString(
            'TIDEN_REPORT_CONNECTION_PATH',
            $resolver->resolve(['TIDEN_MODE' => 'report'], [], $this->directory)->disabled?->message() ?? '',
        );
        $this->assertTrue(
            $resolver->resolve(['TIDEN_MODE' => 'report', 'TIDEN_REPORT_CONNECTION_PATH' => '/tmp/x'], [], $this->directory)->isEnabled(),
        );
    }
}
