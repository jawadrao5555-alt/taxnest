<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Services\SupportMailHealth;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Support mailbox (support@ IMAP) health banner in the SaaS admin layout.
 * Records via SupportMailHealth; banner shows while failing, clears on success.
 */
class SupportMailHealthBannerTest extends TestCase
{
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
            $table->text('value')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        AdminUser::create([
            'name' => 'Super', 'email' => 'super@test.pk',
            'password' => Hash::make('secret123'), 'role' => 'super_admin',
        ]);

        // Neutralize any real mailbox creds so the page render never opens a
        // live IMAP connection (which would legitimately clear the failure).
        config(['support_mail.password' => '']);
    }

    protected function actingAsSuperAdmin(): static
    {
        return $this->actingAs(AdminUser::first(), 'admin');
    }

    public function test_no_banner_when_healthy(): void
    {
        $this->assertNull(SupportMailHealth::current());

        $resp = $this->actingAsSuperAdmin()->get('/admin/support-inbox');
        $resp->assertOk();
        $resp->assertDontSee('Support mailbox (support@) is unreachable');
    }

    public function test_banner_shows_after_recorded_failure(): void
    {
        SupportMailHealth::recordFailure(new \RuntimeException('AUTHENTICATIONFAILED bad password'));
        SupportMailHealth::recordFailure(new \RuntimeException('AUTHENTICATIONFAILED bad password'));

        $state = SupportMailHealth::current();
        $this->assertNotNull($state);
        $this->assertSame(2, $state['count']);

        $resp = $this->actingAsSuperAdmin()->get('/admin/support-inbox');
        $resp->assertOk();
        $resp->assertSee('Support mailbox (support@) is unreachable');
        $resp->assertSee('AUTHENTICATIONFAILED bad password');
        $resp->assertSee('Support Inbox');
    }

    public function test_banner_clears_after_success(): void
    {
        SupportMailHealth::recordFailure(new \RuntimeException('connection refused'));
        $this->assertNotNull(SupportMailHealth::current());

        SupportMailHealth::recordSuccess();
        $this->assertNull(SupportMailHealth::current());

        $resp = $this->actingAsSuperAdmin()->get('/admin/support-inbox');
        $resp->assertOk();
        $resp->assertDontSee('Support mailbox (support@) is unreachable');
    }
}
