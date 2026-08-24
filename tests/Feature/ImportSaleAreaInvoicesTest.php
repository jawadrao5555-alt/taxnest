<?php

namespace Tests\Feature;

use App\Console\Commands\ImportSaleAreaInvoices;
use App\Services\ScheduleEngine;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * The DMS "Sale Area" export is a pivot, and every one of its quirks is a way
 * to mis-file a month of sales silently rather than loudly:
 *
 *   - the delivery date is merged across each day's block, so a column with a
 *     blank date cell belongs to the day on its left, not to no day at all,
 *   - a product's header cell is sometimes lost to the same merge, and the
 *     only way back to its identity is the rate row at the bottom,
 *   - each block ends in "Total"/"Amount" columns, and the sheet ends in a
 *     grand-total column with no header AND no rate — counting any of them as
 *     a product would invent sales,
 *   - 87 shops share a registered name with another shop, so the customer
 *     CODE is the buyer identity; grouping by name merges two real shops,
 *   - volumes are already in Thousand Unit. Any conversion here is the 50x
 *     over-filing bug.
 */
class ImportSaleAreaInvoicesTest extends TestCase
{
    private const MARLBORO_RATE = 26865.00;
    private const CRAFTED_RATE = 8498.50;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = $this->buildExport();
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    private function catalogue(bool $withCrafted = true): array
    {
        $catalogue = [
            'MARLBORO GOLD' => [
                'name' => 'Marlboro Gold', 'hs_code' => '2402.2000', 'pct_code' => '2402.2000',
                'uom' => 'Thousand Unit', 'schedule_type' => '3rd_schedule', 'sro_reference' => null,
                'serial_number' => null, 'mrp' => self::MARLBORO_RATE,
                'default_price' => self::MARLBORO_RATE, 'default_tax_rate' => 18,
            ],
        ];

        if ($withCrafted) {
            $catalogue['CRAFTED BY MARLBORO'] = [
                'name' => 'Crafted By Marlboro', 'hs_code' => '2402.2000', 'pct_code' => '2402.2000',
                'uom' => 'Thousand Unit', 'schedule_type' => '3rd_schedule', 'sro_reference' => null,
                'serial_number' => null, 'mrp' => self::CRAFTED_RATE,
                'default_price' => self::CRAFTED_RATE, 'default_tax_rate' => 18,
            ];
        }

        return $catalogue;
    }

    /**
     * A faithful miniature of the real export.
     *
     * Day 1 block: D Marlboro | E Crafted | F Total | G Amount
     * Day 2 block: H Marlboro | I Crafted (header blanked by a merge) | J Total | K Amount
     * Then L, the sheet-wide grand total: no header, no rate.
     */
    private function buildExport(): string
    {
        $day1 = ExcelDate::PHPToExcel(new \DateTime('2026-08-01'));
        $day2 = ExcelDate::PHPToExcel(new \DateTime('2026-08-02'));

        $sheet = (new Spreadsheet())->getActiveSheet();

        $sheet->setCellValue('A1', 'Calendar Date');
        $sheet->setCellValue('D1', $day1);
        // E1..G1 deliberately blank — the merged date must forward-fill.
        $sheet->setCellValue('H1', $day2);
        $sheet->setCellValue('L1', 'Total');

        $sheet->setCellValue('A2', 'Product Description');
        $sheet->setCellValue('D2', 'MARLBORO GOLD');
        $sheet->setCellValue('E2', 'CRAFTED BY MARLBORO');
        $sheet->setCellValue('F2', 'Total');
        $sheet->setCellValue('G2', 'Amount');
        $sheet->setCellValue('H2', 'MARLBORO GOLD');
        // I2 blank — recoverable only from the rate row.
        $sheet->setCellValue('J2', 'Total');
        $sheet->setCellValue('K2', 'Amount');

        $sheet->setCellValue('A3', 'Town Description');
        $sheet->setCellValue('B3', 'Customer Registered Name');
        $sheet->setCellValue('C3', 'Customer Code');
        foreach (['D', 'E', 'F', 'H', 'I', 'J', 'L'] as $col) {
            $sheet->setCellValue($col . '3', 'Volume (Ms)');
        }

        // Two different shops sharing one registered name.
        $sheet->setCellValue('A5', 'AHMEDPUR EAST-B');
        $sheet->setCellValue('B5', 'AAMIR KIRYANA STORE');
        $sheet->setCellValue('C5', 'BHPAHP-04204');
        $sheet->setCellValue('D5', 0.04);
        $sheet->setCellValue('E5', 0.16);
        $sheet->setCellValue('H5', 0.40);
        $sheet->setCellValue('L5', 0.60);

        $sheet->setCellValue('A6', 'AHMEDPUR EAST-B');
        $sheet->setCellValue('B6', 'AAMIR KIRYANA STORE');
        $sheet->setCellValue('C6', 'BHPAHP-04672');
        $sheet->setCellValue('D6', 0.10);
        $sheet->setCellValue('L6', 0.10);

        // DMS exports names HTML-escaped, and blank cells must not become lines.
        $sheet->setCellValue('A7', 'AHMEDPUR EAST-B');
        $sheet->setCellValue('B7', '&#39;-SHAFEEQ KIRYANA STORE');
        $sheet->setCellValue('C7', 'BHPAHP-06064');
        $sheet->setCellValue('I7', 0.04);
        $sheet->setCellValue('L7', 0.04);

        // Row 8 intentionally empty. Row 9 is the rate row.
        $sheet->setCellValue('D9', self::MARLBORO_RATE);
        $sheet->setCellValue('E9', self::CRAFTED_RATE);
        $sheet->setCellValue('H9', self::MARLBORO_RATE);
        $sheet->setCellValue('I9', self::CRAFTED_RATE);

        $path = tempnam(sys_get_temp_dir(), 'salearea') . '.xlsx';
        (new Xlsx($sheet->getParent()))->save($path);

        return $path;
    }

    public function test_two_shops_sharing_a_name_stay_two_invoices(): void
    {
        [$groups] = ImportSaleAreaInvoices::parseSaleArea($this->file, $this->catalogue());

        // Both shops are "AAMIR KIRYANA STORE" on 01-Aug. Grouping by name
        // would merge them into one invoice billed to the wrong buyer.
        $this->assertArrayHasKey('BHPAHP-04204|2026-08-01', $groups);
        $this->assertArrayHasKey('BHPAHP-04672|2026-08-01', $groups);
        $this->assertSame(
            'AAMIR KIRYANA STORE',
            $groups['BHPAHP-04672|2026-08-01']['buyer'],
            'The two same-named shops must both survive as their own group.'
        );
    }

    public function test_one_group_per_shop_per_delivery_day(): void
    {
        [$groups] = ImportSaleAreaInvoices::parseSaleArea($this->file, $this->catalogue());

        $this->assertCount(4, $groups, 'Expected one group per (customer code, delivery date).');
        $this->assertEqualsCanonicalizing([
            'BHPAHP-04204|2026-08-01',
            'BHPAHP-04204|2026-08-02',
            'BHPAHP-04672|2026-08-01',
            'BHPAHP-06064|2026-08-02',
        ], array_keys($groups));
    }

    public function test_a_merged_date_carries_forward_to_the_rest_of_its_block(): void
    {
        [$groups] = ImportSaleAreaInvoices::parseSaleArea($this->file, $this->catalogue());

        // Column E has no date cell of its own; it belongs to 01-Aug.
        $lines = $groups['BHPAHP-04204|2026-08-01']['lines'];
        $this->assertCount(2, $lines);
        $this->assertEqualsCanonicalizing(
            ['MARLBORO GOLD', 'CRAFTED BY MARLBORO'],
            array_column($lines, 'product')
        );
    }

    public function test_a_product_whose_header_was_merged_away_is_recovered_from_its_rate(): void
    {
        [$groups] = ImportSaleAreaInvoices::parseSaleArea($this->file, $this->catalogue());

        // Column I has a blank header; only its rate identifies it.
        $group = $groups['BHPAHP-06064|2026-08-02'];
        $this->assertSame('CRAFTED BY MARLBORO', $group['lines'][0]['product']);
        $this->assertSame("'-SHAFEEQ KIRYANA STORE", $group['buyer'], 'The HTML-escaped shop name was not decoded.');
    }

    public function test_total_and_amount_columns_never_become_sales(): void
    {
        [$groups] = ImportSaleAreaInvoices::parseSaleArea($this->file, $this->catalogue());

        // Column L holds each shop's month total and has no rate. If it were
        // read as a product the month would be over-filed.
        $everyLine = [];
        foreach ($groups as $group) {
            foreach ($group['lines'] as $line) {
                $everyLine[] = $line['product'];
            }
        }

        // 3 lines for BHPAHP-04204, 1 for BHPAHP-04672, 1 for BHPAHP-06064.
        $this->assertSame(5, count($everyLine));
        foreach ($everyLine as $product) {
            $this->assertContains($product, ['MARLBORO GOLD', 'CRAFTED BY MARLBORO']);
        }
    }

    public function test_volumes_are_taken_as_thousand_unit_untouched(): void
    {
        [$groups] = ImportSaleAreaInvoices::parseSaleArea($this->file, $this->catalogue());

        $line = $groups['BHPAHP-04204|2026-08-02']['lines'][0];

        // 0.40 Ms, NOT 20 packs. 0.40 x 26,865 = 10,746 and 18% of that.
        $this->assertEqualsWithDelta(0.40, $line['quantity'], 0.0001);
        $this->assertEqualsWithDelta(10746.00, $line['quantity'] * self::MARLBORO_RATE, 0.01);
        $this->assertEqualsWithDelta(1934.28, $line['quantity'] * self::MARLBORO_RATE * 0.18, 0.01);
    }

    public function test_a_product_missing_from_the_catalogue_aborts_instead_of_being_dropped(): void
    {
        [$groups, $missing] = ImportSaleAreaInvoices::parseSaleArea($this->file, $this->catalogue(withCrafted: false));

        // Skipping it would quietly under-report the month to FBR.
        $this->assertSame([], $groups);
        $this->assertContains('CRAFTED BY MARLBORO', $missing);
    }

    public function test_the_rows_it_builds_pass_the_same_validation_the_web_form_uses(): void
    {
        [$groups] = ImportSaleAreaInvoices::parseSaleArea($this->file, $this->catalogue());

        // Building rows the ScheduleEngine rejects would mean 5,960 drafts
        // that can never be submitted — catch the contract here, not on live.
        foreach ($groups as $key => $group) {
            $rows = ImportSaleAreaInvoices::rowsFor($group, $this->catalogue());
            $errors = ScheduleEngine::validateItems(array_column($rows, 'data'), 18.0);

            $this->assertSame([], $errors, "Group {$key} failed schedule validation: " . implode('; ', $errors));
        }
    }

    public function test_every_line_carries_thousand_unit_and_the_annex_a_mrp(): void
    {
        [$groups] = ImportSaleAreaInvoices::parseSaleArea($this->file, $this->catalogue());

        $data = ImportSaleAreaInvoices::rowsFor($groups['BHPAHP-04204|2026-08-02'], $this->catalogue())[0]['data'];

        // Losing the UOM to the "Numbers, pieces, units" fallback is the whole
        // bug this import exists to prevent.
        $this->assertSame('Thousand Unit', $data['_default_uom']);
        $this->assertSame('3rd Schedule Goods', $data['_sale_type']);
        $this->assertEqualsWithDelta(0.40, $data['quantity'], 0.0001);
        $this->assertEqualsWithDelta(self::MARLBORO_RATE, $data['price'], 0.01);
        // Annex-A: the notified value IS the invoice value, so MRP == rate.
        $this->assertEqualsWithDelta(self::MARLBORO_RATE, $data['mrp'], 0.01);
        $this->assertEqualsWithDelta(1934.28, $data['tax'], 0.01);
    }

    public function test_the_customer_code_rides_in_the_address_so_same_named_shops_stay_apart(): void
    {
        [$groups] = ImportSaleAreaInvoices::parseSaleArea($this->file, $this->catalogue());

        $one = ImportSaleAreaInvoices::rowsFor($groups['BHPAHP-04204|2026-08-01'], $this->catalogue())[0]['data'];
        $two = ImportSaleAreaInvoices::rowsFor($groups['BHPAHP-04672|2026-08-01'], $this->catalogue())[0]['data'];

        $this->assertSame($one['buyer_name'], $two['buyer_name'], 'Fixture should have two shops sharing a name.');
        $this->assertNotSame(
            $one['buyer_address'],
            $two['buyer_address'],
            'Two shops with the same name must be tellable apart on the invoice.'
        );
        $this->assertStringContainsString('BHPAHP-04204', $one['buyer_address']);
        $this->assertStringContainsString('BHPAHP-04672', $two['buyer_address']);
    }
}
