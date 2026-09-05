<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Services\PharmacyBatchService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Pharmacy Mode (FBR panel) — stock-integrity rules that money depends on.
 *
 * A medical store's batch sub-ledger has to agree with the aggregate it sits
 * under, forever. Three ways it can silently stop agreeing, all of which
 * corrupt the expiry and distributor-claim reports rather than throwing:
 *
 *   1. A hand-edited batch id on the sale request drawing stock off some OTHER
 *      medicine (or another branch) while the aggregate deduction happens on
 *      the product actually being sold.
 *   2. A line sold from two batches, returned twice, restoring the same batch
 *      both times — dated stock the shop never had, appearing in the claim list.
 *   3. A loose (broken-strip) sale measured against a strip instead of the
 *      stocked pack, so a box of 10×10 sells 3 tablets as three-tenths of a box.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' CACHE_STORE=array php vendor/bin/phpunit \
 *     tests/Feature/FbrPosPharmacyBatchIntegrityTest.php --testdox
 */
class FbrPosPharmacyBatchIntegrityTest extends TestCase
{
    private const COMPANY = 4001;
    private const BRANCH = 7;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildTables();
    }

    /** Only the handful of tables these rules touch — the full migration set is not needed. */
    private function buildTables(): void
    {
        Schema::dropIfExists('product_batches');
        Schema::dropIfExists('inventory_stocks');
        Schema::dropIfExists('products');

        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name');
            $t->boolean('is_active')->default(true);
            $t->boolean('allow_loose_sale')->default(false);
            $t->unsignedInteger('strips_per_pack')->nullable();
            $t->unsignedInteger('units_per_strip')->nullable();
            $t->timestamps();
        });

        Schema::create('inventory_stocks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->decimal('quantity', 15, 4)->default(0);
            $t->timestamps();
        });

        Schema::create('product_batches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->string('batch_number')->nullable();
            $t->date('expiry_date')->nullable();
            $t->decimal('quantity', 15, 4)->default(0);
            $t->decimal('cost_price', 15, 4)->default(0);
            $t->decimal('retail_price', 15, 4)->default(0);
            $t->unsignedBigInteger('supplier_id')->nullable();
            $t->unsignedBigInteger('purchase_order_id')->nullable();
            $t->string('status')->default(ProductBatch::STATUS_ACTIVE);
            $t->date('received_at')->nullable();
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
    }

    private function medicine(array $attrs = []): Product
    {
        $p = new Product();
        $p->forceFill(array_merge([
            'company_id' => self::COMPANY,
            'name' => 'Panadol 500mg',
            'is_active' => true,
        ], $attrs));
        $p->save();

        return $p;
    }

    private function stock(int $productId, float $qty, ?int $branchId = self::BRANCH): void
    {
        \DB::table('inventory_stocks')->insert([
            'company_id' => self::COMPANY,
            'product_id' => $productId,
            'branch_id' => $branchId,
            'quantity' => $qty,
        ]);
    }

    private function batch(int $productId, string $number, string $expiry, float $qty, ?int $branchId = self::BRANCH): ProductBatch
    {
        return ProductBatch::create([
            'company_id' => self::COMPANY,
            'product_id' => $productId,
            'branch_id' => $branchId,
            'batch_number' => $number,
            'expiry_date' => $expiry,
            'quantity' => $qty,
            'status' => ProductBatch::STATUS_ACTIVE,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  1. A forged batch id may not reach another product or another branch
    // ─────────────────────────────────────────────────────────────────────

    public function test_a_forged_batch_id_from_another_product_is_refused(): void
    {
        $sold = $this->medicine(['name' => 'Panadol']);
        $other = $this->medicine(['name' => 'Augmentin']);
        $this->stock($sold->id, 50);
        $this->stock($other->id, 50);

        $this->batch($sold->id, 'PAN-1', now()->addYear()->toDateString(), 20);
        $foreign = $this->batch($other->id, 'AUG-9', now()->addYear()->toDateString(), 20);

        $plan = PharmacyBatchService::planAllocation(
            self::COMPANY, $sold, self::BRANCH, 5, $foreign->id
        );

        $this->assertNotNull($plan['error'], 'Another product\'s batch must not be allocatable.');
        $this->assertSame([], $plan['allocations']);
        $this->assertSame(20.0, (float) $foreign->fresh()->quantity, 'The foreign batch must be untouched.');
    }

    public function test_a_forged_batch_id_from_another_branch_is_refused(): void
    {
        $med = $this->medicine();
        $this->stock($med->id, 50);
        $this->batch($med->id, 'HERE-1', now()->addYear()->toDateString(), 20);
        $otherBranch = $this->batch($med->id, 'THERE-1', now()->addYear()->toDateString(), 20, self::BRANCH + 1);

        $plan = PharmacyBatchService::planAllocation(
            self::COMPANY, $med, self::BRANCH, 5, $otherBranch->id
        );

        $this->assertNotNull($plan['error'], 'Another branch\'s batch must not be allocatable.');
        $this->assertSame([], $plan['allocations']);
        $this->assertSame(20.0, (float) $otherBranch->fresh()->quantity);
    }

    public function test_a_forged_batch_id_from_another_company_is_refused(): void
    {
        $med = $this->medicine();
        $this->stock($med->id, 50);
        $foreign = ProductBatch::create([
            'company_id' => self::COMPANY + 1,
            'product_id' => $med->id,
            'branch_id' => self::BRANCH,
            'batch_number' => 'OTHERCO-1',
            'expiry_date' => now()->addYear()->toDateString(),
            'quantity' => 20,
            'status' => ProductBatch::STATUS_ACTIVE,
        ]);

        $plan = PharmacyBatchService::planAllocation(
            self::COMPANY, $med, self::BRANCH, 5, $foreign->id
        );

        $this->assertNotNull($plan['error']);
        $this->assertSame(20.0, (float) $foreign->fresh()->quantity);
    }

    public function test_a_legitimate_override_on_the_same_product_and_branch_still_works(): void
    {
        $med = $this->medicine();
        $this->stock($med->id, 50);
        $this->batch($med->id, 'SOON-1', now()->addDays(20)->toDateString(), 10);
        $later = $this->batch($med->id, 'LATER-1', now()->addYear()->toDateString(), 10);

        $plan = PharmacyBatchService::planAllocation(
            self::COMPANY, $med, self::BRANCH, 4, $later->id
        );

        $this->assertNull($plan['error']);
        $this->assertCount(1, $plan['allocations']);
        $this->assertSame($later->id, (int) $plan['allocations'][0]['batch_id']);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  2. Repeated partial returns across a split allocation
    // ─────────────────────────────────────────────────────────────────────

    public function test_repeated_partial_returns_never_restore_more_than_was_sold(): void
    {
        $med = $this->medicine();
        $this->stock($med->id, 100);
        $a = $this->batch($med->id, 'A', now()->addDays(30)->toDateString(), 6);
        $b = $this->batch($med->id, 'B', now()->addYear()->toDateString(), 10);

        // Sell 10: FEFO takes all 6 of A, then 4 of B.
        $plan = PharmacyBatchService::planAllocation(self::COMPANY, $med, self::BRANCH, 10);
        PharmacyBatchService::applyAllocation($plan['allocations']);
        $sold = $plan['allocations'];

        $this->assertSame(0.0, (float) $a->fresh()->quantity);
        $this->assertSame(6.0, (float) $b->fresh()->quantity);

        // Three partial returns of 4, 4 and 2 — the same shape a counter produces
        // when a customer brings a strip back on three separate days.
        $restoredSoFar = [];
        foreach ([4, 4, 2] as $qty) {
            $outstanding = PharmacyBatchService::remainingAllocation($sold, $restoredSoFar);
            $restoredSoFar[] = PharmacyBatchService::restoreAllocation($outstanding, $qty);
        }

        $this->assertSame(6.0, (float) $a->fresh()->quantity, 'Batch A must be back to exactly what it held.');
        $this->assertSame(10.0, (float) $b->fresh()->quantity, 'Batch B must be back to exactly what it held.');
    }

    public function test_a_return_beyond_the_line_restores_only_what_is_outstanding(): void
    {
        $med = $this->medicine();
        $this->stock($med->id, 100);
        $a = $this->batch($med->id, 'A', now()->addDays(30)->toDateString(), 5);

        $plan = PharmacyBatchService::planAllocation(self::COMPANY, $med, self::BRANCH, 5);
        PharmacyBatchService::applyAllocation($plan['allocations']);
        $sold = $plan['allocations'];

        $first = PharmacyBatchService::restoreAllocation($sold, 5);
        $this->assertSame(5.0, (float) $a->fresh()->quantity);

        // A second return against a fully-returned line has nothing outstanding.
        $outstanding = PharmacyBatchService::remainingAllocation($sold, [$first]);
        $this->assertSame([], $outstanding);
        $second = PharmacyBatchService::restoreAllocation($outstanding, 5);
        $this->assertSame([], $second);
        $this->assertSame(5.0, (float) $a->fresh()->quantity, 'A repeated return must not mint batch stock.');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  3. Loose sale is measured against the whole stocked pack
    // ─────────────────────────────────────────────────────────────────────

    public function test_a_multi_strip_pack_divides_loose_units_by_the_whole_pack(): void
    {
        $box = $this->medicine([
            'allow_loose_sale' => true,
            'strips_per_pack' => 10,
            'units_per_strip' => 10,
        ]);

        // A box of 10 strips × 10 tablets holds 100 tablets, so 3 tablets is
        // 0.03 of a box — not 0.3, which would take (and charge) ten times as much.
        $this->assertSame(100, $box->looseUnitsPerPack());
        $this->assertSame(0.03, round(3 / $box->looseUnitsPerPack(), 4));
    }

    public function test_a_single_strip_pack_still_divides_by_the_strip(): void
    {
        $strip = $this->medicine([
            'allow_loose_sale' => true,
            'strips_per_pack' => null,
            'units_per_strip' => 10,
        ]);

        $this->assertSame(10, $strip->looseUnitsPerPack());
        $this->assertSame(0.3, round(3 / $strip->looseUnitsPerPack(), 4));
    }

    public function test_a_medicine_with_no_composition_cannot_be_broken_open(): void
    {
        $syrup = $this->medicine(['allow_loose_sale' => true]);

        $this->assertNull($syrup->looseUnitsPerPack());
        $this->assertFalse($syrup->sellsLoose());
    }
}
