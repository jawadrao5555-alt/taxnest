<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * di:cigarette-catalogue seeds a tobacco distributor's product catalogue.
 *
 * The things worth pinning are the ones that would silently mis-file an
 * invoice rather than throw:
 *   - cigarettes must be in "Thousand Unit" (a pack-based UoM overstates
 *     volume 50x, and FBR does not validate the unit against the number),
 *   - HS 2402.2000 on the 3rd Schedule carrying an MRP equal to the sale
 *     value, because that is how the distributor's Annex-A reports the same
 *     goods (Value of Purchases == Value of Fixed/notified),
 *   - HS 2404.9100 sits one digit away but is standard rate with NO MRP, so it
 *     must never inherit the cigarette treatment,
 *   - re-running must normalise every duplicate, must not duplicate the
 *     catalogue, and must not wipe a price the distributor typed in.
 */
class CigaretteCatalogueCommandTest extends TestCase
{
    private const COMPANY_ID = 3301;

    /** Cigarette brands on the 3rd Schedule. */
    private const CIGARETTE_COUNT = 8;

    /** Cigarettes + the three standard-rate pouch lines. */
    private const CATALOGUE_COUNT = 11;

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
            'name' => 'Tobacco Distributor',
            'product_type' => 'di',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function catalogue()
    {
        return DB::table('products')->where('company_id', self::COMPANY_ID);
    }

    public function test_it_seeds_cigarettes_in_thousand_unit_on_the_third_schedule(): void
    {
        $this->artisan('di:cigarette-catalogue', ['company' => self::COMPANY_ID])
            ->assertExitCode(0);

        $cigarettes = $this->catalogue()->where('hs_code', '2402.2000')->get();
        $this->assertCount(self::CIGARETTE_COUNT, $cigarettes);

        foreach ($cigarettes as $product) {
            $this->assertSame('Thousand Unit', $product->uom, "{$product->name} is not in Thousand Unit.");
            $this->assertSame('3rd_schedule', $product->schedule_type);
            $this->assertEquals(1, $product->is_third_schedule);
            $this->assertEquals(18, (float) $product->default_tax_rate);
            $this->assertGreaterThan(0, (float) $product->default_price, "{$product->name} has no price.");
            $this->assertEqualsWithDelta(
                (float) $product->default_price,
                (float) $product->mrp,
                0.01,
                "{$product->name}: the 3rd Schedule notified value is the invoice value, so MRP must equal the rate."
            );
        }
    }

    public function test_the_rate_card_is_stored_per_thousand_unit_not_per_pack(): void
    {
        $this->artisan('di:cigarette-catalogue', ['company' => self::COMPANY_ID]);

        // Red & White Special: 12,880 per Thousand Unit => 257.60 per pack.
        // Storing the per-pack figure here is the 50x mis-filing bug.
        $product = $this->catalogue()->where('name', 'Red & White Special')->first();
        $this->assertNotNull($product);
        $this->assertEqualsWithDelta(12880.00, (float) $product->default_price, 0.01);
        $this->assertEqualsWithDelta(12880.00, (float) $product->mrp, 0.01);
    }

    public function test_a_real_invoice_line_reproduces_the_annex_a_arithmetic(): void
    {
        $this->artisan('di:cigarette-catalogue', ['company' => self::COMPANY_ID]);

        // A real line off the distributor's volume export: 0.04 Ms of Crafted
        // By Marlboro. Value 339.94, and 3rd Schedule tax is 18% of it.
        $rate = (float) $this->catalogue()->where('name', 'Crafted By Marlboro')->value('default_price');
        $value = 0.04 * $rate;

        $this->assertEqualsWithDelta(339.94, $value, 0.01);
        $this->assertEqualsWithDelta(61.19, $value * 0.18, 0.01);
    }

    public function test_nicotine_pouches_are_standard_rate_with_no_mrp(): void
    {
        $this->artisan('di:cigarette-catalogue', ['company' => self::COMPANY_ID]);

        $zyn = $this->catalogue()->where('name', 'ZYN Cool Mint 11mg')->first();
        $this->assertNotNull($zyn, 'The standard-rate pouch line was not seeded.');

        $this->assertSame('2404.9100', $zyn->hs_code);
        $this->assertSame('2404.9100', $zyn->pct_code);
        $this->assertSame('standard', $zyn->schedule_type);
        $this->assertEquals(0, $zyn->is_third_schedule);
        $this->assertEquals(18, (float) $zyn->default_tax_rate);
        $this->assertNull($zyn->mrp, 'Standard rate carries no MRP.');
        $this->assertNull($zyn->sro_reference);
    }

    public function test_every_pouch_variant_is_seeded_at_its_own_per_can_rate(): void
    {
        // The rate is per CAN, so the unit must say pieces. Filing a per-can
        // rate against "Kilograms" would understate the line ~100x and FBR
        // would not warn — the same silent unit trap as the 50x cigarette bug.
        $this->artisan('di:cigarette-catalogue', ['company' => self::COMPANY_ID]);

        $expected = [
            'ZYN Cool Mint 6mg' => 137.00,
            'ZYN Cool Mint 11mg' => 183.80,
            'ZYN Cool Mint 13.5mg' => 230.00,
        ];

        foreach ($expected as $name => $rate) {
            $row = $this->catalogue()->where('name', $name)->first();
            $this->assertNotNull($row, "{$name} was not seeded.");
            $this->assertEqualsWithDelta($rate, (float) $row->default_price, 0.01, "{$name} has the wrong rate.");
            $this->assertSame('Numbers, pieces, units', $row->uom, "{$name} is not priced by the can.");
            $this->assertSame('2404.9100', $row->hs_code);
        }
    }

    public function test_the_unpriced_pouch_placeholder_is_retired_not_deleted(): void
    {
        // Before the variants had rates the catalogue carried one zero-rate
        // "ZYN" row. It must stop being sellable, but deleting it would strand
        // any invoice line already written against it.
        DB::table('products')->insert([
            'company_id' => self::COMPANY_ID,
            'name' => 'ZYN',
            'hs_code' => '2404.9100',
            'schedule_type' => 'standard',
            'uom' => 'Kilograms',
            'default_price' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('di:cigarette-catalogue', ['company' => self::COMPANY_ID]);

        $placeholder = $this->catalogue()->where('name', 'ZYN')->first();
        $this->assertNotNull($placeholder, 'The placeholder was deleted instead of retired.');
        $this->assertEquals(0, $placeholder->is_active, 'The zero-rate placeholder is still sellable.');
    }

    public function test_a_dry_run_does_not_retire_the_placeholder(): void
    {
        DB::table('products')->insert([
            'company_id' => self::COMPANY_ID,
            'name' => 'ZYN',
            'hs_code' => '2404.9100',
            'schedule_type' => 'standard',
            'uom' => 'Kilograms',
            'default_price' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('di:cigarette-catalogue', ['company' => self::COMPANY_ID, '--dry-run' => true]);

        $this->assertEquals(1, $this->catalogue()->where('name', 'ZYN')->value('is_active'));
    }

    public function test_a_pouch_row_previously_seeded_as_third_schedule_loses_its_stale_mrp(): void
    {
        // A hand-entered row can already exist with the cigarette treatment
        // copied onto it. Re-seeding must clear the MRP, not leave it behind.
        DB::table('products')->insert([
            'company_id' => self::COMPANY_ID,
            'name' => 'ZYN Cool Mint 6mg',
            'hs_code' => '2402.2000',
            'schedule_type' => '3rd_schedule',
            'is_third_schedule' => true,
            'mrp' => 999.00,
            'sro_reference' => 'SRO-STALE',
            'default_price' => 500.00,
            'uom' => 'Thousand Unit',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('di:cigarette-catalogue', ['company' => self::COMPANY_ID]);

        $zyn = $this->catalogue()->where('name', 'ZYN Cool Mint 6mg')->first();
        $this->assertSame(1, $this->catalogue()->where('name', 'ZYN Cool Mint 6mg')->count());
        $this->assertNull($zyn->mrp, 'A stale 3rd Schedule MRP survived the re-seed.');
        $this->assertNull($zyn->sro_reference, 'A stale SRO survived the re-seed.');
        $this->assertSame('standard', $zyn->schedule_type);
        $this->assertEquals(0, $zyn->is_third_schedule);
    }

    public function test_a_hand_entered_pouch_price_is_never_wiped_by_a_reseed(): void
    {
        // The pouch rate card comes from the distributor, but a price he
        // corrects in the UI is still his data — re-running must not reset it.
        $this->artisan('di:cigarette-catalogue', ['company' => self::COMPANY_ID]);
        $this->catalogue()->where('name', 'ZYN Cool Mint 6mg')->update(['default_price' => 1450.00]);

        $this->artisan('di:cigarette-catalogue', ['company' => self::COMPANY_ID]);

        $this->assertEqualsWithDelta(
            1450.00,
            (float) $this->catalogue()->where('name', 'ZYN Cool Mint 6mg')->value('default_price'),
            0.01,
            'Re-seeding wiped a price the distributor had entered.'
        );
    }

    public function test_every_duplicate_of_a_name_is_normalised_not_just_the_first(): void
    {
        // There is no unique index on (company_id, name), so a catalogue can
        // hold two rows for one brand. Leaving one behind with the wrong HS
        // code mis-files whichever copy gets picked on the sale screen.
        foreach ([1, 2] as $ignored) {
            DB::table('products')->insert([
                'company_id' => self::COMPANY_ID,
                'name' => 'Morven',
                'hs_code' => '9999.0000',
                'schedule_type' => 'standard',
                'uom' => 'Packs',
                'default_price' => 10.00,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->artisan('di:cigarette-catalogue', ['company' => self::COMPANY_ID]);

        $morvens = $this->catalogue()->where('name', 'Morven')->get();
        $this->assertCount(2, $morvens, 'Seeding should normalise duplicates, not add or remove rows.');

        foreach ($morvens as $row) {
            $this->assertSame('2402.2000', $row->hs_code, 'A duplicate kept its wrong HS code.');
            $this->assertSame('Thousand Unit', $row->uom, 'A duplicate kept a pack-based UoM.');
            $this->assertSame('3rd_schedule', $row->schedule_type);
        }
    }

    public function test_rerunning_updates_instead_of_duplicating(): void
    {
        $this->artisan('di:cigarette-catalogue', ['company' => self::COMPANY_ID]);
        $this->catalogue()->where('name', 'Morven')->update(['default_price' => 1]);

        $this->artisan('di:cigarette-catalogue', ['company' => self::COMPANY_ID]);

        $this->assertSame(self::CATALOGUE_COUNT, $this->catalogue()->count(), 'Re-running duplicated the catalogue.');
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
