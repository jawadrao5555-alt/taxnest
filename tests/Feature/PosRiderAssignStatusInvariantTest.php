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

    private function bulkStatus(int $riderId, string $status)
    {
        $this->actAs();
        return $this->controller()->bulkStatus($this->makeRequest('/pos/deliveries/rider/' . $riderId . '/bulk-status', ['delivery_status' => $status]), $riderId);
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
}
