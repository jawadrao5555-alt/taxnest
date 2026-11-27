<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PaymentProof;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Services\ProspectiveSubscriptionPolicy;
use App\Services\SubscriptionAssignmentService;
use App\Services\TrialSubscriptionService;
use App\Services\WebsiteClientPaidSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebsiteClientPaidSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_eligibility_needs_current_full_same_company_package_evidence(): void
    {
        $this->travelTo(CarbonImmutable::parse('2028-11-27 10:30:00', config('app.timezone')));
        $company = $this->company('settled');
        $other = $this->company('excluded');
        $plan = $this->plan();
        $term = $this->legacyTerm($company, $plan);
        $this->legacyTerm($other, $plan);

        $this->assertSame([], WebsiteClientPaidSubscription::eligibleCompanyIds([$company->id, $other->id]));
        $this->charge($other, $term);
        $this->charge($company, $term, ['amount' => 100, 'status' => 'part_paid', 'paid_at' => null]);
        $this->charge($company, $term, ['document_snapshot' => ['kind' => 'extra_branch']]);
        $this->assertSame([], WebsiteClientPaidSubscription::eligibleCompanyIds([$company->id, $other->id]));

        $this->charge($company, $term);
        $this->assertSame([$company->id], WebsiteClientPaidSubscription::eligibleCompanyIds([$company->id, $other->id]));
        $term->update(['override_type' => 'grace', 'override_until' => now()->addWeek()]);
        $this->travelTo($term->end_date);
        $this->assertSame([], WebsiteClientPaidSubscription::eligibleCompanyIds([$company->id]));
    }

    public function test_existing_verified_package_proof_without_document_remains_recognized(): void
    {
        $company = $this->company('historical');
        $plan = $this->plan();
        $term = $this->legacyTerm($company, $plan);
        $proof = PaymentProof::create([
            'company_id' => $company->id, 'pricing_plan_id' => $plan->id, 'subscription_id' => $term->id,
            'request_type' => 'subscription', 'amount' => 1000, 'proof_path' => 'synthetic', 'status' => 'pending',
        ]);
        $this->assertSame([], WebsiteClientPaidSubscription::eligibleCompanyIds([$company->id]));

        $proof->update(['status' => 'verified', 'verified_by' => 1, 'verified_at' => now(), 'distributor_net_amount' => 500]);
        $this->assertSame([], WebsiteClientPaidSubscription::eligibleCompanyIds([$company->id]));
        $proof->update(['distributor_net_amount' => 1000, 'request_type' => 'pos_addon']);
        $this->assertSame([], WebsiteClientPaidSubscription::eligibleCompanyIds([$company->id]));
        $proof->update(['request_type' => 'subscription']);
        $this->assertSame([$company->id], WebsiteClientPaidSubscription::eligibleCompanyIds([$company->id]));

        $this->charge($company, $term, ['payment_proof_id' => $proof->id, 'status' => 'part_paid', 'paid_at' => null]);
        $this->assertSame([], WebsiteClientPaidSubscription::eligibleCompanyIds([$company->id]));
    }

    public function test_wrong_product_and_latest_overrides_do_not_inherit_earlier_payment(): void
    {
        $company = $this->company('company');
        $old = $this->legacyTerm($company, $this->plan());
        $this->charge($company, $old);
        $this->assertSame([$company->id], WebsiteClientPaidSubscription::eligibleCompanyIds([$company->id]));

        $wrong = $this->legacyTerm($company, $this->plan('fbrpos'));
        $this->charge($company, $wrong);
        $this->assertSame([], WebsiteClientPaidSubscription::eligibleCompanyIds([$company->id]));
        $wrong->update(['active' => false]);
        $grant = $this->legacyTerm($company, $this->plan(), ['final_price' => 0, 'override_type' => 'lifetime']);
        $this->assertSame([], WebsiteClientPaidSubscription::eligibleCompanyIds([$company->id]));
        $grant->update(['active' => false]);
        $this->assertSame([$company->id], WebsiteClientPaidSubscription::eligibleCompanyIds([$company->id]));
    }

    public function test_prospective_term_waits_for_effective_paid_start_and_early_renewal_retains_previous_term(): void
    {
        $this->travelTo(CarbonImmutable::parse('2028-11-27 12:00:00', 'UTC'));
        $company = $this->company('prospective');
        $plan = $this->plan();
        TrialSubscriptionService::ensureSelectedTrial($company->id, 'di', $plan->id);
        $quote = SubscriptionAssignmentService::computePrice($plan, 'annual', $company);
        $first = SubscriptionAssignmentService::assign($company->id, $plan->id, 'annual', paidRenewal: true, verifiedReceived: $quote['final_price']);
        $firstDates = ProspectiveSubscriptionPolicy::display($first);
        $this->assertSame([], WebsiteClientPaidSubscription::eligibleCompanyIds([$company->id]));

        $this->travelTo(CarbonImmutable::parse($firstDates['paid_starts_at']));
        $this->assertSame([$company->id], WebsiteClientPaidSubscription::eligibleCompanyIds([$company->id]));
        $renewed = SubscriptionAssignmentService::assign($company->id, $plan->id, 'annual', paidRenewal: true, verifiedReceived: $quote['final_price']);
        $renewedDates = ProspectiveSubscriptionPolicy::display($renewed);
        $this->assertSame($firstDates['paid_ends_at'], $renewedDates['paid_starts_at']);
        $this->assertSame([$company->id], WebsiteClientPaidSubscription::eligibleCompanyIds([$company->id]));

        $this->travelTo(CarbonImmutable::parse($renewedDates['paid_starts_at']));
        $this->assertSame([$company->id], WebsiteClientPaidSubscription::eligibleCompanyIds([$company->id]));
        $this->travelTo(CarbonImmutable::parse($renewedDates['paid_ends_at']));
        $this->assertSame('grace', ProspectiveSubscriptionPolicy::display($renewed)['phase']);
        $this->assertSame([], WebsiteClientPaidSubscription::eligibleCompanyIds([$company->id]));
    }

    private function company(string $identifier): Company
    {
        return Company::create(['name' => 'Synthetic showcase '.$identifier, 'ntn' => 'SHOWCASE-'.$identifier, 'product_type' => 'di']);
    }

    private function plan(string $product = 'di'): PricingPlan
    {
        return PricingPlan::create([
            'name' => 'Example paid package', 'product_type' => $product, 'is_trial' => false, 'price' => 1000,
            'invoice_limit' => 10, 'user_limit' => 3, 'branch_limit' => 1,
        ]);
    }

    private function legacyTerm(Company $company, PricingPlan $plan, array $details = []): Subscription
    {
        return Subscription::create($details + [
            'company_id' => $company->id, 'pricing_plan_id' => $plan->id, 'active' => true,
            'final_price' => 1000, 'billing_cycle' => 'annual', 'start_date' => now()->subDay(), 'end_date' => now()->addMonth(),
        ]);
    }

    private function charge(Company $company, Subscription $term, array $details = []): SubscriptionInvoice
    {
        return SubscriptionInvoice::create($details + [
            'company_id' => $company->id, 'subscription_id' => $term->id, 'amount' => 1000,
            'status' => 'paid', 'paid_at' => now(), 'due_date' => now(),
        ]);
    }
}
