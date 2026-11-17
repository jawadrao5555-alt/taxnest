<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\FbrPosTransaction;
use App\Services\FbrService;
use App\Services\PilotFiscalRouteSnapshot;
use App\Services\SchoolFeeImsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Synthetic transport proof only; this is not an authorized FBR sandbox acceptance. */
class SchoolFeeImsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_finalized_payment_queues_received_amount_and_copies_accepted_identity_to_same_receipt(): void
    {
        $company = Company::create([
            'name' => 'Synthetic School', 'ntn' => 'SYN-'.Str::random(8),
            'product_type' => 'fbrpos', 'pos_type' => 'education', 'business_category' => 'education',
            'fbr_pos_enabled' => true, 'fbr_reporting_enabled' => true,
            'fbr_connection_mode' => 'fiscal_device', 'fbr_pos_environment' => 'sandbox',
            'fbr_pos_id' => '123456', 'agent_enabled' => true,
        ]);
        DB::table('school_fee_heads')->insert([
            'company_id' => $company->id, 'code' => 'tuition', 'label' => 'Tuition fee',
            'active' => true, 'ims_pct_code' => null, 'ims_tax_rate' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $receiptId = DB::table('school_fee_receipts')->insertGetId([
            'company_id' => $company->id, 'branch_id' => null, 'collected_by' => 1,
            'request_key' => (string) Str::uuid(), 'student_reference' => 'PRIVATE-STUDENT',
            'student_name' => 'Private Student', 'fee_head' => 'tuition',
            'fee_lines' => json_encode([['fee_head'=>'tuition','label'=>'Tuition fee','amount_paisa'=>1000000]]),
            'fee_period' => '2026-11', 'amount_paisa' => 400000,
            'payable_paisa' => 1000000, 'paid_paisa' => 400000, 'balance_paisa' => 600000,
            'payment_method' => 'cash', 'collected_at' => now(),
            'fiscal_route_snapshot' => PilotFiscalRouteSnapshot::newBill($company, 'school', 'school_receipt'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $first = SchoolFeeImsService::queueFinalizedReceipt($company->id, $receiptId);
        $again = SchoolFeeImsService::queueFinalizedReceipt($company->id, $receiptId);
        $this->assertTrue($first['queued']);
        $this->assertSame($first['transaction_id'], $again['transaction_id']);
        $this->assertSame(1, FbrPosTransaction::where('company_id', $company->id)->count());

        $mirror = FbrPosTransaction::with(['items','company'])->findOrFail($first['transaction_id']);
        $payload = app(FbrService::class)->buildFbrPosPayload($mirror);
        $this->assertSame(4000.0, $payload['TotalBillAmount']);
        $this->assertSame('00000000', $payload['Items'][0]['PCTCode']);
        $this->assertStringNotContainsString('PRIVATE-STUDENT', json_encode($payload));
        $this->assertSame('pending', DB::table('school_fee_receipts')->where('id',$receiptId)->value('fbr_status'));

        $mirror->forceFill([
            'fbr_status' => 'submitted', 'fbr_invoice_number' => 'SYNTHETIC-FBR-100',
            'fbr_response_code' => '100', 'fbr_error_message' => null,
        ])->save();
        SchoolFeeImsService::reconcileTransaction($mirror->fresh());
        $receipt = DB::table('school_fee_receipts')->find($receiptId);
        $this->assertSame('submitted', $receipt->fbr_status);
        $this->assertSame('SYNTHETIC-FBR-100', $receipt->fbr_invoice_number);
        $this->assertSame('SYNTHETIC-FBR-100', $receipt->fbr_qr_payload);
        $this->assertSame(600000, (int) $receipt->balance_paisa);
    }

    public function test_off_receipt_is_not_backfilled_or_queued(): void
    {
        $company = Company::create([
            'name'=>'Synthetic OFF School','ntn'=>'SYN-'.Str::random(8),'product_type'=>'fbrpos',
            'pos_type'=>'education','business_category'=>'education','fbr_reporting_enabled'=>false,
        ]);
        $id=DB::table('school_fee_receipts')->insertGetId([
            'company_id'=>$company->id,'collected_by'=>1,'request_key'=>(string)Str::uuid(),
            'student_reference'=>'SYN-OFF','fee_head'=>'tuition','amount_paisa'=>10000,
            'collected_at'=>now(),'fiscal_route_snapshot'=>PilotFiscalRouteSnapshot::newBill($company,'school','school_receipt'),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $result=SchoolFeeImsService::queueFinalizedReceipt($company->id,$id);
        $this->assertFalse($result['queued']);
        $this->assertNull(DB::table('school_fee_receipts')->where('id',$id)->value('fbr_pos_transaction_id'));
        $this->assertSame(0,FbrPosTransaction::where('company_id',$company->id)->count());
    }
}
