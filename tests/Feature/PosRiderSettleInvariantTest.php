<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * RIDER SETTLE INVARIANT — "riders kabhi serials/invoice mode nahi chhedte".
 *
 * Locks PosRiderController::settle (incl. settle_all=1 from the Pending
 * Deliveries panel, Task 123) so future edits (e.g. a settle+final combo)
 * can never silently mutate a bill's fiscal identity:
 *
 *   1. settle_all=1 → affected bills' invoice_mode / pra_status /
 *      invoice_number are byte-for-byte unchanged; ONLY
 *      rider_settlement_id + rider_settled_at are set.
 *   2. Scope: settle_all grabs ONLY cash + unsettled + not-returned bills
 *      for THIS rider — returned, already-settled, card, other-rider and
 *      other-company bills are skipped and untouched.
 *   3. Archived-but-unsettled bills (day-close wash) ARE settled
 *      (hide_archived bypass) without flipping archive state.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create,
 * controller invoked directly with a JSON Request (same approach as
 * PosDayCloseAutoFinalizeTest).
 */
class PosRiderSettleInvariantTest extends TestCase
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
            $table->unsignedBigInteger('rider_id')->nullable();
            $table->string('delivery_status')->nullable();
            $table->unsignedBigInteger('rider_settlement_id')->nullable();
            $table->timestamp('rider_settled_at')->nullable();
            $table->timestamps();
        });
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private const COMPANY = 7;

    private function makeRider(int $companyId = self::COMPANY): int
    {
        return (int) DB::table('pos_riders')->insertGetId([
            'company_id' => $companyId,
            'name' => 'Test Rider',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeBill(int $riderId, array $attrs = []): int
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
            'rider_id' => $riderId,
            'delivery_status' => 'dispatched',
            'rider_settlement_id' => null,
            'rider_settled_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    /** POST settle as a JSON client (the sale-screen panel path). */
    private function settle(int $riderId, array $payload = ['settle_all' => 1])
    {
        app()->instance('currentCompanyId', null);
        app()->bind('currentCompanyId', fn () => self::COMPANY);

        $user = User::forceCreate(['company_id' => self::COMPANY, 'name' => 'Owner']);
        auth('pos')->setUser($user);

        $request = Request::create('/pos/riders/' . $riderId . '/settle', 'POST', $payload);
        $request->headers->set('Accept', 'application/json');

        $controller = app(\App\Http\Controllers\PosRiderController::class);

        return $controller->settle($request, $riderId);
    }

    private function tx(int $id): object
    {
        return DB::table('pos_transactions')->where('id', $id)->first();
    }

    // ── 1. settle_all never touches fiscal identity ─────────────────────────

    public function test_settle_all_never_mutates_invoice_mode_status_or_number(): void
    {
        $riderId = $this->makeRider();

        // Three open cash bills across different fiscal states.
        $final = $this->makeBill($riderId, [
            'invoice_number' => 'POS-2026-00001',
            'invoice_mode' => 'pra',
            'pra_status' => 'submitted',
            'pra_invoice_number' => 'PRA-111',
        ]);
        $offline = $this->makeBill($riderId, [
            'invoice_number' => 'POS-2026-00002',
            'invoice_mode' => 'pra',
            'pra_status' => 'offline',
            'pra_invoice_number' => null,
        ]);
        $provisional = $this->makeBill($riderId, [
            'invoice_number' => 'L-0003',
            'invoice_mode' => 'local',
            'pra_status' => 'local',
            'pra_invoice_number' => null,
        ]);

        $before = [];
        foreach ([$final, $offline, $provisional] as $id) {
            $before[$id] = $this->tx($id);
        }

        $response = $this->settle($riderId);
        $this->assertTrue($response->getData(true)['success']);
        $this->assertSame(3, $response->getData(true)['bill_count']);
        $this->assertSame(1500.0, (float) $response->getData(true)['total_amount']);

        $settlementId = (int) DB::table('pos_rider_settlements')->value('id');
        $this->assertGreaterThan(0, $settlementId);

        foreach ($before as $id => $old) {
            $tx = $this->tx($id);
            // THE invariant: fiscal identity byte-for-byte unchanged.
            $this->assertSame($old->invoice_mode, $tx->invoice_mode, "bill {$old->invoice_number}: invoice_mode changed");
            $this->assertSame($old->pra_status, $tx->pra_status, "bill {$old->invoice_number}: pra_status changed");
            $this->assertSame($old->invoice_number, $tx->invoice_number, "bill {$old->invoice_number}: serial changed");
            $this->assertSame($old->pra_invoice_number, $tx->pra_invoice_number, "bill {$old->invoice_number}: fiscal number changed");
            $this->assertSame($old->status, $tx->status);
            $this->assertSame((float) $old->total_amount, (float) $tx->total_amount);
            $this->assertSame((bool) $old->is_archived, (bool) $tx->is_archived);
            $this->assertSame($old->delivery_status, $tx->delivery_status);
            // ONLY the settlement columns move.
            $this->assertSame($settlementId, (int) $tx->rider_settlement_id);
            $this->assertNotNull($tx->rider_settled_at);
        }
    }

    // ── 2. settle_all scope: cash + unsettled + not-returned, this rider ────

    public function test_settle_all_scope_skips_returned_settled_card_and_foreign_bills(): void
    {
        $riderId = $this->makeRider();
        $otherRider = $this->makeRider();

        $open = $this->makeBill($riderId); // the only eligible bill
        $returned = $this->makeBill($riderId, ['delivery_status' => 'returned']);
        $alreadySettled = $this->makeBill($riderId, [
            'rider_settlement_id' => 999,
            'rider_settled_at' => now()->subDay(),
        ]);
        $card = $this->makeBill($riderId, ['payment_method' => 'debit_card']);
        $otherRiderBill = $this->makeBill($otherRider);
        $otherCompanyBill = $this->makeBill($riderId, ['company_id' => self::COMPANY + 1]);

        $response = $this->settle($riderId);
        $data = $response->getData(true);
        $this->assertTrue($data['success']);
        $this->assertSame(1, $data['bill_count'], 'ONLY the open cash bill may be settled');
        $this->assertSame(500.0, (float) $data['total_amount']);

        $this->assertNotNull($this->tx($open)->rider_settlement_id);

        foreach ([
            'returned' => $returned,
            'card' => $card,
            'other rider' => $otherRiderBill,
            'other company' => $otherCompanyBill,
        ] as $label => $id) {
            $this->assertNull($this->tx($id)->rider_settlement_id, "{$label} bill must be skipped");
            $this->assertNull($this->tx($id)->rider_settled_at, "{$label} bill must be skipped");
        }
        // Already-settled bill keeps its ORIGINAL settlement — never re-pointed.
        $this->assertSame(999, (int) $this->tx($alreadySettled)->rider_settlement_id);
    }

    // ── 3. archived-but-unsettled bills settle without archive flip ─────────

    public function test_archived_unsettled_bill_is_settled_without_touching_archive_state(): void
    {
        $riderId = $this->makeRider();

        $archivedAt = now()->subHours(3);
        $washed = $this->makeBill($riderId, [
            'is_archived' => true,
            'archived_at' => $archivedAt,
            'invoice_mode' => 'local',
            'pra_status' => 'local',
            'invoice_number' => 'L-0009',
        ]);

        $response = $this->settle($riderId);
        $this->assertSame(1, $response->getData(true)['bill_count'], 'day-close-washed bill must still be collectible');

        $tx = $this->tx($washed);
        $this->assertNotNull($tx->rider_settlement_id);
        $this->assertTrue((bool) $tx->is_archived, 'archive state must not flip');
        $this->assertSame('local', $tx->invoice_mode);
        $this->assertSame('local', $tx->pra_status);
        $this->assertSame('L-0009', $tx->invoice_number);
    }

    // ── 4. empty khata → clean 422 JSON, nothing written ────────────────────

    public function test_settle_all_with_empty_khata_returns_422_and_writes_nothing(): void
    {
        $riderId = $this->makeRider();

        $response = $this->settle($riderId);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['success']);
        $this->assertSame(0, DB::table('pos_rider_settlements')->count());
    }
}
