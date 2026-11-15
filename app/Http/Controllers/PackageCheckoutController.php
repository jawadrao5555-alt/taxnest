<?php
namespace App\Http\Controllers;

use App\Models\{Company,PricingPlan};
use App\Services\{DistributorDiscountService,PendingSubscriptionSelection,PlanCompanyCompatibility,PlanSellabilityService,SubscriptionAssignmentService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Authenticated quote/proof selection only. Never charges or grants a subscription. */
class PackageCheckoutController extends Controller
{
    private function context(Request $request): array
    {
        $prefix=str_starts_with($request->route()->getName(),'health.') ? 'health' : (str_starts_with($request->route()->getName(),'fbrpos.') ? 'fbrpos' : (str_starts_with($request->route()->getName(),'pos.') ? 'pos' : 'web'));
        $user=auth($prefix)->user();
        abort_unless($user && in_array($user->role,['company_admin','super_admin'],true),403);
        $company=Company::findOrFail($user->company_id);
        return [$company,$prefix];
    }

    public function index(Request $request)
    {
        [$company,$guard]=$this->context($request);
        $plans=PricingPlan::where('is_trial',false)->get()->filter(fn ($plan)=>PlanCompanyCompatibility::matches($company,$plan) && !PlanSellabilityService::isRetired($plan) && ($plan->is_public ?? true));
        $selectedPlan=$plans->firstWhere('id',(int)$request->query('plan_id'));
        $quote=$selectedPlan ? PendingSubscriptionSelection::quote($company,$selectedPlan) : null;
        $checkoutRoute=$guard==='web' ? 'billing.package-checkout' : $guard.'.package-checkout';
        $proofRoute=$guard==='web' ? 'payment-proof.store' : $guard.'.payment-proof.store';
        $layout=match($guard){'pos'=>'pos-layout','fbrpos'=>'fbr-pos-layout','health'=>'health-layout',default=>'app-layout'};
        return response()->view('billing.package-checkout',compact('company','plans','selectedPlan','quote','checkoutRoute','proofRoute','layout'))->header('Cache-Control','private, no-store');
    }

    public function select(Request $request)
    {
        [$company,$guard]=$this->context($request);
        $data=$request->validate(['plan_id'=>'required|integer','billing_cycle'=>'required|in:monthly,quarterly,semi_annual,annual']);
        DB::transaction(function () use ($company,$data) {
            $company=Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $plan=PricingPlan::whereKey($data['plan_id'])->lockForUpdate()->firstOrFail();
            abort_unless(!$plan->is_trial && ($plan->is_public ?? true) && !PlanSellabilityService::isRetired($plan),422);
            PlanCompanyCompatibility::assertMatches($company,$plan);
            $quote=PendingSubscriptionSelection::quote($company,$plan,$data['billing_cycle']);
            if (!$quote) {
                SubscriptionAssignmentService::cycleForPlan($plan,$data['billing_cycle']);
                $quote=DistributorDiscountService::quote($company,$plan,$data['billing_cycle']);
            }
            PendingSubscriptionSelection::remember($company,$quote);
        },3);
        return redirect()->route($guard==='web' ? 'billing.package-checkout' : $guard.'.package-checkout',['plan_id'=>$data['plan_id']]);
    }
}
