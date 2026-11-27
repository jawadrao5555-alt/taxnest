<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\Branch;
use App\Models\Company;
use App\Models\ErpUpgradeSettlement;
use App\Models\PricingPlan;
use App\Models\Scopes\CompanyScope;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Support\NestErps;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class ErpUpgradeCutoverService
{
    public function __construct(
        private ErpUpgradeSettlementService $settlements,
        private ErpUpgradeQuote $quotes,
    ) {}

    public function activeBranches(int $companyId, bool $lock = false): Collection
    {
        return Branch::withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId)
            ->where('is_active', true)->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())->get(['id', 'name']);
    }

    /** New principal writes call with lock=true under the source company lock. */
    public static function sourceIsCutOver(int $companyId, bool $lock = false): bool
    {
        return Schema::hasTable('erp_upgrade_settlements')
            && ErpUpgradeSettlement::where('source_company_id', $companyId)
                ->whereNotNull('activated_at')->when($lock, fn ($query) => $query->lockForUpdate())->first(['id']) !== null;
    }

    public function activate(
        int $sourceCompanyId,
        int $settlementId,
        array $branchMap,
        string $approvalReference,
        int $adminId,
    ): ErpUpgradeSettlement {
        abort_unless(AdminUser::find($adminId)?->isSuperAdmin(), 403, 'Super admin only.');
        $approvalReference = trim($approvalReference);
        if ($approvalReference === '' || mb_strlen($approvalReference) > 150) {
            $this->invalid('activation_reference', 'Record the owner’s cutover approval reference.');
        }
        $branchMap = $this->normalizeBranchMap($branchMap);
        $candidate = ErpUpgradeSettlement::where('source_company_id', $sourceCompanyId)->findOrFail($settlementId);

        return DB::transaction(function () use ($sourceCompanyId, $candidate, $branchMap, $approvalReference, $adminId) {
            [$source, $destination] = $this->settlements->lockCompanies($sourceCompanyId, (int) $candidate->destination_company_id);
            $settlement = ErpUpgradeSettlement::where('source_company_id', $sourceCompanyId)
                ->lockForUpdate()->findOrFail($candidate->id);
            $this->assertSettled($settlement);
            if ($settlement->activated_at) {
                if ($settlement->activation_reference !== $approvalReference || $settlement->branch_map !== $branchMap) {
                    $this->invalid('activation_reference', 'This upgrade was activated with a different approval or branch mapping.');
                }

                return $settlement;
            }
            if (self::sourceIsCutOver($sourceCompanyId, lock: true)) {
                $this->invalid('source_term', 'This Collections account already has an activated ERP upgrade.');
            }

            // The money was settled against the paid source as of the verified
            // review. Revalidate that evidence without changing the settled credit
            // as time passes, including when the destination trial must finish.
            $snapshot = $settlement->quote_snapshot;
            $evidence = $this->quotes->preview(
                $source, (int) $settlement->pricing_plan_id, $settlement->billing_cycle,
                CarbonImmutable::instance($settlement->settled_at), lockEvidence: true,
            );
            if ($evidence['source_invoice_id'] != $settlement->source_invoice_id
                || $evidence['source_evidence'] !== ($snapshot['source_evidence'] ?? null)
                || $evidence['target_price_paisa'] !== $settlement->target_price_paisa
                || $evidence['destination_plan_terms'] != ($snapshot['destination_plan_terms'] ?? null)) {
                $this->invalid('quote', 'The settled source evidence or destination package changed. Reconcile the upgrade before activation.');
            }
            $plan = PricingPlan::whereKey($settlement->pricing_plan_id)->lockForUpdate()->firstOrFail();
            $this->settlements->assertDestination($source, $destination, $plan, $snapshot);
            $school = ErpUpgradeQuote::targetVertical($source) === NestErps::SCHOOL;
            if (!$school && (!in_array('accounts', HealthModuleService::normalize($plan->health_modules), true)
                || !in_array('accounts', HealthModuleService::companyModules($destination), true))) {
                $this->invalid('destination_company_id', 'Enable the ERP Accounts module on an eligible package before switching new collections.');
            }
            $this->assertBranchMap($source, $destination, $plan, $branchMap);

            $activatedAt = CarbonImmutable::now(config('app.timezone'));
            $subscription = $this->activateSubscription($destination, $plan, $settlement, $activatedAt);
            $invoice = $settlement->difference_paisa > 0
                ? $this->issueDocuments($destination, $subscription, $settlement)
                : null;
            $settlement->forceFill([
                'destination_subscription_id' => $subscription->id,
                'branch_map' => $branchMap,
                'activated_by' => $adminId,
                'activated_at' => $activatedAt,
                'activation_reference' => $approvalReference,
            ])->save();
            AdminAuditLog::log($adminId, $school ? 'School ERP cutover activated' : 'Hospital ERP cutover activated', 'ErpUpgradeSettlement', $settlement->id, [
                'source_company_id' => $sourceCompanyId,
                'destination_company_id' => (int) $destination->id,
                'destination_subscription_id' => (int) $subscription->id,
                'destination_invoice_id' => $invoice?->id,
                'mapped_branches' => count($branchMap),
            ]);

            return $settlement;
        }, 3);
    }

    private function assertSettled(ErpUpgradeSettlement $settlement): void
    {
        if (!$settlement->settled_at || !$settlement->settled_by
            || (int) $settlement->allocated_source_invoice_id !== (int) $settlement->source_invoice_id
            || $settlement->verified_received_paisa !== $settlement->difference_paisa
            || $settlement->target_price_paisa <= 0
            || $settlement->credit_paisa < 0 || $settlement->difference_paisa < 0
            || $settlement->credit_paisa + $settlement->difference_paisa !== $settlement->target_price_paisa) {
            $this->invalid('quote', 'Settle the exact verified difference before activating the cutover.');
        }
        $reference = trim((string) $settlement->payment_reference);
        $evidenceMatches = $settlement->difference_paisa === 0
            ? $settlement->payment_method === null && $settlement->payment_reference === null && $settlement->payment_reference_key === null
            : $settlement->payment_method === ErpUpgradeSettlement::PAYMENT_METHOD && $reference !== ''
                && hash('sha256', mb_strtolower($reference)) === $settlement->payment_reference_key;
        if (!$evidenceMatches) {
            $this->invalid('quote', 'The settlement payment evidence requires review.');
        }
    }

    private function normalizeBranchMap(array $branchMap): array
    {
        $normalized = [];
        foreach ($branchMap as $source => $destination) {
            $options = ['options' => ['min_range' => 1]];
            $source = filter_var($source, FILTER_VALIDATE_INT, $options);
            $destination = (is_int($destination) || is_string($destination))
                ? filter_var($destination, FILTER_VALIDATE_INT, $options) : false;
            if (!$source || !$destination || isset($normalized[$source])) {
                $this->invalid('branch_map', 'Select a valid destination branch for each source branch.');
            }
            $normalized[$source] = $destination;
        }
        ksort($normalized, SORT_NUMERIC);

        return $normalized;
    }

    private function assertBranchMap(Company $source, Company $destination, PricingPlan $plan, array $branchMap): void
    {
        $sourceIds = $this->activeBranches((int) $source->id, lock: true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $destinationIds = $this->activeBranches((int) $destination->id, lock: true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (ErpUpgradeQuote::targetVertical($source) === NestErps::SCHOOL && $destinationIds === []) {
            $this->invalid('branch_map', 'Create an active destination campus before switching School Collections.');
        }
        if ($sourceIds !== array_keys($branchMap)) {
            $this->invalid('branch_map', 'Map every active source branch before activating the cutover.');
        }
        if (array_diff($branchMap, $destinationIds) || count(array_unique($branchMap)) !== count($branchMap)) {
            $this->invalid('branch_map', 'Each source branch needs a distinct active branch of the selected ERP company.');
        }
        if ($plan->branch_limit !== null && (int) $plan->branch_limit !== -1 && count($destinationIds) > (int) $plan->branch_limit) {
            $this->invalid('branch_map', 'The target ERP package does not cover the existing destination branches.');
        }
    }

    private function activateSubscription(
        Company $destination,
        PricingPlan $plan,
        ErpUpgradeSettlement $settlement,
        CarbonImmutable $activatedAt,
    ): Subscription {
        $trial = Subscription::where('company_id', $destination->id)->where('active', true)->lockForUpdate()->first();
        $months = Subscription::getMonthsForCycle($settlement->billing_cycle);
        $gross = $settlement->target_price_paisa / 100;
        $coverage = ($settlement->verified_received_paisa + $settlement->credit_paisa) / 100;
        // The verified difference and separately allocated source credit fund
        // the term together. Only the difference enters the receipt ledger.
        $lifecycle = $trial ? ProspectiveSubscriptionPolicy::paid($trial, $months, (int) $plan->id, true, $coverage, $gross) : null;
        $start = $lifecycle ? CarbonImmutable::parse($lifecycle['paid_starts_at'])->setTimezone($activatedAt->timezone) : $activatedAt;
        $end = $lifecycle ? CarbonImmutable::parse($lifecycle['paid_ends_at'])->setTimezone($activatedAt->timezone) : $start->addMonthsNoOverflow($months);
        if ($start->greaterThan($activatedAt)) {
            $this->invalid('destination_company_id', 'The verified ERP term is scheduled for later. Review the destination trial before cutover.');
        }

        $priced = SubscriptionAssignmentService::computePrice($plan, $settlement->billing_cycle);
        $priced['final_price'] = $priced['base_price'] = $settlement->difference_paisa / 100;
        $purchase = SubscriptionPurchaseSnapshot::capture(
            $destination, $plan, $priced, $start->toDateString(), $end->toDateString(), true,
        );
        $purchase['source'] = SubscriptionPurchaseSnapshot::SOURCE_ERP_UPGRADE;
        $purchase['erp_upgrade'] = [
            'settlement_id' => (int) $settlement->id,
            'gross_package_paisa' => $settlement->target_price_paisa,
            'applied_credit_paisa' => $settlement->credit_paisa,
            'verified_cash_paisa' => $settlement->verified_received_paisa,
        ];
        if ($trial) {
            $trial->update(['active' => false]);
        }

        return Subscription::create([
            'company_id' => $destination->id,
            'pricing_plan_id' => $plan->id,
            'billing_cycle' => $settlement->billing_cycle,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'active' => true,
            'discount_percent' => $priced['discount_percent'],
            'final_price' => $priced['final_price'],
            'purchase_snapshot' => $purchase,
            'lifecycle_snapshot' => $lifecycle,
        ]);
    }

    private function issueDocuments(Company $company, Subscription $subscription, ErpUpgradeSettlement $settlement): SubscriptionInvoice
    {
        $paidAt = $settlement->settled_at;
        $net = $settlement->verified_received_paisa / 100;
        $amount = number_format($net, 2, '.', '');
        $common = [
            'version' => 1,
            'kind' => 'subscription',
            'currency' => 'PKR',
            'company' => ['id' => (int) $company->id, 'name' => $company->name, 'product_type' => $company->product_type],
            'subscription_id' => (int) $subscription->id,
            'review' => ['admin_id' => (int) $settlement->settled_by, 'at' => $paidAt->toIso8601String()],
            'amount_due' => $amount,
            'verified_received' => $amount,
            'allocated' => $amount,
            'remaining_balance' => '0.00',
            'erp_upgrade' => $subscription->purchase_snapshot['erp_upgrade'],
        ];
        $number = $paidAt->format('Y').'-'.str_pad((string) $settlement->id, 10, '0', STR_PAD_LEFT);
        $invoice = SubscriptionInvoice::create([
            'company_id' => $company->id,
            'subscription_id' => $subscription->id,
            'document_number' => 'SUB-ERP-'.$number,
            'amount' => $net,
            'status' => 'paid',
            'due_date' => $paidAt->toDateString(),
            'paid_at' => $paidAt,
            'document_snapshot' => $common + ['purchase' => $subscription->purchase_snapshot],
        ]);
        SubscriptionPayment::create([
            'subscription_invoice_id' => $invoice->id,
            'receipt_number' => 'RCP-ERP-'.$number,
            'amount' => $net,
            'payment_method' => $settlement->payment_method,
            'transaction_ref' => $settlement->payment_reference,
            'paid_at' => $paidAt,
            'receipt_snapshot' => $common + ['invoice_number' => $invoice->document_number,
                'payment_method' => $settlement->payment_method, 'transaction_ref' => $settlement->payment_reference],
        ]);

        return $invoice;
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
