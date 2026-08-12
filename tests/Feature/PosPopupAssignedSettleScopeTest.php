<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PRA POS Pending Deliveries popup — settled assigned/dispatched bills must
 * VANISH (Task 523, mirror of the FBR fix locked by
 * FbrPosPopupDeliveredSettleTest).
 *
 * Gap: PosRiderController::settle with settle_all=1 settles the rider's ENTIRE
 * open cash khata — including bills still in 'assigned'/'dispatched' status.
 * The popup's final_deliveries assigned/dispatched branch didn't check
 * rider_settlement_id, so a settled-while-assigned bill kept showing (with a
 * locked action). Fix: whereNull('rider_settlement_id') on that branch.
 *
 * Pattern: sqlite :memory: + minimal Schema::create, controllers invoked
 * directly with the currentCompanyId binding (same approach as
 * PosRiderSettleInvariantTest / FbrPosPopupDeliveredSettleTest).
 */
class PosPopupAssignedSettleScopeTest extends TestCase
{
    private const COMPANY = 9;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Internal account → PosFeatureService plan gates short-circuit to
            // allowed without needing subscriptions/pricing_plans tables.
            $table->boolean('is_internal_account')->default(false);
            $table->text('feature_flags')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('pos_riders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
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
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        // WITH the rider/delivery columns — the popup's final_deliveries block
        // only activates when the hasColumn guards pass.
        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number');
            $table->string('status')->default('completed');
            $table->string('invoice_mode')->nullable();
            $table->string('pra_status')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('order_type')->nullable();
            $table->string('delivery_address')->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('rider_id')->nullable();
            $table->string('delivery_status')->nullable();
            $table->unsignedBigInteger('rider_settlement_id')->nullable();
            $table->timestamp('rider_settled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('item_name')->nullable();
            $table->timestamps();
        });

        DB::table('companies')->insert([
            'id' => self::COMPANY, 'name' => 'PRA Popup Shop', 'is_internal_account' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app()->instance('currentCompanyId', null);
        app()->bind('currentCompanyId', fn () => self::COMPANY);
    }

    private function makeRider(string $name = 'Asgar'): int
    {
        return (int) DB::table('pos_riders')->insertGetId([
            'company_id' => self::COMPANY, 'name' => $name, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Final (non-provisional) cash delivery bill. */
    private function makeFinal(int $riderId, array $attrs = []): int
    {
        return (int) DB::table('pos_transactions')->insertGetId(array_merge([
            'company_id' => self::COMPANY,
            'invoice_number' => 'POS-2026-' . uniqid(),
            'status' => 'completed',
            'invoice_mode' => 'pra',
            'pra_status' => 'submitted',
            'payment_method' => 'cash',
            'order_type' => 'delivery',
            'total_amount' => 500.00,
            'rider_id' => $riderId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    private function popupIds(): array
    {
        $data = (new PosController())->apiProvisionalBills(new Request())->getData(true);
        $this->assertTrue($data['success']);
        return array_column($data['final_deliveries'], 'id');
    }

    // ── 1. Direct scope: settled assigned/dispatched rows never render ──────

    public function test_settled_assigned_and_dispatched_bills_absent_from_popup(): void
    {
        $rider = $this->makeRider();

        $openAssigned   = $this->makeFinal($rider, ['delivery_status' => 'assigned']);
        $openDispatched = $this->makeFinal($rider, ['delivery_status' => 'dispatched']);
        $openDelivered  = $this->makeFinal($rider, ['delivery_status' => 'delivered']);

        // Settled while still assigned/dispatched (settle_all can do this) — must vanish.
        $settledAssigned   = $this->makeFinal($rider, ['delivery_status' => 'assigned',   'rider_settlement_id' => 777, 'rider_settled_at' => now()]);
        $settledDispatched = $this->makeFinal($rider, ['delivery_status' => 'dispatched', 'rider_settlement_id' => 777, 'rider_settled_at' => now()]);
        // Settled delivered bill stays out too (pre-existing branch check).
        $settledDelivered  = $this->makeFinal($rider, ['delivery_status' => 'delivered',  'rider_settlement_id' => 777, 'rider_settled_at' => now()]);

        $ids = $this->popupIds();
        $this->assertEqualsCanonicalizing([$openAssigned, $openDispatched, $openDelivered], $ids);
        $this->assertNotContains($settledAssigned, $ids);
        $this->assertNotContains($settledDispatched, $ids);
        $this->assertNotContains($settledDelivered, $ids);
    }

    // ── 2. End-to-end: settle_all then popup refetch ────────────────────────

    public function test_popup_refetch_after_settle_all_hides_settled_assigned_bills(): void
    {
        $rider = $this->makeRider('Kashif');

        $assigned   = $this->makeFinal($rider, ['delivery_status' => 'assigned',   'total_amount' => 300]);
        $dispatched = $this->makeFinal($rider, ['delivery_status' => 'dispatched', 'total_amount' => 200]);
        $delivered  = $this->makeFinal($rider, ['delivery_status' => 'delivered',  'total_amount' => 500]);
        // Card bill survives settle_all but assigned-card still shows in popup
        // (in transit; the branch is cash-agnostic for open bills).
        $cardAssigned = $this->makeFinal($rider, ['delivery_status' => 'assigned', 'payment_method' => 'debit_card']);

        // Pre-settle: all four visible.
        $this->assertEqualsCanonicalizing(
            [$assigned, $dispatched, $delivered, $cardAssigned],
            $this->popupIds()
        );

        // settle_all via the real controller (whole open cash khata).
        $user = User::forceCreate(['company_id' => self::COMPANY, 'name' => 'Owner']);
        auth('pos')->setUser($user);
        $req = Request::create('/pos/riders/' . $rider . '/settle', 'POST', ['settle_all' => 1]);
        $req->headers->set('Accept', 'application/json');
        $res = app(\App\Http\Controllers\PosRiderController::class)->settle($req, $rider);
        $json = $res->getData(true);
        $this->assertTrue($json['success']);
        $this->assertSame(3, $json['bill_count']);
        $this->assertSame(1000.0, (float) $json['total_amount']);

        // Popup refetch: settled assigned/dispatched/delivered gone; only the
        // still-open card assigned bill remains.
        $this->assertSame([$cardAssigned], $this->popupIds());
    }
}
