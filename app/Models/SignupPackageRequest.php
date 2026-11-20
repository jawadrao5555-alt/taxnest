<?php

namespace App\Models;

use App\Services\DistributorDiscountService;
use App\Services\PlanCompanyCompatibility;
use App\Services\PlanSellabilityService;
use App\Services\ProspectiveSubscriptionPolicy;
use App\Services\RequestedPackageService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class SignupPackageRequest extends Model
{
    protected $fillable = [
        'company_id', 'pricing_plan_id', 'plan_name', 'billing_cycle', 'quoted_amount', 'quote_snapshot',
        'quote_source', 'status', 'reviewer_admin_id', 'reviewed_at', 'rejection_reason', 'subscription_invoice_id',
    ];

    protected $casts = ['quoted_amount' => 'decimal:2', 'quote_snapshot' => 'array', 'reviewed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $sale) {
            if ($sale->isDirty(['company_id', 'pricing_plan_id', 'plan_name', 'billing_cycle', 'quoted_amount', 'quote_snapshot', 'quote_source'])) {
                throw new \LogicException('A captured signup package quote is immutable.');
            }
        });
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function invoice()
    {
        return $this->belongsTo(SubscriptionInvoice::class, 'subscription_invoice_id');
    }

    /** Called under the company's transaction lock. Reopening a request preserves its original quote. */
    public static function capture(Company $company, PricingPlan $plan, string $source): self
    {
        $existing = self::where('company_id', $company->id)->first();
        if ($existing) return $existing;

        PlanCompanyCompatibility::assertMatches($company, $plan);
        if ($plan->is_trial || PlanSellabilityService::isRetired($plan)) {
            throw ValidationException::withMessages(['package' => 'The requested package is no longer available.']);
        }

        $cycle = RequestedPackageService::cycleForPlan($plan, $company->requested_billing_cycle);
        $quote = DistributorDiscountService::quote($company, $plan, $cycle, false);
        return self::create([
            'company_id' => $company->id, 'pricing_plan_id' => $plan->id,
            'plan_name' => $plan->name, 'billing_cycle' => $cycle, 'quoted_amount' => $quote['net_quote'],
            'quote_snapshot' => $quote, 'quote_source' => $source, 'status' => 'pending',
        ]);
    }

    /** Legacy pending signups can receive a new dated quote at review when no signup-time quote exists. */
    public static function forCompanyReview(Company $company): ?self
    {
        if (!Schema::hasTable('signup_package_requests')) return null;

        return DB::transaction(function () use ($company) {
            $company = Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            if ($sale = self::where('company_id', $company->id)->first()) {
                return $sale->status === 'rejected' ? null : $sale;
            }
            if (!$company->requested_plan_id || !in_array('pending', [$company->status, $company->company_status], true)) return null;
            $active = Subscription::where('company_id', $company->id)->where('active', true)->latest('id')->first();
            $snapshot = ProspectiveSubscriptionPolicy::snapshot($active);
            if (!$snapshot || !empty($snapshot['paid_starts_at'])) return null;

            $plan = PricingPlan::findOrFail($company->requested_plan_id);
            return self::capture($company, $plan, 'legacy_review');
        });
    }
}
