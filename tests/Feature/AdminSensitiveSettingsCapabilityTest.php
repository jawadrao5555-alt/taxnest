<?php

namespace Tests\Feature;

use App\Models\{AdminUser, Company, HsMasterGlobal, Invoice, SystemSetting};
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
        $hsMaster = HsMasterGlobal::create([
            'hs_code' => '99999991',
            'description' => 'Fictional retained HS history',
            'schedule_type' => 'standard',
            'default_tax_rate' => 18,
        ]);

        $this->actingAs($actor, 'admin');
        // Preserve the cached super-admin model while another administrator
        // revokes the persisted role.
        DB::table('admin_users')->where('id', $actor->id)->update(['role' => 'support']);
        $settingsBefore = DB::table('system_settings')->orderBy('id')->get()
            ->map(fn ($row) => (array) $row)->all();
        $auditBefore = DB::table('admin_audit_logs')->count();
        $fiscalConfigurationBefore = collect([
            'fbr_hs_rate_links',
            'global_hs_master',
            'medicine_catalogue',
            'hs_master_global',
            'hs_unmapped_queue',
            'hs_code_mappings',
        ])->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();

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

        $this->get('/admin/risk-settings')
            ->assertOk()
            ->assertSee('Risk configuration is read-only for this account.')
            ->assertDontSee('SYNTHETIC-SECRET-ACCOUNT', false)
            ->assertDontSee('action="http://localhost/admin/risk-settings"', false)
            ->assertDontSee('Save Settings');

        $this->get('/admin/madadgar-chats')
            ->assertOk()
            ->assertSee('Madadgar configuration is read-only for this account.')
            ->assertSee('Key source:')
            ->assertSee('restricted')
            ->assertDontSee('Admin panel (encrypted)')
            ->assertDontSee('Server .env')
            ->assertDontSee('action="http://localhost/admin/madadgar-settings"', false)
            ->assertDontSee('OpenAI API Key (leave blank to keep current)')
            ->assertDontSee('Save Settings');

        $this->get('/admin/hs-rate-links')->assertOk()
            ->assertSee('HS rate-link history is read-only for this account.')
            ->assertDontSee('action="'.route('admin.hs-rate-links.store').'"', false)
            ->assertDontSee('action="'.route('admin.hs-rate-links.import').'"', false);
        $this->get('/admin/hs-master')->assertOk()
            ->assertSee('Global HS intelligence and unmapped evidence are read-only for this account.')
            ->assertDontSee('method="POST" action="/admin/hs-master"', false)
            ->assertDontSee('action="/admin/hs-master/seed"', false)
            ->assertDontSee('action="/admin/hs-master/map-unmapped"', false);
        $this->get('/admin/hs-master-global')->assertOk()
            ->assertSee('HS master records are read-only for this account.')
            ->assertSee('Fictional retained HS history')
            ->assertDontSee(route('admin.hs-master-global.edit', $hsMaster->id), false);
        $this->get('/admin/hs-unmapped')->assertOk()
            ->assertSee('Unmapped HS evidence and prior decisions are read-only for this account.')
            ->assertDontSee('/admin/hs-unmapped/', false);
        $this->get('/admin/hs-mapping-engine')->assertOk()
            ->assertSee('HS mapping configuration and change history are read-only for this account.')
            ->assertDontSee('method="POST" action="'.route('admin.hs-mapping-engine.store').'"', false)
            ->assertDontSee('method="POST" action="'.route('admin.hs-mapping-engine.import').'"', false);
        $this->get('/admin/hs-master-global/'.$hsMaster->id.'/edit')->assertForbidden();

        foreach ([
            fn () => $this->post('/admin/settings'),
            fn () => $this->post('/admin/settings/smtp'),
            fn () => $this->post('/admin/settings/whatsapp'),
            fn () => $this->post('/admin/settings/test-email'),
            fn () => $this->post('/admin/risk-settings'),
            fn () => $this->post('/admin/madadgar-settings'),
            fn () => $this->post('/admin/hs-rate-links'),
            fn () => $this->put('/admin/hs-rate-links/999999'),
            fn () => $this->delete('/admin/hs-rate-links/999999'),
            fn () => $this->post('/admin/hs-rate-links/999999/toggle'),
            fn () => $this->post('/admin/hs-rate-links-import'),
            fn () => $this->post('/admin/hs-master'),
            fn () => $this->put('/admin/hs-master/999999'),
            fn () => $this->post('/admin/hs-master/seed'),
            fn () => $this->post('/admin/hs-master/map-unmapped'),
            fn () => $this->post('/admin/medicine-catalogue/sync'),
            fn () => $this->post('/admin/medicine-catalogue/sync-cancel'),
            fn () => $this->post('/admin/medicine-catalogue/import'),
            fn () => $this->post('/admin/medicine-catalogue'),
            fn () => $this->put('/admin/medicine-catalogue/999999'),
            fn () => $this->post('/admin/hs-master-global/999999'),
            fn () => $this->post('/admin/hs-unmapped/999999/map'),
            fn () => $this->post('/admin/hs-unmapped/999999/reject'),
            fn () => $this->post('/admin/hs-unmapped/999999/regenerate'),
            fn () => $this->post('/admin/hs-mapping-engine/import'),
            fn () => $this->post('/admin/hs-mapping-engine'),
            fn () => $this->put('/admin/hs-mapping-engine/999999'),
            fn () => $this->delete('/admin/hs-mapping-engine/999999'),
            fn () => $this->post('/admin/hs-mapping-engine/999999/clone'),
        ] as $request) {
            $request()->assertForbidden();
        }

        $this->assertSame($settingsBefore, DB::table('system_settings')->orderBy('id')->get()
            ->map(fn ($row) => (array) $row)->all());
        $this->assertSame($auditBefore, DB::table('admin_audit_logs')->count());
        $this->assertSame($fiscalConfigurationBefore, collect(array_keys($fiscalConfigurationBefore))
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all());
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

        $this->actingAs($actor, 'admin')->get('/admin/risk-settings')
            ->assertOk()
            ->assertDontSee('Risk configuration is read-only for this account.')
            ->assertSee('/admin/risk-settings', false)
            ->assertSee('Save Settings');

        $this->actingAs($actor, 'admin')->get('/admin/madadgar-chats')
            ->assertOk()
            ->assertDontSee('Madadgar configuration is read-only for this account.')
            ->assertSee('/admin/madadgar-settings', false)
            ->assertSee('OpenAI API Key (leave blank to keep current)');

        $this->actingAs($actor, 'admin')->post('/admin/settings/test-email')
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_id' => $actor->id,
            'action' => 'Test email sent',
        ]);
    }

    public function test_existing_administrator_retains_legacy_operational_configuration_authority(): void
    {
        $actor = AdminUser::create([
            'name' => 'Fictional legacy configuration administrator',
            'email' => 'legacy-config-admin@example.test',
            'password' => bcrypt('SyntheticOnly123!'),
            'role' => 'admin',
        ]);
        $company = Company::create([
            'name' => 'Fictional legacy configuration company',
            'ntn' => 'LEGACY-CONFIG-ONLY',
            'product_type' => 'di',
        ]);
        $invoice = Invoice::create([
            'company_id' => $company->id,
            'invoice_number' => 'LEGACY-CONFIG-INVOICE',
            'buyer_name' => 'Fictional buyer',
            'status' => 'draft',
            'fbr_status' => 'pending',
            'total_amount' => 1,
        ]);
        $hsMaster = HsMasterGlobal::create([
            'hs_code' => '99999992',
            'description' => 'Fictional editable HS history',
            'schedule_type' => 'standard',
            'default_tax_rate' => 18,
        ]);

        $this->actingAs($actor, 'admin')->get('/admin/hs-rate-links')->assertOk()
            ->assertDontSee('HS rate-link history is read-only for this account.')
            ->assertSee('action="'.route('admin.hs-rate-links.store').'"', false);
        $this->get('/admin/hs-master')->assertOk()
            ->assertDontSee('Global HS intelligence and unmapped evidence are read-only for this account.')
            ->assertSee('action="/admin/hs-master/seed"', false);
        $this->get('/admin/hs-master-global')->assertOk()
            ->assertDontSee('HS master records are read-only for this account.')
            ->assertSee(route('admin.hs-master-global.edit', $hsMaster->id), false);
        $this->get('/admin/hs-master-global/'.$hsMaster->id.'/edit')->assertOk()
            ->assertSee('action="'.route('admin.hs-master-global.update', $hsMaster->id).'"', false);
        $this->get('/admin/hs-mapping-engine')->assertOk()
            ->assertDontSee('HS mapping configuration and change history are read-only for this account.')
            ->assertSee('method="POST" action="'.route('admin.hs-mapping-engine.store').'"', false);

        $this->post('/admin/invoice-override/'.$invoice->id, ['action' => 'lock'])
            ->assertOk();
        $this->assertSame('locked', $invoice->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $actor->id,
            'entity_type' => 'invoice',
            'entity_id' => $invoice->id,
            'action' => 'admin_invoice_override',
        ]);

        $this->actingAs($actor, 'admin')->post('/admin/risk-settings', [
            'mom_spike_threshold' => 250,
            'tax_drop_threshold' => 55,
            'critical_score_threshold' => 35,
            'stability_bonus_weight' => 12,
        ])->assertRedirect('/admin/risk-settings');
        $this->assertSame('250', (string) SystemSetting::get('mom_spike_threshold'));

        $this->actingAs($actor, 'admin')->post('/admin/madadgar-settings', [
            'enabled' => '1',
            'mode' => 'local',
        ])->assertRedirect(route('admin.madadgar-chats'));
        $this->assertSame('local', SystemSetting::get(\App\Services\MadadgarService::SETTING_MODE));

        $this->actingAs($actor, 'admin')->post('/admin/hs-rate-links', [
            'hs_code' => '9999.99.99',
            'schedule_type' => 'standard',
            'tax_rate' => 18,
            'rate_label' => 'Fictional standard rate',
        ])->assertRedirect();
        $this->assertDatabaseHas('fbr_hs_rate_links', [
            'hs_code' => '9999.99.99',
            'schedule_type' => 'standard',
            'tax_rate' => 18,
            'is_active' => 1,
        ]);
    }
}
