<?php
namespace Tests\Feature;

use App\Models\{AdminUser, Company, PricingPlan, Subscription, AdminAuditLog, SubscriptionInvoice, SubscriptionPayment, PaymentProof};
use App\Services\{CompanyAccountOverview, CompanyAccountStateService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AdminCompanyAccountOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $admin = AdminUser::create(['name'=>'Synthetic account reviewer','email'=>uniqid().'@b01.invalid','password'=>bcrypt('SyntheticOnly123!'),'role'=>'super_admin']);
        $company = Company::create(['name'=>'Synthetic B01 account','email'=>uniqid().'@b01.invalid','ntn'=>uniqid(),'product_type'=>'pos','status'=>'approved','company_status'=>'active']);
        $plan = PricingPlan::create(['name'=>'Synthetic current catalogue price','product_type'=>'pos','price'=>9999,'invoice_limit'=>100,'user_limit'=>2,'branch_limit'=>1,'is_active'=>true,'is_trial'=>false]);
        $sub = Subscription::create(['company_id'=>$company->id,'pricing_plan_id'=>$plan->id,'billing_cycle'=>'annual','start_date'=>'2025-01-01','end_date'=>'2025-12-31','final_price'=>12499,'active'=>true]);
        return [$admin,$company,$sub];
    }

    public function test_manage_company_exposes_stored_period_and_price_instead_of_only_plan_label(): void
    {
        [$admin,$company,$sub] = $this->fixture();
        $before = $sub->fresh()->getAttributes();
        $this->actingAs($admin,'admin')->get('/admin/companies/'.$company->id)->assertOk()
            ->assertSee('Subscription and access')->assertSee('01 Jan 2025')->assertSee('31 Dec 2025')->assertSee('12,499.00')->assertSee('Your plan has expired');
        $this->assertSame($before,$sub->fresh()->getAttributes());
    }

    public function test_suspension_records_reason_and_restore_condition_atomically_with_company_state(): void
    {
        [$admin,$company,$sub] = $this->fixture();
        $before = $sub->fresh()->getAttributes();
        $this->actingAs($admin,'admin')->post('/admin/companies/'.$company->id.'/suspend',['reason'=>'Synthetic review needed','restoration_condition'=>'Manual owner review'])->assertRedirect();
        $event = AdminAuditLog::where('target_type','Company')->where('target_id',$company->id)->where('action','Company suspended')->firstOrFail();
        $this->assertSame('Synthetic review needed',$event->metadata['reason'] ?? null);
        $this->assertSame('Manual owner review',$event->metadata['restoration_condition'] ?? null);
        $this->assertSame($admin->id,$event->admin_id);
        $this->assertSame('suspended',$company->fresh()->company_status);
        $this->assertSame($before,$sub->fresh()->getAttributes());
        $this->get('/admin/companies/'.$company->id)->assertOk()->assertSee('Synthetic review needed')->assertSee('Synthetic account reviewer');
    }

    public function test_newest_active_row_matches_the_existing_access_service_and_conflicts_stay_visible(): void
    {
        [$admin,$company,$old] = $this->fixture();
        $old->update(['end_date'=>now()->addYear()]);
        $new = Subscription::create(['company_id'=>$company->id,'pricing_plan_id'=>$old->pricing_plan_id,'active'=>true,'start_date'=>'2024-01-01','end_date'=>'2024-12-31','final_price'=>null]);
        $before = Subscription::where('company_id',$company->id)->get()->toJson();
        $this->actingAs($admin,'admin')->get('/admin/companies/'.$company->id)->assertOk()
            ->assertSee('2 active subscription rows need review')
            ->assertViewHas('accountOverview',fn ($a)=>$a['current']->id===$new->id && $a['access']['allowed']===false);
        $this->assertSame($before,Subscription::where('company_id',$company->id)->get()->toJson());
    }

    public function test_valid_grant_and_explicit_suspension_are_separate_and_legacy_reason_is_not_invented(): void
    {
        [$admin,$company,$sub] = $this->fixture();
        $sub->update(['override_type'=>'temporary','override_until'=>now()->addDays(3)]);
        $company->update(['status'=>'suspended','company_status'=>'suspended','suspended_at'=>now()]);
        AdminAuditLog::log($admin->id,'Company suspended','Company',$company->id,['reason'=>'Wrong old cycle','after'=>['suspended_at'=>now()->subDays(2)->toIso8601String()]]);
        $before = $company->fresh()->getAttributes();
        $this->actingAs($admin,'admin')->get('/admin/companies/'.$company->id)->assertOk()
            ->assertSee('Company-wide suspension remains in place')->assertSee('Allowed by subscription rules')
            ->assertSee('Not recorded for this suspension')
            ->assertViewHas('accountOverview',fn ($a)=>$a['suspension']===null && $a['control']==='suspended' && $a['access']['allowed']===true);
        $this->assertSame($before,$company->fresh()->getAttributes());
    }

    public function test_retries_preserve_first_evidence_and_same_second_stale_form_cannot_undo_a_newer_cycle(): void
    {
        [$admin,$company,$sub] = $this->fixture();
        $this->actingAs($admin,'admin');$before = $sub->fresh()->getAttributes();
        $this->travelTo(\Carbon\Carbon::parse('2026-11-01 10:00:00','Asia/Karachi'));
        try {
            $initial = CompanyAccountStateService::token($company->fresh());
            $input = ['state_token'=>$initial,'reason'=>'First review'];
            $this->postJson('/admin/companies/'.$company->id.'/suspend',$input)->assertOk()->assertJson(['changed'=>true]);
            $stamp = $company->fresh()->suspended_at->toIso8601String();
            $this->postJson('/admin/companies/'.$company->id.'/suspend',$input+['restoration_condition'=>'retry'])->assertOk()->assertJson(['changed'=>false]);
            $this->assertSame($stamp,$company->fresh()->suspended_at->toIso8601String());
            $activationToken = CompanyAccountStateService::token($company->fresh());
            $this->postJson('/admin/companies/'.$company->id.'/activate',['state_token'=>$activationToken,'reason'=>'Reviewed'])->assertOk();
            $this->assertFalse(\App\Services\SubscriptionAccessService::hasAccess($company->fresh())['allowed']);
            $this->postJson('/admin/companies/'.$company->id.'/suspend',['state_token'=>CompanyAccountStateService::token($company->fresh()),'reason'=>'Second review'])->assertOk();
            $this->postJson('/admin/companies/'.$company->id.'/activate',['state_token'=>$activationToken,'reason'=>'Stale review'])->assertConflict()->assertJson(['changed'=>false]);
            $this->assertSame('suspended',$company->fresh()->company_status);
            $this->assertSame(3,AdminAuditLog::where('target_id',$company->id)->count());
            $this->assertSame('First review',AdminAuditLog::orderBy('id')->first()->metadata['reason']);
            $this->assertSame($before,$sub->fresh()->getAttributes());
        } finally {$this->travelBack();}
    }

    public function test_audit_failure_rolls_back_company_change_and_never_changes_subscription(): void
    {
        [$admin,$company,$sub] = $this->fixture();
        $before = $company->fresh()->getAttributes();$term = $sub->fresh()->getAttributes();
        $event = 'eloquent.creating: '.AdminAuditLog::class;
        Event::listen($event,static function () {throw new \RuntimeException('Synthetic audit failure');});
        try {
            try {CompanyAccountStateService::change($company->id,false,$admin->id,['reason'=>'Synthetic rollback']);$this->fail('Expected audit write failure');}
            catch (\RuntimeException $e) {$this->assertSame('Synthetic audit failure',$e->getMessage());}
        } finally {Event::forget($event);}
        $this->assertSame($before,$company->fresh()->getAttributes());
        $this->assertSame($term,$sub->fresh()->getAttributes());
        $this->assertSame(0,AdminAuditLog::count());
    }

    public function test_company_scoped_history_and_payment_sources_do_not_render_foreign_records_or_private_metadata(): void
    {
        [$admin,$company,$sub] = $this->fixture();
        $other = Company::create(['name'=>'Foreign B01 company','email'=>uniqid().'@b01.invalid','ntn'=>uniqid(),'product_type'=>'pos','status'=>'approved','company_status'=>'active']);
        $foreignSub = Subscription::create(['company_id'=>$other->id,'active'=>true,'start_date'=>'2025-01-01','end_date'=>'2025-12-31','final_price'=>54321]);
        foreach ([[$company,$sub,'OWN-B01'],[$other,$foreignSub,'FOREIGN-B01']] as [$c,$s,$marker]) {
            $invoice = SubscriptionInvoice::create(['company_id'=>$c->id,'subscription_id'=>$s->id,'amount'=>123,'status'=>'pending','due_date'=>now()]);
            SubscriptionPayment::create(['subscription_invoice_id'=>$invoice->id,'amount'=>50,'payment_method'=>'bank','transaction_ref'=>$marker.'-PAY','paid_at'=>now()]);
            PaymentProof::create(['company_id'=>$c->id,'amount'=>50,'payment_method'=>'bank','reference'=>$marker.'-PROOF','payment_date'=>now(),'proof_path'=>'synthetic/pruned.png','file_pruned_at'=>now(),'status'=>'pending']);
            AdminAuditLog::log($admin->id,$marker.'-EVENT','Subscription',$s->id,['secret'=>'PRIVATE-B01-METADATA']);
        }
        $before = Subscription::orderBy('id')->get()->toJson();
        $this->actingAs($admin,'admin')->get('/admin/companies/'.$company->id)->assertOk()
            ->assertSee('OWN-B01-PAY')->assertSee('OWN-B01-PROOF')->assertSee('OWN-B01-EVENT')
            ->assertDontSee('FOREIGN-B01')->assertDontSee('PRIVATE-B01-METADATA');
        $this->get('/admin/payment-proofs?status=all&company_id='.$company->id)->assertOk()
            ->assertSee('OWN-B01-PROOF')->assertDontSee('FOREIGN-B01-PROOF');
        $this->assertSame($before,Subscription::orderBy('id')->get()->toJson());
    }

    public function test_inactive_history_does_not_become_an_active_plan_or_invent_a_stored_price(): void
    {
        [$admin,$company,$sub] = $this->fixture();$sub->update(['active'=>false,'final_price'=>null]);
        $this->actingAs($admin,'admin')->get('/admin/companies/'.$company->id)->assertOk()
            ->assertSee('No active subscription row')->assertSee('Inactive row')->assertSee('Not recorded')
            ->assertViewHas('accountOverview',fn ($a)=>$a['current']===null && $a['history']->first()->id===$sub->id && $a['history']->first()->final_price===null);
    }

    public function test_versioned_form_validation_is_server_enforced_and_legacy_missing_reason_remains_unknown(): void
    {
        [$admin,$company,$sub] = $this->fixture();$this->actingAs($admin,'admin');
        $token = CompanyAccountStateService::token($company->fresh());
        $this->postJson('/admin/companies/'.$company->id.'/suspend',['state_token'=>$token,'reason'=>' '])->assertUnprocessable();
        $this->postJson('/admin/companies/'.$company->id.'/suspend',['reason'=>['bad']])->assertUnprocessable();
        $this->postJson('/admin/companies/'.$company->id.'/suspend',['reason'=>str_repeat('x',1001)])->assertUnprocessable();
        $this->assertSame('approved',$company->fresh()->status);
        $this->post('/admin/companies/'.$company->id.'/suspend')->assertRedirect();
        $event = AdminAuditLog::where('target_id',$company->id)->firstOrFail();
        $this->assertNull($event->metadata['reason']);
        $this->get('/admin/companies/'.$company->id)->assertOk()->assertSee('Reason: Not recorded');
    }

    public function test_all_product_lines_keep_saved_settings_and_terms_during_account_review_and_state_changes(): void
    {
        [$admin,$company,$sub] = $this->fixture();$this->actingAs($admin,'admin');
        foreach (\App\Support\ProductCatalog::TYPES as $product) {
            $company->update(['product_type'=>$product,'status'=>'approved','company_status'=>'active','suspended_at'=>null,
                'feature_flags'=>['synthetic_saved_choice'=>true],'pos_settings'=>['synthetic_printer'=>'Saved device']]);
            $beforeCompany = $company->fresh()->getAttributes();$beforeTerm = $sub->fresh()->getAttributes();
            $this->get('/admin/companies/'.$company->id)->assertOk()->assertSee('12,499.00');
            $this->assertSame($beforeCompany,$company->fresh()->getAttributes());
            foreach (['suspend','activate'] as $action) {
                $token = CompanyAccountStateService::token($company->fresh());
                $this->postJson('/admin/companies/'.$company->id.'/'.$action,['state_token'=>$token,'reason'=>'Synthetic '.$product.' review'])->assertOk();
            }
            $after = $company->fresh()->getAttributes();
            foreach (array_diff(array_keys($beforeCompany),['status','company_status','suspended_at','updated_at']) as $key) $this->assertSame($beforeCompany[$key],$after[$key],$product.': '.$key);
            $this->assertSame($beforeTerm,$sub->fresh()->getAttributes());
        }
    }

    public function test_company_user_without_admin_guard_cannot_read_or_change_company_account(): void
    {
        [$admin,$company,$sub] = $this->fixture();
        $user = \App\Models\User::create(['name'=>'Synthetic tenant owner','email'=>uniqid().'@b01.invalid','password'=>bcrypt('SyntheticOnly123!'),'company_id'=>$company->id,'role'=>'company_admin','pos_role'=>'pos_admin','is_active'=>true]);
        $this->actingAs($user,'pos')->get('/admin/companies/'.$company->id)->assertRedirect('/admin/login');
        $this->post('/admin/companies/'.$company->id.'/suspend',['reason'=>'Not allowed'])->assertRedirect('/admin/login');
        $this->assertSame('approved',$company->fresh()->status);
        $this->assertSame(0,AdminAuditLog::count());
    }
}
