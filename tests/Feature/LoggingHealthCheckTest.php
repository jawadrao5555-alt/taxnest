<?php

namespace Tests\Feature;

use App\Console\Commands\CheckLoggingHealth;
use App\Models\SystemSetting;
use App\Services\LogHealth;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TASK: rozana logging-health watchdog (logs:health-check) — catch a silently
 * muted LOG_LEVEL / dead log file BETWEEN deploys.
 *
 * Locks:
 *  - channel resolution: single, daily (today's rotated filename), stack
 *    combinations, non-file channels (stderr) never treated as file targets
 *  - effective-level resolution incl. stack "loosest member wins"
 *  - end-to-end probe: pass on healthy single/daily, fail on suppressed
 *    warning level, fail on missing/unwritable destination
 *  - failure state lands in the LogHealth SystemSetting flag; success clears
 */
class LoggingHealthCheckTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();
        Schema::create('system_settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->text('value')->nullable();
            $t->string('description')->nullable();
            $t->timestamps();
        });
        Schema::create('admin_users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->timestamps();
        });

        Mail::fake();

        $this->logDir = storage_path('framework/testing/log-health-' . uniqid());
        @mkdir($this->logDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->logDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->logDir);
        parent::tearDown();
    }

    private function command(): CheckLoggingHealth
    {
        return $this->app->make(CheckLoggingHealth::class);
    }

    private function useSingle(string $level = 'debug'): string
    {
        $path = $this->logDir . '/laravel.log';
        config([
            'logging.default' => 'single',
            'logging.channels.single' => [
                'driver' => 'single',
                'path' => $path,
                'level' => $level,
            ],
        ]);
        // The channel may already be resolved from app boot with the OLD
        // path — drop the cached instance so the new config takes effect.
        \Illuminate\Support\Facades\Log::forgetChannel('single');

        return $path;
    }

    private function useDaily(string $level = 'debug'): string
    {
        config([
            'logging.default' => 'daily',
            'logging.channels.daily' => [
                'driver' => 'daily',
                'path' => $this->logDir . '/laravel.log',
                'level' => $level,
                'days' => 3,
            ],
        ]);
        \Illuminate\Support\Facades\Log::forgetChannel('daily');

        return $this->logDir . '/laravel-' . now()->format('Y-m-d') . '.log';
    }

    // ---------------------------------------------------------- resolution

    public function test_probe_files_single(): void
    {
        $path = $this->useSingle();
        $this->assertSame([$path], $this->command()->probeFiles('single'));
    }

    public function test_probe_files_daily_uses_todays_rotated_filename(): void
    {
        $expected = $this->useDaily();
        $this->assertSame([$expected], $this->command()->probeFiles('daily'));
        $this->assertStringContainsString('laravel-' . now()->format('Y-m-d') . '.log', $expected);
    }

    public function test_probe_files_stack_expands_members_and_skips_non_file(): void
    {
        $single = $this->logDir . '/laravel.log';
        config([
            'logging.channels.single' => ['driver' => 'single', 'path' => $single, 'level' => 'debug'],
            'logging.channels.daily' => ['driver' => 'daily', 'path' => $this->logDir . '/laravel.log', 'level' => 'debug', 'days' => 3],
            'logging.channels.stderr' => ['driver' => 'monolog', 'handler' => \Monolog\Handler\StreamHandler::class, 'with' => ['stream' => 'php://stderr'], 'level' => 'debug'],
            'logging.channels.stack' => ['driver' => 'stack', 'channels' => ['single', 'daily', 'stderr']],
        ]);

        $files = $this->command()->probeFiles('stack');
        $this->assertContains($single, $files);
        $this->assertContains($this->logDir . '/laravel-' . now()->format('Y-m-d') . '.log', $files);
        $this->assertCount(2, $files, 'stderr must not contribute a file target');
    }

    public function test_probe_files_non_file_channel_is_empty(): void
    {
        config(['logging.channels.stderr' => ['driver' => 'monolog', 'level' => 'debug']]);
        $this->assertSame([], $this->command()->probeFiles('stderr'));
        $this->assertSame([], $this->command()->probeFiles('does-not-exist'));
    }

    public function test_effective_level_stack_loosest_member_wins(): void
    {
        config([
            'logging.channels.a' => ['driver' => 'single', 'path' => '/tmp/a.log', 'level' => 'error'],
            'logging.channels.b' => ['driver' => 'single', 'path' => '/tmp/b.log', 'level' => 'info'],
            'logging.channels.stack' => ['driver' => 'stack', 'channels' => ['a', 'b']],
        ]);
        $this->assertSame('info', $this->command()->effectiveLevel('stack'));
        $this->assertSame('error', $this->command()->effectiveLevel('a'));
    }

    // ---------------------------------------------------------- end-to-end

    public function test_healthy_single_channel_passes_and_clears_flag(): void
    {
        LogHealth::recordFailure(['stale failure']);
        $this->useSingle('debug');

        $this->artisan('logs:health-check')->assertExitCode(0);
        $this->assertNull(LogHealth::current());
        $this->assertNotSame('', (string) SystemSetting::get('log_health_last_success_at', ''));
    }

    public function test_healthy_daily_channel_passes(): void
    {
        $expected = $this->useDaily('debug');

        $this->artisan('logs:health-check')->assertExitCode(0);
        $this->assertFileExists($expected, 'probe must land in the rotated daily file');
        $this->assertNull(LogHealth::current());
    }

    public function test_suppressed_level_fails_and_names_log_level(): void
    {
        $this->useSingle('error');

        $this->artisan('logs:health-check')->assertExitCode(1);
        $failure = LogHealth::current();
        $this->assertNotNull($failure);
        $this->assertStringContainsString("LOG_LEVEL is 'error'", implode(' ', $failure['issues']));
    }

    public function test_unwritable_destination_fails(): void
    {
        // Point the channel at a directory that does not exist and cannot be
        // created by Monolog's handler (parent path is a FILE, not a dir).
        $blocker = $this->logDir . '/blocker';
        file_put_contents($blocker, 'x');
        config([
            'logging.default' => 'single',
            'logging.channels.single' => [
                'driver' => 'single',
                'path' => $blocker . '/nested/laravel.log',
                'level' => 'debug',
            ],
        ]);
        \Illuminate\Support\Facades\Log::forgetChannel('single');

        $this->artisan('logs:health-check')->assertExitCode(1);
        $failure = LogHealth::current();
        $this->assertNotNull($failure);
        $this->assertStringContainsString('MISSING', implode(' ', $failure['issues']));
    }

    public function test_non_file_only_channel_is_not_declared_dead(): void
    {
        config([
            'logging.default' => 'stderr',
            'logging.channels.stderr' => [
                'driver' => 'monolog',
                'handler' => \Monolog\Handler\StreamHandler::class,
                'with' => ['stream' => 'php://temp'],
                'level' => 'debug',
            ],
        ]);
        \Illuminate\Support\Facades\Log::forgetChannel('stderr');

        $this->artisan('logs:health-check')->assertExitCode(0);
        $this->assertNull(LogHealth::current());
    }
}
