<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use App\Http\Controllers\RestaurantPosController;
use App\Services\PosFinalSeries;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Short PRA final bill number "P-036" (owner, 25 Aug 2026: "bill number chhota
 * karo taake find out kar sakein").
 *
 * Locked here:
 *  1. A fresh shop's first final is P-001, and BOTH sale paths (retail +
 *     restaurant pay) issue from the same series.
 *  2. CONTINUITY: a shop already sitting on legacy POS-2026-00035 continues at
 *     P-036 — legacy rows still reserve their number and are never renumbered.
 *  3. MONOTONIC: a number is never re-issued after its bill is deleted (day
 *     close deletes reporting-OFF finals) or archived.
 *  4. Both formats stay FINAL serials for the PRA/local stream split, and the
 *     local L-series is never mistaken for one.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create.
 */
class PosFinalSeriesShortNumberTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('pos_final_series_counters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->unique();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number');
            $table->string('status')->nullable();
            $table->string('invoice_mode')->nullable();
            $table->string('pra_status')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamps();
        });
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function makeCompany(string $name = 'Frost and Brew'): int
    {
        return (int) DB::table('companies')->insertGetId([
            'name' => $name, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeBill(int $companyId, string $number, array $attrs = []): int
    {
        return (int) DB::table('pos_transactions')->insertGetId(array_merge([
            'company_id' => $companyId,
            'invoice_number' => $number,
            'status' => 'completed',
            'invoice_mode' => 'pra',
            'pra_status' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    private function nextRetail(int $companyId): string
    {
        return $this->callPrivate(new PosController(), 'generateInvoiceNumber', [$companyId]);
    }

    private function nextRestaurant(int $companyId): string
    {
        return $this->callPrivate(new RestaurantPosController(), 'generateInvoiceNumber', [$companyId]);
    }

    private function callPrivate(object $target, string $method, array $args)
    {
        $ref = new \ReflectionMethod($target, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($target, $args);
    }

    // ── 1. the short number itself ───────────────────────────────────────────

    public function test_first_final_of_a_fresh_shop_is_p_001(): void
    {
        $cid = $this->makeCompany();

        $this->assertSame('P001', PosFinalSeries::previewNext($cid));
        $this->assertSame('P001', $this->nextRetail($cid));
    }

    public function test_both_sale_paths_share_one_series(): void
    {
        $cid = $this->makeCompany();

        $this->assertSame('P001', $this->nextRetail($cid));
        $this->makeBill($cid, 'P001');
        // The restaurant pay path continues the SAME series, never its own.
        $this->assertSame('P002', $this->nextRestaurant($cid));
        $this->makeBill($cid, 'P002');
        $this->assertSame('P003', $this->nextRetail($cid));
    }

    public function test_each_shop_counts_on_its_own(): void
    {
        $a = $this->makeCompany('Shop A');
        $b = $this->makeCompany('Shop B');
        $this->makeBill($a, 'P001');
        $this->makeBill($a, 'P002');

        $this->assertSame('P003', $this->nextRetail($a));
        $this->assertSame('P001', $this->nextRetail($b));
    }

    // ── 2. continuity with the old long serial ───────────────────────────────

    public function test_shop_on_the_old_long_serial_continues_where_it_stopped(): void
    {
        $cid = $this->makeCompany();
        $this->makeBill($cid, 'POS-2026-00034');
        $this->makeBill($cid, 'POS-2026-00035');

        $this->assertSame('P036', $this->nextRetail($cid));
        // …and the old bills keep their own numbers, untouched.
        $this->assertSame(
            ['POS-2026-00034', 'POS-2026-00035'],
            DB::table('pos_transactions')->where('invoice_number', 'like', 'POS-%')
                ->orderBy('invoice_number')->pluck('invoice_number')->all()
        );
    }

    public function test_legacy_serials_of_earlier_years_still_reserve_their_numbers(): void
    {
        $cid = $this->makeCompany();
        // Last year's series ran higher than this year's — the shared counter has
        // to clear the highest number in ANY year, or a short number collides
        // with an old bill on UNIQUE(company_id, invoice_number).
        $this->makeBill($cid, 'POS-2025-00120');
        $this->makeBill($cid, 'POS-2026-00003');

        $this->assertSame('P121', $this->nextRetail($cid));
    }

    public function test_archived_finals_still_hold_their_number(): void
    {
        $cid = $this->makeCompany();
        $this->makeBill($cid, 'P007', ['is_archived' => true]);

        $this->assertSame('P008', $this->nextRetail($cid));
    }

    // ── 3. monotonic across day-close deletes ────────────────────────────────

    public function test_deleted_finals_never_hand_their_number_to_a_new_bill(): void
    {
        $cid = $this->makeCompany();
        $this->assertSame('P001', $this->nextRetail($cid));
        $one = $this->makeBill($cid, 'P001');
        $this->assertSame('P002', $this->nextRetail($cid));
        $two = $this->makeBill($cid, 'P002');

        // Day close under the delete policy wipes both reporting-OFF finals.
        DB::table('pos_transactions')->whereIn('id', [$one, $two])->delete();

        $this->assertSame('P003', $this->nextRetail($cid), 'a short number must never point at two different sales');
    }

    /**
     * The counter advance must ride INSIDE the caller's sale transaction: every
     * sale path allocates within its own DB transaction, so a bill that fails
     * half-way (card machine, validation, deadlock) gives its number back
     * instead of burning it. This is also what makes the row lock real — an
     * allocation taken outside a transaction holds no lock at all on MySQL and
     * two cashiers could be handed the same serial.
     */
    public function test_a_failed_sale_gives_its_number_back(): void
    {
        $cid = $this->makeCompany();
        $this->makeBill($cid, 'P001');

        try {
            DB::transaction(function () use ($cid) {
                PosFinalSeries::issueNext($cid); // P002 reserved …
                throw new \RuntimeException('card machine died mid-sale');
            });
            $this->fail('the sale transaction should have thrown');
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertSame('P002', $this->nextRetail($cid), 'a rolled-back sale must not burn a number');
    }

    public function test_preview_never_advances_the_counter(): void
    {
        $cid = $this->makeCompany();
        $this->makeBill($cid, 'P005');

        $this->assertSame('P006', PosFinalSeries::previewNext($cid));
        $this->assertSame('P006', PosFinalSeries::previewNext($cid));
        $this->assertSame('P006', $this->nextRetail($cid));
    }

    public function test_pad_grows_past_999_without_reusing_numbers(): void
    {
        $cid = $this->makeCompany();
        $this->makeBill($cid, 'P999');

        $this->assertSame('P1000', $this->nextRetail($cid));
    }

    // ── 4. what counts as a final serial ─────────────────────────────────────

    public function test_short_and_legacy_serials_both_count_as_final(): void
    {
        $this->assertTrue(PosFinalSeries::isFinalSerial('P036'));
        $this->assertTrue(PosFinalSeries::isFinalSerial('P1000'));
        // Dashed bills issued before the dash was dropped stay valid serials.
        $this->assertTrue(PosFinalSeries::isFinalSerial('P-036'));
        $this->assertTrue(PosFinalSeries::isFinalSerial('POS-2026-00035'));

        $this->assertSame(36, PosFinalSeries::serialOf('P036'));
        $this->assertSame(36, PosFinalSeries::serialOf('P-036'));
        $this->assertSame(35, PosFinalSeries::serialOf('POS-2026-00035'));
    }

    /**
     * The read-only verifier command must read a mixed history the same way the
     * sale path does — highest number wins, not the newest row, and stray text
     * reserves nothing. Otherwise the one tool used to audit live shops reports
     * a number the shop will never see.
     */
    public function test_verifier_reads_a_mixed_history_like_the_sale_path(): void
    {
        $cid = $this->makeCompany();
        $this->makeBill($cid, 'POS-2025-00120'); // legacy long serial
        $this->makeBill($cid, 'P-999');          // dashed short serial
        $this->makeBill($cid, 'P001');           // …and a LATER, lower one
        $this->makeBill($cid, 'P-ABC');          // stray text: reserves nothing

        $command = new \App\Console\Commands\VerifyDiSerials();
        $derive = new \ReflectionMethod($command, 'nextDerived');
        $derive->setAccessible(true);

        $derived = $derive->invoke($command, 'pos_transactions', $cid, [
            'P', 3, ['P%', 'POS-%'], ['/^P-?(\d+)$/', '/^POS-\d{4}-(\d+)$/'],
        ]);

        $this->assertSame('P1000', $derived);
        $this->assertSame(PosFinalSeries::previewNext($cid), $derived, 'the verifier must agree with the issuing series');
    }

    /**
     * A shop that already billed on the dashed spelling keeps those numbers and
     * counts on above them — one number may never reach two sales.
     */
    public function test_dashed_finals_still_reserve_their_number(): void
    {
        $cid = $this->makeCompany();
        $this->makeBill($cid, 'P-041');

        $this->assertSame('P042', $this->nextRetail($cid));
    }

    public function test_local_and_stray_numbers_are_not_final_serials(): void
    {
        // A local bill must never be read as PRA-bound.
        $this->assertFalse(PosFinalSeries::isFinalSerial('L015'));
        $this->assertFalse(PosFinalSeries::isFinalSerial('LOCAL-2026-00007'));
        $this->assertFalse(PosFinalSeries::isFinalSerial('P-ABC'));
        $this->assertFalse(PosFinalSeries::isFinalSerial('FPOS-2026-00001'));
        $this->assertFalse(PosFinalSeries::isFinalSerial(''));
        $this->assertFalse(PosFinalSeries::isFinalSerial(null));

        $this->assertNull(PosFinalSeries::serialOf('L015'));
    }

    public function test_local_bills_do_not_move_the_final_series(): void
    {
        $cid = $this->makeCompany();
        $this->makeBill($cid, 'L045', ['invoice_mode' => 'local', 'pra_status' => 'local']);

        $this->assertSame('P001', $this->nextRetail($cid));
    }
}
