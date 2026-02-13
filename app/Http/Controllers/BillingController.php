<?php

namespace App\Http\Controllers;

use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Branch;
use App\Services\SecurityLogService;
use Illuminate\Http\Request;
use Carbon\Carbon;

class BillingController extends Controller
{
    public function plans()
    {
        $plans = PricingPlan::where('is_trial', false)->orderBy('price')->get();
        $currentSubscription = null;
        $usageData = null;

        $companyId = app()->bound('currentCompanyId') ? app('currentCompanyId') : null;
        if ($companyId) {
            $currentSubscription = Subscription::where('company_id', $companyId)
                ->where('active', true)
                ->with('pricingPlan')
                ->first();

            if ($currentSubscription) {
                $invoiceCount = Invoice::where('company_id', $companyId)->count();
                $limit = $currentSubscription->pricingPlan->invoice_limit;
                $usagePercent = ($limit > 0 && $limit !== -1) ? round(($invoiceCount / $limit) * 100, 1) : ($limit === -1 ? 0 : 0);
                $daysLeft = Carbon::parse($currentSubscription->end_date)->isFuture()
                    ? Carbon::parse($currentSubscription->end_date)->diffInDays(now())
                    : 0;
                $totalDays = Carbon::parse($currentSubscription->start_date)->diffInDays(Carbon::parse($currentSubscription->end_date));

                $userCount = User::where('company_id', $companyId)->where('is_active', true)->count();
                $branchCount = Branch::where('company_id', $companyId)->count();
                $userLimit = $currentSubscription->pricingPlan->user_limit;
                $branchLimit = $currentSubscription->pricingPlan->branch_limit;

                $trialInfo = null;
                if ($currentSubscription->trial_ends_at) {
                    $trialInfo = [
                        'is_trial' => $currentSubscription->isTrialActive(),
                        'is_expired' => $currentSubscription->isTrialExpired(),
                        'days_left' => $currentSubscription->trial_ends_at->isFuture()
                            ? now()->diffInDays($currentSubscription->trial_ends_at)
                            : 0,
                        'ends_at' => $currentSubscription->trial_ends_at->format('M d, Y'),
                    ];
                }

                $usageData = [
                    'invoice_count' => $invoiceCount,
                    'invoice_limit' => $limit,
                    'invoice_limit_display' => $limit === -1 ? 'Unlimited' : $limit,
                    'usage_percent' => $limit === -1 ? 0 : min(100, $usagePercent),
                    'days_left' => $daysLeft,
                    'total_days' => $totalDays > 0 ? $totalDays : 30,
                    'is_expiring_soon' => $daysLeft <= 7 && Carbon::parse($currentSubscription->end_date)->isFuture(),
                    'is_expired' => Carbon::parse($currentSubscription->end_date)->isPast(),
                    'needs_upgrade' => ($limit !== -1 && $usagePercent >= 80),
                    'trial' => $trialInfo,
                    'user_count' => $userCount,
                    'user_limit' => $userLimit,
                    'user_limit_display' => $userLimit === -1 ? 'Unlimited' : $userLimit,
                    'branch_count' => $branchCount,
                    'branch_limit' => $branchLimit,
                    'branch_limit_display' => $branchLimit === -1 ? 'Unlimited' : $branchLimit,
                    'billing_cycle' => $currentSubscription->billing_cycle ?? 'monthly',
                    'discount_percent' => $currentSubscription->discount_percent ?? 0,
                    'final_price' => $currentSubscription->final_price,
                ];
            }
        }

        $billingCycles = [
            'monthly' => ['label' => 'Monthly', 'discount' => 0],
            'quarterly' => ['label' => 'Quarterly', 'discount' => 1],
            'semi_annual' => ['label' => 'Semi-Annual', 'discount' => 3],
            'annual' => ['label' => 'Annual', 'discount' => 6],
        ];

        return view('billing.plans', compact('plans', 'currentSubscription', 'usageData', 'billingCycles'));
    }

    public function subscribe(Request $request)
    {
        $request->validate([
            'plan_id' => 'required|exists:pricing_plans,id',
            'billing_cycle' => 'required|in:monthly,quarterly,semi_annual,annual',
        ]);

        $plan = PricingPlan::findOrFail($request->plan_id);
        $companyId = app('currentCompanyId');
        $cycle = $request->billing_cycle;

        if ($plan->is_trial) {
            return back()->with('error', 'Trial plan cannot be subscribed to directly.');
        }

        $pricing = Subscription::calculateFinalPrice($plan->price, $cycle);
        $months = Subscription::getMonthsForCycle($cycle);

        Subscription::where('company_id', $companyId)->update(['active' => false]);

        Subscription::create([
            'company_id' => $companyId,
            'pricing_plan_id' => $plan->id,
            'billing_cycle' => $cycle,
            'discount_percent' => $pricing['discount_percent'],
            'final_price' => $pricing['final_price'],
            'start_date' => now(),
            'end_date' => now()->addMonths($months),
            'active' => true,
        ]);

        SecurityLogService::log('subscription_changed', auth()->id(), [
            'plan' => $plan->name,
            'cycle' => $cycle,
            'discount' => $pricing['discount_percent'] . '%',
            'final_price' => $pricing['final_price'],
            'company_id' => $companyId,
        ]);

        $cycleLabel = Subscription::getCycleLabel($cycle);
        return redirect('/dashboard')->with('success', "Subscribed to {$plan->name} plan ({$cycleLabel}) for Rs. " . number_format($pricing['final_price']) . "!");
    }

    public function calculatePrice(Request $request)
    {
        $plan = PricingPlan::find($request->plan_id);
        if (!$plan) {
            return response()->json(['error' => 'Plan not found'], 404);
        }

        $cycle = $request->billing_cycle ?? 'monthly';
        $pricing = Subscription::calculateFinalPrice($plan->price, $cycle);

        return response()->json($pricing);
    }

    public function customPlanBuilder()
    {
        if (!in_array(auth()->user()->role, ['super_admin', 'company_admin'])) {
            abort(403);
        }
        return view('billing.custom-plan');
    }

    public function calculateCustomPlan(Request $request)
    {
        $request->validate([
            'invoice_limit' => 'required|integer|min:50|max:100000',
            'user_count' => 'required|integer|min:1|max:500',
            'branch_count' => 'required|integer|min:1|max:100',
            'billing_cycle' => 'required|in:monthly,quarterly,semi_annual,annual',
        ]);

        $invoiceFactor = 2.5;
        $userFactor = 500;
        $branchFactor = 1000;

        $baseRate = ($invoiceFactor * $request->invoice_limit)
                  + ($userFactor * $request->user_count)
                  + ($branchFactor * $request->branch_count);

        $cycle = $request->billing_cycle;
        $discounts = ['monthly' => 0, 'quarterly' => 1, 'semi_annual' => 3, 'annual' => 6];
        $discount = $discounts[$cycle] ?? 0;
        $months = Subscription::getMonthsForCycle($cycle);

        $totalBeforeDiscount = $baseRate * $months;
        $discountAmount = $totalBeforeDiscount * ($discount / 100);
        $finalPrice = $totalBeforeDiscount - $discountAmount;

        return response()->json([
            'base_rate_monthly' => round($baseRate, 2),
            'months' => $months,
            'discount_percent' => $discount,
            'total_before_discount' => round($totalBeforeDiscount, 2),
            'discount_amount' => round($discountAmount, 2),
            'final_price' => round($finalPrice, 2),
            'monthly_effective' => round($finalPrice / $months, 2),
            'breakdown' => [
                'invoices' => round($invoiceFactor * $request->invoice_limit, 2),
                'users' => round($userFactor * $request->user_count, 2),
                'branches' => round($branchFactor * $request->branch_count, 2),
            ],
        ]);
    }

    public function subscribeCustomPlan(Request $request)
    {
        $request->validate([
            'invoice_limit' => 'required|integer|min:50|max:100000',
            'user_count' => 'required|integer|min:1|max:500',
            'branch_count' => 'required|integer|min:1|max:100',
            'billing_cycle' => 'required|in:monthly,quarterly,semi_annual,annual',
        ]);

        $companyId = app('currentCompanyId');
        $company = \App\Models\Company::find($companyId);

        if ($company && $company->is_internal_account) {
            return back()->with('error', 'Internal accounts cannot be billed.');
        }

        $invoiceFactor = 2.5;
        $userFactor = 500;
        $branchFactor = 1000;
        $baseRate = ($invoiceFactor * $request->invoice_limit) + ($userFactor * $request->user_count) + ($branchFactor * $request->branch_count);

        $cycle = $request->billing_cycle;
        $discounts = ['monthly' => 0, 'quarterly' => 1, 'semi_annual' => 3, 'annual' => 6];
        $discount = $discounts[$cycle] ?? 0;
        $months = Subscription::getMonthsForCycle($cycle);
        $totalBeforeDiscount = $baseRate * $months;
        $discountAmount = $totalBeforeDiscount * ($discount / 100);
        $finalPrice = $totalBeforeDiscount - $discountAmount;

        $customPlan = PricingPlan::create([
            'name' => 'Custom Plan',
            'invoice_limit' => $request->invoice_limit,
            'user_limit' => $request->user_count,
            'branch_limit' => $request->branch_count,
            'is_trial' => false,
            'price' => round($baseRate, 2),
            'features' => ['custom' => true, 'invoice_limit' => $request->invoice_limit, 'user_count' => $request->user_count, 'branch_count' => $request->branch_count],
        ]);

        Subscription::where('company_id', $companyId)->update(['active' => false]);

        Subscription::create([
            'company_id' => $companyId,
            'pricing_plan_id' => $customPlan->id,
            'billing_cycle' => $cycle,
            'discount_percent' => $discount,
            'final_price' => round($finalPrice, 2),
            'start_date' => now(),
            'end_date' => now()->addMonths($months),
            'active' => true,
        ]);

        SecurityLogService::log('custom_subscription', auth()->id(), [
            'plan' => 'Custom Plan',
            'invoice_limit' => $request->invoice_limit,
            'user_count' => $request->user_count,
            'branch_count' => $request->branch_count,
            'cycle' => $cycle,
            'final_price' => round($finalPrice, 2),
        ]);

        return redirect('/billing/plans')->with('success', 'Custom plan activated! Rs. ' . number_format($finalPrice) . ' for ' . $months . ' months.');
    }
}
