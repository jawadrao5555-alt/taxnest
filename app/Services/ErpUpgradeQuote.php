<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PaymentProof;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\SubscriptionFinancialAdjustment;
use App\Models\SubscriptionInvoice;
use App\Support\NestErps;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** A read-only estimate. Settling an upgrade requires its own verified workflow. */
final class ErpUpgradeQuote
{
    public static function targetVertical(Company $source): ?string
    {
        $school = $source->isSchoolFeePilot();
        $hospital = $source->isHospitalCollectionPilot();

        return match (true) {
            $school && !$hospital => NestErps::SCHOOL,
            $hospital && !$school => NestErps::HEALTH,
            default => null,
        };
    }

    public function plansFor(Company $source): Collection
    {
        $vertical = self::targetVertical($source);

        return $vertical === null ? collect() : $this->planQuery($vertical)->orderBy('name')->orderBy('id')->get()
            ->filter(fn (PricingPlan $plan) => $this->canSellPlan($plan, $vertical));
    }

    private function planQuery(string $vertical): Builder
    {
        $plans = PricingPlan::query()
            ->whereIn('product_type', NestErps::PRODUCT_TYPES)
            ->where('is_trial', false)->where('price', '>', 0);

        return $vertical === NestErps::SCHOOL
            ? $plans->where(NestErps::VERTICAL_COLUMN, NestErps::SCHOOL)
            : $plans->where(fn (Builder $query) => $query->where(NestErps::VERTICAL_COLUMN, NestErps::HEALTH)
                ->orWhereNull(NestErps::VERTICAL_COLUMN)->orWhere(NestErps::VERTICAL_COLUMN, ''));
    }

    private function canSellPlan(PricingPlan $plan, string $vertical): bool
    {
        return PlanSellabilityService::isSellable($plan)
            && ($vertical === NestErps::SCHOOL || in_array('ipd', HealthModuleService::normalize($plan->health_modules), true));
    }

    public function preview(
        Company $source,
        int $planId,
        string $cycle,
        ?CarbonImmutable $asOf = null,
        bool $lockEvidence = false
    ): array
    {
        $asOf = ($asOf ?? CarbonImmutable::now())->setTimezone(config('app.timezone'));
        $vertical = self::targetVertical($source);
        if ($vertical === null || $source->company_status !== 'active'
            || $source->status !== 'approved' || $source->suspended_at) {
            $this->unavailable('Choose an active School or Hospital Collections POS account.');
        }

        $plan = $this->planQuery($vertical)->when($lockEvidence, fn ($query) => $query->lockForUpdate())->find($planId);
        if (!$plan || !$this->canSellPlan($plan, $vertical)) {
            $message = $vertical === NestErps::SCHOOL ? 'Choose an available School ERP package.'
                : 'Choose an available Healthcare package with inpatient care.';
            throw ValidationException::withMessages(['pricing_plan_id' => $message]);
        }
        $targetPrice = $this->paisa(SubscriptionAssignmentService::computePrice($plan, $cycle)['final_price']);
        if ($targetPrice <= 0) {
            throw ValidationException::withMessages(['pricing_plan_id' => 'This period requires a positive configured package price.']);
        }

        $active = Subscription::query()->where('company_id', $source->id)->where('active', true)
            ->when($lockEvidence, fn ($query) => $query->lockForUpdate())->limit(2)->get();
        if ($active->count() !== 1 || $active->first()->hasActiveOverride()) {
            $this->unavailable('An unambiguous active paid term is required before estimating credit.');
        }
        $subscription = $active->first();
        [$start, $end, $basePaid, $evidence] = $this->verifiedSource($source, $subscription, $asOf, $lockEvidence);

        $totalDays = (int) $start->startOfDay()->diffInDays($end->startOfDay());
        $remainingDays = (int) $asOf->startOfDay()->diffInDays($end->startOfDay());
        if ($totalDays <= 0 || $totalDays > 366 || $remainingDays < 0 || $remainingDays > $totalDays) {
            $this->unavailable('The paid term dates require review.');
        }
        $unusedValue = intdiv($basePaid * $remainingDays, $totalDays);
        $credit = min($targetPrice, $unusedValue);

        return [
            'plan_name' => $plan->name,
            'cycle' => $cycle,
            'as_of' => $asOf->toDateString(),
            'source_term_ends' => $end->toDateString(),
            'remaining_days' => $remainingDays,
            'total_days' => $totalDays,
            'target_price_paisa' => $targetPrice,
            'unused_source_value_paisa' => $unusedValue,
            'credit_paisa' => $credit,
            'difference_paisa' => $targetPrice - $credit,
            'source_invoice_id' => $evidence['invoice_id'],
            'source_evidence' => hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR)),
            'destination_plan_terms' => SubscriptionPurchaseSnapshot::planTerms($plan),
        ];
    }

    private function verifiedSource(Company $source, Subscription $subscription, CarbonImmutable $asOf, bool $lockEvidence): array
    {
        $purchase = $subscription->purchase_snapshot;
        $price = $purchase['price'] ?? [];
        $cycle = $price['cycle'] ?? null;
        if (($purchase['version'] ?? null) !== 1 || ($purchase['source'] ?? null) !== 'paid_assignment'
            || (int) ($purchase['company_id'] ?? 0) !== (int) $source->id
            || (int) ($purchase['plan']['id'] ?? 0) !== (int) $subscription->pricing_plan_id
            || ($purchase['plan']['product_type'] ?? null) !== $source->product_type
            || ($purchase['plan']['is_trial'] ?? null) !== false || ($price['currency'] ?? null) !== 'PKR'
            || !in_array($cycle, SubscriptionAssignmentService::SELLABLE_CYCLES, true)
            || $cycle !== SubscriptionAssignmentService::normalizeCycle($subscription->billing_cycle)) {
            $this->unavailable('The source term does not have verified paid-package evidence.');
        }

        if ($subscription->hasLifecycleSnapshot()) {
            $policy = ProspectiveSubscriptionPolicy::snapshot($subscription);
            if (empty($policy['paid_starts_at']) || empty($policy['paid_ends_at'])) {
                $this->unavailable('The source invoice does not identify a verified paid period.');
            }
            $start = CarbonImmutable::parse($policy['paid_starts_at'])->setTimezone($asOf->timezone);
            $end = CarbonImmutable::parse($policy['paid_ends_at'])->setTimezone($asOf->timezone);
        } else {
            $start = $subscription->start_date?->toImmutable();
            $end = $subscription->end_date?->toImmutable();
        }
        if (!$start || !$end || $start->greaterThan($asOf) || !$end->greaterThan($asOf)
            || !$start->addMonthsNoOverflow(Subscription::getMonthsForCycle($cycle))->equalTo($end)
            || ($purchase['period']['start'] ?? null) !== $start->toDateString()
            || ($purchase['period']['end'] ?? null) !== $end->toDateString()) {
            $this->unavailable('The current paid period is expired, scheduled, or requires reconciliation.');
        }

        $invoices = SubscriptionInvoice::query()->where('company_id', $source->id)
            ->where('subscription_id', $subscription->id)->packageCharge()
            ->when($lockEvidence, fn ($query) => $query->lockForUpdate())->limit(2)->get();
        $invoice = $invoices->first();
        $document = $invoice?->document_snapshot;
        $amount = $this->paisa($price['final_price'] ?? null);
        $base = $this->paisa($price['base_price'] ?? null);
        $extras = $this->paisa($price['extra_branch_price'] ?? null);
        if ($invoices->count() !== 1 || !$invoice->isPaid() || !$invoice->paid_at || $invoice->paid_at->greaterThan($asOf)
            || ($document['version'] ?? null) !== 1 || ($document['kind'] ?? null) !== 'subscription'
            || ($document['currency'] ?? null) !== 'PKR' || ($document['purchase'] ?? null) != $purchase
            || (int) ($document['company']['id'] ?? 0) !== (int) $source->id
            || (int) ($document['subscription_id'] ?? 0) !== (int) $subscription->id
            || (int) ($document['payment_proof_id'] ?? 0) !== (int) $invoice->payment_proof_id
            || $amount <= 0 || $base <= 0 || $extras < 0 || $base + $extras !== $amount
            || $this->paisa($invoice->amount) !== $amount || $this->paisa($document['allocated'] ?? null) !== $amount
            || $this->paisa($document['remaining_balance'] ?? null) !== 0
            || SubscriptionFinancialAdjustment::where('subscription_invoice_id', $invoice->id)->exists()) {
            $this->unavailable('Verify the exact paid package invoice and review any adjustments before estimating credit.');
        }

        $proof = PaymentProof::query()->where('company_id', $source->id)->where('subscription_id', $subscription->id)
            ->where('status', 'verified')->when($lockEvidence, fn ($query) => $query->lockForUpdate())->find($invoice->payment_proof_id);
        $receiptQuery = $invoice->payments()->limit(2);
        if ($lockEvidence) {
            $receiptQuery->lockForUpdate();
        }
        $receipts = $receiptQuery->get();
        $receipt = $receipts->first();
        if (!$proof?->verified_by || !$proof->verified_at || $proof->verified_at->greaterThan($asOf)
            || $proof->kind() !== 'subscription' || (int) $proof->pricing_plan_id !== (int) $subscription->pricing_plan_id
            || $receipts->count() !== 1 || !$receipt->receipt_number || !$receipt->paid_at || $receipt->paid_at->greaterThan($asOf)
            || (int) ($receipt->receipt_snapshot['payment_proof_id'] ?? 0) !== (int) $proof->id
            || $this->paisa($receipt->receipt_snapshot['allocated'] ?? null) !== $amount
            || $this->paisa($receipt->amount) < $amount) {
            $this->unavailable('A reviewed payment receipt for this package is required to estimate credit.');
        }

        return [$start, $end, $base, [
            'invoice_id' => $invoice->id,
            'subscription_id' => $subscription->id,
            'proof_id' => $proof->id,
            'receipt_id' => $receipt->id,
            'paid_base_paisa' => $base,
            'term_starts_at' => $start->toIso8601String(),
            'term_ends_at' => $end->toIso8601String(),
            'verified_by' => $proof->verified_by,
            'verified_at' => $proof->verified_at->toIso8601String(),
        ]];
    }

    private function paisa(mixed $value): int
    {
        if (!is_numeric($value) || !is_finite((float) $value) || $value < 0 || $value > 9999999999.99) {
            return -1;
        }

        return (int) round((float) $value * 100);
    }

    private function unavailable(string $message): never
    {
        throw ValidationException::withMessages(['source_term' => $message]);
    }
}
