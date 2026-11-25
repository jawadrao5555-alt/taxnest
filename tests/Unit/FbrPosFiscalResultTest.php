<?php

namespace Tests\Unit;

use App\Services\FbrPosFiscalResult;
use App\Services\PosImsReportingService;
use PHPUnit\Framework\TestCase;

final class FbrPosFiscalResultTest extends TestCase
{
    public function test_malformed_invoice_fields_do_not_make_an_ims_rejection_replayable(): void
    {
        foreach (['InvoiceNumber', 'FBRInvoiceNumber', 'invoiceNumber'] as $field) {
            foreach ([false, [], 0, '0', '  '] as $value) {
                $result = PosImsReportingService::result(['Code' => '102', $field => $value], true);
                $this->assertSame('verification_required', $result['state']);
                $this->assertNull($result['reference']);
            }
        }

        foreach ([null, ''] as $value) {
            $result = PosImsReportingService::result(['Code' => '102', 'InvoiceNumber' => $value], true);
            $this->assertSame('rejected', $result['state']);
        }
        $this->assertSame('verification_required', PosImsReportingService::result([
            'Code' => '102', 'Response' => 'TEST-123456',
        ], true)['state']);
    }

    public function test_ims_reference_binding_preserves_short_and_zero_padded_pos_ids(): void
    {
        foreach (['123', '000123'] as $posId) {
            $this->assertTrue(\App\Services\ImsNumberOnlyAcknowledgement::matchesPosId('000123SYN123', $posId));
        }
        foreach (['', '000000', 'ABC123', '1234567'] as $invalid) {
            $this->assertFalse(\App\Services\ImsNumberOnlyAcknowledgement::matchesPosId('000123SYN123', $invalid));
        }
        $this->assertFalse(\App\Services\ImsNumberOnlyAcknowledgement::matchesPosId('000124SYN123', '123'));
    }

    public function test_number_only_acknowledgement_requires_a_recorded_pos_identity(): void
    {
        $fields = ['FBRInvoiceNumber:string'];
        $this->assertNull(\App\Services\ImsNumberOnlyAcknowledgement::number(
            ['FBRInvoiceNumber' => '000000TEST123'], '', 'response_received', $fields
        ));
        $this->assertSame('000123TEST456', \App\Services\ImsNumberOnlyAcknowledgement::number(
            ['FBRInvoiceNumber' => '000123TEST456'], '123', 'response_received', $fields
        ));
    }

    public function test_legacy_number_must_agree_with_the_structured_invoice_number(): void
    {
        $response = ['Code' => 100, 'InvoiceNumber' => 'TEST-123456'];
        $this->assertNull(FbrPosFiscalResult::explicitAcknowledgement(
            $response + ['Response' => 'TEST-654321'], true, 'TEST-123456'
        ));
        foreach (['TEST-123456', 'Invoice accepted'] as $legacy) {
            $this->assertSame('TEST-123456', FbrPosFiscalResult::explicitAcknowledgement(
                $response + ['Response' => $legacy], true, 'TEST-123456'
            ));
        }
        $this->assertSame('TEST-123456', FbrPosFiscalResult::fromResponse(['Response' => 'TEST-123456']));
    }

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
