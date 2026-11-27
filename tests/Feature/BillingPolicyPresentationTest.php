<?php
namespace Tests\Feature;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;
class BillingPolicyPresentationTest extends TestCase
{
    private function terms():array{return ['version'=>'selected_trial_v1','phase'=>'trial','selected_plan_name'=>'Synthetic selected package','trial_starts_at'=>'2026-11-15T03:15:00+00:00','trial_ends_at'=>'2026-11-18T03:15:00+00:00','paid_starts_at'=>null,'paid_ends_at'=>null,'grace_ends_at'=>null];}
    private function render(?array $terms):string{return Blade::render('<x-billing-policy-summary :terms="$terms" />',compact('terms'));}
    public function test_locales_show_exact_saved_dates_and_unknown_paid_dates_not_invented_grace():void {
        foreach(['en','ur','rur']as$locale){app()->setLocale($locale);$terms=$this->terms();$html=$this->render($terms);
            $this->assertStringContainsString('data-policy-version="selected_trial_v1"',$html);
            $this->assertStringContainsString(e(__('billing_policy.heading')),$html);
            foreach(['trial_starts_at','trial_ends_at']as$field)$this->assertStringContainsString('datetime="'.$terms[$field].'"',$html);
            $this->assertSame(2,substr_count($html,'<time '));
            $this->assertStringContainsString(e(__('billing_policy.not_recorded')),$html);
            $this->assertStringContainsString(e(__('billing_policy.phase_trial')),$html);
            $this->assertStringContainsString(e(__('billing_policy.grace_rule')),$html);
        }
    }
    public function test_paid_dates_remain_frozen_including_early_payment_and_selected_name_is_escaped():void {
        $terms=$this->terms();$terms['selected_plan_name']='<script>doNotRun()</script>';
        $terms['paid_starts_at']=$terms['trial_ends_at'];$terms['paid_ends_at']='2026-12-18T03:15:00+00:00';$terms['grace_ends_at']='2026-12-25T03:15:00+00:00';
        $this->travelTo(\Carbon\Carbon::parse('2026-11-16T00:00:00Z'));$first=$this->render($terms);
        $this->travelTo(\Carbon\Carbon::parse('2026-11-17T00:00:00Z'));$second=$this->render($terms);
        $this->assertSame($first,$second);$this->assertSame(5,substr_count($first,'<time '));
        $this->assertStringNotContainsString('<script>doNotRun()',$first);$this->assertStringContainsString('&lt;script&gt;',$first);
    }
    public function test_legacy_does_not_claim_new_trial_or_seven_day_grace():void {
        foreach(['en','ur','rur']as$locale){app()->setLocale($locale);$html=$this->render(null);
            $this->assertStringContainsString('data-policy-version="legacy"',$html);$this->assertStringContainsString(e(__('billing_policy.legacy_note')),$html);
            $this->assertStringNotContainsString('<time ',$html);$this->assertStringNotContainsString(e(__('billing_policy.grace_rule')),$html);
        }
    }
    public function test_nonnull_invalid_policy_is_unavailable_not_legacy():void {
        foreach(['en','ur','rur']as$locale){app()->setLocale($locale);
            $policySubscription=new \App\Models\Subscription;
            $policySubscription->setRawAttributes(['company_id'=>123,'lifecycle_snapshot'=>'{"version":"unknown_version","company_id":123}'],true);
            $html=Blade::render("@include('billing._policy-status')",compact('policySubscription'));
            $this->assertStringContainsString('data-policy-version="unavailable"',$html);
            $this->assertStringContainsString(e(__('billing_policy.unavailable')),$html);
            $this->assertStringNotContainsString('data-policy-legacy',$html);
            $this->assertStringNotContainsString('<time ',$html);
            $policySubscription->setRawAttributes(['company_id'=>123,'lifecycle_snapshot'=>null],true);
            $legacy=Blade::render("@include('billing._policy-status')",compact('policySubscription'));
            $this->assertStringContainsString('data-policy-legacy',$legacy);
        }
    }
    public function test_signup_selection_is_required_and_every_surface_uses_same_api():void {
        $signupFields = ['auth/register' => 'requested_plan', 'health/auth/register' => 'pricing_plan_id', 'fbr-pos/auth/register' => 'requested_plan'];
        foreach ($signupFields as $view => $field) {
            $html = file_get_contents(resource_path('views/'.$view.'.blade.php'));
            $this->assertMatchesRegularExpression('/<select\b[^>]*\bname="'.$field.'"[^>]*\brequired\b/', $html);
            $this->assertStringContainsString('billing._signup-policy', $html);
        }
        $pos=file_get_contents(resource_path('views/pos/auth/register.blade.php'));$this->assertMatchesRegularExpression('/name="pricing_plan_id"[^>]*required/',$pos);$this->assertStringContainsString(':disabled="!planId ||',$pos);
        $partial=file_get_contents(resource_path('views/billing/_policy-status.blade.php'));$this->assertStringContainsString('ProspectiveSubscriptionPolicy::display($policySubscription)',$partial);
        foreach(['billing/plans','billing/package-checkout','pos/billing','fbr-pos/billing','health/settings','saas-admin/companies/partials/account-overview']as$view)$this->assertStringContainsString('billing._policy-status',file_get_contents(resource_path('views/'.$view.'.blade.php')));
        app()->setLocale('en');$signup=Blade::render("@include('billing._signup-policy')");
        foreach(['72-hour','Bill count','package features','verified full payment','not an unpaid trial','history and receipt reprint']as$text)$this->assertStringContainsString($text,$signup);
    }
}
