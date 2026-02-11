<?php

namespace App\Http\Controllers;

use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\Invoice;
use App\Services\SecurityLogService;
use Illuminate\Http\Request;
use Carbon\Carbon;

class BillingController extends Controller
{
    public function plans()
    {
        $plans = PricingPlan::orderBy('price')->get();
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
                $usagePercent = $limit > 0 ? round(($invoiceCount / $limit) * 100, 1) : 0;
                $daysLeft = Carbon::parse($currentSubscription->end_date)->diffInDays(now(), false);
                $daysLeft = max(0, Carbon::parse($currentSubscription->end_date)->diffInDays(now()));
                $totalDays = Carbon::parse($currentSubscription->start_date)->diffInDays(Carbon::parse($currentSubscription->end_date));

                $usageData = [
                    'invoice_count' => $invoiceCount,
                    'invoice_limit' => $limit,
                    'usage_percent' => min(100, $usagePercent),
                    'days_left' => Carbon::parse($currentSubscription->end_date)->isFuture()
                        ? Carbon::parse($currentSubscription->end_date)->diffInDays(now())
                        : 0,
                    'total_days' => $totalDays > 0 ? $totalDays : 30,
                    'is_expiring_soon' => Carbon::parse($currentSubscription->end_date)->diffInDays(now()) <= 7 && Carbon::parse($currentSubscription->end_date)->isFuture(),
                    'is_expired' => Carbon::parse($currentSubscription->end_date)->isPast(),
                    'needs_upgrade' => $usagePercent >= 80,
                ];
            }
        }

        return view('billing.plans', compact('plans', 'currentSubscription', 'usageData'));
    }

    public function subscribe(Request $request)
    {
        $plan = PricingPlan::findOrFail($request->plan_id);
        $companyId = app('currentCompanyId');

        Subscription::where('company_id', $companyId)->update(['active' => false]);

        Subscription::create([
            'company_id' => $companyId,
            'pricing_plan_id' => $plan->id,
            'start_date' => now(),
            'end_date' => now()->addMonth(),
            'active' => true,
        ]);

        SecurityLogService::log('subscription_changed', auth()->id(), ['plan' => $plan->name, 'company_id' => $companyId]);

        return redirect('/dashboard')->with('success', 'Subscribed to ' . $plan->name . ' plan successfully!');
    }
}
