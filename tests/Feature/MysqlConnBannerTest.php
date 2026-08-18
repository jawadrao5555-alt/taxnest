<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\SystemSetting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 1121: HTTP-level tests for the MySQL-connections banner in the SaaS
 * admin layout (layouts/admin-app.blade.php).
 *
 * The banner shows when mysql_conn_last_breach_at exists AND is <= 10 minutes
 * old. It must disappear when:
 *  - the health command deletes the breach keys on recovery, or
 *  - the breach timestamp goes stale (> 10 min) even if the keys still exist
 *    (time-based guard for the case where the command never ran again).
 */
class MysqlConnBannerTest extends TestCase
{
    private const BANNER_TEXT = 'MySQL connections critically high';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('super_admin');
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value');
            $table->string('description')->nullable();
            $table->timestamps();
        });

        AdminUser::create([
            'name' => 'Super', 'email' => 'super@test.pk',
            'password' => Hash::make('secret123'), 'role' => 'super_admin',
        ]);

        // Never open a live IMAP connection during the page render.
        config(['support_mail.password' => '']);
    }

    protected function actingAsSuperAdmin(): static
    {
        return $this->actingAs(AdminUser::first(), 'admin');
    }

    private function seedBreach(string $at, string $pct = '82.5'): void
    {
        SystemSetting::create(['key' => 'mysql_conn_last_breach_at',  'value' => $at]);
        SystemSetting::create(['key' => 'mysql_conn_last_breach_pct', 'value' => $pct]);
    }

    public function test_no_banner_when_keys_absent(): void
    {
        $resp = $this->actingAsSuperAdmin()->get('/admin/support-inbox');
        $resp->assertOk();
        $resp->assertDontSee(self::BANNER_TEXT);
    }

    public function test_banner_shows_for_recent_breach(): void
    {
        $this->seedBreach(now()->subMinutes(3)->toDateTimeString(), '82.5');

        $resp = $this->actingAsSuperAdmin()->get('/admin/support-inbox');
        $resp->assertOk();
        $resp->assertSee(self::BANNER_TEXT);
        $resp->assertSee('82.5');
    }

    public function test_banner_gone_after_command_clears_keys_on_recovery(): void
    {
        $this->seedBreach(now()->subMinutes(3)->toDateTimeString());

        // Recovery run of the health command (below threshold) deletes the keys.
        \App\Console\Commands\CheckMysqlConnectionHealth::$testStatusOverride = [50, 100];
        try {
            $this->artisan('app:mysql-conn-health')->assertExitCode(0);
        } finally {
            \App\Console\Commands\CheckMysqlConnectionHealth::$testStatusOverride = null;
        }

        $this->assertNull(SystemSetting::get('mysql_conn_last_breach_at'));

        $resp = $this->actingAsSuperAdmin()->get('/admin/support-inbox');
        $resp->assertOk();
        $resp->assertDontSee(self::BANNER_TEXT);
    }

    public function test_banner_expires_on_its_own_after_10_minutes(): void
    {
        // Keys still present (command never ran again) but timestamp is stale.
        $this->seedBreach(now()->subMinutes(11)->toDateTimeString());

        $resp = $this->actingAsSuperAdmin()->get('/admin/support-inbox');
        $resp->assertOk();
        $resp->assertDontSee(self::BANNER_TEXT);
    }
}
