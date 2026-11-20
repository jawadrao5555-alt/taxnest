<?php

namespace App\Services;

use App\Models\{AdminAuditLog, AdminUser, Company, Franchise, PaymentProof, PricingPlan, SignupPackageRequest, Subscription, SubscriptionInvoice};
use Illuminate\Support\Facades\{DB, Schema, Validator};
use Illuminate\Validation\ValidationException;

/** One verified collection, one entitlement decision, one charge and one receipt. */
final class AdminPackageSettlement
{
    public static function assign(int $companyId, int $planId, string $cycle, array $payment, int $adminId, bool $pendingSignup = false): SubscriptionInvoice
    {
        self::assertActor($adminId);
        $cycle = SubscriptionAssignmentService::purchaseCycle($cycle);
        $data = self::paymentData($payment, !$pendingSignup);
        $origin = $pendingSignup ? 'signup' : 'assignment';

        return DB::transaction(function () use ($companyId, $planId, $cycle, $data, $adminId, $origin, $pendingSignup) {
            $company = Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
            $fingerprint = self::fingerprint($origin, $planId, $cycle, $data);
            if ($document = self::replayed($company, $data, $fingerprint)) return $document;
            if (in_array($company->status, ['suspended', 'rejected'], true)
                || in_array($company->company_status, ['suspended', 'rejected'], true)) {
                self::invalid('Resolve the company suspension or rejection before assigning a package.');
            }
            $request = SignupPackageRequest::where('company_id', $companyId)->where('status', 'pending')->lockForUpdate()->first();
            if ($pendingSignup && (!$request || (int) $request->pricing_plan_id !== $planId || $request->billing_cycle !== $cycle)) {
                self::invalid('The signup package or billing period changed. Reload the request.');
            }
            if (!$pendingSignup && $request) self::invalid('Confirm this company’s pending signup from Package requests.');
            self::guardPendingProof($companyId);
            if ($pendingSignup && PaymentProof::subscriptionKind()->where('company_id', $companyId)->where('status', 'verified')->lockForUpdate()->first()) {
                self::invalid('A package payment has already been verified for this signup. Open its receipt.');
            }
            if (!$pendingSignup) {
                $active = Subscription::where('company_id', $companyId)->where('active', true)->latest('id')->lockForUpdate()->first();
                if ((int) $active?->id !== (int) $data['expected_subscription_id']) {
                    self::invalid('The active subscription changed after this quote. Reload before confirming another sale.');
                }
            }
            $plan = PricingPlan::whereKey($planId)->lockForUpdate()->firstOrFail();
            if ($plan->is_trial) self::invalid('Choose a paid package.');
            PlanCompanyCompatibility::assertMatches($company, $plan);
            $quote = $pendingSignup ? $request->quote_snapshot : DistributorDiscountService::quote($company, $plan, $cycle, false);
            if (!is_array($quote)) self::invalid('The original signup quote is missing. Review the request.');
            if ($error = DistributorDiscountService::validateSnapshot($quote, $company, $plan, $cycle)) self::invalid($error);
            self::requireFullAmount($data, $quote['net_quote']);
            $subscription = SubscriptionAssignmentService::assign($companyId, $planId, $cycle,
                paidRenewal: true, approvedQuote: $quote, verifiedReceived: (float) $data['verified_received_amount']);
            $document = self::collect($company, $subscription, $data, $adminId, $origin, $fingerprint, $quote);
            if ($company->status !== 'approved' || $company->company_status !== 'active') {
                $company->update(['status' => 'approved', 'company_status' => 'active']);
            }
            return $document;
        }, 3);
    }

    /** Record a separately attested collection against a specific, previously undocumented paid term. */
    public static function historical(int $companyId, int $subscriptionId, array $payment, int $adminId): SubscriptionInvoice
    {
        self::assertActor($adminId);
        $data = self::paymentData($payment);
        return DB::transaction(function () use ($companyId, $subscriptionId, $data, $adminId) {
            $company = Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
            $fingerprint = self::fingerprint('history', $subscriptionId, '', $data);
            if ($document = self::replayed($company, $data, $fingerprint)) return $document;
            $subscription = self::historicalSubscription($companyId, $subscriptionId);
            self::guardPendingProof($companyId);
            $legacy = self::outstandingCharge($companyId, $subscriptionId);
            if ((int) ($data['subscription_invoice_id'] ?? 0) !== (int) $legacy?->id) {
                self::invalid('Reload and select the exact unpaid charge before recording its payment.');
            }
            if (PaymentProof::subscriptionKind()->where('company_id', $companyId)->where('status', 'verified')
                ->where(function ($q) use ($subscriptionId) { $q->where('subscription_id', $subscriptionId)->orWhereNull('subscription_id'); })
                ->lockForUpdate()->first()) {
                self::invalid('Verified package evidence already exists or is unallocated. Review it before recording a new collection.');
            }
            self::requireFullAmount($data, $subscription->final_price);
            return self::collect($company, $subscription, $data, $adminId, 'history', $fingerprint, $subscription->distributor_quote_snapshot, $legacy);
        }, 3);
    }

    /** Issue missing documents only against identifiable, previously verified collection evidence. */
    public static function historicalVerifiedProof(int $companyId, int $subscriptionId, int $proofId, int $adminId): SubscriptionInvoice
    {
        self::assertActor($adminId);
        return DB::transaction(function () use ($companyId, $subscriptionId, $proofId, $adminId) {
            Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
            $subscription = Subscription::where('company_id', $companyId)->whereKey($subscriptionId)->lockForUpdate()->firstOrFail();
            $proof = PaymentProof::subscriptionKind()->where('company_id', $companyId)->where('subscription_id', $subscriptionId)
                ->where('status', 'verified')->whereKey($proofId)->lockForUpdate()->firstOrFail();
            $existing = SubscriptionInvoice::where('company_id', $companyId)->where('subscription_id', $subscriptionId)
                ->where('payment_proof_id', $proofId)->first();
            if ($existing) return $existing;
            if ($proof->admin_settlement_invoice_id) {
                return SubscriptionInvoice::where('company_id', $companyId)->where('subscription_id', $subscriptionId)
                    ->findOrFail($proof->admin_settlement_invoice_id);
            }
            $legacy = self::outstandingCharge($companyId, $subscriptionId);
            $amount = $proof->distributor_net_amount;
            if (!$proof->verified_by || !$proof->verified_at || !is_numeric($amount) || (float) $amount <= 0
                || !is_numeric($subscription->final_price) || $subscription->final_price <= 0
                || !$proof->payment_method || !$proof->payment_date) {
                self::invalid('Reviewed amount, method, payment date, package price or verification evidence is missing.');
            }
            if (PaymentProof::subscriptionKind()->where('company_id', $companyId)->where('subscription_id', $subscriptionId)
                ->where('status', 'verified')->where('id', '!=', $proofId)->exists()) {
                self::invalid('Several verified proofs reference this subscription. Review their allocations.');
            }
            $document = VerifiedSubscriptionDocuments::record($proof, $subscription, (float) $amount, $proof->payment_date, $legacy);
            if (!$document) self::invalid('Subscription document storage is unavailable.');
            if ($legacy) $proof->forceFill(['admin_settlement_invoice_id' => $document->id])->save();
            AdminAuditLog::log($adminId, 'Historical verified package receipt issued', 'PaymentProof', $proofId, [
                'company_id' => $companyId, 'subscription_invoice_id' => $document->id
            ]);
            return $document;
        }, 3);
    }

    private static function historicalSubscription(int $companyId, int $id): Subscription
    {
        $sub = Subscription::where('company_id', $companyId)->whereKey($id)->with('pricingPlan')->lockForUpdate()->firstOrFail();
        $snapshot = ProspectiveSubscriptionPolicy::snapshot($sub);
        if (!$sub->pricing_plan_id || !$sub->billing_cycle || !is_numeric($sub->final_price) || $sub->final_price <= 0
            || $sub->pricingPlan?->is_trial || ($sub->hasLifecycleSnapshot() && empty($snapshot['paid_starts_at']))
            || (!$sub->hasLifecycleSnapshot() && $sub->trial_ends_at)) {
            self::invalid('This record does not establish a paid package with a known price and period.');
        }
        return $sub;
    }

    private static function collect(Company $company, Subscription $sub, array $data, int $adminId, string $origin, string $fingerprint, ?array $quote, ?SubscriptionInvoice $legacy = null): SubscriptionInvoice
    {
        $proof = new PaymentProof;
        $proof->forceFill([
            'company_id' => $company->id, 'pricing_plan_id' => $sub->pricing_plan_id, 'billing_cycle' => $sub->billing_cycle,
            'subscription_id' => $sub->id, 'request_type' => 'subscription', 'amount' => $data['verified_received_amount'],
            'payment_method' => $data['payment_method'], 'payment_date' => $data['payment_date'], 'reference' => $data['reference'],
            'proof_path' => '', 'status' => 'verified', 'verified_by' => $adminId, 'verified_at' => now(),
            'admin_settlement_origin' => $origin, 'admin_settlement_key' => $data['idempotency_key'],
            'admin_settlement_fingerprint' => $fingerprint, 'admin_settlement_invoice_id' => $legacy?->id, 'distributor_quote_snapshot' => $quote,
            'distributor_net_amount' => $data['verified_received_amount'],
        ]);
        self::attributeFranchise($company, $proof);
        $proof->save();
        $document = VerifiedSubscriptionDocuments::record($proof, $sub, (float) $data['verified_received_amount'], $proof->payment_date, $legacy);
        if (!$document) self::invalid('Subscription document storage is unavailable.');
        AgentCommissionService::recordForProof($proof, atomic: true);
        FranchiseCommissionService::recordForProof($proof, atomic: true);
        AdminAuditLog::log($adminId, 'Admin package payment recorded', 'PaymentProof', $proof->id, [
            'company_id' => $company->id, 'subscription_id' => $sub->id, 'subscription_invoice_id' => $document->id,
            'origin' => $origin, 'method' => $data['payment_method'], 'payment_date' => $data['payment_date'],
            'verified_received_amount' => $data['verified_received_amount'],
        ]);
        return $document;
    }

    /** Capture the same verification-time attribution for uploaded and counter receipts. */
    public static function attributeFranchise(Company $company, PaymentProof $proof): void
    {
        if ($company->franchise_id && Schema::hasColumn('payment_proofs', 'franchise_id_at_verification')) {
            $franchise = Franchise::find($company->franchise_id);
            if ($franchise && $franchise->isActive()) {
                $proof->franchise_id_at_verification = $franchise->id;
                $proof->franchise_rate_at_verification = $franchise->commission_rate;
                $proof->franchise_attribution_conflict = (bool) $company->agent_id;
            }
        }
    }

    private static function guardPendingProof(int $companyId): void
    {
        if (PaymentProof::subscriptionKind()->where('company_id', $companyId)->where('status', 'pending')->lockForUpdate()->first()) {
            self::invalid('This company already has a package payment awaiting review in Payment Proofs.');
        }
    }

    private static function outstandingCharge(int $companyId, int $subscriptionId): ?SubscriptionInvoice
    {
        $charges = SubscriptionInvoice::packageCharge()->where('company_id', $companyId)
            ->where('subscription_id', $subscriptionId)->lockForUpdate()->get();
        if ($charges->isEmpty()) return null;
        $charge = $charges->first();
        if ($charges->count() !== 1 || $charge->document_snapshot !== null || $charge->payment_proof_id
            || $charge->paid_at || !in_array($charge->status, ['pending', 'unpaid'], true)
            || $charge->payments()->lockForUpdate()->first()) {
            self::invalid('An issued document, allocated payment or ambiguous charge already exists. Review the existing allocation.');
        }
        return $charge;
    }

    private static function assertActor(int $id): void
    {
        abort_unless((int) AdminUser::requireBillingAdministrator()->id === $id, 403);
    }

    private static function paymentData(array $payment, bool $requireTerm = false): array
    {
        $rules = [
            'verified_received_amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:1000000000'],
            'payment_method' => ['required', 'in:cash,card'], 'payment_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:120'], 'idempotency_key' => ['required', 'uuid'],
        ];
        if (!$requireTerm) $rules['subscription_invoice_id'] = ['nullable', 'integer', 'min:1'];
        if ($requireTerm) $rules['expected_subscription_id'] = ['required', 'integer', 'min:0'];
        $data = Validator::make($payment, $rules)->validate();
        $data['reference'] = trim((string) ($data['reference'] ?? '')) ?: null;
        $data['verified_received_amount'] = number_format((float) $data['verified_received_amount'], 2, '.', '');
        if ($requireTerm) $data['expected_subscription_id'] = (int) $data['expected_subscription_id'];
        return $data;
    }

    private static function fingerprint(string $origin, int $target, string $cycle, array $data): string
    {
        return hash('sha256', json_encode([$origin, $target, $cycle, $data], JSON_THROW_ON_ERROR));
    }

    private static function replayed(Company $company, array $data, string $fingerprint): ?SubscriptionInvoice
    {
        $prior = PaymentProof::where('company_id', $company->id)->where('admin_settlement_key', $data['idempotency_key'])->lockForUpdate()->first();
        if (!$prior) return null;
        if (!hash_equals((string) $prior->admin_settlement_fingerprint, $fingerprint)) self::invalid('This confirmation has already been used with different payment details.');
        $document = SubscriptionInvoice::where('company_id', $company->id)->where('subscription_id', $prior->subscription_id);
        return $prior->admin_settlement_invoice_id ? $document->findOrFail($prior->admin_settlement_invoice_id)
            : $document->where('payment_proof_id', $prior->id)->firstOrFail();
    }

    private static function requireFullAmount(array $data, mixed $expected): void
    {
        if (!is_numeric($expected) || $expected <= 0 || $data['verified_received_amount'] !== number_format((float) $expected, 2, '.', '')) {
            self::invalid('The confirmed amount must equal the full package price.');
        }
    }

    private static function invalid(string $message): never
    {
        throw ValidationException::withMessages(['payment' => $message]);
    }
}
