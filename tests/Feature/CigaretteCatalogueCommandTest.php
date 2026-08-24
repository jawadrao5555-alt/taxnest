<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * di:cigarette-catalogue seeds a DI distributor's product catalogue.
 *
 * The things worth pinning are the ones that would silently mis-file an
 * invoice rather than throw:
 *   - UoM must be "Thousand Unit" (a pack-based UoM overstates volume 50x),
 *   - HS 2402.2000 on the 3rd Schedule with an MRP, because 3rd Schedule tax
 *     is charged on MRP and not on the sale price,
 *   - re-running must not duplicate the catalogue.
 */
class CigaretteCatalogueCommandTest extends TestCase
{
    private const COMPANY_ID = 3301;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->default('di');
            $t->string('status')->default('approved');
            $t->string('company_status')->default('active');
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name');
            $t->string('barcode')->nullable();
            $t->string('sku')->nullable();
            $t->string('hs_code')->nullable();
            $t->string('pct_code')->nullable();
            $t->decimal('default_tax_rate', 5, 2)->default(18);
            $t->string('tax_type', 20)->default('taxable');
            $t->string('uom')->default('PCS');
            $t->string('schedule_type')->nullable();
            $t->string('sro_reference')->nullable();
            $t->string('serial_number', 100)->nullable();
            $t->decimal('mrp', 14, 2)->nullable();
            $t->decimal('default_price', 15, 2)->default(0);
            $t->boolean('is_price_editable')->default(true);
            $t->boolean('is_active')->default(true);
            $t->boolean('show_on_sale')->default(true);
            $t->boolean('is_third_schedule')->default(false);
            $t->integer('pack_size')->nullable();
            $t->timestamps();
        });

        DB::table('companies')->insert([
            'id' => self::COMPANY_ID,
            'name' => 'Cigarette Distributor',
            'product_type' => 'di',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function catalogue()
    {
        return DB::table('products')->where('company_id', self::COMPANY_ID);
    }

    public function test_it_seeds_the_catalogue_in_thousand_unit_on_the_third_schedule(): void
    {
        $this->artisan('di:cigarette-catalogue', ['company' => self::COMPANY_ID])
            ->assertExitCode(0);

        $products = $this->catalogue()->get();
        $this->assertCount(8, $products);

        foreach ($products as $product) {
            $this->assertSame('Thousand Unit', $product->uom, "{$product->name} is not in Thousand Unit.");
            $this->assertSame('2402.2000', $product->hs_code);
            $this->assertSame('3rd_schedule', $product->schedule_type);
            $this->assertEquals(1, $product->is_third_schedule);
            $this->assertEquals(18, (float) $product->default_tax_rate);
            $this->assertGreaterThan(0, (float) $product->default_price, "{$product->name} has no price.");
            $this->assertGreaterThan(
                (float) $product->default_price,
                (float) $product->mrp,
                "{$product->name}: 3rd Schedule tax rides MRP, so MRP must exceed the ex-tax price."
            );
        }
    }

    public function test_the_owner_supplied_rate_survives_the_conversion_to_thousand_unit(): void
    {
        $this->artisan('di:cigarette-catalogue', ['company' => self::COMPANY_ID]);

        // Red & White Special: 12,880 per Thousand Unit => 257.60 per pack.
        $product = $this->catalogue()->where('name', 'Red & White Special')->first();
        $this->assertNotNull($product);

        // Ex-tax price is the gross rate less the ~17.8% tax component.
        $this->assertEqualsWithDelta(10586.96, (float) $product->default_price, 0.05);
        // 18% of MRP must reproduce the sales tax the DMS charges on that value.
        $this->assertEqualsWithDelta(0.18 * (float) $product->mrp, 1978.99, 1.0);
    }

    public function test_rerunning_updates_instead_of_duplicating(): void
    {
        $this->artisan('di:cigarette-catalogue', ['company' => self::COMPANY_ID]);
        $this->catalogue()->where('name', 'Morven')->update(['default_price' => 1]);

        $this->artisan('di:cigarette-catalogue', ['company' => self::COMPANY_ID]);

        $this->assertSame(8, $this->catalogue()->count(), 'Re-running duplicated the catalogue.');
        $this->assertGreaterThan(
            1,
            (float) $this->catalogue()->where('name', 'Morven')->value('default_price'),
            'Re-running did not restore the rate card.'
        );
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->artisan('di:cigarette-catalogue', ['company' => self::COMPANY_ID, '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertSame(0, $this->catalogue()->count());
    }

    public function test_an_unknown_company_fails_loudly(): void
    {
        $this->artisan('di:cigarette-catalogue', ['company' => 999999])
            ->assertExitCode(1);
    }
}
