<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\User;
use App\Http\Controllers\FbrPosController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 1277 — Rs 1 FBR POS service fee: integration-configured gate.
 *
 * Owner's rule (SRO 1279/21 fee): the rupee goes on the bill ONLY when the
 * FBR Reporting toggle is ON AND the integration is actually configured —
 * i.e. the bill CAN really reach FBR. Toggle-ON-but-unconfigured must never
 * charge the customer.
 *
 * Part A — Company::fbrPosIntegrationConfigured() truth table (mirrors the
 *          submit path exactly, never stricter):
 *   - POSID missing/zero → false in every mode
 *   - cloud + POSID + encrypted token → true
 *   - cloud + POSID + plausible RAW token → true
 *   - cloud + POSID + corrupt/undecryptable blob → false
 *   - cloud + POSID + no token → false
 *   - fiscal_device + POSID + fbr_pos_enabled + agent_enabled → true (no token)
 *   - fiscal_device + POSID but agent_enabled=false → falls back to token rule
 *
 * Part B — store() end-to-end matrix (stored fbr_service_charge + total_amount
 *          + response totals agree; server total is proven via the cash guard's
 *          change_due):
 *   1. Reporting ON, cloud UNCONFIGURED → fee 0.00, total 300 (no rupee even
 *      though the submit attempt ends in config_error)
 *   2. Reporting ON, fiscal device CONFIGURED → fee 1.00, total 301, 'pending'
 *   3. Reporting OFF, fully configured → fee 0.00 (unchanged behaviour)
 *   4. Provisional with reporting ON + configured → fee 0.00; promoting it
 *      never adds the fee retroactively (amounts are never re-derived)
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create +
 * direct controller call (FbrPosStoreReplayGuardTest pattern).
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/FbrPosServiceChargeConfiguredGateTest.php --testdox
 */
class FbrPosServiceChargeConfiguredGateTest extends TestCase
{
    protected int $companyId;
    protected object $user;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->string('company_status')->nullable();
            $table->boolean('fbr_reporting_enabled')->default(false);
            $table->boolean('fbr_pos_enabled')->default(false);
            $table->string('fbr_pos_id')->nullable();
            $table->text('fbr_pos_token')->nullable();
            $table->string('fbr_pos_environment')->nullable();
            $table->string('fbr_connection_mode')->nullable();
            $table->boolean('agent_enabled')->default(false);
            $table->string('agent_api_key')->nullable();
            $table->boolean('agent_submits_pra')->nullable();
            $table->boolean('inventory_enabled')->default(false);
            $table->boolean('is_internal_account')->default(false);
            $table->integer('invoice_limit_override')->nullable();
            $table->decimal('cashier_discount_limit', 5, 2)->nullable();
            $table->decimal('manager_discount_limit', 5, 2)->nullable();
            $table->string('confidential_pin')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('fbr_pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('terminal_id')->nullable();
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->string('invoice_number');
            $table->string('invoice_mode')->nullable();
            $table->string('transaction_type')->nullable();
            $table->string('status')->nullable();
            $table->string('fbr_status')->nullable();
            $table->string('fbr_invoice_number')->nullable();
            $table->string('fbr_response_code')->nullable();
            $table->text('fbr_response')->nullable();
            $table->string('fbr_submission_hash')->nullable();
            $table->text('fbr_error_message')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_ntn')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_value', 12, 2)->nullable();
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('fbr_service_charge', 12, 2)->nullable();
            $table->decimal('loyalty_points_earned', 12, 2)->nullable();
            $table->decimal('loyalty_points_redeemed', 12, 2)->nullable();
            $table->decimal('loyalty_redemption_amount', 12, 2)->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->text('payment_breakdown')->nullable();
            $table->decimal('cash_received', 12, 2)->nullable();
            $table->decimal('change_due', 12, 2)->nullable();
            $table->unsignedBigInteger('promotion_id')->nullable();
            $table->string('promotion_code')->nullable();
            $table->unsignedBigInteger('parent_transaction_id')->nullable();
            $table->string('order_type')->nullable();
            $table->string('delivery_address')->nullable();
            $table->string('offline_uuid', 64)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'offline_uuid'], 'fbr_txn_offline_uuid_unique');
        });

        Schema::create('fbr_pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('item_name');
            $table->string('hs_code')->nullable();
            $table->string('uom')->nullable();
            $table->decimal('quantity', 12, 4)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('cost_price', 12, 4)->nullable();
            $table->decimal('discount', 12, 2)->nullable();
            $table->decimal('item_discount', 12, 2)->nullable();
            $table->boolean('is_tax_exempt')->default(false);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->nullable();
            $table->decimal('subtotal', 12, 2)->nullable();
            $table->decimal('total', 12, 2)->nullable();
            $table->timestamps();
        });

        // Submit path writes a log row on config_error (missing POSID/token).
        Schema::create('fbr_pos_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->text('request_payload')->nullable();
            $table->text('response_payload')->nullable();
            $table->string('response_code')->nullable();
            $table->string('status')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('fbr_pos_loyalty_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->unique();
            $table->boolean('is_enabled')->default(false);
            $table->decimal('rs_per_point', 8, 2)->default(100);
            $table->decimal('point_value', 8, 2)->default(1);
            $table->integer('min_redeem_points')->default(50);
            $table->integer('points_expiry_days')->nullable();
            $table->timestamps();
        });

        Schema::create('fbr_pos_shifts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');
            $table->string('status')->default('open');
            $table->decimal('sales_count', 12, 2)->default(0);
            $table->decimal('total_sales', 12, 2)->default(0);
            $table->decimal('total_cash', 12, 2)->default(0);
            $table->decimal('total_card', 12, 2)->default(0);
            $table->decimal('total_other', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('pos_customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('phone')->nullable();
            $table->decimal('khata_balance', 12, 2)->default(0);
            $table->integer('loyalty_points')->default(0);
            $table->decimal('total_spent', 12, 2)->default(0);
            $table->integer('total_orders')->default(0);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->decimal('default_price', 12, 2)->default(0);
            $table->boolean('is_price_editable')->default(true);
            $table->timestamps();
        });

        // Baseline: reporting OFF, cloud mode, nothing configured.
        $company = Company::create([
            'name' => 'Fee Gate Test Shop',
            'fbr_reporting_enabled' => false,
            'fbr_pos_enabled' => true,
            'fbr_connection_mode' => 'cloud',
            'agent_enabled' => false,
            'inventory_enabled' => false,
        ]);
        $this->companyId = $company->id;

        DB::table('fbr_pos_loyalty_settings')->insert([
            'company_id' => $this->companyId,
            'is_enabled' => false,
            'rs_per_point' => 100.00,
            'point_value' => 1.00,
            'min_redeem_points' => 50,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        $this->user = (object) [
            'id' => DB::table('users')->insertGetId([
                'name' => 'Test Cashier',
                'email' => 'cashier@feegate.pk',
                'password' => bcrypt('test'),
                'company_id' => $this->companyId,
                'role' => 'company_admin',
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'company_id' => $this->companyId,
            'role' => 'company_admin',
        ];

        app()->bind('currentCompanyId', fn () => $this->companyId);
        app()->bind('currentBranchId', fn () => null);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** In-memory Company for the truth-table tests (no DB row needed). */
    private function makeCompany(array $attrs): Company
    {
        $c = new Company();
        foreach ($attrs as $k => $v) {
            $c->setAttribute($k, $v);
        }
        return $c;
    }

    private function setCompany(array $attrs): void
    {
        DB::table('companies')->where('id', $this->companyId)->update($attrs);
    }

    /** Basic Rs 300 cash-sale payload (tax-exempt: subtotal 300, tax 0). */
    private function cashPayload(array $override = []): array
    {
        return array_merge([
            'items' => [[
                'item_name' => 'Rooh Afza',
                'quantity' => 2,
                'unit_price' => 150,
                'uom' => 'U',
                'tax_rate' => 0,
                'is_tax_exempt' => true,
                'item_discount' => 0,
            ]],
            'payment_method' => 'cash',
            'cash_received' => 400,
            'discount_type' => 'percentage',
            'discount_value' => 0,
            'tax_inclusive' => false,
        ], $override);
    }

    private function callStore(array $payload): \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
    {
        $userModel = new \App\Models\User();
        $userModel->id = $this->user->id;
        $userModel->role = $this->user->role;
        $userModel->company_id = $this->companyId;
        \Illuminate\Support\Facades\Auth::guard('fbrpos')->setUser($userModel);

        $req = Request::create('/fbr-pos/store', 'POST', $payload);
        $req->headers->set('Accept', 'application/json');

        return (new FbrPosController())->store($req);
    }

    private function txRow(int $id): object
    {
        return DB::table('fbr_pos_transactions')->where('id', $id)->first();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Part A — fbrPosIntegrationConfigured() truth table
    // ─────────────────────────────────────────────────────────────────────────

    public function test_configured_false_without_pos_id_in_every_mode(): void
    {
        // Cloud with a perfectly good token but no POSID
        $c = $this->makeCompany([
            'fbr_connection_mode' => 'cloud',
            'fbr_pos_token' => Crypt::encryptString('pos-token-xyz'),
        ]);
        $this->assertFalse($c->fbrPosIntegrationConfigured(), 'cloud: no POSID → not configured');

        // Zero / non-numeric POSID is the same as missing (submit casts to int)
        $c->fbr_pos_id = '0';
        $this->assertFalse($c->fbrPosIntegrationConfigured(), 'POSID "0" → not configured');
        $c->fbr_pos_id = 'abc';
        $this->assertFalse($c->fbrPosIntegrationConfigured(), 'non-numeric POSID → not configured');

        // Fiscal device fully set up but no POSID
        $d = $this->makeCompany([
            'fbr_pos_enabled' => true,
            'fbr_connection_mode' => 'fiscal_device',
            'agent_enabled' => true,
        ]);
        $this->assertFalse($d->fbrPosIntegrationConfigured(), 'fiscal device: no POSID → not configured');
    }

    public function test_configured_cloud_token_rules(): void
    {
        $base = ['fbr_connection_mode' => 'cloud', 'fbr_pos_id' => '123456'];

        // No token at all
        $c = $this->makeCompany($base);
        $this->assertFalse($c->fbrPosIntegrationConfigured(), 'cloud: POSID but no token → not configured');

        // Encrypted token
        $c = $this->makeCompany($base + ['fbr_pos_token' => Crypt::encryptString('pos-token-xyz')]);
        $this->assertTrue($c->fbrPosIntegrationConfigured(), 'cloud: encrypted token → configured');

        // Plausible RAW token (30–64 chars, no Crypt envelope) — submit uses it as-is
        $c = $this->makeCompany($base + ['fbr_pos_token' => 'a3f1c2d4-5b6e-7f80-91a2-b3c4d5e6f708']);
        $this->assertTrue($c->fbrPosIntegrationConfigured(), 'cloud: raw plausible token → configured');

        // Corrupted / undecryptable blob — submit gets '' → config_error → no fee
        $c = $this->makeCompany($base + ['fbr_pos_token' => 'eyJ' . str_repeat('Xy7', 90)]);
        $this->assertFalse($c->fbrPosIntegrationConfigured(), 'cloud: corrupt blob → not configured');
    }

    public function test_configured_fiscal_device_rules(): void
    {
        $base = ['fbr_pos_id' => '123456', 'fbr_connection_mode' => 'fiscal_device'];

        // Full agent setup — no token needed (agent submits from the shop PC)
        $c = $this->makeCompany($base + ['fbr_pos_enabled' => true, 'agent_enabled' => true]);
        $this->assertTrue($c->fbrPosIntegrationConfigured(), 'fiscal device: agent set up → configured');

        // Agent disabled → mirrors the submit guard falling through to cloud rules (no token → false)
        $c = $this->makeCompany($base + ['fbr_pos_enabled' => true, 'agent_enabled' => false]);
        $this->assertFalse($c->fbrPosIntegrationConfigured(), 'fiscal device: agent disabled, no token → not configured');
    }

    public function test_settings_warning_only_shows_when_reporting_is_on_and_setup_is_incomplete(): void
    {
        $this->actingAs(User::findOrFail($this->user->id), 'fbrpos');
        $controller = app(FbrPosController::class);

        // A reachable owner state: Reporting is on, but no POSID/credential exists yet.
        $this->setCompany([
            'fbr_reporting_enabled' => true,
            'fbr_connection_mode' => 'cloud',
            'fbr_pos_id' => null,
            'fbr_pos_token' => null,
            'agent_enabled' => false,
        ]);

        $incomplete = $controller->fbrSettings(Request::create('/fbr-pos/settings', 'GET'));
        $this->assertTrue(
            $incomplete->getData()['fbrReportingSetupIncomplete'],
            'settings must warn while Reporting is on but no working FBR route exists'
        );

        // Completing Fiscal Device setup makes the same canonical predicate true,
        // so the settings warning must disappear without changing the reporting toggle.
        $this->setCompany([
            'fbr_pos_enabled' => true,
            'fbr_connection_mode' => 'fiscal_device',
            'fbr_pos_id' => '812345',
            'agent_enabled' => true,
        ]);

        $configured = $controller->fbrSettings(Request::create('/fbr-pos/settings', 'GET'));
        $this->assertFalse(
            $configured->getData()['fbrReportingSetupIncomplete'],
            'settings must clear the warning as soon as the fiscal-device setup is complete'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Part B — store() end-to-end fee matrix
    // ─────────────────────────────────────────────────────────────────────────

    public function test_reporting_on_unconfigured_cloud_charges_no_fee(): void
    {
        // Toggle ON, but no POSID and no token — a real reachable state.
        $this->setCompany([
            'fbr_reporting_enabled' => true,
            'fbr_connection_mode' => 'cloud',
            'fbr_pos_id' => null,
            'fbr_pos_token' => null,
        ]);

        $res = $this->callStore($this->cashPayload(['cash_received' => 300]));
        $data = $res->getData(true);

        $this->assertTrue($data['success'], 'sale must still save');
        $row = $this->txRow($data['transaction_id']);

        $this->assertSame(0.0, (float) $row->fbr_service_charge, 'NO Rs 1 fee when unconfigured');
        $this->assertSame(300.0, (float) $row->total_amount, 'total has no rupee folded in');
        $this->assertSame(300.0, (float) $data['total_amount'], 'response total matches stored');
        $this->assertSame(0.0, (float) $row->change_due, 'cash guard accepted exactly Rs 300 — server total is 300');
        // The submit attempt itself dead-ends as a permanent config error (no network call).
        $this->assertSame('config_error', $row->fbr_status);
    }

    public function test_reporting_on_configured_fiscal_device_charges_fee(): void
    {
        $this->setCompany([
            'fbr_reporting_enabled' => true,
            'fbr_pos_enabled' => true,
            'fbr_connection_mode' => 'fiscal_device',
            'fbr_pos_id' => '812345',
            'agent_enabled' => true,
            'agent_api_key' => 'tnk_testkey',
        ]);

        $res = $this->callStore($this->cashPayload(['cash_received' => 400]));
        $data = $res->getData(true);

        $this->assertTrue($data['success']);
        $row = $this->txRow($data['transaction_id']);

        $this->assertSame(1.0, (float) $row->fbr_service_charge, 'Rs 1 fee when configured — unchanged behaviour');
        $this->assertSame(301.0, (float) $row->total_amount, 'total includes the rupee');
        $this->assertSame(301.0, (float) $data['total_amount'], 'response total matches stored');
        $this->assertSame(99.0, (float) $row->change_due, 'change proves server total 301');
        $this->assertSame('pending', $row->fbr_status, 'queued for the Desktop Sync Agent');
    }

    public function test_reporting_off_configured_charges_no_fee(): void
    {
        // Fully configured but toggle OFF — never a fee (unchanged behaviour).
        $this->setCompany([
            'fbr_reporting_enabled' => false,
            'fbr_pos_enabled' => true,
            'fbr_connection_mode' => 'fiscal_device',
            'fbr_pos_id' => '812345',
            'agent_enabled' => true,
        ]);

        $res = $this->callStore($this->cashPayload(['cash_received' => 300]));
        $data = $res->getData(true);

        $this->assertTrue($data['success']);
        $row = $this->txRow($data['transaction_id']);

        $this->assertSame(0.0, (float) $row->fbr_service_charge, 'reporting OFF → no fee');
        $this->assertSame(300.0, (float) $row->total_amount);
        $this->assertNull($row->fbr_status, 'reporting-OFF final = fbr mode + NULL status');
    }

    public function test_provisional_never_charged_and_promote_never_adds_fee(): void
    {
        // Configured + reporting ON — the strongest case: even here a
        // provisional gets no fee, and promoting it must not add one.
        $this->setCompany([
            'fbr_reporting_enabled' => true,
            'fbr_pos_enabled' => true,
            'fbr_connection_mode' => 'fiscal_device',
            'fbr_pos_id' => '812345',
            'agent_enabled' => true,
        ]);

        $res = $this->callStore($this->cashPayload([
            'save_as_provisional' => 1,
            'cash_received' => 300,
        ]));
        $data = $res->getData(true);
        $this->assertTrue($data['success']);
        $txId = $data['transaction_id'];

        $row = $this->txRow($txId);
        $this->assertSame('local', $row->invoice_mode);
        $this->assertSame('local', $row->fbr_status);
        $this->assertSame(0.0, (float) $row->fbr_service_charge, 'provisional → no fee');
        $this->assertSame(300.0, (float) $row->total_amount);

        // Promote (F10) — atomic flip to fbr/'pending'; amounts NEVER re-derived.
        $userModel = new \App\Models\User();
        $userModel->id = $this->user->id;
        $userModel->role = $this->user->role;
        $userModel->company_id = $this->companyId;
        \Illuminate\Support\Facades\Auth::guard('fbrpos')->setUser($userModel);
        $promoteReq = Request::create('/fbr-pos/api/provisional/' . $txId . '/promote', 'POST', []);
        $promoteReq->headers->set('Accept', 'application/json');
        $promoteRes = (new FbrPosController())->apiPromoteProvisional($promoteReq, $txId);
        $this->assertTrue($promoteRes->getData(true)['success'], 'promote succeeds');

        $row = $this->txRow($txId);
        $this->assertSame('fbr', $row->invoice_mode, 'promoted to final');
        $this->assertSame('pending', $row->fbr_status, 'queued for FBR (reporting ON)');
        $this->assertSame(0.0, (float) $row->fbr_service_charge, 'promote NEVER adds the fee retroactively');
        $this->assertSame(300.0, (float) $row->total_amount, 'total unchanged on promote');
    }
}
