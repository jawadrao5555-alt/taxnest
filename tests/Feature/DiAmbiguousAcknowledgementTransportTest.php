<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\FbrLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\DiFiscalSubmissionState;
use App\Services\FbrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DiAmbiguousAcknowledgementTransportTest extends TestCase
{
    use RefreshDatabase;

    private function submit(array $response): array
    {
        // Exercise the actual curl/response path against a disposable loopback
        // server, never a regulator endpoint. The suite's egress guard is required.
        if (getenv('RC_SAFE_RUN') !== '1') $this->markTestSkipped('Run transport regressions through scripts/rc-safe-run.');
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertNotFalse($socket, $error);
        $address = stream_socket_get_name($socket, false); fclose($socket);
        $router = tempnam(sys_get_temp_dir(), 'di-ack-');
        file_put_contents($router, '<?php header("Content-Type: application/json"); echo base64_decode("' . base64_encode(json_encode($response)) . '");');
        $server = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), '-S', $address, $router]);
        $server->start();
        try {
            $ready = false;
            for ($i = 0; $i < 100; $i++) {
                $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
                if ($probe) { fclose($probe); $ready = true; break; }
                usleep(20000);
            }
            $this->assertTrue($ready, 'Loopback fixture did not start.');
            Http::fake(['*' => Http::response([['description' => 'Numbers, pieces, units']], 200)]);
            $company = Company::create(['name' => 'Synthetic DI transport seller', 'ntn' => '7654321', 'product_type' => 'di', 'province' => 'Punjab']);
            $company->forceFill(['fbr_environment' => 'sandbox', 'fbr_sandbox_url' => 'http://' . $address, 'fbr_sandbox_token' => str_repeat('a', 36)])->save();
            $invoice = Invoice::withoutGlobalScopes()->create(['company_id' => $company->id, 'invoice_number' => 'DI-ACK-1', 'document_type' => 'Sale Invoice', 'invoice_date' => now()->toDateString(), 'buyer_name' => 'Synthetic buyer', 'buyer_ntn' => '1234567', 'buyer_registration_type' => 'Registered', 'buyer_address' => 'Synthetic address', 'destination_province' => 'Punjab', 'total_value_excluding_st' => 100, 'total_sales_tax' => 18, 'total_amount' => 118, 'status' => 'draft']);
            InvoiceItem::create(['invoice_id' => $invoice->id, 'hs_code' => '33049900', 'description' => 'Synthetic test line', 'quantity' => 1, 'price' => 100, 'tax' => 18, 'tax_rate' => 18, 'schedule_type' => 'standard', 'default_uom' => 'Numbers, pieces, units']);
            $claimed = DiFiscalSubmissionState::reserve($invoice->id, 'transport_test', 'sandbox');
            $result = (new FbrService())->submitInvoice($claimed);
            return [$result, $claimed->fresh(), FbrLog::where('invoice_id', $invoice->id)->latest('id')->first()];
        } finally {
            $server->stop(); unlink($router);
        }
    }

    public function test_valid_response_without_reference_keeps_fingerprint_and_blocks_replay(): void
    {
        [$result, $invoice, $log] = $this->submit(['validationResponse' => ['statusCode' => '00', 'status' => 'valid']]);
        $this->assertSame('pending_verification', $result['status']);
        $this->assertSame('pending_verification', $log->status);
        $this->assertNotEmpty($invoice->fbr_submission_hash);
        DiFiscalSubmissionState::verificationRequired($invoice, 'sandbox', $result['failure_type']); $invoice->save();
        $this->assertNull(DiFiscalSubmissionState::reserve($invoice->id, 'retry', 'sandbox'));
    }

    public function test_explicit_rejection_without_reference_remains_correctable(): void
    {
        [$result, $invoice] = $this->submit(['validationResponse' => ['statusCode' => '01', 'status' => 'invalid', 'errorCode' => '0099', 'error' => 'Invalid UOM']]);
        $this->assertSame('failed', $result['status']);
        DiFiscalSubmissionState::rejected($invoice); $invoice->save();
        $this->assertNotNull(DiFiscalSubmissionState::reserve($invoice->id, 'corrected', 'sandbox'));
    }
}
