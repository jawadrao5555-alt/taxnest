<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\FbrService;
use Tests\TestCase;

class DiWithholdingAmountTest extends TestCase
{
    private function invoice(bool $flag, ?float $amount): Invoice
    {
        $invoice = new Invoice(['buyer_name' => 'Fictional buyer']);
        $invoice->setRelation('company', new Company(['name' => 'Fictional seller', 'ntn' => '1234567', 'fbr_environment' => 'production']));
        $invoice->setRelation('items', collect([new InvoiceItem([
            'description' => 'Fictional line', 'quantity' => 1, 'price' => 10000, 'tax' => 1800, 'tax_rate' => 18,
            'st_withheld_at_source' => $flag, 'st_withheld_amount' => $amount,
        ])]));
        return $invoice;
    }

    public function test_actual_withholding_amount_is_sent_instead_of_boolean_one(): void
    {
        $invoice = $this->invoice(true, 500.25); $service = new FbrService();
        $this->assertSame(500.25, $service->buildPayload($invoice)['items'][0]['salesTaxWithheldAtSource']);
        $this->assertSame([], $service->validateWithholdingAmounts($invoice));
    }

    public function test_legacy_flag_without_amount_requires_operator_correction(): void
    {
        $service = new FbrService(); $invoice = $this->invoice(true, null);
        $this->assertSame(0.0, $service->buildPayload($invoice)['items'][0]['salesTaxWithheldAtSource']);
        $this->assertSame('WITHHOLDING_AMOUNT', $service->validateWithholdingAmounts($invoice)[0]['code']);
        $invoice = $this->invoice(false, 500);
        $this->assertSame(0.0, $service->buildPayload($invoice)['items'][0]['salesTaxWithheldAtSource']);
        $this->assertSame([], $service->validateWithholdingAmounts($invoice));
    }
}
