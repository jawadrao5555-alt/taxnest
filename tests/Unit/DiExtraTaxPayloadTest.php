<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\FbrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DiExtraTaxPayloadTest extends TestCase
{
    use RefreshDatabase;

    public static function extraTaxCases(): array
    {
        return [
            'sandbox reduced without charge' => ['sandbox', 'reduced', 0.0, '', 2.02],
            'production reduced without charge' => ['production', 'reduced', 0.0, '', 2.02],
            'legacy reduced line with an inapplicable stored charge' => ['sandbox', 'reduced', 1.25, '', 2.02],
            'standard zero remains numeric' => ['sandbox', 'standard', 0.0, 0.0, 2.36],
            'standard charge is multiplied by quantity' => ['production', 'standard', 1.25, 2.5, 4.86],
        ];
    }

    #[DataProvider('extraTaxCases')]
    public function test_shared_di_payload_encodes_extra_tax_by_sale_type(
        string $environment,
        string $schedule,
        float $storedExtraTax,
        float|string $expectedExtraTax,
        float $expectedTotal
    ): void {
        $company = (new Company)->forceFill([
            'name' => 'Fixture Seller',
            'fbr_environment' => $environment,
            'province' => 'Punjab',
        ]);
        $item = (new InvoiceItem)->forceFill([
            'description' => 'Fixture goods',
            'schedule_type' => $schedule,
            'hs_code' => '0101.2100',
            'default_uom' => 'Numbers, pieces, units',
            'quantity' => 2,
            'price' => 1,
            'tax_rate' => $schedule === 'reduced' ? 1 : 18,
            'extra_tax' => $storedExtraTax,
            'sro_schedule_no' => 'EIGHTH SCHEDULE Table 1',
            'serial_no' => '70',
        ]);
        $invoice = (new Invoice)->forceFill([
            'document_type' => 'Sale Invoice',
            'internal_invoice_number' => 'D0001',
            'buyer_registration_type' => 'Unregistered',
        ]);
        $invoice->setRelation('company', $company);
        $invoice->setRelation('items', collect([$item]));

        $payload = (new FbrService)->buildPayload($invoice, localOnly: true);
        $line = $payload['items'][0];

        $this->assertSame($expectedExtraTax, $line['extraTax']);
        $this->assertSame($expectedTotal, $line['totalValues']);
    }
}
