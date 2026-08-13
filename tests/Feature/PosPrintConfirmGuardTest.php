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
 * Task 569 — "Print se pehle poocho" (print_confirm_ask) NEVER silently turns OFF.
 *
 * The flag lives inside the pos_printer_settings JSON's normalized shape
 * (Company::printerSettings()). That shape has a known POST-rebuild trap:
 * any settings-save path that rebuilds the array WITHOUT starting from
 * printerSettings() silently drops keys it doesn't know about — the shop's
 * setting flips OFF and the unwanted print preview returns (the original
 * customer complaint behind Task 565).
 *
 * Invariants locked here:
 *   1. printerSettings() defaults print_confirm_ask=false; a saved true persists.
 *   2. PRA printer-settings POST (checkbox form):
 *        • posting print_confirm_ask=1 turns it ON, preserving agent telemetry
 *          (available_printers / printers_reported_at) and prompt_dismissed_at;
 *        • posting the form WITHOUT the checkbox = deliberate uncheck → OFF
 *          (the page always renders the checkbox, so absence = user unticked),
 *          again without dropping any other key.
 *   3. FBR receipt-settings POST with the flag ON must NOT clobber
 *      silent_print_enabled / receipt_printer / prompt_dismissed_at.
 *   4. apiPrinterPrompt (enable + dismiss) preserves an ON flag.
 *   5. posConfigRev() changes when the flag flips (SW boot-fingerprint refresh
 *      guard — cached sale screens must reload on a toggle).
 *   6. Cashier (posCashierBlocked) printer-settings POST stays 403 and the
 *      stored settings stay untouched.
 *
 * Pattern: APP_ENV=testing + SQLite :memory: + minimal Schema::create.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' CACHE_STORE=array \
 *     php vendor/bin/phpunit tests/Feature/PosPrintConfirmGuardTest.php --testdox
 */
class PosPrintConfirmGuardTest extends TestCase
{
    private int $posCompanyId;
    private int $fbrCompanyId;
    private int $posAdminId;
    private int $posCashierId;
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
            // The JSON under test + the FBR receipt-settings writers
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

        // Printer-settings GET path lists failed print jobs — POSTs don't touch
        // it, but keep the table so a follow/redirect never explodes.
        Schema::create('pos_print_jobs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('status')->nullable();
            $t->timestamps();
        });

        $now = now();
        $this->posCompanyId = DB::table('companies')->insertGetId([
            'name' => 'PrintConfirm PRA Shop', 'product_type' => 'pos',
            'status' => 'approved', 'company_status' => 'approved',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->fbrCompanyId = DB::table('companies')->insertGetId([
            'name' => 'PrintConfirm FBR Shop', 'product_type' => 'fbrpos',
            'fbr_pos_enabled' => true,
            'status' => 'approved', 'company_status' => 'approved',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->posAdminId = DB::table('users')->insertGetId([
            'name' => 'Admin', 'email' => 'pc-admin@test.pk',
            'password' => Hash::make('Secret@12345'),
            'company_id' => $this->posCompanyId,
            'role' => 'company_admin', 'pos_role' => 'pos_admin',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->posCashierId = DB::table('users')->insertGetId([
            'name' => 'Cashier', 'email' => 'pc-cashier@test.pk',
            'password' => Hash::make('Secret@12345'),
            'company_id' => $this->posCompanyId,
            'role' => 'user', 'pos_role' => 'pos_cashier',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->fbrAdminId = DB::table('users')->insertGetId([
            'name' => 'FBR Admin', 'email' => 'pc-fbradmin@test.pk',
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

    /** Seed a "real shop" JSON: silent print ON + telemetry + dismissed prompt. */
    private function seedRichPrinterSettings(int $companyId, bool $confirmAsk): array
    {
        $settings = [
            'silent_print_enabled' => true,
            'receipt_printer' => 'POS-80C',
            'kot_printer' => 'Kitchen-1',
            'counter_kot_printer' => null,
            'counter_kot_enabled' => false,
            'available_printers' => [
                ['name' => 'POS-80C', 'displayName' => 'Thermal 80mm', 'isDefault' => true],
                ['name' => 'Kitchen-1', 'displayName' => 'Kitchen Printer'],
            ],
            'printers_reported_at' => '2026-08-13T09:00:00+05:00',
            'print_confirm_ask' => $confirmAsk,
            'prompt_dismissed_at' => '2026-08-01T10:00:00+05:00',
        ];
        Company::where('id', $companyId)->update([
            'pos_printer_settings' => json_encode($settings),
        ]);

        return $settings;
    }

    // ── 1. Model defaults + persistence ───────────────────────────────────

    public function test_printer_settings_defaults_print_confirm_ask_to_false(): void
    {
        $ps = $this->company($this->posCompanyId)->printerSettings();
        $this->assertFalse($ps['print_confirm_ask']);
        // Full normalized shape always present — the rebuild contract.
        foreach (['silent_print_enabled', 'receipt_printer', 'kot_printer',
                  'counter_kot_printer', 'counter_kot_enabled', 'available_printers',
                  'printers_reported_at', 'print_confirm_ask', 'prompt_dismissed_at'] as $key) {
            $this->assertArrayHasKey($key, $ps);
        }
    }

    public function test_saved_true_flag_persists_through_normalization(): void
    {
        $company = $this->company($this->posCompanyId);
        $s = $company->printerSettings();
        $s['print_confirm_ask'] = true;
        $company->update(['pos_printer_settings' => $s]);

        $this->assertTrue($this->company($this->posCompanyId)->printerSettings()['print_confirm_ask']);
    }

    // ── 2. PRA printer-settings POST ──────────────────────────────────────

    public function test_pra_printer_settings_post_turns_flag_on_and_preserves_other_keys(): void
    {
        $this->seedRichPrinterSettings($this->posCompanyId, false);

        $resp = $this->actingAs(User::find($this->posAdminId), 'pos')
            ->post('/pos/printer-settings', [
                'silent_print_enabled' => '1',
                'receipt_printer' => 'POS-80C',
                'kot_printer' => 'Kitchen-1',
                'print_confirm_ask' => '1',
            ]);
        $resp->assertRedirect();

        $ps = $this->company($this->posCompanyId)->printerSettings();
        $this->assertTrue($ps['print_confirm_ask'], 'checkbox ON must persist');
        $this->assertTrue($ps['silent_print_enabled']);
        $this->assertSame('POS-80C', $ps['receipt_printer']);
        $this->assertSame('Kitchen-1', $ps['kot_printer']);
        // Agent telemetry must survive a manual save.
        $this->assertCount(2, $ps['available_printers']);
        $this->assertSame('2026-08-13T09:00:00+05:00', $ps['printers_reported_at']);
        // Existing dismissed-stamp must never be re-stamped/dropped.
        $this->assertSame('2026-08-01T10:00:00+05:00', $ps['prompt_dismissed_at']);
    }

    public function test_pra_printer_settings_post_without_checkbox_is_deliberate_off_but_drops_nothing_else(): void
    {
        $this->seedRichPrinterSettings($this->posCompanyId, true);

        // The printer-settings form ALWAYS renders the print_confirm_ask
        // checkbox — a POST without it means the admin unticked it. That is
        // the one legitimate OFF path; everything else must survive.
        $resp = $this->actingAs(User::find($this->posAdminId), 'pos')
            ->post('/pos/printer-settings', [
                'silent_print_enabled' => '1',
                'receipt_printer' => 'POS-80C',
                'kot_printer' => 'Kitchen-1',
            ]);
        $resp->assertRedirect();

        $ps = $this->company($this->posCompanyId)->printerSettings();
        $this->assertFalse($ps['print_confirm_ask'], 'unticked checkbox = deliberate OFF');
        $this->assertTrue($ps['silent_print_enabled']);
        $this->assertSame('POS-80C', $ps['receipt_printer']);
        $this->assertCount(2, $ps['available_printers']);
        $this->assertSame('2026-08-01T10:00:00+05:00', $ps['prompt_dismissed_at']);
    }

    /** The blade must actually render the checkbox — the "absence = uncheck" logic above depends on it. */
    public function test_printer_settings_blade_renders_print_confirm_checkbox(): void
    {
        $blade = file_get_contents(resource_path('views/pos/printer-settings.blade.php'));
        $this->assertStringContainsString('name="print_confirm_ask"', $blade);
    }

    // ── 3. FBR receipt-settings POST must not clobber sibling keys ────────

    public function test_fbr_receipt_settings_post_preserves_silent_print_keys(): void
    {
        $seeded = $this->seedRichPrinterSettings($this->fbrCompanyId, false);

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
        $this->assertTrue($ps['print_confirm_ask'], 'FBR checkbox ON must persist');
        // The rebuild trap: these must all survive the FBR save.
        $this->assertTrue($ps['silent_print_enabled']);
        $this->assertSame($seeded['receipt_printer'], $ps['receipt_printer']);
        $this->assertSame($seeded['kot_printer'], $ps['kot_printer']);
        $this->assertSame($seeded['printers_reported_at'], $ps['printers_reported_at']);
        $this->assertSame($seeded['prompt_dismissed_at'], $ps['prompt_dismissed_at']);
        $this->assertCount(2, $ps['available_printers']);
    }

    public function test_fbr_receipt_settings_post_without_checkbox_turns_flag_off_only(): void
    {
        $seeded = $this->seedRichPrinterSettings($this->fbrCompanyId, true);

        $resp = $this->actingAs(User::find($this->fbrAdminId), 'fbrpos')
            ->from('/fbr-pos/receipt-settings')
            ->post('/fbr-pos/receipt-settings', [
                'rp_logo_style' => 'center',
            ]);
        $resp->assertRedirect();

        $ps = $this->company($this->fbrCompanyId)->printerSettings();
        $this->assertFalse($ps['print_confirm_ask']);
        $this->assertTrue($ps['silent_print_enabled'], 'silent print must survive an FBR uncheck');
        $this->assertSame($seeded['receipt_printer'], $ps['receipt_printer']);
        $this->assertSame($seeded['prompt_dismissed_at'], $ps['prompt_dismissed_at']);
    }

    // ── 4. Silent-print one-click prompt paths preserve an ON flag ────────

    public function test_api_printer_prompt_dismiss_preserves_flag(): void
    {
        $this->seedRichPrinterSettings($this->posCompanyId, true);

        $resp = $this->actingAs(User::find($this->posAdminId), 'pos')
            ->postJson('/pos/api/printer-prompt', ['action' => 'dismiss']);
        $resp->assertOk();

        $this->assertTrue($this->company($this->posCompanyId)->printerSettings()['print_confirm_ask']);
    }

    public function test_api_printer_prompt_enable_preserves_flag(): void
    {
        $this->seedRichPrinterSettings($this->posCompanyId, true);

        $resp = $this->actingAs(User::find($this->posAdminId), 'pos')
            ->postJson('/pos/api/printer-prompt', ['action' => 'enable']);
        $resp->assertOk();

        $ps = $this->company($this->posCompanyId)->printerSettings();
        $this->assertTrue($ps['silent_print_enabled']);
        $this->assertTrue($ps['print_confirm_ask'], 'one-click enable must not drop the flag');
    }

    /** Agent printer report (telemetry rewrite every ~5 min) must never drop an ON flag. */
    public function test_agent_report_printers_preserves_flag(): void
    {
        $this->seedRichPrinterSettings($this->posCompanyId, true);

        $request = \Illuminate\Http\Request::create('/api/agent/printers', 'POST', [
            'printers' => [['name' => 'New-Printer', 'isDefault' => true]],
        ]);
        $request->attributes->set('agent_company', $this->company($this->posCompanyId));
        app(\App\Http\Controllers\AgentController::class)->reportPrinters($request);

        $ps = $this->company($this->posCompanyId)->printerSettings();
        $this->assertTrue($ps['print_confirm_ask'], 'agent telemetry rewrite must preserve the flag');
        $this->assertTrue($ps['silent_print_enabled']);
        $this->assertCount(1, $ps['available_printers']);
    }

    // ── 5. posConfigRev — flag flip must refresh SW-cached sale screens ───

    public function test_pos_config_rev_changes_when_flag_flips(): void
    {
        $company = $this->company($this->posCompanyId);
        $before = $company->posConfigRev();

        $s = $company->printerSettings();
        $s['print_confirm_ask'] = true;
        $company->update(['pos_printer_settings' => $s]);

        $after = $this->company($this->posCompanyId)->posConfigRev();
        $this->assertNotSame($before, $after, 'flag flip must change posConfigRev (boot-fingerprint refresh)');

        // Flip back → rev returns (deterministic hash of routing keys).
        $s['print_confirm_ask'] = false;
        $this->company($this->posCompanyId)->update(['pos_printer_settings' => $s]);
        $this->assertSame($before, $this->company($this->posCompanyId)->posConfigRev());
    }

    public function test_pos_config_rev_ignores_printer_telemetry_noise(): void
    {
        $this->seedRichPrinterSettings($this->posCompanyId, true);
        $company = $this->company($this->posCompanyId);
        $before = $company->posConfigRev();

        // Agent printer report rewrites telemetry every ~5 min — must NOT
        // fake a settings change (reload-loop guard).
        $s = json_decode($company->getRawOriginal('pos_printer_settings'), true);
        $s['printers_reported_at'] = '2026-08-13T09:05:00+05:00';
        $s['available_printers'][] = ['name' => 'PDF Printer'];
        $company->update(['pos_printer_settings' => $s]);

        $this->assertSame($before, $this->company($this->posCompanyId)->posConfigRev());
    }

    // ── 6. Cashier stays 403 ──────────────────────────────────────────────

    public function test_cashier_printer_settings_post_is_403_and_settings_untouched(): void
    {
        $seeded = $this->seedRichPrinterSettings($this->posCompanyId, true);

        // HTTP layer: PosAdminOnly bounces a plain cashier before the
        // controller (redirect away — settings never touched).
        $web = $this->actingAs(User::find($this->posCashierId), 'pos')
            ->post('/pos/printer-settings', ['print_confirm_ask' => '1']);
        $web->assertRedirect(route('pos.dashboard'));

        // Controller layer: even if a future route change dropped the
        // middleware, printerSettings() itself must 403 a posCashierBlocked
        // user (defense in depth — the invariant named by this task).
        $request = \Illuminate\Http\Request::create('/pos/printer-settings', 'POST', [
            'print_confirm_ask' => '1',
            'silent_print_enabled' => '1',
        ]);
        app()->instance('request', $request);
        auth('pos')->setUser(User::find($this->posCashierId));
        app()->instance('currentCompanyId', $this->posCompanyId);
        try {
            app(\App\Http\Controllers\PosController::class)->printerSettings($request);
            $this->fail('cashier printerSettings POST must abort 403');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        } finally {
            auth('pos')->forgetUser();
        }

        $raw = json_decode($this->company($this->posCompanyId)->getRawOriginal('pos_printer_settings'), true);
        $this->assertSame($seeded, $raw, 'blocked cashier POST must not alter stored settings');
    }
}
