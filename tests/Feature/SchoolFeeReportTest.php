<?php

namespace Tests\Feature;

use App\Http\Controllers\FbrPosSchoolFeeController;
use App\Http\Controllers\SchoolFeeReportController;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Synthetic report proof only; this is not an FBR sandbox acceptance. */
class SchoolFeeReportTest extends TestCase
{
    use RefreshDatabase;

    private Company $school;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->school = Company::create([
            'name' => 'Synthetic Report School', 'ntn' => 'SYN-'.Str::random(8),
            'product_type' => 'fbrpos', 'pos_type' => 'education', 'business_category' => 'education',
            'fbr_reporting_enabled' => false,
        ]);
        $this->owner = User::create([
            'company_id' => $this->school->id, 'name' => 'Synthetic Owner',
            'email' => 'school-report@example.test', 'password' => bcrypt('synthetic'),
            'role' => 'company_admin', 'pos_role' => 'pos_admin', 'is_active' => true,
        ]);
        $this->actingAs($this->owner, 'fbrpos');
        app()->instance('currentCompanyId', $this->school->id);
        app()->bind('currentBranchId', fn () => null);
    }

    private function receipt(string $date, int $amount, string $method, ?string $fiscal = null): int
    {
        return DB::table('school_fee_receipts')->insertGetId([
            'company_id' => $this->school->id, 'branch_id' => null, 'collected_by' => $this->owner->id,
            'request_key' => (string) Str::uuid(), 'student_reference' => 'SYN-STUDENT-'.$amount,
            'student_name' => 'Synthetic Student', 'fee_head' => 'tuition', 'fee_head_label' => 'Tuition',
            'fee_period' => substr($date, 0, 7), 'fee_lines' => json_encode([['fee_head'=>'tuition','label'=>'Tuition','amount_paisa'=>$amount]]),
            'amount_paisa' => $amount, 'payable_paisa' => $amount, 'paid_paisa' => $amount, 'balance_paisa' => 0,
            'payment_method' => $method, 'collected_at' => $date.' 10:00:00',
            'fbr_status' => $fiscal ? 'submitted' : null, 'fbr_invoice_number' => $fiscal,
            'created_at' => $date.' 10:00:00', 'updated_at' => $date.' 10:00:00',
        ]);
    }

    public function test_unrestricted_custom_and_all_time_reports_keep_totals_and_tenant_isolation(): void
    {
        $this->receipt('2021-01-02', 10000, 'cash');
        $accepted = $this->receipt(now()->toDateString(), 25000, 'bank', 'SYN-FISCAL-1');
        $other = Company::create([
            'name'=>'Other School','ntn'=>'SYN-'.Str::random(8),'product_type'=>'fbrpos',
            'pos_type'=>'education','business_category'=>'education',
        ]);
        DB::table('school_fee_receipts')->insert([
            'company_id'=>$other->id,'branch_id'=>null,'collected_by'=>$this->owner->id,'request_key'=>(string)Str::uuid(),
            'student_reference'=>'PRIVATE-OTHER','fee_head'=>'tuition','amount_paisa'=>999999,'payment_method'=>'cash','collected_at'=>now(),
            'created_at'=>now(),'updated_at'=>now(),
        ]);

        $request = Request::create('/fbr-pos/school-fees/reports', 'GET', [
            'preset'=>'custom','from'=>'2020-01-01','to'=>'2035-12-31',
        ]);
        app()->instance('request', $request);
        $data = app(SchoolFeeReportController::class)->index($request)->original->getData();
        $this->assertSame(2, $data['summary']['count']);
        $this->assertSame(35000, $data['summary']['total_paisa']);
        $this->assertSame(1, $data['summary']['accepted']);
        $this->assertSame([$accepted], $data['receipts']->where('fbr_invoice_number','SYN-FISCAL-1')->pluck('id')->all());
        $this->assertStringContainsString('unrestricted custom date reports', app(SchoolFeeReportController::class)->index($request)->getContent());
    }

    public function test_csv_exports_complete_filtered_range_and_neutralizes_formula_cells(): void
    {
        $id = $this->receipt('2022-05-01', 12050, 'cash');
        DB::table('school_fee_receipts')->where('id',$id)->update(['student_reference'=>'=SYNTHETIC']);
        $request = Request::create('/fbr-pos/school-fees/reports.csv', 'GET', ['preset'=>'all']);
        app()->instance('request', $request);
        $response = app(SchoolFeeReportController::class)->csv($request);
        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();
        $this->assertStringContainsString('SF-'.$id, $csv);
        $this->assertStringContainsString("'=SYNTHETIC", $csv);
        $this->assertStringContainsString('120.50', $csv);
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }

    public function test_owner_can_prepare_ims_identity_without_turning_reporting_on(): void
    {
        $request = Request::create('/fbr-pos/school-fees/settings/ims', 'POST', [
            'fbr_pos_id'=>'123456','fbr_pos_environment'=>'production',
        ]);
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        app(FbrPosSchoolFeeController::class)->saveImsSettings($request);
        $this->school->refresh();
        $this->assertSame('123456', $this->school->fbr_pos_id);
        $this->assertSame('production', $this->school->fbr_pos_environment);
        $this->assertSame('fiscal_device', $this->school->fbr_connection_mode);
        $this->assertTrue((bool) $this->school->fbr_pos_enabled);
        $this->assertFalse($this->school->fbr_reporting_enabled);
    }
}
