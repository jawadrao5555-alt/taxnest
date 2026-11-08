<?php

namespace Tests\Feature;

use App\Models\{AdminUser, SystemSetting};
use App\Services\{SmtpRuntimeConfig, WhatsAppBusinessApi};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Mail};
use Tests\TestCase;

class AdminSensitiveSettingsCapabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_cached_role_downgrade_hides_values_and_refuses_every_sensitive_settings_write(): void
    {
        Mail::fake();
        $actor = AdminUser::create([
            'name' => 'Fictional cached configuration administrator',
            'email' => 'cached-config-admin@example.test',
            'password' => bcrypt('SyntheticOnly123!'),
            'role' => 'super_admin',
        ]);
        SystemSetting::set('payment_account_number', 'SYNTHETIC-SECRET-ACCOUNT');
        SmtpRuntimeConfig::save([
            'enabled' => true,
            'host' => 'secret.smtp.example.test',
            'port' => 465,
            'encryption' => 'ssl',
            'username' => 'secret-mailbox@example.test',
            'from_address' => 'secret-mailbox@example.test',
            'from_name' => 'Synthetic sender',
        ], 'NeverDeliveredSyntheticPassword!');
        SystemSetting::set(WhatsAppBusinessApi::CENTRAL_PHONE_ID_KEY, '998877665544332211');
        SystemSetting::set(WhatsAppBusinessApi::CENTRAL_TOKEN_KEY, 'synthetic-encrypted-token-placeholder');

        $this->actingAs($actor, 'admin');
        // Preserve the cached super-admin model while another administrator
        // revokes the persisted role.
        DB::table('admin_users')->where('id', $actor->id)->update(['role' => 'support']);
        $settingsBefore = DB::table('system_settings')->orderBy('id')->get()
            ->map(fn ($row) => (array) $row)->all();
        $auditBefore = DB::table('admin_audit_logs')->count();

        $this->get('/admin/settings')
            ->assertOk()
            ->assertSee('Sensitive configuration is read-only for this account.')
            ->assertDontSee('SYNTHETIC-SECRET-ACCOUNT')
            ->assertDontSee('secret.smtp.example.test')
            ->assertDontSee('secret-mailbox@example.test')
            ->assertDontSee('998877665544332211')
            ->assertDontSee('/admin/settings/smtp', false)
            ->assertDontSee('/admin/settings/whatsapp', false)
            ->assertDontSee('/admin/settings/test-email', false)
            ->assertDontSee('action="http://localhost/admin/settings"', false);

        foreach ([
            fn () => $this->post('/admin/settings'),
            fn () => $this->post('/admin/settings/smtp'),
            fn () => $this->post('/admin/settings/whatsapp'),
            fn () => $this->post('/admin/settings/test-email'),
        ] as $request) {
            $request()->assertForbidden();
        }

        $this->assertSame($settingsBefore, DB::table('system_settings')->orderBy('id')->get()
            ->map(fn ($row) => (array) $row)->all());
        $this->assertSame($auditBefore, DB::table('admin_audit_logs')->count());
        $this->assertSame('support', $actor->fresh()->role);
        Mail::assertNothingSent();
    }

    public function test_existing_administrator_can_open_settings_and_send_mocked_test_email(): void
    {
        Mail::fake();
        $actor = AdminUser::create([
            'name' => 'Fictional configuration administrator',
            'email' => 'config-admin@example.test',
            'password' => bcrypt('SyntheticOnly123!'),
            'role' => 'admin',
        ]);

        $this->actingAs($actor, 'admin')->get('/admin/settings')
            ->assertOk()
            ->assertDontSee('Sensitive configuration is read-only for this account.')
            ->assertSee('/admin/settings/smtp', false)
            ->assertSee('/admin/settings/whatsapp', false)
            ->assertSee('/admin/settings/test-email', false);

        $this->actingAs($actor, 'admin')->post('/admin/settings/test-email')
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_id' => $actor->id,
            'action' => 'Test email sent',
        ]);
    }
}
