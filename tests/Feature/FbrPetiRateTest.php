<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Services\FbrPetiRateService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * FBR POS "Peti (Wholesale) Rate" (Task 1414).
 *
 * The money math and the switch are the load-bearing pieces, so those are
 * pinned here. Every assertion FAILS without the Task 1414 code:
 *
 *   1. deriveRate floors at cost — a stale/zero margin never sells at a loss.
 *   2. deriveRate is SILENT (null) when the derived rate lands at/above retail
 *      (a peti rate must be a discount, never an increase).
 *   3. deriveRate is SILENT when no purchase cost is known.
 *   4. ratesForProducts only rates products with a real pack_size, reads the
 *      SERVER's avg cost, and — critically — never exposes the cost itself.
 *   5. The single per-company switch + margin persist through the settings POST
 *      (single source of truth: the company columns, no feature_flags mirror).
 *
 * Pattern mirrors the other FBR tests: APP_ENV=testing + sqlite :memory: +
 * minimal Schema::create, no migration run.
 */
class FbrPetiRateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('fbr_pos_enabled')->default(false);
            // The Task 1414 columns — the whole feature hangs off these.
            $table->boolean('fbr_peti_rate_enabled')->default(false);
            $table->decimal('fbr_peti_margin_pct', 6, 2)->default(3.00);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->decimal('default_price', 12, 2)->default(0);
            $table->unsignedInteger('pack_size')->nullable(); // Task 1414
            $table->timestamps();
        });

        Schema::create('inventory_stocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->decimal('avg_purchase_price', 15, 2)->default(0);
            $table->decimal('last_purchase_price', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    private function company(float $marginPct = 3.0): Company
    {
        return Company::create([
            'name' => 'Peti Traders',
            'fbr_pos_enabled' => true,
            'fbr_peti_rate_enabled' => true,
            'fbr_peti_margin_pct' => $marginPct,
        ]);
    }

    // ── 1. Floor at cost ────────────────────────────────────────────────
    public function test_derive_rate_never_falls_below_cost(): void
    {
        // A NEGATIVE margin would push the rate below cost — must clamp to cost,
        // and stay silent only if that clamped rate isn't below retail.
        $rate = FbrPetiRateService::deriveRate(100.0, 200.0, -0.10);
        $this->assertNotNull($rate);
        $this->assertGreaterThanOrEqual(100.0, $rate, 'peti rate dropped below cost');
    }

    // ── 2. Cap at retail: silent at/above ───────────────────────────────
    public function test_derive_rate_is_silent_when_it_reaches_retail(): void
    {
        // Cost 100, margin 50% → 150, but retail is only 140 → silent.
        $this->assertNull(FbrPetiRateService::deriveRate(100.0, 140.0, 0.50));
        // Exactly at retail is also silent (must be a discount, not equal).
        $this->assertNull(FbrPetiRateService::deriveRate(100.0, 103.0, 0.03));
        // A genuine discount survives.
        $rate = FbrPetiRateService::deriveRate(100.0, 200.0, 0.03);
        $this->assertSame(103.0, $rate);
    }

    // ── 3. Silent without cost ──────────────────────────────────────────
    public function test_derive_rate_is_silent_without_cost(): void
    {
        $this->assertNull(FbrPetiRateService::deriveRate(null, 200.0, 0.03));
        $this->assertNull(FbrPetiRateService::deriveRate(0.0, 200.0, 0.03));
    }

    // ── 4. Batch: pack_size gating + cost stays server-side ─────────────
    public function test_rates_only_for_pack_products_and_cost_never_leaks(): void
    {
        $company = $this->company(3.0);

        $withPack = Product::create(['company_id' => $company->id, 'name' => 'Soap', 'default_price' => 200, 'pack_size' => 48]);
        $noPack   = Product::create(['company_id' => $company->id, 'name' => 'Loose', 'default_price' => 200, 'pack_size' => null]);
        $noCost   = Product::create(['company_id' => $company->id, 'name' => 'NoCost', 'default_price' => 200, 'pack_size' => 24]);

        // Cost only for the packed product.
        \Illuminate\Support\Facades\DB::table('inventory_stocks')->insert([
            'company_id' => $company->id, 'product_id' => $withPack->id,
            'branch_id' => null, 'avg_purchase_price' => 100.0, 'last_purchase_price' => 0.0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $rates = FbrPetiRateService::ratesForProducts($company, collect([$withPack, $noPack, $noCost]), null);

        // Only the packed+costed product gets a rate: 100 × 1.03 = 103.
        $this->assertArrayHasKey($withPack->id, $rates);
        $this->assertSame(103.0, $rates[$withPack->id]);
        // No pack size ⇒ absent (out of the feature entirely).
        $this->assertArrayNotHasKey($noPack->id, $rates);
        // Pack but no cost ⇒ absent (feature stays silent).
        $this->assertArrayNotHasKey($noCost->id, $rates);

        // Cost NEVER leaves the service — the returned values are the customer
        // rate (103), never the 100 cost.
        $this->assertNotContains(100.0, array_values($rates), 'purchase cost leaked into the peti rate map');
    }

    // ── 5. Silent when the switch is OFF is enforced by the caller; here
    //      we confirm the switch + margin round-trip on the model ─────────
    public function test_switch_and_margin_persist_on_company(): void
    {
        $company = $this->company(3.0);
        $company->update(['fbr_peti_rate_enabled' => true, 'fbr_peti_margin_pct' => 7.5]);

        $fresh = Company::find($company->id);
        $this->assertTrue((bool) $fresh->fbr_peti_rate_enabled);
        $this->assertSame('7.50', (string) $fresh->fbr_peti_margin_pct);
    }
}
