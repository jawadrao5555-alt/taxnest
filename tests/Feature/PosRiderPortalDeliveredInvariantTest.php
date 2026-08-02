<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * RIDER PORTAL "MARK DELIVERED" INVARIANT — "rider portal se Delivered mark
 * karna bhi bill ke serials kabhi nahi chhedta".
 *
 * Companion to PosRiderSettleInvariantTest (settle) and
 * PosRiderAssignStatusInvariantTest (assign/updateStatus/bulkStatus).
 * This locks the RIDER-FACING write path:
 *
 *   portalMarkDelivered() — may write ONLY delivery_status → 'delivered'
 *   on an open (assigned/dispatched, unsettled) bill that belongs to the
 *   logged-in rider (pos_rider role). Settled bills, returned/terminal
 *   bills, other riders' bills and other companies' bills are all
 *   unreachable (404) and untouched.
 *
 * In every case invoice_mode / pra_status / invoice_number /
 * pra_invoice_number must be byte-for-byte unchanged.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create,
 * controller invoked directly (same approach as
 * PosRiderAssignStatusInvariantTest).
 */
class PosRiderPortalDeliveredInvariantTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name')->nullable();
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

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number');
            $table->string('status')->default('completed');
            $table->string('invoice_mode')->nullable();
            $table->string('pra_status')->nullable();
            $table->string('pra_invoice_number')->nullable();
            $table->string('payment_method')->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->string('order_type')->nullable();
            $table->string('delivery_address')->nullable();
            $table->unsignedBigInteger('rider_id')->nullable();
            $table->string('delivery_status')->nullable();
            $table->unsignedBigInteger('rider_settlement_id')->nullable();
            $table->timestamp('rider_settled_at')->nullable();
            $table->timestamps();
        });

        // Company whose plan allows riders (internal accounts pass every gate).
        DB::table('companies')->insert([
            'id' => self::COMPANY,
            'name' => 'Portal Test Co',
            'is_internal_account' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('companies')->insert([
            'id' => self::COMPANY + 1,
            'name' => 'Other Co',
            'is_internal_account' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private const COMPANY = 7;

    /** Create a rider linked to a fresh pos user; returns [riderId, userId]. */
    private function makeRiderWithUser(int $companyId = self::COMPANY): array
    {
        $userId = (int) DB::table('users')->insertGetId([
            'company_id' => $companyId,
            'name' => 'Rider User',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $riderId = (int) DB::table('pos_riders')->insertGetId([
            'company_id' => $companyId,
            'name' => 'Portal Rider',
            'is_active' => true,
            'user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return [$riderId, $userId];
    }

    private function makeBill(?int $riderId, array $attrs = []): int
    {
        return (int) DB::table('pos_transactions')->insertGetId(array_merge([
            'company_id' => self::COMPANY,
            'invoice_number' => 'POS-2026-0000' . rand(1, 9) . uniqid(),
            'status' => 'completed',
            'invoice_mode' => 'pra',
            'pra_status' => 'submitted',
            'pra_invoice_number' => 'PRA-XYZ',
            'payment_method' => 'cash',
            'total_amount' => 500.00,
            'is_archived' => false,
            'order_type' => 'delivery',
            'delivery_address' => 'House 1, Street 2',
            'rider_id' => $riderId,
            'delivery_status' => $riderId ? 'dispatched' : null,
            'rider_settlement_id' => null,
            'rider_settled_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    private function actAsRider(int $userId, int $companyId = self::COMPANY): void
    {
        app()->instance('currentCompanyId', null);
        app()->bind('currentCompanyId', fn () => $companyId);

        auth('pos')->setUser(User::find($userId));
    }

    private function markDelivered(int $userId, int $txnId)
    {
        $this->actAsRider($userId);

        // portalMarkDelivered ends with back()->with(...) — needs a request + session.
        $request = \Illuminate\Http\Request::create('/pos/rider/deliveries/' . $txnId . '/delivered', 'POST');
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);

        return app(\App\Http\Controllers\PosRiderController::class)->portalMarkDelivered($txnId);
    }

    /** Attempt that MUST 404 (bill unreachable for this rider). */
    private function markDeliveredExpecting404(int $userId, int $txnId): void
    {
        try {
            $this->markDelivered($userId, $txnId);
            $this->fail("Expected ModelNotFoundException for txn {$txnId} — bill must be unreachable");
        } catch (ModelNotFoundException $e) {
            // expected — bill locked/skipped
        }
    }

    private function tx(int $id): object
    {
        return DB::table('pos_transactions')->where('id', $id)->first();
    }

    /** THE invariant: fiscal identity byte-for-byte unchanged. */
    private function assertFiscalIdentityUnchanged(object $old, int $id): void
    {
        $tx = $this->tx($id);
        $this->assertSame($old->invoice_mode, $tx->invoice_mode, "bill {$old->invoice_number}: invoice_mode changed");
        $this->assertSame($old->pra_status, $tx->pra_status, "bill {$old->invoice_number}: pra_status changed");
        $this->assertSame($old->invoice_number, $tx->invoice_number, "bill {$old->invoice_number}: serial changed");
        $this->assertSame($old->pra_invoice_number, $tx->pra_invoice_number, "bill {$old->invoice_number}: fiscal number changed");
        $this->assertSame($old->status, $tx->status, "bill {$old->invoice_number}: status changed");
        $this->assertSame((float) $old->total_amount, (float) $tx->total_amount);
        $this->assertSame((bool) $old->is_archived, (bool) $tx->is_archived);
    }

    // ── 1. happy path: ONLY delivery_status flips ───────────────────────────

    public function test_portal_mark_delivered_never_mutates_fiscal_identity(): void
    {
        [$rider, $user] = $this->makeRiderWithUser();

        $final = $this->makeBill($rider, [
            'invoice_number' => 'POS-2026-00001',
            'invoice_mode' => 'pra',
            'pra_status' => 'submitted',
            'pra_invoice_number' => 'PRA-111',
            'delivery_status' => 'dispatched',
        ]);
        $provisional = $this->makeBill($rider, [
            'invoice_number' => 'L-0002',
            'invoice_mode' => 'local',
            'pra_status' => 'local',
            'pra_invoice_number' => null,
            'delivery_status' => 'assigned',
        ]);

        foreach ([$final, $provisional] as $id) {
            $before = $this->tx($id);
            $this->markDelivered($user, $id);

            $this->assertFiscalIdentityUnchanged($before, $id);
            $tx = $this->tx($id);
            // ONLY delivery_status moves.
            $this->assertSame('delivered', $tx->delivery_status);
            $this->assertSame($rider, (int) $tx->rider_id, 'rider must never change');
            $this->assertNull($tx->rider_settlement_id);
            $this->assertNull($tx->rider_settled_at);
        }
    }

    // ── 2. settled bill is locked (even if still assigned/dispatched) ───────

    public function test_settled_bill_is_locked_and_untouched(): void
    {
        [$rider, $user] = $this->makeRiderWithUser();
        $settled = $this->makeBill($rider, [
            'delivery_status' => 'dispatched',
            'rider_settlement_id' => 55,
            'rider_settled_at' => now()->subDay(),
        ]);
        $before = $this->tx($settled);

        $this->markDeliveredExpecting404($user, $settled);

        $this->assertFiscalIdentityUnchanged($before, $settled);
        $tx = $this->tx($settled);
        $this->assertSame('dispatched', $tx->delivery_status, 'settled bill status must stay locked');
        $this->assertSame(55, (int) $tx->rider_settlement_id);
    }

    // ── 3. terminal bills (returned / already delivered) unreachable ────────

    public function test_returned_and_delivered_bills_are_unreachable(): void
    {
        [$rider, $user] = $this->makeRiderWithUser();

        foreach (['returned', 'delivered'] as $terminal) {
            $bill = $this->makeBill($rider, ['delivery_status' => $terminal]);
            $before = $this->tx($bill);

            $this->markDeliveredExpecting404($user, $bill);

            $this->assertFiscalIdentityUnchanged($before, $bill);
            $this->assertSame($terminal, $this->tx($bill)->delivery_status);
        }
    }

    // ── 4. other rider's / other company's bill unreachable ─────────────────

    public function test_other_riders_and_other_companys_bills_are_unreachable(): void
    {
        [, $user] = $this->makeRiderWithUser();
        [$otherRider] = $this->makeRiderWithUser();

        $foreign = $this->makeBill($otherRider, ['delivery_status' => 'dispatched']);
        $otherCompany = $this->makeBill($otherRider, [
            'company_id' => self::COMPANY + 1,
            'delivery_status' => 'dispatched',
        ]);

        foreach ([$foreign, $otherCompany] as $id) {
            $before = $this->tx($id);
            $this->markDeliveredExpecting404($user, $id);
            $this->assertFiscalIdentityUnchanged($before, $id);
            $this->assertSame('dispatched', $this->tx($id)->delivery_status, 'foreign bill must be skipped');
        }
    }

    // ── 5. archived-but-open bill still flips ONLY delivery_status ──────────

    public function test_archived_open_bill_flips_status_without_touching_archive_state(): void
    {
        [$rider, $user] = $this->makeRiderWithUser();
        $archived = $this->makeBill($rider, [
            'delivery_status' => 'dispatched',
            'is_archived' => true,
            'archived_at' => now()->subHours(3),
        ]);
        $before = $this->tx($archived);

        $this->markDelivered($user, $archived);

        $this->assertFiscalIdentityUnchanged($before, $archived);
        $tx = $this->tx($archived);
        $this->assertSame('delivered', $tx->delivery_status);
        $this->assertTrue((bool) $tx->is_archived, 'archive state must not flip');
    }
}
