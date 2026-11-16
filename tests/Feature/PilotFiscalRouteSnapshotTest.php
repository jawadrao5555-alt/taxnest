<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\PilotFiscalRouteSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PilotFiscalRouteSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $category): Company
    {
        return Company::create(['name'=>'Synthetic snapshot','ntn'=>uniqid(),'product_type'=>'fbrpos',
            'pos_type'=>$category,'business_category'=>$category,'fbr_reporting_enabled'=>true]);
    }

    private function receipt(Company $company, ?string $snapshot): int
    {
        return DB::table('school_fee_receipts')->insertGetId(['company_id'=>$company->id,
            'collected_by'=>1,'request_key'=>(string)\Illuminate\Support\Str::uuid(),
            'student_reference'=>'SYN-1','fee_head'=>'tuition','amount_paisa'=>10000,
            'collected_at'=>now(),'created_at'=>now(),'updated_at'=>now(),'fiscal_route_snapshot'=>$snapshot]);
    }

    public function test_off_and_legacy_routes_remain_unchanged_and_never_backfill(): void
    {
        Http::preventStrayRequests();
        $company=$this->company('education');
        $raw=PilotFiscalRouteSnapshot::newBill($company,'school','school_receipt');
        $this->assertSame('off',json_decode($raw,true)['route']);
        $this->assertFalse(json_decode($raw,true)['eligible']);
        $id=$this->receipt($company,$raw);$legacy=$this->receipt($company,null);
        $before=DB::table('school_fee_receipts')->orderBy('id')->get()->toArray();
        DB::table('companies')->where('id',$company->id)->update(['fbr_reporting_enabled'=>true,'pos_type'=>'retail','business_category'=>'retail']);
        foreach (range(1,2) as $attempt) {
            $plan=PilotFiscalRouteSnapshot::recoveryPlan($company->id,'school','school_receipt',$id);
            $this->assertSame('local_only',$plan['state']);$this->assertSame('off',$plan['route']);
            $this->assertFalse($plan['can_submit']);$this->assertFalse($plan['can_retry']);
            $this->assertFalse($plan['historical_backfill_allowed']);
            $this->assertSame('legacy_unverified',PilotFiscalRouteSnapshot::recoveryPlan($company->id,'school','school_receipt',$legacy)['state']);
        }
        $this->assertSame('route_mismatch',PilotFiscalRouteSnapshot::recoveryPlan($company->id,'school','school_receipt',$id,'ims')['reason']);
        $this->assertEquals($before,DB::table('school_fee_receipts')->orderBy('id')->get()->toArray());
        Http::assertNothingSent();
    }

    public function test_unknown_ims_and_di_only_reconcile_on_frozen_route_and_never_send_or_mutate(): void
    {
        Http::preventStrayRequests();$company=$this->company('education');
        foreach (['ims','di'] as $route) {
            // Synthetic historical uncertainty, not a route authorization or live acceptance.
            $snapshot=json_decode(PilotFiscalRouteSnapshot::newBill($company,'school','school_receipt'),true);
            $snapshot['route']=$route;$snapshot['eligible']=true;
            $snapshot['request_identity']='SYN-UNCERTAIN';$snapshot['acknowledgement']=['fiscal_number'=>'SYN-PRESERVE'];
            $id=$this->receipt($company,json_encode($snapshot));$before=DB::table('school_fee_receipts')->find($id);
            $plan=PilotFiscalRouteSnapshot::recoveryPlan($company->id,'school','school_receipt',$id,$route);
            $this->assertSame($route,$plan['route']);$this->assertSame('reconciliation_required',$plan['state']);
            $this->assertTrue($plan['requires_reconciliation']);$this->assertFalse($plan['can_submit']);$this->assertFalse($plan['can_retry']);
            $this->assertSame('route_mismatch',PilotFiscalRouteSnapshot::recoveryPlan($company->id,'school','school_receipt',$id,$route==='ims'?'di':'ims')['reason']);
            $this->assertEquals($before,DB::table('school_fee_receipts')->find($id));
        }
        Http::assertNothingSent();
    }

    public function test_foreign_company_category_source_and_malformed_snapshot_are_denied(): void
    {
        $company=$this->company('education');$other=$this->company('education');
        $id=$this->receipt($company,PilotFiscalRouteSnapshot::newBill($other,'school','school_receipt'));
        $this->assertSame('invalid_snapshot_scope',PilotFiscalRouteSnapshot::recoveryPlan($company->id,'school','school_receipt',$id)['reason']);
        $bad=$this->receipt($company,'not-json');
        $this->assertSame('invalid_snapshot',PilotFiscalRouteSnapshot::recoveryPlan($company->id,'school','school_receipt',$bad)['reason']);
        foreach ([[$other->id,'school','school_receipt'],[$company->id,'hospital','school_receipt'],[$company->id,'school','companies']] as [$tenant,$category,$source]) {
            try { PilotFiscalRouteSnapshot::recoveryPlan($tenant,$category,$source,$id);$this->fail('Scope accepted'); }
            catch (HttpException $e) { $this->assertSame(404,$e->getStatusCode()); }
        }
    }

    public function test_hospital_payment_inherits_exact_parent_bytes_and_legacy_null_stays_unverified(): void
    {
        Http::preventStrayRequests();$company=$this->company('hospital');
        foreach ([PilotFiscalRouteSnapshot::newBill($company,'hospital','hospital_invoice'),null] as $raw) {
            $invoice=DB::table('hospital_invoices')->insertGetId(['company_id'=>$company->id,'request_key'=>(string)\Illuminate\Support\Str::uuid(),
                'patient_reference'=>'SYN-P','service_head'=>'consultation','total_paisa'=>10000,'created_by'=>1,'campus_name'=>'Synthetic','created_at'=>now(),'updated_at'=>now(),'fiscal_route_snapshot'=>$raw]);
            $payment=DB::table('hospital_payments')->insertGetId(['company_id'=>$company->id,'invoice_id'=>$invoice,'request_key'=>(string)\Illuminate\Support\Str::uuid(),
                'amount_paisa'=>1000,'payment_method'=>'cash','balance_paisa'=>9000,'collected_by'=>1,'cashier_name'=>'Synthetic','campus_name'=>'Synthetic',
                'collected_at'=>now(),'created_at'=>now(),'updated_at'=>now(),'fiscal_route_snapshot'=>$raw]);
            $before=DB::table('hospital_payments')->find($payment);
            $plan=PilotFiscalRouteSnapshot::recoveryPlan($company->id,'hospital','hospital_payment',$payment);
            $this->assertSame($raw===null?'legacy_unverified':'local_only',$plan['state']);
            $this->assertEquals($before,DB::table('hospital_payments')->find($payment));
            if ($raw!==null) {
                DB::table('hospital_invoices')->where('id',$invoice)->update(['fiscal_route_snapshot'=>null]);
                $this->assertSame('parent_snapshot_mismatch',PilotFiscalRouteSnapshot::recoveryPlan($company->id,'hospital','hospital_payment',$payment)['reason']);
            }
        }
        Http::assertNothingSent();
    }
    public function test_migration_rerun_and_rollback_preserve_existing_rows_and_null_legacy_snapshot(): void
    {
        $company=$this->company('education');
        $legacy=$this->receipt($company,null);
        $current=$this->receipt($company,PilotFiscalRouteSnapshot::newBill($company,'school','school_receipt'));
        $before=DB::table('school_fee_receipts')->orderBy('id')->get()->toArray();
        $migration=require database_path('migrations/2027_01_01_000000_add_pilot_fiscal_route_snapshots.php');
        $migration->up();$migration->down();$migration->up();
        $this->assertEquals($before,DB::table('school_fee_receipts')->orderBy('id')->get()->toArray());
        $this->assertNull(DB::table('school_fee_receipts')->where('id',$legacy)->value('fiscal_route_snapshot'));
        $this->assertNotNull(DB::table('school_fee_receipts')->where('id',$current)->value('fiscal_route_snapshot'));
    }

    public function test_hospital_unknown_routes_hold_same_invoice_without_cross_route_retry(): void
    {
        Http::preventStrayRequests();$company=$this->company('hospital');
        foreach (['ims','di'] as $route) {
            $snapshot=json_decode(PilotFiscalRouteSnapshot::newBill($company,'hospital','hospital_invoice'),true);
            $snapshot['route']=$route;$snapshot['request_identity']='SYN-HOSPITAL-UNCERTAIN';
            $raw=json_encode($snapshot);
            $id=DB::table('hospital_invoices')->insertGetId(['company_id'=>$company->id,'request_key'=>(string)\Illuminate\Support\Str::uuid(),
                'patient_reference'=>'SYN-P','service_head'=>'consultation','total_paisa'=>10000,'created_by'=>1,'campus_name'=>'Synthetic','created_at'=>now(),'updated_at'=>now(),'fiscal_route_snapshot'=>$raw]);
            $before=DB::table('hospital_invoices')->find($id);
            $plan=PilotFiscalRouteSnapshot::recoveryPlan($company->id,'hospital','hospital_invoice',$id,$route);
            $this->assertSame('reconciliation_required',$plan['state']);$this->assertSame($route,$plan['route']);
            $this->assertFalse($plan['can_submit']);$this->assertFalse($plan['can_retry']);
            $this->assertSame('route_mismatch',PilotFiscalRouteSnapshot::recoveryPlan($company->id,'hospital','hospital_invoice',$id,$route==='ims'?'di':'ims')['reason']);
            $this->assertEquals($before,DB::table('hospital_invoices')->find($id));
        }
        Http::assertNothingSent();
    }

}
