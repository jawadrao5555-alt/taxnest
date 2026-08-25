<?php

namespace Tests\Feature;

use App\Console\Commands\ImportSaleAreaInvoices;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The Sale Area importer resolves each export column against the company's own
 * product catalogue.
 *
 * A product can be retired — the unpriced "ZYN" placeholder was deactivated
 * once the three priced variants arrived. Retirement only means anything if
 * the importer stops seeing it: an export column bearing a retired name must
 * hit the missing-product abort, not silently file the retired row's stale
 * rate and UoM. FBR does not validate a unit against a number, so that would
 * fail quietly.
 */
class SaleAreaCatalogueActiveOnlyTest extends TestCase
{
    private const COMPANY_ID = 3311;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name');
            $t->string('hs_code')->nullable();
            $t->string('pct_code')->nullable();
            $t->decimal('default_tax_rate', 5, 2)->default(18);
            $t->string('uom')->default('PCS');
            $t->string('schedule_type')->nullable();
            $t->string('sro_reference')->nullable();
            $t->string('serial_number', 100)->nullable();
            $t->decimal('mrp', 14, 2)->nullable();
            $t->decimal('default_price', 15, 2)->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
    }

    private function insert(string $name, bool $active, float $price): void
    {
        DB::table('products')->insert([
            'company_id' => self::COMPANY_ID,
            'name' => $name,
            'hs_code' => '2404.9100',
            'uom' => 'Kilograms',
            'schedule_type' => 'standard',
            'default_price' => $price,
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function loadCatalogue(): array
    {
        $method = new ReflectionMethod(ImportSaleAreaInvoices::class, 'loadCatalogue');
        $method->setAccessible(true);

        return $method->invoke(app(ImportSaleAreaInvoices::class), self::COMPANY_ID);
    }

    public function test_a_retired_product_is_not_importable(): void
    {
        $this->insert('ZYN', active: false, price: 0.00);

        $this->assertArrayNotHasKey(
            'ZYN',
            $this->loadCatalogue(),
            'A retired zero-rate line is still resolvable, so an export column named ZYN would file it.'
        );
    }

    public function test_sellable_products_are_still_loaded(): void
    {
        $this->insert('ZYN Cool Mint 6mg', active: true, price: 137.00);

        $catalogue = $this->loadCatalogue();

        $this->assertArrayHasKey('ZYN COOL MINT 6MG', $catalogue, 'The catalogue is keyed upper-cased.');
        $this->assertEqualsWithDelta(137.00, (float) $catalogue['ZYN COOL MINT 6MG']['default_price'], 0.01);
    }

    public function test_another_companys_product_never_leaks_in(): void
    {
        DB::table('products')->insert([
            'company_id' => self::COMPANY_ID + 1,
            'name' => 'Marlboro Gold',
            'is_active' => true,
            'default_price' => 26865.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame([], $this->loadCatalogue());
    }
}
