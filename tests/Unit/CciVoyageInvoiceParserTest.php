<?php

namespace Tests\Unit;

use App\Services\CciVoyageInvoiceParser;
use App\Services\InvoiceImportMoney;
use PHPUnit\Framework\TestCase;

class CciVoyageInvoiceParserTest extends TestCase
{
    public function test_full_sanitized_pilot_preserves_73_bills_413_rows_31_codes_and_footer_money(): void
    {
        $grid = json_decode(file_get_contents(__DIR__ . '/../Fixtures/smart-di/cci-voyage-sanitized.json'), true);
        $out = (new CciVoyageInvoiceParser())->parseGrids($grid);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertCount(413, $out['rows']);
        $this->assertCount(73, array_unique(array_column(array_column($out['rows'], 'data'), 'source_invoice_number')));
        $this->assertCount(31, array_unique(array_column(array_column($out['rows'], 'data'), 'source_sku')));
        $this->assertSame('2026-10-10', $out['rows'][0]['data']['invoice_date']);
        $this->assertSame('122559.3', $out['rows'][0]['data']['invoice_gross']);
        $this->assertSame('67674.99', $out['rows'][0]['data']['invoice_net']);
        $this->assertSame('', $out['rows'][0]['data']['source_vat']);
        $this->assertSame('18', $out['rows'][0]['data']['source_vat_percent']);
        $this->assertSame('28071.35', $out['rows'][0]['data']['source_reported_net']);
        foreach ($out['rows'] as $row) {
            $d = $row['data'];
            $this->assertSame(InvoiceImportMoney::cents($d['invoice_net']), InvoiceImportMoney::cents($d['invoice_gross']) - InvoiceImportMoney::cents($d['invoice_discount']) + InvoiceImportMoney::cents($d['invoice_vat']));
        }
    }

    public function test_layout_changes_require_mapping_and_unrecognized_tables_are_not_guessed(): void
    {
        $parser = new CciVoyageInvoiceParser();
        $this->assertNull($parser->parseGrids(['flat' => [['Bill', 'SKU'], ['A', 'B']]]));
        $grid = json_decode(file_get_contents(__DIR__ . '/../Fixtures/smart-di/cci-voyage-sanitized.json'), true);
        $grid['Fictional pilot'][13][16] = 'Moved amount';
        $this->assertArrayHasKey('error', $parser->parseGrids($grid));
    }

    public function test_money_parser_preserves_paisa_and_rejects_ambiguous_or_overprecise_values(): void
    {
        $this->assertSame(123450, InvoiceImportMoney::cents('1,234.50'));
        $this->assertSame(-1, InvoiceImportMoney::cents('-0.01'));
        $this->assertSame('-0.01', InvoiceImportMoney::decimal(-1));
        foreach (['', '1,23', '1e3', '12.001', 'NaN'] as $bad) {
            try { InvoiceImportMoney::cents($bad); $this->fail('Accepted invalid money: ' . $bad); }
            catch (\InvalidArgumentException $e) { $this->assertNotEmpty($e->getMessage()); }
        }
    }
}
