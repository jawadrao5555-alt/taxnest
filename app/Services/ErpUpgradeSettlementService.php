<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\Company;
use App\Models\ErpUpgradeSettlement;
use App\Models\PaymentProof;
use App\Models\PricingPlan;
use App\Models\SignupPackageRequest;
use App\Models\Subscription;
use App\Models\User;
use App\Support\NestErps;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a reviewed Hospital upgrade as its own financial event. A normal
 * subscription payment would treat the applied credit as cash or an unpaid
 * balance, and would activate access before migration is approved.
 */
final class ErpUpgradeSettlementService
{
    public function __construct(private ErpUpgradeQuote $quotes) {}

    public function freeze(
        int $sourceCompanyId,
        int $destinationCompanyId,
        int $planId,
        string $cycle,
        int $adminId,
        string $ownerAuthorizationReference
    ): ErpUpgradeSettlement {
        $this->requireOwnerAdmin($adminId);
        $ownerAuthorizationReference = trim($ownerAuthorizationReference);
        if ($ownerAuthorizationReference === '' || mb_strlen($ownerAuthorizationReference) > 150) {
            $this->invalid('owner_authorization_reference', 'Record the owner’s approval or support case reference.');
        }
        if (!in_array($cycle, SubscriptionAssignmentService::SELLABLE_CYCLES, true)) {
            $this->invalid('billing_cycle', 'Choose a supported billing period.');
        }

        return DB::transaction(function () use ($sourceCompanyId, $destinationCompanyId, $planId, $cycle, $adminId, $ownerAuthorizationReference) {
            [$source, $destination] = $this->lockCompanies($sourceCompanyId, $destinationCompanyId);
            $effective = CarbonImmutable::now(config('app.timezone'));
            $snapshot = $this->quoteSnapshot($source, $destination, $planId, $cycle, $effective);
            $quoteKey = hash('sha256', json_encode([
                $sourceCompanyId, $destinationCompanyId, $planId, $snapshot, $ownerAuthorizationReference,
            ], JSON_THROW_ON_ERROR));
            $existing = ErpUpgradeSettlement::where('quote_key', $quoteKey)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }
            $this->assertChargeAvailable($sourceCompanyId, $snapshot['source_invoice_id']);

            $quote = new ErpUpgradeSettlement;
            $quote->forceFill([
                'source_company_id' => $sourceCompanyId,
                'destination_company_id' => $destinationCompanyId,
                'source_invoice_id' => $snapshot['source_invoice_id'],
                'pricing_plan_id' => $planId,
                'billing_cycle' => $cycle,
                'quoted_on' => $effective->toDateString(),
                'quoted_by' => $adminId,
                'owner_authorization_reference' => $ownerAuthorizationReference,
                'quote_key' => $quoteKey,
                'quote_snapshot' => $snapshot,
                'target_price_paisa' => $snapshot['target_price_paisa'],
                'credit_paisa' => $snapshot['credit_paisa'],
                'difference_paisa' => $snapshot['difference_paisa'],
            ])->save();

            AdminAuditLog::log($adminId, 'ERP upgrade quote frozen', 'ErpUpgradeSettlement', $quote->id, [
                'source_company_id' => $sourceCompanyId,
                'destination_company_id' => $destinationCompanyId,
                'difference_paisa' => $quote->difference_paisa,
            ]);

            return $quote;
        }, 3);
    }

    public function settle(
        int $sourceCompanyId,
        int $settlementId,
        string $verifiedReceivedAmount,
        ?string $paymentReference,
        int $adminId
    ): ErpUpgradeSettlement {
        $this->requireOwnerAdmin($adminId);
        $received = $this->parsePaisa($verifiedReceivedAmount);
        $reference = trim($paymentReference ?? '');
        if ($received > 0 && ($reference === '' || mb_strlen($reference) > 120)) {
            $this->invalid('payment_reference', 'Enter the unique reference for the verified upgrade payment.');
        }
        if ($received === 0 && $reference !== '') {
            $this->invalid('payment_reference', 'An upgrade fully covered by credit has no cash payment reference.');
        }
        $normalizedReference = $reference !== '' ? mb_strtolower($reference) : null;
        $referenceKey = $normalizedReference !== null ? hash('sha256', $normalizedReference) : null;

        $candidate = ErpUpgradeSettlement::where('source_company_id', $sourceCompanyId)->findOrFail($settlementId);
        try {
            return DB::transaction(function () use ($sourceCompanyId, $candidate, $received, $reference, $normalizedReference, $referenceKey, $adminId) {
                [$source, $destination] = $this->lockCompanies($sourceCompanyId, (int) $candidate->destination_company_id);
                $quote = ErpUpgradeSettlement::where('source_company_id', $sourceCompanyId)
                    ->lockForUpdate()->findOrFail($candidate->id);
                if ($received !== $quote->difference_paisa) {
                    $this->invalid('verified_received_amount', 'The verified amount must equal the frozen upgrade difference exactly.');
                }
                if ($quote->settled_at) {
                    if ($quote->payment_reference_key !== $referenceKey) {
                        $this->invalid('payment_reference', 'This upgrade already has a different verified payment reference.');
                    }

                    return $quote;
                }

                $effective = CarbonImmutable::now(config('app.timezone'));
                if (!$quote->quoted_on->isSameDay($effective)) {
                    $this->invalid('quote', 'This quote expired. Review a fresh same-day estimate before settlement.');
                }
                $snapshot = $this->quoteSnapshot($source, $destination, (int) $quote->pricing_plan_id, $quote->billing_cycle, $effective);
                if ($snapshot != $quote->quote_snapshot) {
                    $this->invalid('quote', 'The source payment evidence or destination package changed. Review a fresh quote.');
                }
                $this->assertChargeAvailable($sourceCompanyId, (int) $quote->source_invoice_id);
                if ($referenceKey !== null) {
                    $this->assertReferenceAvailable($normalizedReference, $referenceKey);
                }

                $quote->forceFill([
                    'allocated_source_invoice_id' => $quote->source_invoice_id,
                    'verified_received_paisa' => $received,
                    'payment_method' => $received > 0 ? ErpUpgradeSettlement::PAYMENT_METHOD : null,
                    'payment_reference' => $reference !== '' ? $reference : null,
                    'payment_reference_key' => $referenceKey,
                    'settled_by' => $adminId,
                    'settled_at' => $effective,
                ])->save();
                AdminAuditLog::log($adminId, 'ERP upgrade difference settled', 'ErpUpgradeSettlement', $quote->id, [
                    'source_company_id' => (int) $source->id,
                    'destination_company_id' => (int) $destination->id,
                    'package_price_paisa' => $quote->target_price_paisa,
                    'applied_credit_paisa' => $quote->credit_paisa,
                    'verified_received_paisa' => $received,
                ]);

                return $quote;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            // The source company is serialized. An equal reference can still
            // arrive concurrently from a different pair of companies.
            if ($referenceKey !== null && ErpUpgradeSettlement::where('payment_reference_key', $referenceKey)->exists()) {
                $this->invalid('payment_reference', 'This payment reference is already recorded for an ERP upgrade.');
            }
            throw $exception;
        }
    }

    /** Both company locks precede the settlement lock in all upgrade writes. */
    public function lockCompanies(int $sourceId, int $destinationId): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('ERP upgrade company locks require an active transaction.');
        }
        if ($sourceId === $destinationId) {
            $this->invalid('destination_company_id', 'Choose a separate Hospital ERP company for the upgrade.');
        }
        $companies = Company::whereIn('id', [$sourceId, $destinationId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $source = $companies->get($sourceId);
        $destination = $companies->get($destinationId);
        if (!$source) {
            $this->invalid('source_term', 'The source company is unavailable.');
        }
        if (!$destination) {
            $this->invalid('destination_company_id', 'Choose an active Hospital ERP company.');
        }

        return [$source, $destination];
    }

    /** Read and lock the destination criteria within the caller's company-locked transaction. */
    public function assertDestination(
        Company $source,
        Company $destination,
        PricingPlan $plan,
        ?array $frozenSnapshot = null
    ): void
    {
        $scope = $this->eligibleDestinationScope($source, $destination);
        PlanCompanyCompatibility::assertMatches($destination, $plan);
        if ($frozenSnapshot !== null && $scope != array_intersect_key($frozenSnapshot, $scope)) {
            $this->invalid('destination_company_id', 'The destination owner or trial changed. Reconcile the upgrade before activation.');
        }
    }

    private function eligibleDestinationScope(Company $source, Company $destination): array
    {
        if ((int) $source->id === (int) $destination->id
            || !NestErps::isProductType($destination->product_type)
            || NestErps::verticalOf($destination) !== NestErps::HEALTH
            || $destination->health_org_type !== 'hospital'
            || $destination->status !== 'approved'
            || $destination->company_status !== 'active'
            || $destination->suspended_at) {
            $this->invalid('destination_company_id', 'Choose an active Hospital ERP company.');
        }

        $ownerScope = $this->ownerScope($source, $destination);
        $active = Subscription::where('company_id', $destination->id)->where('active', true)
            ->lockForUpdate()->limit(2)->get();
        if ($active->count() > 1
            || $active->contains(fn (Subscription $term) => !$this->canReplaceTrial($term))
            || PaymentProof::subscriptionKind()->where('company_id', $destination->id)
                ->where('status', 'pending')->lockForUpdate()->first()
            || SignupPackageRequest::where('company_id', $destination->id)
                ->where('status', 'pending')->lockForUpdate()->first()) {
            $this->invalid('destination_company_id', 'Resolve the destination subscription or pending payment before upgrading.');
        }

        return ['owner_user_ids' => $ownerScope, 'destination_trial_id' => $active->first()?->id];
    }

    private function canReplaceTrial(Subscription $term): bool
    {
        if (!$term->trial_ends_at || $term->hasActiveOverride() || (float) $term->final_price !== 0.0) {
            return false;
        }
        if (!$term->hasLifecycleSnapshot()) {
            return true;
        }
        $policy = ProspectiveSubscriptionPolicy::snapshot($term);

        return $policy && empty($policy['paid_starts_at'])
            && CarbonImmutable::parse($policy['trial_ends_at'])->lessThanOrEqualTo(CarbonImmutable::now());
    }

    private function quoteSnapshot(
        Company $source,
        Company $destination,
        int $planId,
        string $cycle,
        CarbonImmutable $effective
    ): array {
        $scope = $this->eligibleDestinationScope($source, $destination);
        $quote = $this->quotes->preview($source, $planId, $cycle, $effective, lockEvidence: true);
        PlanCompanyCompatibility::assertMatches($destination, PricingPlan::whereKey($planId)->lockForUpdate()->first());

        return $quote + $scope;
    }

    private function ownerScope(Company $source, Company $destination): array
    {
        $owners = User::whereIn('company_id', [$source->id, $destination->id])->where('is_active', true)
            ->whereIn('role', ['owner', 'company_admin', 'admin'])
            ->orderBy('id')->lockForUpdate()->get(['id', 'company_id', 'email'])->groupBy('company_id');
        $sourceOwners = $owners->get($source->id, collect());
        $destinationOwners = $owners->get($destination->id, collect());
        $matches = $sourceOwners->crossJoin($destinationOwners)->filter(function (array $pair): bool {
            [$left, $right] = $pair;
            $email = trim((string) $left->email);

            return $email !== '' && strcasecmp($email, trim((string) $right->email)) === 0;
        });
        if ($matches->count() !== 1) {
            $this->invalid('destination_company_id', 'Both companies must have one matching active owner account.');
        }

        return array_map(fn (User $owner) => (int) $owner->id, $matches->first());
    }

    private function assertChargeAvailable(int $sourceCompanyId, int $invoiceId): void
    {
        if (ErpUpgradeSettlement::where('allocated_source_invoice_id', $invoiceId)->lockForUpdate()->first()) {
            $this->invalid('source_term', 'Credit from this paid source invoice has already been used.');
        }
        if (ErpUpgradeSettlement::where('source_company_id', $sourceCompanyId)->whereNotNull('settled_at')->first()) {
            $this->invalid('source_term', 'This source company already has a settled ERP upgrade.');
        }
    }

    private function assertReferenceAvailable(string $normalizedReference, string $referenceKey): void
    {
        if (ErpUpgradeSettlement::where('payment_reference_key', $referenceKey)->first()
            || PaymentProof::whereIn('status', ['pending', 'verified'])
                ->whereRaw('LOWER(TRIM(reference)) = ?', [$normalizedReference])->first()) {
            $this->invalid('payment_reference', 'This payment reference is already in use.');
        }
    }

    private function requireOwnerAdmin(int $adminId): void
    {
        abort_unless(AdminUser::find($adminId)?->isSuperAdmin(), 403, 'Super admin only.');
    }

    private function parsePaisa(string $amount): int
    {
        if (!preg_match('/\A(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?\z/', trim($amount), $matches)) {
            $this->invalid('verified_received_amount', 'Enter a valid PKR amount with up to two decimal places.');
        }

        return (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    private function invalid(string $field, string $reason): never
    {
        throw ValidationException::withMessages([$field => $reason]);
    }
}
