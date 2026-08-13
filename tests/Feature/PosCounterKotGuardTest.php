<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 573 — Counter-KOT / KOT routing keys NEVER silently drop.
 *
 * Sibling of PosPrintConfirmGuardTest (Task 569): the same POST-rebuild trap
 * applies to the REMAINING deliberate keys of pos_printer_settings —
 * counter_kot_printer / counter_kot_enabled (Counter KOT Copy, dine-in only)
 * and kot_printer routing. Any settings-save path that rebuilds the JSON
 * without starting from Company::printerSettings() would silently turn the
 * counter KOT copy OFF (the Pizza Master class of complaint).
 *
 * Invariants locked here:
 *   1. printerSettings() defaults counter_kot_enabled=false / printer=null and
 *      always includes both keys in the normalized shape.
 *   2. PRA printer-settings POST:
 *        • can enable Counter KOT Copy (printer must be agent-reported);
 *        • an unknown printer name forces enabled=false (never route blind);
 *        • posting WITHOUT the counter fields = deliberate OFF — but every
 *          other key (kot_printer, telemetry, print_confirm_ask,
 *          prompt_dismissed_at) survives.
 *   3. FBR receipt-settings POST must NOT clobber counter_kot_* / kot_printer.
 *   4. apiPrinterPrompt (enable + dismiss) preserves counter_kot_* / kot_printer.
 *   5. Agent reportPrinters (telemetry rewrite every ~5 min) preserves them.
 *   6. posConfigRev DESIGN DECISION pinned: counter_kot_* flips do NOT change
 *      the rev — the counter copy is resolved SERVER-side at KOT print time
 *      (PosController counter-copy closure), nothing is baked into the cached
 *      sale screen, so no SW refresh is needed. kot_printer IS baked
 *      (silentKotPrint in universal.blade.php) and MUST change the rev.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' CACHE_STORE=array \
 *     php vendor/bin/phpunit tests/Feature/PosCounterKotGuardTest.php --testdox
 */
class PosCounterKotGuardTest extends TestCase
{
    private int $posCompanyId;
    private int $fbrCompanyId;
    private int $posAdminId;
    private int $fbrAdminId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->nullable();
            $t->string('status')->default('approved');
            $t->string('company_status')->default('approved');
            $t->boolean('fbr_pos_enabled')->default(false);
            $t->boolean('agent_enabled')->default(false);
            $t->timestamp('agent_last_seen')->nullable();
            $t->text('pos_printer_settings')->nullable();
            $t->text('invoice_display_prefs')->nullable();
            $t->string('order_match_style')->default('off');
            $t->string('receipt_printer_size')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('role')->nullable();
            $t->string('pos_role')->nullable();
            $t->text('pos_custom_access')->nullable();
            $t->boolean('is_active')->default(true);
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name');
            $t->boolean('is_head_office')->default(false);
            $t->boolean('is_active')->default(true);
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('branch_user', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('user_id');
            $t->timestamps();
        });

        Schema::create('pos_print_jobs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('status')->nullable();
            $t->timestamps();
        });

        $now = now();
        $this->posCompanyId = DB::table('companies')->insertGetId([
            'name' => 'CounterKot PRA Shop', 'product_type' => 'pos',
            'status' => 'approved', 'company_status' => 'approved',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->fbrCompanyId = DB::table('companies')->insertGetId([
            'name' => 'CounterKot FBR Shop', 'product_type' => 'fbrpos',
            'fbr_pos_enabled' => true,
            'status' => 'approved', 'company_status' => 'approved',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->posAdminId = DB::table('users')->insertGetId([
            'name' => 'Admin', 'email' => 'ck-admin@test.pk',
            'password' => Hash::make('Secret@12345'),
            'company_id' => $this->posCompanyId,
            'role' => 'company_admin', 'pos_role' => 'pos_admin',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->fbrAdminId = DB::table('users')->insertGetId([
            'name' => 'FBR Admin', 'email' => 'ck-fbradmin@test.pk',
            'password' => Hash::make('Secret@12345'),
            'company_id' => $this->fbrCompanyId,
            'role' => 'company_admin', 'pos_role' => 'pos_admin',
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function company(int $id): Company
    {
        return Company::findOrFail($id);
    }

    /**
     * Seed a "real restaurant" JSON: silent print ON, KOT routed, Counter KOT
     * Copy ON to its own counter printer, telemetry + dismissed prompt.
     */
    private function seedRichPrinterSettings(int $companyId): array
    {
        $settings = [
            'silent_print_enabled' => true,
            'receipt_printer' => 'POS-80C',
            'kot_printer' => 'Kitchen-1',
            'counter_kot_printer' => 'Counter-58',
            'counter_kot_enabled' => true,
            'available_printers' => [
                ['name' => 'POS-80C', 'displayName' => 'Thermal 80mm', 'isDefault' => true],
                ['name' => 'Kitchen-1', 'displayName' => 'Kitchen Printer'],
                ['name' => 'Counter-58', 'displayName' => 'Counter 58mm'],
            ],
            'printers_reported_at' => '2026-08-13T09:00:00+05:00',
            'print_confirm_ask' => true,
            'prompt_dismissed_at' => '2026-08-01T10:00:00+05:00',
        ];
        Company::where('id', $companyId)->update([
            'pos_printer_settings' => json_encode($settings),
        ]);

        return $settings;
    }

    // ── 1. Model defaults ─────────────────────────────────────────────────

    public function test_printer_settings_defaults_counter_kot_off_with_keys_present(): void
    {
        $ps = $this->company($this->posCompanyId)->printerSettings();
        $this->assertArrayHasKey('counter_kot_printer', $ps);
        $this->assertArrayHasKey('counter_kot_enabled', $ps);
        $this->assertArrayHasKey('kot_printer', $ps);
        $this->assertNull($ps['counter_kot_printer']);
        $this->assertFalse($ps['counter_kot_enabled']);
    }

    public function test_saved_counter_kot_settings_persist_through_normalization(): void
    {
        $company = $this->company($this->posCompanyId);
        $s = $company->printerSettings();
        $s['counter_kot_printer'] = 'Counter-58';
        $s['counter_kot_enabled'] = true;
        $company->update(['pos_printer_settings' => $s]);

        $ps = $this->company($this->posCompanyId)->printerSettings();
        $this->assertSame('Counter-58', $ps['counter_kot_printer']);
        $this->assertTrue($ps['counter_kot_enabled']);
    }

    // ── 2. PRA printer-settings POST ──────────────────────────────────────

    public function test_pra_post_enables_counter_kot_and_preserves_other_keys(): void
    {
        $seeded = $this->seedRichPrinterSettings($this->posCompanyId);
        // Start from counter KOT OFF so the POST is the thing turning it on.
        $seeded['counter_kot_printer'] = null;
        $seeded['counter_kot_enabled'] = false;
        Company::where('id', $this->posCompanyId)
            ->update(['pos_printer_settings' => json_encode($seeded)]);

        $resp = $this->actingAs(User::find($this->posAdminId), 'pos')
            ->post('/pos/printer-settings', [
                'silent_print_enabled' => '1',
                'receipt_printer' => 'POS-80C',
                'kot_printer' => 'Kitchen-1',
                'counter_kot_printer' => 'Counter-58',
                'counter_kot_enabled' => '1',
                'print_confirm_ask' => '1',
            ]);
        $resp->assertRedirect();

        $ps = $this->company($this->posCompanyId)->printerSettings();
        $this->assertSame('Counter-58', $ps['counter_kot_printer']);
        $this->assertTrue($ps['counter_kot_enabled']);
        // Nothing else dropped by the rebuild.
        $this->assertTrue($ps['silent_print_enabled']);
        $this->assertSame('POS-80C', $ps['receipt_printer']);
        $this->assertSame('Kitchen-1', $ps['kot_printer']);
        $this->assertTrue($ps['print_confirm_ask']);
        $this->assertCount(3, $ps['available_printers']);
        $this->assertSame('2026-08-13T09:00:00+05:00', $ps['printers_reported_at']);
        $this->assertSame('2026-08-01T10:00:00+05:00', $ps['prompt_dismissed_at']);
    }

    public function test_pra_post_with_unknown_counter_printer_forces_enabled_false(): void
    {
        $this->seedRichPrinterSettings($this->posCompanyId);

        $resp = $this->actingAs(User::find($this->posAdminId), 'pos')
            ->post('/pos/printer-settings', [
                'silent_print_enabled' => '1',
                'receipt_printer' => 'POS-80C',
                'kot_printer' => 'Kitchen-1',
                'counter_kot_printer' => 'Ghost-Printer',
                'counter_kot_enabled' => '1',
                'print_confirm_ask' => '1',
            ]);
        $resp->assertRedirect();

        $ps = $this->company($this->posCompanyId)->printerSettings();
        // Never route blind: unknown printer = unset + tick forced OFF.
        $this->assertNull($ps['counter_kot_printer']);
        $this->assertFalse($ps['counter_kot_enabled']);
        // The rest of the form still applied normally.
        $this->assertSame('Kitchen-1', $ps['kot_printer']);
        $this->assertTrue($ps['silent_print_enabled']);
    }

    public function test_pra_post_without_counter_fields_is_deliberate_off_but_drops_nothing_else(): void
    {
        $seeded = $this->seedRichPrinterSettings($this->posCompanyId);

        // The printer-settings form ALWAYS renders the Counter KOT block —
        // a POST without it means the admin cleared/unticked it. That is the
        // one legitimate OFF path; every other key must survive.
        $resp = $this->actingAs(User::find($this->posAdminId), 'pos')
            ->post('/pos/printer-settings', [
                'silent_print_enabled' => '1',
                'receipt_printer' => 'POS-80C',
                'kot_printer' => 'Kitchen-1',
                'print_confirm_ask' => '1',
            ]);
        $resp->assertRedirect();

        $ps = $this->company($this->posCompanyId)->printerSettings();
        $this->assertNull($ps['counter_kot_printer']);
        $this->assertFalse($ps['counter_kot_enabled']);
        $this->assertSame('Kitchen-1', $ps['kot_printer']);
        $this->assertTrue($ps['silent_print_enabled']);
        $this->assertTrue($ps['print_confirm_ask']);
        $this->assertCount(3, $ps['available_printers']);
        $this->assertSame($seeded['printers_reported_at'], $ps['printers_reported_at']);
        $this->assertSame($seeded['prompt_dismissed_at'], $ps['prompt_dismissed_at']);
    }

    /** The blade must actually render the counter-KOT fields — the "absence = deliberate OFF" logic above depends on it. */
    public function test_printer_settings_blade_renders_counter_kot_fields(): void
    {
        $blade = file_get_contents(resource_path('views/pos/printer-settings.blade.php'));
        $this->assertStringContainsString('name="counter_kot_printer"', $blade);
        $this->assertStringContainsString('name="counter_kot_enabled"', $blade);
        $this->assertStringContainsString('name="kot_printer"', $blade);
    }

    // ── 3. FBR receipt-settings POST must not clobber counter-KOT keys ────

    public function test_fbr_receipt_settings_post_preserves_counter_kot_and_kot_routing(): void
    {
        $seeded = $this->seedRichPrinterSettings($this->fbrCompanyId);

        $resp = $this->actingAs(User::find($this->fbrAdminId), 'fbrpos')
            ->from('/fbr-pos/receipt-settings')
            ->post('/fbr-pos/receipt-settings', [
                'rp_logo_style' => 'center',
                'rp_style_bold' => '1',
                'rp_order_match' => 'off',
                'rp_print_confirm' => '1',
            ]);
        $resp->assertRedirect();

        $ps = $this->company($this->fbrCompanyId)->printerSettings();
        $this->assertSame($seeded['counter_kot_printer'], $ps['counter_kot_printer']);
        $this->assertTrue($ps['counter_kot_enabled'], 'FBR receipt save must not kill the counter KOT copy');
        $this->assertSame($seeded['kot_printer'], $ps['kot_printer']);
        $this->assertTrue($ps['silent_print_enabled']);
        $this->assertSame($seeded['receipt_printer'], $ps['receipt_printer']);
        $this->assertSame($seeded['printers_reported_at'], $ps['printers_reported_at']);
        $this->assertSame($seeded['prompt_dismissed_at'], $ps['prompt_dismissed_at']);
    }

    // ── 4. Silent-print one-click prompt paths preserve counter-KOT keys ──

    public function test_api_printer_prompt_dismiss_preserves_counter_kot(): void
    {
        $this->seedRichPrinterSettings($this->posCompanyId);

        $resp = $this->actingAs(User::find($this->posAdminId), 'pos')
            ->postJson('/pos/api/printer-prompt', ['action' => 'dismiss']);
        $resp->assertOk();

        $ps = $this->company($this->posCompanyId)->printerSettings();
        $this->assertSame('Counter-58', $ps['counter_kot_printer']);
        $this->assertTrue($ps['counter_kot_enabled']);
        $this->assertSame('Kitchen-1', $ps['kot_printer']);
    }

    public function test_api_printer_prompt_enable_preserves_counter_kot(): void
    {
        $this->seedRichPrinterSettings($this->posCompanyId);

        $resp = $this->actingAs(User::find($this->posAdminId), 'pos')
            ->postJson('/pos/api/printer-prompt', ['action' => 'enable']);
        $resp->assertOk();

        $ps = $this->company($this->posCompanyId)->printerSettings();
        $this->assertTrue($ps['silent_print_enabled']);
        $this->assertSame('Counter-58', $ps['counter_kot_printer'], 'one-click enable must not drop counter KOT');
        $this->assertTrue($ps['counter_kot_enabled']);
        $this->assertSame('Kitchen-1', $ps['kot_printer']);
    }

    /** Agent printer report (telemetry rewrite every ~5 min) must never drop counter-KOT routing. */
    public function test_agent_report_printers_preserves_counter_kot(): void
    {
        $this->seedRichPrinterSettings($this->posCompanyId);

        $request = \Illuminate\Http\Request::create('/api/agent/printers', 'POST', [
            'printers' => [['name' => 'New-Printer', 'isDefault' => true]],
        ]);
        $request->attributes->set('agent_company', $this->company($this->posCompanyId));
        app(\App\Http\Controllers\AgentController::class)->reportPrinters($request);

        $ps = $this->company($this->posCompanyId)->printerSettings();
        $this->assertSame('Counter-58', $ps['counter_kot_printer'], 'telemetry rewrite must preserve counter KOT printer');
        $this->assertTrue($ps['counter_kot_enabled']);
        $this->assertSame('Kitchen-1', $ps['kot_printer']);
        $this->assertCount(1, $ps['available_printers']);
    }

    // ── 5. posConfigRev — pinned design decisions ─────────────────────────

    /**
     * DESIGN DECISION (Task 573): counter_kot_* is deliberately NOT in the
     * posConfigRev printer_routing hash. The counter copy is resolved
     * SERVER-side at KOT print time (nothing is baked into the SW-cached sale
     * screen), so a flip must NOT force every cashier screen to reload.
     * If counter-KOT state ever gets baked into the sale screen, add the keys
     * to the printer_routing block in Company::posConfigRev() and flip this test.
     */
    public function test_pos_config_rev_does_not_change_on_counter_kot_flip(): void
    {
        $this->seedRichPrinterSettings($this->posCompanyId);
        $company = $this->company($this->posCompanyId);
        $before = $company->posConfigRev();

        $s = json_decode($company->getRawOriginal('pos_printer_settings'), true);
        $s['counter_kot_enabled'] = false;
        $s['counter_kot_printer'] = null;
        $company->update(['pos_printer_settings' => $s]);

        $this->assertSame($before, $this->company($this->posCompanyId)->posConfigRev(),
            'counter_kot_* is server-resolved at print time — flips must not fake a config change');
    }

    /** kot_printer IS baked (silentKotPrint in universal sale screen) — change must refresh cached screens. */
    public function test_pos_config_rev_changes_when_kot_printer_changes(): void
    {
        $this->seedRichPrinterSettings($this->posCompanyId);
        $company = $this->company($this->posCompanyId);
        $before = $company->posConfigRev();

        $s = json_decode($company->getRawOriginal('pos_printer_settings'), true);
        $s['kot_printer'] = null;
        $company->update(['pos_printer_settings' => $s]);

        $this->assertNotSame($before, $this->company($this->posCompanyId)->posConfigRev(),
            'kot_printer routing is baked into the sale screen — a change must bump posConfigRev');
    }
}
