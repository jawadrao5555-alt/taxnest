<?php

namespace Tests\Feature;

use App\Jobs\SubmitCollectionFbrReceipt;
use App\Models\Company;
use App\Models\FbrPosTransaction;
use App\Services\CollectionFbrSubmission;
use App\Services\FbrService;
use App\Services\HospitalPaymentImsService;
use App\Services\PilotFiscalRouteSnapshot;
use App\Services\SchoolFeeImsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CollectionFbrSubmissionTest extends TestCase
{
    use RefreshDatabase;

    public static function categories(): array
    {
        return [['education'], ['hospital']];
    }

    private function receipt(string $category): array
    {
        Bus::fake([SubmitCollectionFbrReceipt::class]);
        $company = Company::create([
            'name' => 'Synthetic collection', 'ntn' => 'SYN-'.Str::random(8),
            'product_type' => 'fbrpos', 'pos_type' => $category, 'business_category' => $category,
            'fbr_pos_enabled' => true, 'fbr_reporting_enabled' => true, 'fbr_pos_id' => '123456',
            'fbr_connection_mode' => 'cloud', 'fbr_pos_environment' => 'sandbox',
            'fbr_pos_token' => '11111111-2222-4333-8444-555555555555',
        ]);
        $data = ['company_id' => $company->id, 'request_key' => (string) Str::uuid(),
            'collected_by' => 1, 'amount_paisa' => 12500, 'payment_method' => 'cash',
            'collected_at' => now(), 'created_at' => now(), 'updated_at' => now()];
        if ($category === 'education') {
            $table = 'school_fee_receipts';
            $data += ['student_reference' => 'SYN-STUDENT', 'fee_head' => 'tuition',
                'fiscal_route_snapshot' => PilotFiscalRouteSnapshot::newBill($company, 'school', 'school_receipt')];
            $service = SchoolFeeImsService::class;
        } else {
            $table = 'hospital_payments';
            $invoice = DB::table('hospital_invoices')->insertGetId(['company_id' => $company->id,
                'request_key' => (string) Str::uuid(), 'patient_reference' => 'SYN-PATIENT',
                'service_head' => 'consultation', 'total_paisa' => 50000, 'created_by' => 1,
                'created_at' => now(), 'updated_at' => now()]);
            $data += ['invoice_id' => $invoice, 'balance_paisa' => 37500,
                'fiscal_route_snapshot' => PilotFiscalRouteSnapshot::newHospitalPayment($company, $invoice)];
            $service = HospitalPaymentImsService::class;
        }
        $id = DB::table($table)->insertGetId($data);
        $result = $service::queueFinalizedReceipt($company->id, $id);
        $this->assertTrue($result['queued']);
        return [$company, $table, $id, FbrPosTransaction::with('company')->findOrFail($result['transaction_id']), $service];
    }

    #[DataProvider('categories')]
    public function test_direct_mode_uses_shared_transport_and_updates_the_same_payment_receipt(string $category): void
    {
        [$company, $table, $id, $mirror, $service] = $this->receipt($category);
        Bus::assertDispatched(SubmitCollectionFbrReceipt::class, fn ($job) => $job->transactionId === $mirror->id);
        $service::queueFinalizedReceipt($company->id, $id);
        Bus::assertDispatchedTimes(SubmitCollectionFbrReceipt::class, 1);
        $this->assertSame(125.0, (float) $mirror->total_amount);
        $this->assertTrue(CollectionFbrSubmission::canSubmit($mirror));

        $fbr = \Mockery::mock(FbrService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $fbr->shouldReceive('sendDirectToFbr')->once()->andReturn([
            'body' => json_encode(['Code' => '100', 'InvoiceNumber' => '123456SYN000001']),
            'http_code' => 200, 'curl_error' => '',
        ]);
        $job = new SubmitCollectionFbrReceipt($mirror->id);
        $job->handle($fbr);
        $job->handle($fbr);
        $receipt = DB::table($table)->find($id);
        $this->assertSame('submitted', $receipt->fbr_status);
        $this->assertSame('123456SYN000001', $receipt->fbr_invoice_number);
        $this->assertSame($receipt->fbr_invoice_number, $receipt->fbr_qr_payload);
        $this->assertSame(12500, (int) $receipt->amount_paisa);
    }

    #[DataProvider('categories')]
    public function test_saved_receipt_cannot_switch_transport_environment_pos_or_company(string $category): void
    {
        [$company, $table, $id, $mirror] = $this->receipt($category);
        foreach ([['fbr_connection_mode' => 'fiscal_device', 'agent_enabled' => true],
            ['fbr_pos_environment' => 'production'], ['fbr_pos_id' => '654321'], ['fbr_reporting_enabled' => false]] as $change) {
            $original = $company->getAttributes();
            $company->forceFill($change)->save();
            $this->assertFalse(CollectionFbrSubmission::canSubmit($mirror->fresh('company')));
            $company->setRawAttributes($original)->save();
            $company->refresh();
        }
        DB::table($table)->where('id', $id)->update(['company_id' => $company->id + 999]);
        $this->assertFalse(CollectionFbrSubmission::canSubmit($mirror->fresh('company')));
    }
}
