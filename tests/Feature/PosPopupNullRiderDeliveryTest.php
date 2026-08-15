<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use App\Http\Controllers\PosRiderController;
use App\Models\PosTransaction;
use App\Models\User;
use App\Services\PosBusinessDay;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 785 — PRA sale-screen Pending Deliveries popup: NULL-rider (unassigned)
 * delivery bill shape + updateStatus parity with the FBR side.
 *
 * The popup (PosController::apiProvisionalBills) intentionally includes
 * unassigned delivery bills (Task 513, 7-day window) so the cashier can assign
 * a rider without opening the Deliveries board in a separate window.
 *
 * Locked in this suite:
 *  1. Popup final_deliveries shape for an unassigned bill:
 *     - rider_id = null, rider_name = null
 *     - rider_unsettled = false (no khata for a riderless bill)
 *     - rider_open_count = 0, rider_open_amount = 0
 *     - is_stale_unassigned = false (fresh today's bill)
 *     - is_final = true
 *  2. 7-day window: unassigned bills older than 7 days are excluded entirely
 *     (the board handles them via oldUnassigned; popup must not overflow).
 *  3. Assigned/dispatched finals with non-null rider carry rider_name from
 *     the batch lookup and rider_unsettled / khata counts correctly.
 *  4. updateStatus (PosRiderController): riderless 'delivered' succeeds (Task 774
 *     PRA parity); other transitions 422; settled/incomplete bills 422.
 *
 * Pattern: sqlite :memory: + minimal Schema::create + direct controller calls
 * with currentCompanyId binding — mirrors PosOldUnassignedDeliveriesTest and
 * FbrPosPopupDeliveredSettleTest.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit \
 *     tests/Feature/PosPopupNullRiderDeliveryTest.php
 */
class PosPopupNullRiderDeliveryTest extends TestCase
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

        Schema::create('pos_user_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->timestamp('login_at')->nullable();
            $table->timestamp('logout_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
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
            'name'                => 'Null Rider Popup Test Co',
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

    private function makeAdmin(): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Admin', 'email' => 'admin' . rand() . '@popup785.test',
            'password' => Hash::make('Secret@12'), 'company_id' => $this->companyId,
            'role' => 'user', 'pos_role' => 'pos_admin', 'is_active' => true,
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

    /** Minimal final PRA delivery bill. */
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
            'total_amount'       => 500.00,
            'created_at'         => now(),
            'updated_at'         => now(),
        ], $attrs));
        return PosTransaction::find($id);
    }

    private function popupFinals(): \Illuminate\Support\Collection
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'pos');
        $data = (new PosController())->apiProvisionalBills(new Request())->getData(true);
        $this->assertTrue($data['success']);
        return collect($data['final_deliveries']);
    }

    private function jsonReq(array $data = []): Request
    {
        $req = Request::create('/', 'POST', $data);
        $req->headers->set('Accept', 'application/json');
        return $req;
    }

    // ── 1. NULL-rider bill: response shape ──────────────────────────────────

    /**
     * An unassigned delivery bill within the 7-day window appears in the popup's
     * final_deliveries with rider_id=null, rider_name=null, rider_unsettled=false,
     * and zero khata counts — the popup JS's null checks (|| fallback, x-if guard)
     * mean none of these trigger a JS error.
     */
    public function test_unassigned_bill_appears_in_popup_with_null_rider_fields(): void
    {
        $bill = $this->makeFinal(['rider_id' => null, 'delivery_status' => null]);

        $finals = $this->popupFinals();
        $row = $finals->firstWhere('id', $bill->id);

        $this->assertNotNull($row, 'Unassigned delivery bill must be in final_deliveries');

        // Shape assertions — the popup JS reads these; all null/zero is safe.
        $this->assertTrue($row['is_final']);
        $this->assertNull($row['rider_id'],   'rider_id must be null for unassigned bill');
        $this->assertNull($row['rider_name'], 'rider_name must be null for unassigned bill');
        $this->assertFalse($row['rider_unsettled'], 'rider_unsettled must be false — no khata without a rider');
        $this->assertSame(0, (int) $row['rider_open_count'],  'rider_open_count must be 0 — no rider, no khata');
        $this->assertSame(0.0, (float) $row['rider_open_amount'], 'rider_open_amount must be 0.0');
        $this->assertFalse($row['is_stale_unassigned'], 'fresh today bill must not be stale');
        $this->assertSame('delivery', $row['order_type']);
        $this->assertNull($row['delivery_status']);
    }

    // ── 2. 7-day window: ancient unassigned excluded ─────────────────────────

    /**
     * Unassigned bills older than 7 days must NOT appear in the popup at all
     * (they go to the board's collapsed "Purani deliveries" section instead).
     */
    public function test_unassigned_bill_older_than_7_days_excluded_from_popup(): void
    {
        $ancient = $this->makeFinal([
            'rider_id'        => null,
            'delivery_status' => null,
            'business_date'   => now()->subDays(10)->toDateString(),
            'created_at'      => now()->subDays(10),
        ]);
        // A fresh one from today — must appear.
        $fresh = $this->makeFinal(['rider_id' => null, 'delivery_status' => null]);

        $finals = $this->popupFinals();
        $ids = $finals->pluck('id');

        $this->assertNotContains($ancient->id, $ids, 'Bill older than 7 days must be excluded from popup');
        $this->assertContains($fresh->id, $ids, 'Fresh unassigned bill must appear in popup');
    }

    // ── 3. Assigned bill: rider_name populated from batch lookup ─────────────

    /**
     * A bill with a non-null rider_id carries the rider's name in the popup
     * response, and has the correct unsettled / khata fields.
     */
    public function test_assigned_bill_carries_rider_name_and_khata_fields(): void
    {
        $riderId = $this->makeRider('Kamran');
        $assigned = $this->makeFinal([
            'rider_id'        => $riderId,
            'delivery_status' => 'assigned',
            'total_amount'    => 300.00,
        ]);

        $finals = $this->popupFinals();
        $row = $finals->firstWhere('id', $assigned->id);

        $this->assertNotNull($row);
        $this->assertSame($riderId, (int) $row['rider_id']);
        $this->assertSame('Kamran', $row['rider_name']);
        $this->assertTrue($row['rider_unsettled'], 'Cash assigned bill must be rider_unsettled = true');
        $this->assertSame(1, (int) $row['rider_open_count']);
        $this->assertSame(300.0, (float) $row['rider_open_amount']);
    }

    // ── 4. Provisionals (local triple) excluded from final_deliveries ─────────

    /**
     * Deliberate provisionals (invoice_mode=local + pra_status=local — the
     * "local triple") must never appear in final_deliveries, even if they are
     * order_type=delivery. They belong to the provisionals list.
     */
    public function test_provisional_delivery_bill_excluded_from_final_deliveries(): void
    {
        $provisional = $this->makeFinal([
            'invoice_mode'       => 'local',
            'pra_status'         => 'local',
            'pra_invoice_number' => null,
            'rider_id'           => null,
            'delivery_status'    => null,
        ]);

        $finals = $this->popupFinals();
        $this->assertNotContains($provisional->id, $finals->pluck('id')->all(),
            'Provisional (local triple) must not appear in final_deliveries');
    }

    // ── 5. updateStatus: riderless 'delivered' succeeds (Task 774 PRA parity) ─

    /**
     * POST /pos/deliveries/{id}/status with delivery_status=delivered succeeds
     * on a riderless (rider_id NULL) bill — Task 774 added this path for the
     * board; the popup's "Mark Delivered" button reuses the same endpoint.
     */
    public function test_updateStatus_riderless_delivered_succeeds(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'pos');

        $bill = $this->makeFinal(['rider_id' => null, 'delivery_status' => null]);

        $res = (new PosRiderController())->updateStatus($this->jsonReq(['delivery_status' => 'delivered']), $bill->id);

        $this->assertSame(200, $res->getStatusCode());
        $json = $res->getData(true);
        $this->assertTrue($json['success']);
        $this->assertSame('delivered', $json['delivery_status']);

        $bill->refresh();
        $this->assertSame('delivered', $bill->delivery_status);
    }

    /**
     * After marking an unassigned bill as delivered via updateStatus, the popup
     * must no longer include it in final_deliveries (delivery_status is now
     * 'delivered' and rider_id is still NULL — no khata, no settle button needed;
     * the bill drops off the popup naturally).
     */
    public function test_unassigned_delivered_bill_absent_from_popup(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'pos');

        $bill = $this->makeFinal(['rider_id' => null, 'delivery_status' => null]);

        // Mark as delivered.
        (new PosRiderController())->updateStatus($this->jsonReq(['delivery_status' => 'delivered']), $bill->id);

        // Popup should no longer include it (delivered + riderless = not in the
        // filter: the filter requires assigned/dispatched OR (null status + null rider)).
        $finals = $this->popupFinals();
        $this->assertNotContains($bill->id, $finals->pluck('id')->all(),
            'Delivered riderless bill must disappear from popup final_deliveries');
    }

    /**
     * A riderless bill cannot be moved to 'dispatched' or 'returned' via
     * updateStatus — only 'delivered' is allowed (Task 774 invariant).
     */
    public function test_updateStatus_riderless_non_delivered_transition_422s(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'pos');

        $bill = $this->makeFinal(['rider_id' => null, 'delivery_status' => null]);

        foreach (['dispatched', 'returned'] as $badStatus) {
            $res = (new PosRiderController())->updateStatus($this->jsonReq(['delivery_status' => $badStatus]), $bill->id);
            $this->assertSame(422, $res->getStatusCode(), "Status '$badStatus' on riderless bill must 422");
            $this->assertFalse($res->getData(true)['success']);
        }
    }

    /**
     * A riderless bill that is not yet completed (held/provisional) cannot be
     * marked delivered — the status=completed guard must fire.
     */
    public function test_updateStatus_riderless_incomplete_bill_422s(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'pos');

        $bill = $this->makeFinal(['rider_id' => null, 'delivery_status' => null]);
        PosTransaction::where('id', $bill->id)->update(['status' => 'provisional']);

        $res = (new PosRiderController())->updateStatus($this->jsonReq(['delivery_status' => 'delivered']), $bill->id);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertFalse($res->getData(true)['success']);
    }

    /**
     * A riderless bill that already has a delivery_status set (idempotency /
     * double-tap guard) cannot be marked delivered again.
     */
    public function test_updateStatus_riderless_already_delivered_422s(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'pos');

        $bill = $this->makeFinal(['rider_id' => null, 'delivery_status' => 'delivered']);

        $res = (new PosRiderController())->updateStatus($this->jsonReq(['delivery_status' => 'delivered']), $bill->id);
        $this->assertSame(422, $res->getStatusCode());
    }
}
