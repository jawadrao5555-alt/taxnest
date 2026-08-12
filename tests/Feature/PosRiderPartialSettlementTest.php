<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * RIDER PARTIAL SETTLEMENT (Task 525) — "aadha cash abhi, baqi baad mein".
 *
 * Locks the received_amount flow on BOTH panels:
 *
 *   1. Partial amount allocates oldest-first: fully covered bills settle,
 *      the remainder lands on the next bill's rider_partial_paid, and the
 *      settlement row records received cash + allocation + outstanding_after.
 *   2. Carry-forward: a second settlement clears the earlier remainder first
 *      (remaining = total - rider_partial_paid), never double-counts.
 *   3. Over-amount → 422, NOTHING written; zero/negative → 422, NOTHING written.
 *   4. Full-amount (explicit received_amount = outstanding) behaves exactly
 *      like the legacy full settle.
 *   5. Fiscal identity (invoice_mode / pra_status / serials) byte-for-byte
 *      unchanged on partial paths too.
 *   6. FBR twin: same semantics against fbr_pos_transactions, panel='fbr'.
 *
 * Pattern: sqlite :memory: minimal Schema::create WITH the new columns
 * (rider_partial_paid; settlements allocation/panel/outstanding_after),
 * controller invoked directly with a JSON Request — same approach as
 * PosRiderSettleInvariantTest.
 */
class PosRiderPartialSettlementTest extends TestCase
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
            // Task 525 columns
            $table->decimal('outstanding_after', 12, 2)->nullable();
            $table->text('allocation')->nullable();
            $table->string('panel', 10)->nullable();
            $table->timestamps();
        });

        foreach (['pos_transactions', 'fbr_pos_transactions'] as $t) {
            Schema::create($t, function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('invoice_number');
                $table->string('status')->default('completed');
                $table->string('invoice_mode')->nullable();
                $table->string('pra_status')->nullable();
                $table->string('pra_invoice_number')->nullable();
                $table->string('fbr_status')->nullable();
                $table->string('fbr_invoice_number')->nullable();
                $table->string('payment_method')->nullable();
                $table->decimal('total_amount', 12, 2)->default(0);
                $table->boolean('is_archived')->default(false);
                $table->timestamp('archived_at')->nullable();
                $table->unsignedBigInteger('rider_id')->nullable();
                $table->string('delivery_status')->nullable();
                $table->unsignedBigInteger('rider_settlement_id')->nullable();
                $table->timestamp('rider_settled_at')->nullable();
                // Task 525 column
                $table->decimal('rider_partial_paid', 12, 2)->default(0);
                $table->timestamps();
            });
        }
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private const COMPANY = 7;

    private function makeRider(): int
    {
        return (int) DB::table('pos_riders')->insertGetId([
            'company_id' => self::COMPANY,
            'name' => 'Qaisar',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeBill(int $riderId, array $attrs = [], string $table = 'pos_transactions'): int
    {
        return (int) DB::table($table)->insertGetId(array_merge([
            'company_id' => self::COMPANY,
            'invoice_number' => 'POS-2026-' . uniqid(),
            'status' => 'completed',
            'invoice_mode' => $table === 'pos_transactions' ? 'pra' : null,
            'pra_status' => $table === 'pos_transactions' ? 'submitted' : null,
            'pra_invoice_number' => $table === 'pos_transactions' ? 'PRA-XYZ' : null,
            'fbr_status' => $table === 'fbr_pos_transactions' ? 'submitted' : null,
            'fbr_invoice_number' => $table === 'fbr_pos_transactions' ? 'FBR-XYZ' : null,
            'payment_method' => 'cash',
            'total_amount' => 500.00,
            'is_archived' => false,
            'rider_id' => $riderId,
            'delivery_status' => 'dispatched',
            'rider_settlement_id' => null,
            'rider_settled_at' => null,
            'rider_partial_paid' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    /** POST settle as a JSON client. */
    private function settle(int $riderId, array $payload, string $panel = 'pra')
    {
        app()->instance('currentCompanyId', null);
        app()->bind('currentCompanyId', fn () => self::COMPANY);

        $user = User::forceCreate(['company_id' => self::COMPANY, 'name' => 'Owner']);
        auth($panel === 'pra' ? 'pos' : 'fbrpos')->setUser($user);

        $request = Request::create('/settle/' . $riderId, 'POST', $payload);
        $request->headers->set('Accept', 'application/json');

        $controller = $panel === 'pra'
            ? app(\App\Http\Controllers\PosRiderController::class)
            : app(\App\Http\Controllers\FbrPosRiderController::class);

        return $controller->settle($request, $riderId);
    }

    private function tx(int $id, string $table = 'pos_transactions'): object
    {
        return DB::table($table)->where('id', $id)->first();
    }

    // ── 1. partial allocates oldest-first ───────────────────────────────────

    public function test_partial_amount_allocates_oldest_first_and_records_remainder(): void
    {
        $riderId = $this->makeRider();

        $oldest = $this->makeBill($riderId, ['total_amount' => 500, 'created_at' => now()->subHours(3)]);
        $middle = $this->makeBill($riderId, ['total_amount' => 400, 'created_at' => now()->subHours(2)]);
        $newest = $this->makeBill($riderId, ['total_amount' => 300, 'created_at' => now()->subHour()]);

        // Khata = 1200, rider hands over 700: oldest (500) settles fully,
        // middle gets 200 partial, newest untouched.
        $response = $this->settle($riderId, ['settle_all' => 1, 'received_amount' => 700]);
        $data = $response->getData(true);

        $this->assertTrue($data['success']);
        $this->assertSame(700.0, (float) $data['total_amount']);
        $this->assertSame(1, $data['bill_count'], 'only the fully covered bill counts');
        $this->assertSame(500.0, (float) $data['outstanding_after'], '1200 - 700 = 500 left');

        $this->assertNotNull($this->tx($oldest)->rider_settlement_id);
        $this->assertNull($this->tx($middle)->rider_settlement_id, 'partially paid bill stays OPEN');
        $this->assertSame(200.0, (float) $this->tx($middle)->rider_partial_paid);
        $this->assertNull($this->tx($newest)->rider_settlement_id);
        $this->assertSame(0.0, (float) $this->tx($newest)->rider_partial_paid);

        $s = DB::table('pos_rider_settlements')->first();
        $this->assertSame(700.0, (float) $s->total_amount, 'settlement row = cash actually received');
        $this->assertSame(500.0, (float) $s->outstanding_after);
        $this->assertSame('pra', $s->panel);
        $alloc = json_decode($s->allocation, true);
        $this->assertCount(2, $alloc);
        $this->assertSame($oldest, (int) $alloc[0]['bill_id']);
        $this->assertSame(500.0, (float) $alloc[0]['amount']);
        $this->assertSame($middle, (int) $alloc[1]['bill_id']);
        $this->assertSame(200.0, (float) $alloc[1]['amount']);
    }

    // ── 2. carry-forward: second settlement clears the earlier remainder ────

    public function test_second_settlement_clears_carry_forward_without_double_count(): void
    {
        $riderId = $this->makeRider();

        $b1 = $this->makeBill($riderId, ['total_amount' => 500, 'created_at' => now()->subHours(2)]);
        $b2 = $this->makeBill($riderId, ['total_amount' => 400, 'created_at' => now()->subHour()]);

        $this->settle($riderId, ['settle_all' => 1, 'received_amount' => 600]);
        // b1 settled; b2 has 100 partial, 300 remaining.
        $this->assertSame(100.0, (float) $this->tx($b2)->rider_partial_paid);

        // Rider brings the remaining 300 — full settle of the rest.
        $response = $this->settle($riderId, ['settle_all' => 1]);
        $data = $response->getData(true);

        $this->assertTrue($data['success']);
        $this->assertSame(300.0, (float) $data['total_amount'], 'default = REMAINING, not original total');
        $this->assertSame(1, $data['bill_count']);
        $this->assertSame(0.0, (float) $data['outstanding_after']);

        $this->assertNotNull($this->tx($b2)->rider_settlement_id);

        // Both settlement rows together = exactly 900 received, never 1000.
        $this->assertSame(900.0, (float) DB::table('pos_rider_settlements')->sum('total_amount'));
    }

    // ── 3. over-amount / zero / negative → 422, nothing written ─────────────

    public function test_over_amount_returns_422_and_writes_nothing(): void
    {
        $riderId = $this->makeRider();
        $bill = $this->makeBill($riderId, ['total_amount' => 500]);

        $response = $this->settle($riderId, ['settle_all' => 1, 'received_amount' => 501]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['success']);
        $this->assertSame(0, DB::table('pos_rider_settlements')->count());
        $this->assertNull($this->tx($bill)->rider_settlement_id);
        $this->assertSame(0.0, (float) $this->tx($bill)->rider_partial_paid);
    }

    public function test_zero_and_negative_amounts_return_422_and_write_nothing(): void
    {
        $riderId = $this->makeRider();
        $bill = $this->makeBill($riderId, ['total_amount' => 500]);

        foreach ([0, -50] as $bad) {
            $response = $this->settle($riderId, ['settle_all' => 1, 'received_amount' => $bad]);
            $this->assertSame(422, $response->getStatusCode(), "amount {$bad} must be rejected");
        }
        $this->assertSame(0, DB::table('pos_rider_settlements')->count());
        $this->assertSame(0.0, (float) $this->tx($bill)->rider_partial_paid);
    }

    // ── 4. explicit full amount == legacy full settle ────────────────────────

    public function test_explicit_full_amount_settles_everything_like_legacy(): void
    {
        $riderId = $this->makeRider();
        $b1 = $this->makeBill($riderId, ['total_amount' => 500]);
        $b2 = $this->makeBill($riderId, ['total_amount' => 400]);

        $response = $this->settle($riderId, ['settle_all' => 1, 'received_amount' => 900]);
        $data = $response->getData(true);

        $this->assertTrue($data['success']);
        $this->assertSame(900.0, (float) $data['total_amount']);
        $this->assertSame(2, $data['bill_count']);
        $this->assertSame(0.0, (float) $data['outstanding_after']);
        $this->assertNotNull($this->tx($b1)->rider_settlement_id);
        $this->assertNotNull($this->tx($b2)->rider_settlement_id);
        $this->assertSame(0.0, (float) DB::table('pos_transactions')->sum('rider_partial_paid'));
    }

    // ── 5. fiscal identity untouched on partial paths ────────────────────────

    public function test_partial_settle_never_touches_fiscal_identity(): void
    {
        $riderId = $this->makeRider();
        $b1 = $this->makeBill($riderId, ['total_amount' => 500, 'created_at' => now()->subHour(),
            'invoice_number' => 'POS-2026-00001', 'pra_invoice_number' => 'PRA-111']);
        $b2 = $this->makeBill($riderId, ['total_amount' => 400,
            'invoice_number' => 'POS-2026-00002', 'pra_invoice_number' => 'PRA-222']);

        $before = [$b1 => $this->tx($b1), $b2 => $this->tx($b2)];

        $this->settle($riderId, ['settle_all' => 1, 'received_amount' => 650]);

        foreach ($before as $id => $old) {
            $tx = $this->tx($id);
            $this->assertSame($old->invoice_mode, $tx->invoice_mode);
            $this->assertSame($old->pra_status, $tx->pra_status);
            $this->assertSame($old->invoice_number, $tx->invoice_number);
            $this->assertSame($old->pra_invoice_number, $tx->pra_invoice_number);
            $this->assertSame($old->status, $tx->status);
            $this->assertSame((float) $old->total_amount, (float) $tx->total_amount);
        }
    }

    // ── 6. selected-bills partial (bill_ids path, not settle_all) ────────────

    public function test_partial_with_selected_bill_ids_respects_selection_outstanding(): void
    {
        $riderId = $this->makeRider();
        $selected = $this->makeBill($riderId, ['total_amount' => 500, 'created_at' => now()->subHour()]);
        $unselected = $this->makeBill($riderId, ['total_amount' => 400]);

        // Over the SELECTION's outstanding (500) even though khata is 900 → 422.
        $over = $this->settle($riderId, ['bill_ids' => [$selected], 'received_amount' => 600]);
        $this->assertSame(422, $over->getStatusCode());

        // Partial 200 against the selected bill only.
        $response = $this->settle($riderId, ['bill_ids' => [$selected], 'received_amount' => 200]);
        $data = $response->getData(true);
        $this->assertTrue($data['success']);
        $this->assertSame(0, $data['bill_count']);
        $this->assertSame(700.0, (float) $data['outstanding_after'], 'whole khata: 300 + 400');
        $this->assertSame(200.0, (float) $this->tx($selected)->rider_partial_paid);
        $this->assertNull($this->tx($selected)->rider_settlement_id);
        $this->assertSame(0.0, (float) $this->tx($unselected)->rider_partial_paid);
    }

    // ── 7. FBR twin ──────────────────────────────────────────────────────────

    public function test_fbr_partial_settlement_same_semantics_panel_fbr(): void
    {
        $riderId = $this->makeRider();
        $t = 'fbr_pos_transactions';

        $oldest = $this->makeBill($riderId, ['total_amount' => 500, 'created_at' => now()->subHours(2)], $t);
        $newest = $this->makeBill($riderId, ['total_amount' => 400, 'created_at' => now()->subHour()], $t);

        $response = $this->settle($riderId, ['settle_all' => 1, 'received_amount' => 650], 'fbr');
        $data = $response->getData(true);

        $this->assertTrue($data['success']);
        $this->assertSame(650.0, (float) $data['total_amount']);
        $this->assertSame(1, $data['bill_count']);
        $this->assertSame(250.0, (float) $data['outstanding_after']);

        $this->assertNotNull($this->tx($oldest, $t)->rider_settlement_id);
        $this->assertNull($this->tx($newest, $t)->rider_settlement_id);
        $this->assertSame(150.0, (float) $this->tx($newest, $t)->rider_partial_paid);

        $s = DB::table('pos_rider_settlements')->first();
        $this->assertSame('fbr', $s->panel);
        $this->assertSame(650.0, (float) $s->total_amount);
        $this->assertSame(250.0, (float) $s->outstanding_after);

        // Fiscal fields untouched.
        $this->assertSame('submitted', $this->tx($oldest, $t)->fbr_status);
        $this->assertSame('FBR-XYZ', $this->tx($oldest, $t)->fbr_invoice_number);

        // Carry-forward on FBR too.
        $response2 = $this->settle($riderId, ['settle_all' => 1], 'fbr');
        $this->assertSame(250.0, (float) $response2->getData(true)['total_amount']);
        $this->assertNotNull($this->tx($newest, $t)->rider_settlement_id);
        $this->assertSame(900.0, (float) DB::table('pos_rider_settlements')->sum('total_amount'));
    }
}
