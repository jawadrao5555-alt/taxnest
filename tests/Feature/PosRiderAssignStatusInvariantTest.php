<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * RIDER ASSIGN / STATUS INVARIANT — "rider assign & delivery-status changes
 * kabhi bill ke serials / invoice mode nahi chhedte".
 *
 * Companion to PosRiderSettleInvariantTest (which locks settle). This locks
 * the OTHER rider write-paths in PosRiderController:
 *
 *   assign()      — may write ONLY rider_id + delivery_status; settled bills
 *                   are locked ('already settled'), terminal delivered/returned
 *                   bills are locked too.
 *   updateStatus()— may write ONLY delivery_status; settled bills locked;
 *                   returned is final; delivered → only returned.
 *   bulkStatus()  — may write ONLY delivery_status on open (assigned/dispatched,
 *                   unsettled) bills of THIS rider; settled + terminal +
 *                   foreign bills untouched.
 *
 * In every case invoice_mode / pra_status / invoice_number /
 * pra_invoice_number must be byte-for-byte unchanged.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create,
 * controller invoked directly (same approach as PosRiderSettleInvariantTest).
 */
class PosRiderAssignStatusInvariantTest extends TestCase
{
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
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();
        });
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private const COMPANY = 7;

    private function makeRider(int $companyId = self::COMPANY, bool $active = true): int
    {
        return (int) DB::table('pos_riders')->insertGetId([
            'company_id' => $companyId,
            'name' => 'Test Rider',
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

    private function actAs(): void
    {
        app()->instance('currentCompanyId', null);
        app()->bind('currentCompanyId', fn () => self::COMPANY);

        $user = User::forceCreate(['company_id' => self::COMPANY, 'name' => 'Owner']);
        auth('pos')->setUser($user);
    }

    private function controller(): \App\Http\Controllers\PosRiderController
    {
        return app(\App\Http\Controllers\PosRiderController::class);
    }

    /** Build a POST request with a session (controller uses back()->with()). */
    private function makeRequest(string $uri, array $payload): Request
    {
        $request = Request::create($uri, 'POST', $payload);
        $request->setLaravelSession(app('session.store'));
        return $request;
    }

    private function assign(int $txnId, array $payload)
    {
        $this->actAs();
        return $this->controller()->assign($this->makeRequest('/pos/deliveries/' . $txnId . '/assign', $payload), $txnId);
    }

    private function updateStatus(int $txnId, string $status)
    {
        $this->actAs();
        return $this->controller()->updateStatus($this->makeRequest('/pos/deliveries/' . $txnId . '/status', ['delivery_status' => $status]), $txnId);
    }

    /** Same as updateStatus() but with Accept: application/json — mirrors the sale-screen fetch path. */
    private function updateStatusJson(int $txnId, string $status)
    {
        $this->actAs();
        $request = Request::create('/pos/deliveries/' . $txnId . '/status', 'POST', ['delivery_status' => $status], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $request->setLaravelSession(app('session.store'));
        return $this->controller()->updateStatus($request, $txnId);
    }

    private function bulkStatus(int $riderId, string $status)
    {
        $this->actAs();
        return $this->controller()->bulkStatus($this->makeRequest('/pos/deliveries/rider/' . $riderId . '/bulk-status', ['delivery_status' => $status]), $riderId);
    }

    private function bulkStatusJson(int $riderId, string $status)
    {
        $this->actAs();
        $request = Request::create(
            '/pos/deliveries/rider/' . $riderId . '/bulk-status',
            'POST',
            ['delivery_status' => $status],
            [], [], ['HTTP_ACCEPT' => 'application/json']
        );
        $request->setLaravelSession(app('session.store'));
        return $this->controller()->bulkStatus($request, $riderId);
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

    private function flashError($response): ?string
    {
        return $response->getSession()->get('error');
    }

    // ── 1. assign: fiscal identity unchanged, only rider_id/delivery_status ─

    public function test_assign_rider_never_mutates_fiscal_identity(): void
    {
        $rider = $this->makeRider();
        // Unassigned delivery bills across different fiscal states.
        $final = $this->makeBill(null, [
            'invoice_number' => 'POS-2026-00001',
            'invoice_mode' => 'pra',
            'pra_status' => 'submitted',
            'pra_invoice_number' => 'PRA-111',
        ]);
        $provisional = $this->makeBill(null, [
            'invoice_number' => 'L-0002',
            'invoice_mode' => 'local',
            'pra_status' => 'local',
            'pra_invoice_number' => null,
        ]);

        foreach ([$final, $provisional] as $id) {
            $before = $this->tx($id);
            $this->assign($id, ['rider_id' => $rider]);

            $this->assertFiscalIdentityUnchanged($before, $id);
            $tx = $this->tx($id);
            // ONLY rider columns move.
            $this->assertSame($rider, (int) $tx->rider_id);
            $this->assertSame('assigned', $tx->delivery_status);
            $this->assertNull($tx->rider_settlement_id);
            $this->assertNull($tx->rider_settled_at);
        }
    }

    public function test_reassign_and_unassign_keep_fiscal_identity(): void
    {
        $rider = $this->makeRider();
        $rider2 = $this->makeRider();

        $bill = $this->makeBill($rider, ['delivery_status' => 'dispatched']);
        $before = $this->tx($bill);

        // Reassign to another rider — status stays dispatched, fiscal untouched.
        $this->assign($bill, ['rider_id' => $rider2]);
        $this->assertFiscalIdentityUnchanged($before, $bill);
        $this->assertSame($rider2, (int) $this->tx($bill)->rider_id);
        $this->assertSame('dispatched', $this->tx($bill)->delivery_status);

        // Unassign — rider + status cleared, fiscal untouched.
        $this->assign($bill, []);
        $this->assertFiscalIdentityUnchanged($before, $bill);
        $this->assertNull($this->tx($bill)->rider_id);
        $this->assertNull($this->tx($bill)->delivery_status);
    }

    // ── 2. assign: settled + terminal bills are locked ──────────────────────

    public function test_assign_on_settled_bill_is_locked_and_writes_nothing(): void
    {
        $rider = $this->makeRider();
        $rider2 = $this->makeRider();
        $settled = $this->makeBill($rider, [
            'rider_settlement_id' => 55,
            'rider_settled_at' => now()->subDay(),
            'delivery_status' => 'delivered',
        ]);
        $before = $this->tx($settled);

        $response = $this->assign($settled, ['rider_id' => $rider2]);

        $this->assertStringContainsString('already settled', (string) $this->flashError($response));
        $this->assertFiscalIdentityUnchanged($before, $settled);
        $tx = $this->tx($settled);
        $this->assertSame($rider, (int) $tx->rider_id, 'settled bill must keep its rider');
        $this->assertSame(55, (int) $tx->rider_settlement_id);
        $this->assertSame('delivered', $tx->delivery_status);
    }

    public function test_assign_on_terminal_delivered_or_returned_bill_is_locked(): void
    {
        $rider = $this->makeRider();
        $rider2 = $this->makeRider();

        foreach (['delivered', 'returned'] as $terminal) {
            $bill = $this->makeBill($rider, ['delivery_status' => $terminal]);
            $before = $this->tx($bill);

            $response = $this->assign($bill, ['rider_id' => $rider2]);

            $this->assertStringContainsString('already ' . $terminal, (string) $this->flashError($response));
            $this->assertFiscalIdentityUnchanged($before, $bill);
            $tx = $this->tx($bill);
            $this->assertSame($rider, (int) $tx->rider_id, "{$terminal} bill must keep its rider");
            $this->assertSame($terminal, $tx->delivery_status);
        }
    }

    // ── 3. updateStatus: fiscal identity unchanged, settled/terminal locked ─

    public function test_update_status_never_mutates_fiscal_identity(): void
    {
        $rider = $this->makeRider();
        $final = $this->makeBill($rider, [
            'invoice_number' => 'POS-2026-00007',
            'invoice_mode' => 'pra',
            'pra_status' => 'submitted',
            'pra_invoice_number' => 'PRA-777',
            'delivery_status' => 'assigned',
        ]);
        $before = $this->tx($final);

        foreach (['dispatched', 'delivered', 'returned'] as $step) {
            $this->updateStatus($final, $step);
            $this->assertFiscalIdentityUnchanged($before, $final);
            $tx = $this->tx($final);
            $this->assertSame($step, $tx->delivery_status);
            $this->assertSame($rider, (int) $tx->rider_id, 'rider must never change on status update');
            $this->assertNull($tx->rider_settlement_id);
        }
    }

    public function test_update_status_on_settled_bill_is_locked(): void
    {
        $rider = $this->makeRider();
        $settled = $this->makeBill($rider, [
            'rider_settlement_id' => 88,
            'rider_settled_at' => now()->subDay(),
            'delivery_status' => 'delivered',
        ]);
        $before = $this->tx($settled);

        $response = $this->updateStatus($settled, 'returned');

        $this->assertStringContainsString('already settled', (string) $this->flashError($response));
        $this->assertFiscalIdentityUnchanged($before, $settled);
        $this->assertSame('delivered', $this->tx($settled)->delivery_status, 'settled bill status must stay locked');
    }

    public function test_update_status_terminal_rules(): void
    {
        $rider = $this->makeRider();

        // Returned = fully final.
        $returned = $this->makeBill($rider, ['delivery_status' => 'returned']);
        $response = $this->updateStatus($returned, 'delivered');
        $this->assertStringContainsString('already returned', (string) $this->flashError($response));
        $this->assertSame('returned', $this->tx($returned)->delivery_status);

        // Delivered → only returned allowed.
        $delivered = $this->makeBill($rider, ['delivery_status' => 'delivered']);
        $response = $this->updateStatus($delivered, 'dispatched');
        $this->assertStringContainsString('already delivered', (string) $this->flashError($response));
        $this->assertSame('delivered', $this->tx($delivered)->delivery_status);

        $this->updateStatus($delivered, 'returned');
        $this->assertSame('returned', $this->tx($delivered)->delivery_status);
    }

    // ── 4. bulkStatus: only open bills of THIS rider, fiscal untouched ──────

    public function test_bulk_status_flips_only_open_bills_and_never_touches_fiscal_identity(): void
    {
        $rider = $this->makeRider();
        $otherRider = $this->makeRider();

        $assigned = $this->makeBill($rider, ['delivery_status' => 'assigned', 'invoice_number' => 'POS-2026-00011']);
        $dispatched = $this->makeBill($rider, ['delivery_status' => 'dispatched', 'invoice_number' => 'L-0012', 'invoice_mode' => 'local', 'pra_status' => 'local', 'pra_invoice_number' => null]);
        $delivered = $this->makeBill($rider, ['delivery_status' => 'delivered']);
        $returned = $this->makeBill($rider, ['delivery_status' => 'returned']);
        $settled = $this->makeBill($rider, ['delivery_status' => 'dispatched', 'rider_settlement_id' => 33, 'rider_settled_at' => now()->subDay()]);
        $foreign = $this->makeBill($otherRider, ['delivery_status' => 'dispatched']);

        $ids = [$assigned, $dispatched, $delivered, $returned, $settled, $foreign];
        $before = [];
        foreach ($ids as $id) {
            $before[$id] = $this->tx($id);
        }

        $this->bulkStatus($rider, 'delivered');

        // Fiscal identity untouched on EVERY bill.
        foreach ($before as $id => $old) {
            $this->assertFiscalIdentityUnchanged($old, $id);
            $this->assertSame((int) $old->rider_id, (int) $this->tx($id)->rider_id, 'bulk status must never move rider_id');
        }

        // Only the open bills flipped.
        $this->assertSame('delivered', $this->tx($assigned)->delivery_status);
        $this->assertSame('delivered', $this->tx($dispatched)->delivery_status);
        // Terminal + settled + foreign untouched.
        $this->assertSame('delivered', $this->tx($delivered)->delivery_status);
        $this->assertSame('returned', $this->tx($returned)->delivery_status);
        $this->assertSame('dispatched', $this->tx($settled)->delivery_status, 'settled bill must be skipped');
        $this->assertSame(33, (int) $this->tx($settled)->rider_settlement_id);
        $this->assertSame('dispatched', $this->tx($foreign)->delivery_status, 'other rider\'s bill must be skipped');
    }

    public function test_bulk_status_with_no_open_deliveries_writes_nothing(): void
    {
        $rider = $this->makeRider();
        $delivered = $this->makeBill($rider, ['delivery_status' => 'delivered']);
        $before = $this->tx($delivered);

        $response = $this->bulkStatus($rider, 'returned');

        $this->assertStringContainsString('No open deliveries', (string) $this->flashError($response));
        $this->assertFiscalIdentityUnchanged($before, $delivered);
        $this->assertSame('delivered', $this->tx($delivered)->delivery_status);
    }

    // ── 5. bulkStatus: riderless bills in the DB never crash or corrupt ──────

    /**
     * Edge case (Task 796): the DB may contain delivery bills whose rider_id is
     * NULL — either because the rider was unassigned post-creation or because
     * the bill was created before the rider-assignment feature existed.  The
     * bulk-status query scopes strictly to rider_id = $rider->id, so these rows
     * are never matched.  This test confirms:
     *   a) no 500 / exception when riderless bills coexist with open rider bills;
     *   b) fiscal identity of the riderless bill is byte-for-byte untouched;
     *   c) the delivery_status of the riderless bill is NOT changed;
     *   d) the rider's legitimate open bills ARE flipped as expected.
     */
    public function test_bulk_status_skips_riderless_bills_and_does_not_crash(): void
    {
        $rider = $this->makeRider();

        // A riderless delivery bill that happens to be in 'assigned' / 'dispatched'
        // state — edge-case data that should never be touched by bulkStatus.
        $riderlessAssigned = $this->makeBill(null, [
            'invoice_number'  => 'POS-2026-99001',
            'invoice_mode'    => 'pra',
            'pra_status'      => 'submitted',
            'pra_invoice_number' => 'PRA-RIDERLESS-1',
            'delivery_status' => 'assigned',   // unusual but possible in edge cases
            'rider_settlement_id' => null,
        ]);
        // A completely fresh unassigned delivery bill (rider_id NULL, status NULL).
        $riderlessNull = $this->makeBill(null, [
            'invoice_number'  => 'POS-2026-99002',
            'invoice_mode'    => 'local',
            'pra_status'      => 'local',
            'pra_invoice_number' => null,
            'delivery_status' => null,
            'rider_settlement_id' => null,
        ]);

        // The rider also has a legitimate open bill that SHOULD be flipped.
        $riderOpen = $this->makeBill($rider, [
            'delivery_status' => 'dispatched',
            'invoice_number'  => 'POS-2026-99003',
        ]);

        $beforeRiderless1 = $this->tx($riderlessAssigned);
        $beforeRiderless2 = $this->tx($riderlessNull);
        $beforeRiderOpen  = $this->tx($riderOpen);

        // Must not throw; must return a redirect (not a 500).
        $response = $this->bulkStatus($rider, 'delivered');
        $this->assertNull($this->flashError($response), 'bulkStatus should succeed without an error flash');

        // Rider's open bill flipped to delivered.
        $this->assertSame('delivered', $this->tx($riderOpen)->delivery_status);
        $this->assertSame($rider, (int) $this->tx($riderOpen)->rider_id, 'rider_id must not change on bulk flip');
        $this->assertFiscalIdentityUnchanged($beforeRiderOpen, $riderOpen);

        // Riderless bills completely untouched — no status change, no fiscal mutation.
        $this->assertSame('assigned', $this->tx($riderlessAssigned)->delivery_status, 'riderless bill must keep its delivery_status');
        $this->assertNull($this->tx($riderlessAssigned)->rider_id, 'riderless bill must keep rider_id NULL');
        $this->assertFiscalIdentityUnchanged($beforeRiderless1, $riderlessAssigned);

        $this->assertNull($this->tx($riderlessNull)->delivery_status, 'riderless NULL-status bill must stay NULL');
        $this->assertNull($this->tx($riderlessNull)->rider_id, 'riderless bill must keep rider_id NULL');
        $this->assertFiscalIdentityUnchanged($beforeRiderless2, $riderlessNull);
    }

    // ── 6. updateStatus JSON path: riderless bill via sale-screen fetch ───────

    /**
     * Task 806: the sale-screen Pending Deliveries popup sends the same POST
     * with Accept: application/json (fetch, not a form submit).  updateStatus()
     * takes a different code branch for JSON clients and returns a JSON body
     * instead of back().  This test confirms:
     *   a) no 500 / exception — the riderless path is reached safely;
     *   b) HTTP 200 with success:true and delivery_status:'delivered';
     *   c) fiscal identity (invoice_mode / pra_status / serials) byte-for-byte
     *      unchanged after the update.
     */
    public function test_update_status_json_path_on_riderless_bill_returns_200_success(): void
    {
        // Riderless delivery bill — the exact shape the sale-screen popup creates
        // when no rider is assigned (rider_id NULL, delivery_status NULL).
        $bill = $this->makeBill(null, [
            'invoice_number'     => 'POS-2026-80601',
            'invoice_mode'       => 'pra',
            'pra_status'         => 'submitted',
            'pra_invoice_number' => 'PRA-806',
            'order_type'         => 'delivery',
            'status'             => 'completed',
            'delivery_status'    => null,
            'rider_id'           => null,
            'rider_settlement_id' => null,
        ]);
        $before = $this->tx($bill);

        // Act — JSON-flavoured request (sale-screen fetch path).
        $response = $this->updateStatusJson($bill, 'delivered');

        // Must be a JsonResponse, HTTP 200.
        $this->assertInstanceOf(\Illuminate\Http\JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $body = $response->getData(true);
        $this->assertTrue((bool) ($body['success'] ?? false), 'JSON body must contain success:true');
        $this->assertSame('delivered', $body['delivery_status'] ?? null, 'JSON body must report delivery_status:delivered');

        // Delivery status flipped; rider_id stays NULL.
        $tx = $this->tx($bill);
        $this->assertSame('delivered', $tx->delivery_status);
        $this->assertNull($tx->rider_id, 'rider_id must stay NULL on a riderless bill');
        $this->assertNull($tx->rider_settlement_id, 'settlement must be untouched');

        // THE invariant: fiscal identity byte-for-byte unchanged.
        $this->assertFiscalIdentityUnchanged($before, $bill);
    }

    /**
     * Negative: a riderless bill that is already delivered must NOT be marked
     * delivered again via the JSON path — guarded to prevent double-delivery.
     */
    public function test_update_status_json_path_rejects_already_delivered_riderless_bill(): void
    {
        $bill = $this->makeBill(null, [
            'invoice_number'  => 'POS-2026-80602',
            'order_type'      => 'delivery',
            'status'          => 'completed',
            'delivery_status' => 'delivered',   // already done
            'rider_id'        => null,
            'rider_settlement_id' => null,
        ]);
        $before = $this->tx($bill);

        $response = $this->updateStatusJson($bill, 'delivered');

        // Must return an error response (JSON 422 or a JSON error body), never 200 success.
        $body = $response->getData(true);
        $this->assertFalse((bool) ($body['success'] ?? true), 'already-delivered riderless bill must not return success:true');

        // Delivery status and fiscal identity must be unchanged.
        $this->assertSame('delivered', $this->tx($bill)->delivery_status);
        $this->assertFiscalIdentityUnchanged($before, $bill);
    }

    // ── 7. updateStatus JSON path: dispatched/returned rejected on riderless ──

    /**
     * Task 813: the riderless guard in updateStatus() allows ONLY 'delivered'
     * (once, from NULL).  POSTing 'dispatched' or 'returned' via the
     * JSON/sale-screen path must return a JSON error body (success:false or
     * absent), never a 200 success, and must not mutate any column.
     *
     * This test covers delivery_status='dispatched' on a riderless bill.
     */
    public function test_update_status_json_path_rejects_dispatched_on_riderless_bill(): void
    {
        $bill = $this->makeBill(null, [
            'invoice_number'     => 'POS-2026-81301',
            'invoice_mode'       => 'pra',
            'pra_status'         => 'submitted',
            'pra_invoice_number' => 'PRA-8131',
            'order_type'         => 'delivery',
            'status'             => 'completed',
            'delivery_status'    => null,
            'rider_id'           => null,
            'rider_settlement_id' => null,
        ]);
        $before = $this->tx($bill);

        $response = $this->updateStatusJson($bill, 'dispatched');

        // Must be a JsonResponse — not a redirect, not a 500.
        $this->assertInstanceOf(\Illuminate\Http\JsonResponse::class, $response);

        // success must NOT be true.
        $body = $response->getData(true);
        $this->assertFalse(
            (bool) ($body['success'] ?? false),
            'dispatched on riderless bill must not return success:true'
        );

        // Delivery status unchanged (still NULL).
        $this->assertNull($this->tx($bill)->delivery_status, 'delivery_status must stay NULL after rejected dispatched');

        // Fiscal identity byte-for-byte unchanged.
        $this->assertFiscalIdentityUnchanged($before, $bill);
    }

    // ── 8. bulkStatus JSON path: no open bills → JSON error, riderless untouched ─

    /**
     * Task 825: bulkStatus() already scopes to rider_id = $rider->id, so a
     * rider with no open bills matches zero rows.  When the caller sends
     * Accept: application/json (the sale-screen panel fetch path) the response
     * must be a JsonResponse with success:false — not a redirect and not a 500.
     *
     * Two sub-cases are tested (delivered + returned) because the two code
     * paths inside bulkStatus() differ: delivered uses a bulk UPDATE, returned
     * iterates a collection; both now share the same wantsJson() guard.
     */
    public function test_bulk_status_json_path_no_open_bills_returns_json_error_for_delivered(): void
    {
        $rider = $this->makeRider();

        // Riderless bill that happens to be in the right delivery state —
        // it must NOT be matched, and its fiscal identity must be unchanged.
        $riderless = $this->makeBill(null, [
            'invoice_number'     => 'POS-2026-82501',
            'invoice_mode'       => 'pra',
            'pra_status'         => 'submitted',
            'pra_invoice_number' => 'PRA-82501',
            'delivery_status'    => 'dispatched',
            'rider_settlement_id' => null,
        ]);
        $before = $this->tx($riderless);

        // Act — JSON-flavoured bulkStatus for 'delivered' with no open rider bills.
        $response = $this->bulkStatusJson($rider, 'delivered');

        // Must be a JsonResponse (not a redirect object).
        $this->assertInstanceOf(\Illuminate\Http\JsonResponse::class, $response);

        // Must signal failure.
        $body = $response->getData(true);
        $this->assertFalse(
            (bool) ($body['success'] ?? false),
            'bulkStatus JSON must return success:false when rider has no open bills'
        );
        $this->assertArrayHasKey('message', $body, 'JSON error body must include a message key');

        // Riderless bill: delivery_status and fiscal identity untouched.
        $this->assertSame('dispatched', $this->tx($riderless)->delivery_status, 'riderless bill delivery_status must be unchanged');
        $this->assertFiscalIdentityUnchanged($before, $riderless);
    }

    public function test_bulk_status_json_path_no_open_bills_returns_json_error_for_returned(): void
    {
        $rider = $this->makeRider();

        // Riderless bill in 'assigned' state — must never be touched.
        $riderless = $this->makeBill(null, [
            'invoice_number'     => 'POS-2026-82502',
            'invoice_mode'       => 'pra',
            'pra_status'         => 'submitted',
            'pra_invoice_number' => 'PRA-82502',
            'delivery_status'    => 'assigned',
            'rider_settlement_id' => null,
        ]);
        $before = $this->tx($riderless);

        // Act — JSON-flavoured bulkStatus for 'returned' with no open rider bills.
        $response = $this->bulkStatusJson($rider, 'returned');

        // Must be a JsonResponse (not a redirect).
        $this->assertInstanceOf(\Illuminate\Http\JsonResponse::class, $response);

        // Must signal failure.
        $body = $response->getData(true);
        $this->assertFalse(
            (bool) ($body['success'] ?? false),
            'bulkStatus JSON must return success:false when rider has no open bills (returned path)'
        );
        $this->assertArrayHasKey('message', $body, 'JSON error body must include a message key');

        // Riderless bill: delivery_status and fiscal identity untouched.
        $this->assertSame('assigned', $this->tx($riderless)->delivery_status, 'riderless bill delivery_status must be unchanged');
        $this->assertFiscalIdentityUnchanged($before, $riderless);
    }

    // ── 9. bulkStatus JSON path: delivered stamps delivered_at; riderless untouched ─

    /**
     * Task 831: bulkStatus() bulk-UPDATE for 'delivered' includes delivered_at
     * when the column exists.  This test exercises the JSON path (Accept:
     * application/json — the sale-screen panel fetch) to confirm:
     *   a) rider's dispatched bills get delivered_at stamped (non-null)
     *   b) a riderless bill's delivered_at stays NULL
     *   c) fiscal identity of both bills is untouched
     */
    public function test_bulk_status_json_path_delivered_stamps_delivered_at(): void
    {
        $rider = $this->makeRider();

        // Rider's own open bill (dispatched).
        $riderBill = $this->makeBill($rider, [
            'invoice_number'      => 'POS-2026-83101',
            'invoice_mode'        => 'pra',
            'pra_status'          => 'submitted',
            'pra_invoice_number'  => 'PRA-83101',
            'delivery_status'     => 'dispatched',
            'rider_settlement_id' => null,
        ]);

        // Riderless bill that must NOT be touched.
        $riderlessBill = $this->makeBill(null, [
            'invoice_number'      => 'POS-2026-83102',
            'invoice_mode'        => 'pra',
            'pra_status'          => 'submitted',
            'pra_invoice_number'  => 'PRA-83102',
            'delivery_status'     => 'dispatched',
            'rider_settlement_id' => null,
        ]);
        $beforeRiderless = $this->tx($riderlessBill);

        // Act — JSON-flavoured bulkStatus for 'delivered'.
        $response = $this->bulkStatusJson($rider, 'delivered');

        // Controller returns a redirect (back()->with(success)) when rows > 0.
        // The JSON guard only fires on wantsJson() with count === 0.
        // Verify the rider's bill was updated, not the response type.
        $updatedRiderBill = $this->tx($riderBill);

        // a) Rider bill: delivery_status flipped to 'delivered'.
        $this->assertSame('delivered', $updatedRiderBill->delivery_status, 'rider bill delivery_status must be delivered');

        // b) Rider bill: delivered_at must be stamped (non-null).
        $this->assertNotNull($updatedRiderBill->delivered_at, 'rider bill delivered_at must be stamped after bulk delivered');

        // c) Riderless bill: delivered_at stays NULL.
        $updatedRiderless = $this->tx($riderlessBill);
        $this->assertNull($updatedRiderless->delivered_at, 'riderless bill delivered_at must remain NULL');

        // d) Riderless bill: fiscal identity untouched.
        $this->assertFiscalIdentityUnchanged($beforeRiderless, $riderlessBill);

        // e) Rider bill: fiscal identity untouched.
        $beforeRider = (object) [
            'invoice_mode'       => 'pra',
            'pra_status'         => 'submitted',
            'invoice_number'     => 'POS-2026-83101',
            'pra_invoice_number' => 'PRA-83101',
            'status'             => 'completed',
            'total_amount'       => 500.00,
            'is_archived'        => 0,
        ];
        $this->assertSame($beforeRider->invoice_mode,       $updatedRiderBill->invoice_mode,       'rider bill invoice_mode must be unchanged');
        $this->assertSame($beforeRider->pra_status,         $updatedRiderBill->pra_status,         'rider bill pra_status must be unchanged');
        $this->assertSame($beforeRider->invoice_number,     $updatedRiderBill->invoice_number,     'rider bill serial must be unchanged');
        $this->assertSame($beforeRider->pra_invoice_number, $updatedRiderBill->pra_invoice_number, 'rider bill fiscal number must be unchanged');
    }

    // ── 10. bulkStatus JSON path: returned stamps returned_at; riderless untouched ─

    /**
     * Task 839: bulkStatus() bulk-returned path stamps returned_at on each
     * bill when the column exists (Schema::hasColumn guard, same pattern as
     * delivered_at in Task 831).  Confirms:
     *   a) rider's dispatched bills get returned_at stamped (non-null)
     *   b) a riderless bill's returned_at stays NULL
     *   c) fiscal identity of both bills is untouched
     */
    public function test_bulk_status_returned_stamps_returned_at(): void
    {
        $rider = $this->makeRider();

        // Rider's own open bill (dispatched).
        $riderBill = $this->makeBill($rider, [
            'invoice_number'      => 'POS-2026-83901',
            'invoice_mode'        => 'pra',
            'pra_status'          => 'submitted',
            'pra_invoice_number'  => 'PRA-83901',
            'delivery_status'     => 'dispatched',
            'rider_settlement_id' => null,
        ]);

        // Riderless bill that must NOT be touched.
        $riderlessBill = $this->makeBill(null, [
            'invoice_number'      => 'POS-2026-83902',
            'invoice_mode'        => 'pra',
            'pra_status'          => 'submitted',
            'pra_invoice_number'  => 'PRA-83902',
            'delivery_status'     => 'dispatched',
            'rider_settlement_id' => null,
        ]);
        $beforeRiderless = $this->tx($riderlessBill);

        // Act — JSON-flavoured bulkStatus for 'returned'.
        $this->bulkStatusJson($rider, 'returned');

        $updatedRiderBill = $this->tx($riderBill);

        // a) Rider bill: delivery_status flipped to 'returned'.
        $this->assertSame('returned', $updatedRiderBill->delivery_status, 'rider bill delivery_status must be returned');

        // b) Rider bill: returned_at must be stamped (non-null).
        $this->assertNotNull($updatedRiderBill->returned_at, 'rider bill returned_at must be stamped after bulk returned');

        // c) Riderless bill: returned_at stays NULL.
        $updatedRiderless = $this->tx($riderlessBill);
        $this->assertNull($updatedRiderless->returned_at, 'riderless bill returned_at must remain NULL');

        // d) Riderless bill: fiscal identity untouched.
        $this->assertFiscalIdentityUnchanged($beforeRiderless, $riderlessBill);

        // e) Rider bill: fiscal identity untouched.
        $beforeRider = (object) [
            'invoice_mode'       => 'pra',
            'pra_status'         => 'submitted',
            'invoice_number'     => 'POS-2026-83901',
            'pra_invoice_number' => 'PRA-83901',
            'status'             => 'completed',
            'total_amount'       => 500.00,
            'is_archived'        => 0,
        ];
        $this->assertSame($beforeRider->invoice_mode,       $updatedRiderBill->invoice_mode,       'rider bill invoice_mode must be unchanged');
        $this->assertSame($beforeRider->pra_status,         $updatedRiderBill->pra_status,         'rider bill pra_status must be unchanged');
        $this->assertSame($beforeRider->invoice_number,     $updatedRiderBill->invoice_number,     'rider bill serial must be unchanged');
        $this->assertSame($beforeRider->pra_invoice_number, $updatedRiderBill->pra_invoice_number, 'rider bill fiscal number must be unchanged');
    }

    // ── 11. updateStatus single-bill path: returned stamps returned_at ─────────

    /**
     * Task 847: updateStatus() single-bill returned path stamps returned_at
     * when the column exists (Schema::hasColumn guard, same pattern as
     * delivered_at and the bulk path in Task 839).  Confirms:
     *   a) delivery_status flips to 'returned'
     *   b) returned_at is non-null after the call
     *   c) fiscal identity of the bill is untouched
     */
    public function test_update_status_returned_stamps_returned_at(): void
    {
        $rider = $this->makeRider();

        $bill = $this->makeBill($rider, [
            'invoice_number'      => 'POS-2026-84701',
            'invoice_mode'        => 'pra',
            'pra_status'          => 'submitted',
            'pra_invoice_number'  => 'PRA-84701',
            'delivery_status'     => 'dispatched',
            'rider_settlement_id' => null,
        ]);

        $before = $this->tx($bill);

        // Act — single-bill updateStatus via JSON path.
        $this->updateStatusJson($bill, 'returned');

        $updated = $this->tx($bill);

        // a) delivery_status must flip to 'returned'.
        $this->assertSame('returned', $updated->delivery_status, 'delivery_status must be returned after updateStatus');

        // b) returned_at must be stamped (non-null).
        $this->assertNotNull($updated->returned_at, 'returned_at must be stamped on single-bill returned');

        // c) Fiscal identity byte-for-byte unchanged.
        $this->assertFiscalIdentityUnchanged($before, $bill);
    }

    /**
     * Task 857: sale-screen Pending Deliveries panel path — updateStatus() via
     * JSON fetch for 'returned' must return success:true + delivery_status:'returned'
     * in the response body, AND stamp returned_at on the DB row.
     *
     * The existing Task 847 test confirms the DB stamp; this test locks the JSON
     * response contract so a future refactor can't silently break the panel.
     */
    public function test_update_status_json_response_has_success_and_delivery_status_returned_and_stamps_returned_at(): void
    {
        $rider = $this->makeRider();

        $bill = $this->makeBill($rider, [
            'invoice_number'      => 'POS-2026-85701',
            'invoice_mode'        => 'pra',
            'pra_status'          => 'submitted',
            'pra_invoice_number'  => 'PRA-85701',
            'delivery_status'     => 'dispatched',
            'rider_settlement_id' => null,
        ]);

        // Act — sale-screen Pending Deliveries panel sends Accept: application/json.
        $response = $this->updateStatusJson($bill, 'returned');

        // a) Must be a JSON response.
        $this->assertInstanceOf(\Illuminate\Http\JsonResponse::class, $response);

        $body = $response->getData(true);

        // b) JSON body must carry success:true.
        $this->assertTrue(
            (bool) ($body['success'] ?? false),
            'JSON response must have success:true for returned status'
        );

        // c) JSON body must carry delivery_status:'returned'.
        $this->assertSame(
            'returned',
            $body['delivery_status'] ?? null,
            'JSON response body must carry delivery_status:returned'
        );

        // d) DB row must have returned_at stamped (non-null).
        $updated = $this->tx($bill);
        $this->assertNotNull(
            $updated->returned_at,
            'returned_at must be stamped on the DB row via the JSON/sale-screen path'
        );

        // e) delivery_status on the DB row must also be 'returned'.
        $this->assertSame('returned', $updated->delivery_status, 'DB delivery_status must be returned');
    }

    /**
     * Task 813: same guard — 'returned' on a riderless bill must also be
     * rejected via the JSON path with a JSON error body and no DB mutation.
     */
    public function test_update_status_json_path_rejects_returned_on_riderless_bill(): void
    {
        $bill = $this->makeBill(null, [
            'invoice_number'     => 'POS-2026-81302',
            'invoice_mode'       => 'pra',
            'pra_status'         => 'submitted',
            'pra_invoice_number' => 'PRA-8132',
            'order_type'         => 'delivery',
            'status'             => 'completed',
            'delivery_status'    => null,
            'rider_id'           => null,
            'rider_settlement_id' => null,
        ]);
        $before = $this->tx($bill);

        $response = $this->updateStatusJson($bill, 'returned');

        // Must be a JsonResponse — not a redirect, not a 500.
        $this->assertInstanceOf(\Illuminate\Http\JsonResponse::class, $response);

        // success must NOT be true.
        $body = $response->getData(true);
        $this->assertFalse(
            (bool) ($body['success'] ?? false),
            'returned on riderless bill must not return success:true'
        );

        // Delivery status unchanged (still NULL).
        $this->assertNull($this->tx($bill)->delivery_status, 'delivery_status must stay NULL after rejected returned');

        // Fiscal identity byte-for-byte unchanged.
        $this->assertFiscalIdentityUnchanged($before, $bill);
    }
}
