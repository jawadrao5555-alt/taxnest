<?php

namespace Tests\Feature;

use App\Services\FbrService;
use App\Services\FbrPosFiscalResult;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReusableFiscalAcknowledgementTest extends TestCase
{
    public function test_di_requires_complete_unique_item_acceptance_and_whole_invoice_reference(): void
    {
        Http::preventStrayRequests();
        $service = new FbrService();
        $valid = ['invoiceNumber'=>'SYN-DI-123', 'validationResponse'=>[
            'statusCode'=>'00', 'status'=>'valid', 'invoiceStatuses'=>[
                ['itemSNo'=>'1','statusCode'=>'00','status'=>'valid'],
                ['itemSNo'=>'2','statusCode'=>'00','status'=>'valid'],
            ],
        ]];
        $this->assertSame(['state'=>'accepted','reference'=>'SYN-DI-123','error_codes'=>[]], $service->interpretPosDiAcknowledgement($valid, 2));
        $duplicate = $valid; $duplicate['validationResponse']['invoiceStatuses'][1]['itemSNo']='1';
        $invalid = $valid; $invalid['validationResponse']['invoiceStatuses'][1]['status']='invalid';
        $itemOnly = $valid; unset($itemOnly['invoiceNumber']); $itemOnly['validationResponse']['invoiceStatuses'][0]['invoiceNumber']='SYN-ITEM-123';
        foreach ([$duplicate, $invalid, $itemOnly, [], ['validationResponse'=>'malformed']] as $data) {
            $result = $service->interpretPosDiAcknowledgement($data, 2);
            $this->assertSame('verification_required', $result['state']);
            $this->assertNull($result['reference']);
        }
        $this->assertSame('verification_required', $service->interpretPosDiAcknowledgement($valid, 1)['state']);
        $this->assertSame('verification_required', $service->interpretPosDiAcknowledgement($valid, 0)['state']);
        Http::assertNothingSent();
    }

    public function test_di_explicit_rejection_cannot_echo_untrusted_errors_or_override_partial_ack(): void
    {
        $service = new FbrService();
        $data=['validationResponse'=>['statusCode'=>'01','status'=>'invalid','errorCode'=>'0099','error'=>'SECRET ECHO','invoiceStatuses'=>[]]];
        $this->assertSame(['state'=>'rejected','reference'=>null,'error_codes'=>['0099']], $service->interpretPosDiAcknowledgement($data,1));
        $data['invoiceNumber']='SYN-PARTIAL-123';
        $this->assertSame('verification_required',$service->interpretPosDiAcknowledgement($data,1)['state']);
        unset($data['invoiceNumber']); $data['validationResponse']['errorCode']='500';
        $this->assertSame('verification_required',$service->interpretPosDiAcknowledgement($data,1)['state']);
    }

    public function test_ims_interpretation_requires_explicit_code_matching_number_and_success(): void
    {
        Http::preventStrayRequests();
        foreach (['Code','response_code','code'] as $key) {
            $data=[$key=>'100','InvoiceNumber'=>'SYN-IMS-123'];
            $this->assertSame('SYN-IMS-123',FbrPosFiscalResult::explicitAcknowledgement($data,true,'SYN-IMS-123'));
            $this->assertNull(FbrPosFiscalResult::explicitAcknowledgement($data,false,'SYN-IMS-123'));
            $this->assertNull(FbrPosFiscalResult::explicitAcknowledgement($data,true,'SYN-OTHER-123'));
        }
        foreach ([null, ['InvoiceNumber'=>'SYN-IMS-123'], ['Code'=>'101','InvoiceNumber'=>'SYN-IMS-123'],
            ['Code'=>'100','InvoiceNumber'=>'SYN-IMS-123','FBRInvoiceNumber'=>'SYN-OTHER-123']] as $data) {
            $this->assertNull(FbrPosFiscalResult::explicitAcknowledgement($data,true,'SYN-IMS-123'));
        }
        Http::assertNothingSent();
    }
}
