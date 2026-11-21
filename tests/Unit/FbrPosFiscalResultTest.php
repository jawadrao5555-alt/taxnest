<?php

namespace Tests\Unit;

use App\Services\FbrPosFiscalResult;
use PHPUnit\Framework\TestCase;

final class FbrPosFiscalResultTest extends TestCase
{
    public function test_malformed_ims_fields_cannot_be_accepted_as_a_fiscal_result(): void
    {
        $invoice = 'SYNTHETIC-100001';
        $this->assertSame($invoice, FbrPosFiscalResult::explicitAcknowledgement(
            ['Code' => 100, 'InvoiceNumber' => $invoice], true, $invoice
        ));
        $this->assertNull(FbrPosFiscalResult::explicitAcknowledgement(
            ['Code' => [100], 'code' => '100', 'InvoiceNumber' => $invoice], true, $invoice
        ));
        $this->assertNull(FbrPosFiscalResult::explicitAcknowledgement(
            ['Code' => '100', 'InvoiceNumber' => ['invalid'], 'FBRInvoiceNumber' => $invoice], true, $invoice
        ));
        $this->assertNull(FbrPosFiscalResult::explicitAcknowledgement(
            ['Code' => '100', 'response_code' => '500', 'InvoiceNumber' => $invoice], true, $invoice
        ));
        $this->assertSame($invoice, FbrPosFiscalResult::explicitAcknowledgement(
            ['Code' => 100, 'response_code' => '100', 'InvoiceNumber' => $invoice], true, $invoice
        ));
    }
}
