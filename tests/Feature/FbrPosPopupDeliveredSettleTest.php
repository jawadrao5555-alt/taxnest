<?php

namespace Tests\Feature;

use App\Http\Controllers\FbrPosController;
use App\Http\Controllers\FbrPosRiderController;
use App\Models\Company;
use App\Models\FbrPosTransaction;
use App\Models\PosRider;
use App\Models\PosRiderSettlement;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * FBR POS Pending Deliveries popup — Delivered-mark + whole-khata Settle
 * (Task 522, locking Task 521's PRA-parity port).
 *
 * Covers:
 *   1. apiProvisionalBills final_deliveries: assigned/dispatched final bills
 *      AND delivered-CASH-unsettled bills appear, rider_name + rider_open_count
 *      / rider_open_amount are the rider's WHOLE open khata (settle_all scope,
 *      same predicate as FbrPosRiderController::settle) — settled / returned /
 *      provisional rows never leak in.
 *   2. POST fbrpos.deliveries.status JSON path: delivered mark succeeds +
 *      stamps delivered_at; rider-less bill 404s (whereNotNull rider_id guard);
 *      settled bill is locked (422).
 *   3. POST fbrpos.riders.settle with settle_all: settles EVERY open cash bill
 *      (all dates), skips returned/card/settled, creates the settlement row,
 *      and an immediate second settle_all 422s (khata empty).
 *
 * Pattern: sqlite :memory: + minimal Schema::create, controllers invoked
 * directly with the currentCompanyId binding — mirrors
 * FbrPosPendingDeliveriesPanelTest.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/FbrPosPopupDeliveredSettleTest.php
 */
class FbrPosPopupDeliveredSettleTest extends TestCase
{
    protected int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('confidential_pin')->nullable();
            $table->boolean('fbr_reporting_enabled')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        // WITH the rider/delivery columns — the popup's final_deliveries block
        // only activates when rider_id/delivery_status/rider_settlement_id/
        // order_type all exist (hasColumn guards).
        Schema::create('fbr_pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number');
            $table->string('status')->nullable();
            $table->string('invoice_mode')->nullable();
            $table->string('fbr_status')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('order_type')->nullable();
            $table->string('delivery_address')->nullable();
            $table->unsignedBigInteger('rider_id')->nullable();
            $table->string('delivery_status')->nullable();
            $table->timestamp('rider_assigned_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->unsignedBigInteger('rider_settlement_id')->nullable();
            $table->timestamp('rider_settled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('fbr_pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('item_name')->nullable();
            $table->timestamps();
        });

        Schema::create('fbr_day_close_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('report_date');
            $table->timestamps();
        });

        Schema::create('pos_riders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('phone')->nullable();
            $table->boolean('is_active')->default(true);
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

        $this->companyId = Company::create(['name' => 'FBR Popup Shop'])->id;
        app()->bind('currentCompanyId', fn () => $this->companyId);
    }

    protected function makeRider(string $name = 'Bilal'): PosRider
    {
        return PosRider::create(['company_id' => $this->companyId, 'name' => $name, 'is_active' => true]);
    }

    /** Final (non-provisional) delivery bill — reporting-OFF finals invariant: fbr mode + NULL status. */
    protected function makeFinal(array $attrs = []): FbrPosTransaction
    {
        return FbrPosTransaction::create(array_merge([
            'company_id' => $this->companyId,
            'invoice_number' => 'F-' . uniqid(),
            'status' => 'completed',
            'invoice_mode' => 'fbr',
            'fbr_status' => null,
            'subtotal' => 100,
            'tax_amount' => 16,
            'total_amount' => 116,
            'payment_method' => 'cash',
            'order_type' => 'delivery',
        ], $attrs));
    }

    protected function jsonReq(array $data = []): Request
    {
        $req = Request::create('/', 'POST', $data);
        $req->headers->set('Accept', 'application/json');
        return $req;
    }

    // ── 1. final_deliveries scope + rider khata summary ─────────────────────

    public function test_final_deliveries_lists_assigned_dispatched_and_delivered_cash_unsettled(): void
    {
        $rider = $this->makeRider('Bilal');

        $assigned   = $this->makeFinal(['rider_id' => $rider->id, 'delivery_status' => 'assigned']);
        $dispatched = $this->makeFinal(['rider_id' => $rider->id, 'delivery_status' => 'dispatched']);
        $deliveredCash = $this->makeFinal(['rider_id' => $rider->id, 'delivery_status' => 'delivered', 'total_amount' => 500]);

        // Must NOT appear:
        $this->makeFinal(['rider_id' => $rider->id, 'delivery_status' => 'delivered', 'payment_method' => 'debit_card']); // card, off khata
        $this->makeFinal(['rider_id' => $rider->id, 'delivery_status' => 'delivered', 'rider_settlement_id' => 999]);     // already settled
        $this->makeFinal(['rider_id' => $rider->id, 'delivery_status' => 'assigned', 'rider_settlement_id' => 999]);      // settled while assigned (settle_all can do this)
        $this->makeFinal(['rider_id' => $rider->id, 'delivery_status' => 'returned']);                                    // returned
        // Provisional (local/local) never joins final_deliveries. (Rider-less —
        // the khata summary intentionally counts ANY open cash bill on the
        // rider, provisional or final, matching the settle_all scope.)
        $this->makeFinal(['invoice_mode' => 'local', 'fbr_status' => 'local', 'delivery_status' => null]);

        $data = (new FbrPosController())->apiProvisionalBills(new Request())->getData(true);
        $this->assertTrue($data['success']);

        $finals = collect($data['final_deliveries']);
        $this->assertEqualsCanonicalizing(
            [$assigned->id, $dispatched->id, $deliveredCash->id],
            $finals->pluck('id')->all()
        );

        // Rider fields filled; khata summary = WHOLE open khata (settle_all
        // scope): assigned 116 + dispatched 116 + delivered 500 = 732 / 3 bills.
        foreach ($finals as $row) {
            $this->assertSame('Bilal', $row['rider_name']);
            $this->assertSame($rider->id, $row['rider_id']);
            $this->assertSame(3, $row['rider_open_count']);
            $this->assertSame(732.0, (float) $row['rider_open_amount']);
        }

        // Delivered-cash-unsettled row flags rider_unsettled (Settle button shows).
        $deliveredRow = $finals->firstWhere('id', $deliveredCash->id);
        $this->assertTrue($deliveredRow['rider_unsettled']);
        $this->assertSame('delivered', $deliveredRow['delivery_status']);
        $this->assertTrue($deliveredRow['is_final']);
    }

    // ── 2. Delivered mark (fbrpos.deliveries.status JSON path) ──────────────

    public function test_status_delivered_json_marks_and_stamps_delivered_at(): void
    {
        $rider = $this->makeRider();
        $bill = $this->makeFinal(['rider_id' => $rider->id, 'delivery_status' => 'dispatched']);

        $res = (new FbrPosRiderController())->updateStatus($this->jsonReq(['delivery_status' => 'delivered']), $bill->id);

        $this->assertSame(200, $res->getStatusCode());
        $json = $res->getData(true);
        $this->assertTrue($json['success']);
        $this->assertSame('delivered', $json['delivery_status']);

        $bill->refresh();
        $this->assertSame('delivered', $bill->delivery_status);
        $this->assertNotNull($bill->delivered_at);
    }

    /** Task 774: riderless unassigned delivery can now be marked delivered directly. */
    public function test_status_on_riderless_bill_delivered_succeeds(): void
    {
        $bill = $this->makeFinal(['rider_id' => null, 'delivery_status' => null, 'order_type' => 'delivery']);

        $res = (new FbrPosRiderController())->updateStatus($this->jsonReq(['delivery_status' => 'delivered']), $bill->id);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertTrue($res->getData(true)['success']);

        $bill->refresh();
        $this->assertSame('delivered', $bill->delivery_status);
    }

    /** Task 774: riderless bill — any transition other than 'delivered' must still be rejected. */
    public function test_status_on_riderless_bill_non_delivered_422s(): void
    {
        $bill = $this->makeFinal(['rider_id' => null, 'delivery_status' => null, 'order_type' => 'delivery']);

        $res = (new FbrPosRiderController())->updateStatus($this->jsonReq(['delivery_status' => 'dispatched']), $bill->id);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertFalse($res->getData(true)['success']);
    }

    /** Task 774: incomplete (non-completed status) riderless delivery cannot be marked delivered. */
    public function test_status_on_riderless_incomplete_bill_422s(): void
    {
        // Simulate a held/provisional bill that is NOT completed
        $bill = $this->makeFinal(['rider_id' => null, 'delivery_status' => null, 'order_type' => 'delivery']);
        FbrPosTransaction::where('id', $bill->id)->update(['status' => 'provisional']);

        $res = (new FbrPosRiderController())->updateStatus($this->jsonReq(['delivery_status' => 'delivered']), $bill->id);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertFalse($res->getData(true)['success']);
    }

    public function test_status_on_settled_bill_locked_422_json(): void
    {
        $rider = $this->makeRider();
        $bill = $this->makeFinal(['rider_id' => $rider->id, 'delivery_status' => 'delivered', 'rider_settlement_id' => 5]);

        $res = (new FbrPosRiderController())->updateStatus($this->jsonReq(['delivery_status' => 'returned']), $bill->id);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertFalse($res->getData(true)['success']);
    }

    // ── 3. Whole-khata Settle (fbrpos.riders.settle settle_all JSON) ────────

    public function test_settle_all_json_settles_entire_open_cash_khata(): void
    {
        $rider = $this->makeRider('Kashif');

        $old = $this->makeFinal(['rider_id' => $rider->id, 'delivery_status' => 'delivered', 'total_amount' => 200]);
        FbrPosTransaction::where('id', $old->id)->update(['created_at' => now()->subDays(10)]); // all-dates scope
        $b2 = $this->makeFinal(['rider_id' => $rider->id, 'delivery_status' => 'assigned', 'total_amount' => 300]);

        // Must survive untouched:
        $card     = $this->makeFinal(['rider_id' => $rider->id, 'delivery_status' => 'delivered', 'payment_method' => 'debit_card']);
        $returned = $this->makeFinal(['rider_id' => $rider->id, 'delivery_status' => 'returned']);
        $settled  = $this->makeFinal(['rider_id' => $rider->id, 'delivery_status' => 'delivered', 'rider_settlement_id' => 777]);

        $res = (new FbrPosRiderController())->settle($this->jsonReq(['settle_all' => 1]), $rider->id);

        $this->assertSame(200, $res->getStatusCode());
        $json = $res->getData(true);
        $this->assertTrue($json['success']);
        $this->assertSame(2, $json['bill_count']);
        $this->assertSame(500.0, (float) $json['total_amount']);

        $settlement = PosRiderSettlement::where('company_id', $this->companyId)->where('rider_id', $rider->id)->first();
        $this->assertNotNull($settlement);
        $this->assertSame(2, (int) $settlement->bill_count);

        foreach ([$old, $b2] as $b) {
            $b->refresh();
            $this->assertSame($settlement->id, (int) $b->rider_settlement_id);
            $this->assertNotNull($b->rider_settled_at);
        }
        $this->assertNull($card->refresh()->rider_settlement_id);
        $this->assertNull($returned->refresh()->rider_settlement_id);
        $this->assertSame(777, (int) $settled->refresh()->rider_settlement_id);

        // Popup refetch: settled bills (including the ASSIGNED one settled by
        // settle_all) must vanish from final_deliveries; card/returned rows
        // also stay out (card delivered is off-khata, returned is final).
        $popup = (new FbrPosController())->apiProvisionalBills(new Request())->getData(true);
        $ids = collect($popup['final_deliveries'])->pluck('id');
        $this->assertNotContains($old->id, $ids);
        $this->assertNotContains($b2->id, $ids);
        $this->assertNotContains($settled->id, $ids);

        // Second settle_all: khata now empty → 422.
        $again = (new FbrPosRiderController())->settle($this->jsonReq(['settle_all' => 1]), $rider->id);
        $this->assertSame(422, $again->getStatusCode());
        $this->assertFalse($again->getData(true)['success']);
    }
}
