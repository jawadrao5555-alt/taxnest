<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use App\Models\PosTransaction;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Owner video (25 Aug 2026) — "yeh Prepaid aur search wala option yahan
 * (Pending Deliveries popup mein) nahi aa sakta?"
 *
 * The sale-screen popup now offers the Deliveries board's Prepaid /
 * Back-to-Cash pair. The BUTTONS are painted from server verdicts shipped in
 * the popup payload (PosController::apiProvisionalBills → final_deliveries),
 * so the popup can never show an action the POST would refuse.
 *
 * Locked here — the verdicts mirror PosRiderController::markPrepaid /
 * unmarkPrepaid guard-for-guard:
 *  1. Admin + unsettled CASH delivery bill with a rider  → can_mark_prepaid.
 *  2. Cashier (and any non admin/manager role)           → both false.
 *  3. Manager                                            → can_mark_prepaid.
 *  4. Settled bill (rider_settlement_id)                 → both false.
 *  5. Returned delivery                                  → both false.
 *  6. Riderless bill                                     → both false.
 *  7. Converted bill (qr_payment + stamp) → can_unmark_prepaid only,
 *     is_prepaid_converted = true.
 *
 * Pattern: sqlite :memory: + minimal schema + direct controller call, mirroring
 * PosPopupNullRiderDeliveryTest.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit \
 *     tests/Feature/PosDeliveryPopupPrepaidTest.php
 */
class PosDeliveryPopupPrepaidTest extends TestCase
{
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        User::flushScopeColumnCache();
        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('ntn')->nullable();
            $table->string('product_type')->nullable();
            $table->string('status')->default('approved');
            $table->string('company_status')->default('approved');
            $table->boolean('restaurant_mode')->default(false);
            $table->boolean('pos_setup_completed')->default(true);
            $table->string('confidential_pin')->nullable();
            $table->string('default_language')->nullable();
            $table->text('invoice_display_prefs')->nullable();
            $table->text('feature_flags')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->boolean('agent_enabled')->default(false);
            $table->string('pra_connection_mode')->nullable();
            $table->string('pra_environment')->nullable();
            $table->boolean('delivery_kot_after_payment')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('password')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->string('pos_billing_scope', 10)->nullable();
            $table->text('pos_custom_access')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('dark_mode')->default(false);
            $table->string('language')->nullable();
            $table->unsignedBigInteger('default_branch_id')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->boolean('is_head_office')->default(false);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('branch_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->string('status')->default('active');
            $table->boolean('is_active')->default(true);
            $table->boolean('active')->default(true);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->string('product_type')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->text('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('riders_enabled')->default(true);
            $table->boolean('restaurant_enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number');
            $table->string('business_date')->nullable();
            $table->string('status');
            $table->string('invoice_mode')->nullable();
            $table->string('pra_status')->nullable();
            $table->string('pra_invoice_number')->nullable();
            $table->string('pra_response_code')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('archived_by_report_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('order_type')->nullable();
            $table->string('delivery_address')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->decimal('cash_received', 12, 2)->nullable();
            $table->decimal('change_due', 12, 2)->nullable();
            $table->boolean('tax_inclusive')->default(false);
            $table->unsignedBigInteger('rider_id')->nullable();
            $table->string('delivery_status')->nullable();
            $table->unsignedBigInteger('rider_settlement_id')->nullable();
            $table->timestamp('rider_settled_at')->nullable();
            $table->timestamp('rider_assigned_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('prepaid_converted_at')->nullable();
            $table->unsignedBigInteger('prepaid_converted_by')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('item_name')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_day_close_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('report_date');
            $table->timestamps();
        });

        Schema::create('pos_rider_settlements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('rider_id');
            $table->unsignedBigInteger('settled_by')->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->integer('bill_count')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_riders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
        });

        \App\Services\PosFeatureService::flushGateCaches();

        $this->companyId = (int) DB::table('companies')->insertGetId([
            'name'                => 'Popup Prepaid Test Co',
            'product_type'        => 'pos',
            'status'              => 'approved',
            'company_status'      => 'approved',
            'is_internal_account' => true,
            'feature_flags'       => json_encode(['delivery' => true]),
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        DB::table('pricing_plans')->insert([
            'id' => 1, 'name' => 'Pro', 'product_type' => 'pos', 'price' => 0,
            'riders_enabled' => true, 'restaurant_enabled' => true, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('subscriptions')->insert([
            'company_id' => $this->companyId, 'pricing_plan_id' => 1, 'status' => 'active',
            'is_active' => true, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        app()->instance('currentCompanyId', null);
        app()->bind('currentCompanyId', fn () => $this->companyId);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function makeUser(string $posRole): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => $posRole, 'email' => $posRole . rand() . '@popupprepaid.test',
            'password' => Hash::make('Secret@12'), 'company_id' => $this->companyId,
            'role' => 'user', 'pos_role' => $posRole, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return User::find($id);
    }

    private function makeRider(string $name = 'Bilal'): int
    {
        return (int) DB::table('pos_riders')->insertGetId([
            'company_id' => $this->companyId, 'name' => $name, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Final PRA delivery bill, cash, on a rider's open khata by default. */
    private function makeFinal(array $attrs = []): PosTransaction
    {
        $id = DB::table('pos_transactions')->insertGetId(array_merge([
            'company_id'         => $this->companyId,
            'invoice_number'     => 'INV-' . uniqid(),
            'business_date'      => now()->toDateString(),
            'status'             => 'completed',
            'invoice_mode'       => 'pra',
            'pra_status'         => 'completed',
            'pra_invoice_number' => 'PRA-' . uniqid(),
            'payment_method'     => 'cash',
            'order_type'         => 'delivery',
            'delivery_status'    => 'assigned',
            'total_amount'       => 500.00,
            'created_at'         => now(),
            'updated_at'         => now(),
        ], $attrs));
        return PosTransaction::find($id);
    }

    /** The popup row for one bill, as seen by $user. */
    private function popupRow(User $user, int $billId): ?array
    {
        $this->actingAs($user, 'pos');
        $data = (new PosController())->apiProvisionalBills(new Request())->getData(true);
        $this->assertTrue($data['success']);
        return collect($data['final_deliveries'])->firstWhere('id', $billId);
    }

    // ── 1. Admin, unsettled cash bill on a rider ────────────────────────────

    public function test_admin_can_mark_prepaid_on_unsettled_cash_delivery_bill(): void
    {
        $bill = $this->makeFinal(['rider_id' => $this->makeRider()]);

        $row = $this->popupRow($this->makeUser('pos_admin'), $bill->id);

        $this->assertNotNull($row, 'Unsettled cash delivery bill must be in the popup');
        $this->assertTrue($row['can_mark_prepaid'], 'Admin must get the Prepaid button');
        $this->assertFalse($row['can_unmark_prepaid'], 'Never converted → no Back-to-Cash');
        $this->assertFalse($row['is_prepaid_converted']);
    }

    // ── 2/3. Role gate — admin+manager only ─────────────────────────────────

    public function test_manager_can_mark_prepaid_but_cashier_cannot(): void
    {
        $bill = $this->makeFinal(['rider_id' => $this->makeRider()]);

        $mgr = $this->popupRow($this->makeUser('pos_manager'), $bill->id);
        $this->assertTrue($mgr['can_mark_prepaid'], 'Manager is allowed by markPrepaid');

        $cashier = $this->popupRow($this->makeUser('pos_cashier'), $bill->id);
        $this->assertFalse($cashier['can_mark_prepaid'], 'Cashier POST 403s — button must not paint');
        $this->assertFalse($cashier['can_unmark_prepaid']);
    }

    // ── 4. Settled bill is locked ───────────────────────────────────────────

    public function test_settled_bill_offers_no_prepaid_action(): void
    {
        $riderId = $this->makeRider();
        $settlementId = (int) DB::table('pos_rider_settlements')->insertGetId([
            'company_id' => $this->companyId, 'rider_id' => $riderId,
            'total_amount' => 500, 'bill_count' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Delivered + settled cash bill still shows in the popup only while
        // unsettled; assert through a dispatched row that carries a settlement.
        $bill = $this->makeFinal([
            'rider_id'             => $riderId,
            'delivery_status'      => 'dispatched',
            'rider_settlement_id'  => $settlementId,
            'prepaid_converted_at' => now(),
            'payment_method'       => 'qr_payment',
        ]);

        // Settled rows drop out of the popup query entirely — that alone proves
        // the action can never be offered. If the row IS present (query change),
        // the verdicts must still be false.
        $row = $this->popupRow($this->makeUser('pos_admin'), $bill->id);
        if ($row !== null) {
            $this->assertFalse($row['can_mark_prepaid'], 'Settled bill must never offer Prepaid');
            $this->assertFalse($row['can_unmark_prepaid'], 'Settled bill must never offer Back-to-Cash');
        } else {
            $this->assertTrue(true, 'Settled bill is out of the popup list — action unreachable');
        }
    }

    // ── 5. Returned delivery ────────────────────────────────────────────────

    public function test_returned_delivery_offers_no_prepaid_action(): void
    {
        $bill = $this->makeFinal([
            'rider_id'        => $this->makeRider(),
            'delivery_status' => 'returned',
        ]);

        $row = $this->popupRow($this->makeUser('pos_admin'), $bill->id);
        if ($row !== null) {
            $this->assertFalse($row['can_mark_prepaid'], 'Returned delivery is off the khata');
            $this->assertFalse($row['can_unmark_prepaid']);
        } else {
            $this->assertTrue(true, 'Returned bill is out of the popup list — action unreachable');
        }
    }

    // ── 6. Riderless bill ───────────────────────────────────────────────────

    public function test_riderless_bill_offers_no_prepaid_action(): void
    {
        $bill = $this->makeFinal(['rider_id' => null, 'delivery_status' => null]);

        $row = $this->popupRow($this->makeUser('pos_admin'), $bill->id);

        $this->assertNotNull($row, 'Unassigned bill still rides the 7-day popup window');
        $this->assertFalse($row['can_mark_prepaid'], 'markPrepaid needs a rider');
        $this->assertFalse($row['can_unmark_prepaid']);
    }

    // ── 7. Converted bill → Back to Cash only ───────────────────────────────

    public function test_converted_bill_offers_back_to_cash_only(): void
    {
        $bill = $this->makeFinal([
            'rider_id'             => $this->makeRider(),
            'delivery_status'      => 'dispatched',
            'payment_method'       => 'qr_payment',
            'prepaid_converted_at' => now(),
        ]);

        $row = $this->popupRow($this->makeUser('pos_admin'), $bill->id);

        $this->assertNotNull($row, 'Converted-but-undelivered bill stays in the popup');
        $this->assertTrue($row['is_prepaid_converted'], 'Chip must show the bill was converted');
        $this->assertFalse($row['can_mark_prepaid'], 'Already prepaid — no second conversion');
        $this->assertTrue($row['can_unmark_prepaid'], 'Admin may revert an unsettled conversion');
    }
}
