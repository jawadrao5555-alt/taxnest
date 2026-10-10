<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\DiInvoiceApiController;
use App\Models\FbrLog;
use App\Models\Invoice;
use App\Services\FbrService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class DiRedactedErrorPersistenceTest extends TestCase
{
    public function test_safe_item_diagnostics_survive_storage_and_api_status_lookup(): void
    {
        Schema::dropAllTables();
        Schema::create('fbr_logs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('invoice_id'); $t->text('response_payload'); $t->timestamps();
        });
        $body = json_encode(['buyer' => 'PRIVATE BUYER', 'token' => 'PRIVATE TOKEN', 'validationResponse' => [
            'statusCode' => '01', 'status' => 'invalid', 'invoiceStatuses' => [[
                'itemSNo' => '2', 'statusCode' => '01', 'errorCode' => '0099', 'error' => 'PRIVATE BUYER 3620312345671',
            ]],
        ]]);
        $record = (new ReflectionMethod(FbrService::class, 'redactedResponseRecord'))->invoke(new FbrService(), $body);
        $this->assertStringNotContainsString('PRIVATE', $record);
        $this->assertStringNotContainsString('3620312345671', $record);
        FbrLog::create(['invoice_id' => 91, 'response_payload' => $record]);
        $invoice = new Invoice(); $invoice->id = 91;
        $errors = (new ReflectionMethod(DiInvoiceApiController::class, 'latestFbrErrors'))->invoke(new DiInvoiceApiController(), $invoice);
        $this->assertSame(['Item 2: [0099] Select the permitted unit for this HS code.'], $errors);
        $invoice->id = 92;
        $this->assertSame([], (new ReflectionMethod(DiInvoiceApiController::class, 'latestFbrErrors'))->invoke(new DiInvoiceApiController(), $invoice));
    }

    public function test_success_record_retains_reference_without_error_or_raw_body(): void
    {
        $record = (new ReflectionMethod(FbrService::class, 'redactedResponseRecord'))->invoke(new FbrService(), json_encode([
            'invoiceNumber' => 'ACK-91', 'validationResponse' => ['statusCode' => '00', 'status' => 'valid'], 'secret' => 'PRIVATE',
        ]));
        $data = json_decode($record, true);
        $this->assertSame('ACK-91', $data['invoiceNumber']);
        $this->assertSame([], $data['errors']);
        $this->assertStringNotContainsString('PRIVATE', $record);
    }
}
