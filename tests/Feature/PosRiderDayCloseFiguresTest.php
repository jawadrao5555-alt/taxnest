<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * DAY-CLOSE RIDER FIGURES with PARTIAL RECEIPTS (Task 533, locks Task 525).
 *
 * buildRiderDayFigures() ka contract:
 *   - cash_out / cash_pending = TODAY's rider cash bills ka REMAINING
 *     (total_amount - rider_partial_paid) — partial cash drawer mein aa chuka.
 *   - cash_in HYBRID hai:
 *       (a) legacy bill-based: sirf un settlements ke liye jin ki allocation
 *           NULL hai (pre-Task-525 rows) — bill ka poora total_amount ginta
 *           hai jab bill aaj settle hua aur business_date < aaj.
 *       (b) allocation-based: naye settlement rows (allocation JSON) mein se
 *           SIRF wo entries jin ka business_date < aaj — exact received rupees.
 *     Dono ek hi bill ko kabhi double-count nahi karte (legacy query
 *     allocation-wale settlements ke bills EXCLUDE karta hai).
 *
 * Pattern: sqlite :memory:, minimal Schema::create, private method via
 * reflection — same approach as PosRiderPartialSettlementTest.
 */
class PosRiderDayCloseFiguresTest extends TestCase
{
    private const COMPANY = 7;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

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
            // Task 525 columns
            $table->decimal('outstanding_after', 12, 2)->nullable();
            $table->text('allocation')->nullable();
            $table->string('panel', 10)->nullable();
            $table->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number');
            $table->string('status')->default('completed');
            $table->string('invoice_mode')->nullable();
            $table->string('pra_status')->nullable();
            $table->string('payment_method')->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->boolean('is_archived')->default(false);
            $table->date('business_date')->nullable();
            $table->unsignedBigInteger('rider_id')->nullable();
            $table->string('delivery_status')->nullable();
            $table->unsignedBigInteger('rider_settlement_id')->nullable();
            $table->timestamp('rider_settled_at')->nullable();
            // Task 525 column
            $table->decimal('rider_partial_paid', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function makeRider(string $name = 'Qaisar'): int
    {
        return (int) DB::table('pos_riders')->insertGetId([
            'company_id' => self::COMPANY,
            'name' => $name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeBill(int $riderId, string $businessDate, array $attrs = []): int
    {
        return (int) DB::table('pos_transactions')->insertGetId(array_merge([
            'company_id' => self::COMPANY,
            'invoice_number' => 'POS-2026-' . uniqid(),
            'status' => 'completed',
            'invoice_mode' => 'pra',
            'pra_status' => 'submitted',
            'payment_method' => 'cash',
            'total_amount' => 500.00,
            'is_archived' => false,
            'business_date' => $businessDate,
            'rider_id' => $riderId,
            'delivery_status' => 'dispatched',
            'rider_settlement_id' => null,
            'rider_settled_at' => null,
            'rider_partial_paid' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    /** Insert a settlement row; allocation = null (legacy) or array (Task 525). */
    private function makeSettlement(int $riderId, float $received, ?array $allocation, ?string $panel = 'pra'): int
    {
        return (int) DB::table('pos_rider_settlements')->insertGetId([
            'company_id' => self::COMPANY,
            'rider_id' => $riderId,
            'total_amount' => $received,
            'bill_count' => $allocation ? count(array_filter($allocation, fn ($a) => !empty($a['full']))) : 1,
            'allocation' => $allocation === null ? null : json_encode(array_map(
                fn ($a) => ['bill_id' => $a['bill_id'], 'amount' => $a['amount'], 'business_date' => $a['business_date']],
                $allocation
            )),
            'panel' => $panel,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Invoke the private buildRiderDayFigures via reflection. */
    private function figures(string $date): array
    {
        $controller = app(\App\Http\Controllers\PosController::class);
        $m = new \ReflectionMethod($controller, 'buildRiderDayFigures');
        $m->setAccessible(true);
        return $m->invoke($controller, self::COMPANY, $date);
    }

    // ── 1. today's partial receipt against EARLIER-day bills ────────────────
    // cash_in = sirf received rupees (700), kabhi bill face value (900) nahi;
    // earlier-day bills aaj ke cash_out mein nahi aate (day-scoped by design).

    public function test_partial_receipt_against_earlier_day_bills_counts_only_received_cash(): void
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $riderId = $this->makeRider();

        // Yesterday's khata: 500 + 400. Today rider hands over 700:
        // b1 fully settled, b2 gets 200 partial (stays open).
        $b1 = $this->makeBill($riderId, $yesterday, ['total_amount' => 500]);
        $b2 = $this->makeBill($riderId, $yesterday, ['total_amount' => 400]);

        $sid = $this->makeSettlement($riderId, 700, [
            ['bill_id' => $b1, 'amount' => 500, 'business_date' => $yesterday, 'full' => true],
            ['bill_id' => $b2, 'amount' => 200, 'business_date' => $yesterday],
        ]);
        DB::table('pos_transactions')->where('id', $b1)
            ->update(['rider_settlement_id' => $sid, 'rider_settled_at' => now()]);
        DB::table('pos_transactions')->where('id', $b2)
            ->update(['rider_partial_paid' => 200]);

        $f = $this->figures($today);

        $this->assertTrue($f['active'], 'cash_in alone must activate the section');
        $this->assertSame(700.0, $f['cash_in'], 'exact rupees received today, NOT bill face value');
        $this->assertSame(0.0, $f['cash_out'], "earlier-day bills never join today's cash_out");
        // b1 is settled + has business_date < today: the legacy bill-based
        // query MUST exclude it (its settlement has allocation) or cash_in
        // would double to 1200.
        $this->assertNotSame(1200.0, $f['cash_in'], 'legacy+allocation double-count guard');
    }

    // ── 2. same-day partial: cash_in 0, cash_out = remaining ────────────────

    public function test_same_day_partial_keeps_cash_in_zero_and_cash_out_remaining(): void
    {
        $today = now()->toDateString();
        $riderId = $this->makeRider();

        // Today's bill 500; rider hands over 200 mid-day (partial).
        $bill = $this->makeBill($riderId, $today, ['total_amount' => 500]);
        $this->makeSettlement($riderId, 200, [
            ['bill_id' => $bill, 'amount' => 200, 'business_date' => $today],
        ]);
        DB::table('pos_transactions')->where('id', $bill)->update(['rider_partial_paid' => 200]);

        $f = $this->figures($today);

        $this->assertTrue($f['active']);
        $this->assertSame(0.0, $f['cash_in'], "same-day allocation (business_date == today) is NOT cash_in");
        $this->assertSame(300.0, $f['cash_out'], 'remaining-based: 500 - 200 partial already in drawer');
        $this->assertCount(1, $f['riders']);
        $this->assertSame(300.0, $f['riders'][0]['cash_pending'], 'per-rider pending = remaining too');
        $this->assertSame(500.0, $f['riders'][0]['cash_total'], 'cash_total stays face value');
    }

    // ── 3. pre-feature (NULL allocation) settlement stays legacy bill-based ──

    public function test_legacy_null_allocation_settlement_counts_bill_totals(): void
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $riderId = $this->makeRider();

        // Pre-feature full settle today of yesterday's 450 bill: settlement row
        // has NO allocation — legacy query must count the bill's total_amount.
        $bill = $this->makeBill($riderId, $yesterday, ['total_amount' => 450]);
        $sid = $this->makeSettlement($riderId, 450, null);
        DB::table('pos_transactions')->where('id', $bill)
            ->update(['rider_settlement_id' => $sid, 'rider_settled_at' => now()]);

        $f = $this->figures($today);

        $this->assertTrue($f['active']);
        $this->assertSame(450.0, $f['cash_in'], 'legacy bill-based cash_in preserved');
        $this->assertSame(0.0, $f['cash_out']);
    }

    // ── 4. transition day: legacy + allocation settlements together, no dupes ─

    public function test_mixed_legacy_and_allocation_settlements_never_double_count(): void
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $riderId = $this->makeRider();

        // Legacy full settle (NULL allocation) of a 300 bill.
        $legacyBill = $this->makeBill($riderId, $yesterday, ['total_amount' => 300]);
        $legacySid = $this->makeSettlement($riderId, 300, null);
        DB::table('pos_transactions')->where('id', $legacyBill)
            ->update(['rider_settlement_id' => $legacySid, 'rider_settled_at' => now()]);

        // New partial settle: 250 received against yesterday's 400 bill.
        $newBill = $this->makeBill($riderId, $yesterday, ['total_amount' => 400]);
        $this->makeSettlement($riderId, 250, [
            ['bill_id' => $newBill, 'amount' => 250, 'business_date' => $yesterday],
        ]);
        DB::table('pos_transactions')->where('id', $newBill)->update(['rider_partial_paid' => 250]);

        $f = $this->figures($today);

        $this->assertSame(550.0, $f['cash_in'], '300 legacy + 250 allocation, exactly once each');
    }

    // ── 5. non-PRA panel / local bills stay out of the recon ─────────────────

    public function test_fbr_panel_settlements_and_local_bills_are_excluded(): void
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $riderId = $this->makeRider();

        // FBR-panel allocation settlement must NOT leak into PRA cash_in.
        $fbrBill = $this->makeBill($riderId, $yesterday, ['total_amount' => 600]);
        $this->makeSettlement($riderId, 600, [
            ['bill_id' => $fbrBill, 'amount' => 600, 'business_date' => $yesterday, 'full' => true],
        ], 'fbr');

        // Local provisional cash bill today must NOT join cash_out (PRA-set-only).
        $this->makeBill($riderId, $today, [
            'total_amount' => 999, 'invoice_mode' => 'local', 'pra_status' => 'local',
        ]);

        $f = $this->figures($today);

        $this->assertSame(0.0, $f['cash_in'], "panel='fbr' settlements excluded");
        $this->assertSame(0.0, $f['cash_out'], 'local provisionals excluded from PRA recon');
    }
}
