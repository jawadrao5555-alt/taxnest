<?php
namespace App\Http\Controllers;

use App\Models\{Company,PricingPlan};
use App\Services\{DistributorDiscountService,ErpPublicPricing,PendingSubscriptionSelection,PlanCompanyCompatibility,PlanSellabilityService,PosSignupBundle,SubscriptionAssignmentService};
use App\Support\NestErps;
use App\Support\SchoolErpPanel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Authenticated quote/proof selection only. Never charges or grants a subscription. */
class PackageCheckoutController extends Controller
{
    private function context(Request $request): array
    {
        $panel = explode('.', $request->route()->getName(), 2)[0];
        $prefix = match ($panel) {
            'school-erp' => SchoolErpPanel::GUARD,
            'health', 'fbrpos', 'pos' => $panel,
            default => 'web',
        };
        $user=auth($prefix)->user();
        abort_unless($user && in_array($user->role,['company_admin','super_admin'],true),403);
        $company=Company::findOrFail($user->company_id);
        return [$company,$prefix];
    }

    public function index(Request $request)
    {
        [$company,$guard]=$this->context($request);
        $policySubscription=$company->subscriptions()->where('active',true)->orderByDesc('id')->first();
        $compatiblePlans=PricingPlan::where('is_trial',false)->get()->filter(fn ($plan)=>PlanCompanyCompatibility::matches($company,$plan)
            && !PlanSellabilityService::isRetired($plan));
        $plans=$compatiblePlans->filter(fn ($plan)=>($plan->is_public ?? true)
            && (!NestErps::isProductType($plan->product_type) || ErpPublicPricing::availableForPurchase($plan)));
        $signup = PosSignupBundle::pending($company);
        $planId = $signup ? (int) $signup->pricing_plan_id : $request->integer('plan_id');
        $selectedPlan = $signup ? $compatiblePlans->firstWhere('id', $planId) : $plans->firstWhere('id', $planId);
        if ($signup) $plans = $selectedPlan ? collect([$selectedPlan]) : collect();
        $quote=$selectedPlan ? PendingSubscriptionSelection::quote($company,$selectedPlan) : null;
        if (!$selectedPlan && in_array($guard, ['health', SchoolErpPanel::GUARD], true)) {
            // A company can finish its saved quote after the plan becomes quote-only.
            $previousPlan = $compatiblePlans->firstWhere('id', $planId);
            $quote = $previousPlan && NestErps::isProductType($previousPlan->product_type)
                ? PendingSubscriptionSelection::quote($company, $previousPlan) : null;
            $selectedPlan = $quote ? $previousPlan : null;
        }
        $checkoutRoute = $this->checkoutRoute($guard);
        $proofRoute = match ($guard) {
            'web' => 'payment-proof.store',
            SchoolErpPanel::GUARD => 'school-erp.payment-proof.store',
            default => $guard.'.payment-proof.store',
        };
        $layout=match($guard){'pos'=>'pos-layout','fbrpos'=>'fbr-pos-layout','health'=>'health-layout',
            SchoolErpPanel::GUARD=>'school-erp-layout',default=>'app-layout'};
        return response()->view('billing.package-checkout',compact('policySubscription','company','plans','selectedPlan','quote','checkoutRoute','proofRoute','layout'))->header('Cache-Control','private, no-store');
    }

    public function select(Request $request)
    {
        [$company,$guard]=$this->context($request);
        $data=$request->validate(['plan_id'=>'required|integer','billing_cycle'=>'required|in:monthly,quarterly,semi_annual,annual']);
        DB::transaction(function () use ($company,$data) {
            $company=Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $plan=PricingPlan::whereKey($data['plan_id'])->lockForUpdate()->firstOrFail();
            PlanCompanyCompatibility::assertMatches($company,$plan);
            $quote=PendingSubscriptionSelection::quote($company,$plan,$data['billing_cycle']);
            $frozenPraSignup = isset($quote[PosSignupBundle::KEY]);
            abort_unless(!$plan->is_trial && (($plan->is_public ?? true) || $frozenPraSignup) && !PlanSellabilityService::isRetired($plan),422);
            abort_unless(!NestErps::isProductType($plan->product_type) || ErpPublicPricing::availableForPurchase($plan), 422);
            if (!$quote) {
                abort_if(PosSignupBundle::pending($company), 422, 'Use the original PRA signup package and billing period.');
                SubscriptionAssignmentService::cycleForPlan($plan,$data['billing_cycle']);
                $quote=DistributorDiscountService::quote($company,$plan,$data['billing_cycle']);
            }
            if (!$frozenPraSignup) PendingSubscriptionSelection::remember($company,$quote);
        },3);
        return redirect()->route($this->checkoutRoute($guard), ['plan_id' => $data['plan_id']]);
    }

    private function checkoutRoute(string $guard): string
    {
        return match ($guard) {
            'web' => 'billing.package-checkout',
            SchoolErpPanel::GUARD => 'school-erp.package-checkout',
            default => $guard.'.package-checkout',
        };
    }
}
