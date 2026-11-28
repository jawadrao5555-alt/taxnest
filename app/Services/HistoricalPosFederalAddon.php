<?php

namespace App\Services;

use App\Models\{AdminAuditLog, AdminUser, Company, PaymentProof, PosAddon, Subscription, SubscriptionInvoice};
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Validation\ValidationException;

/** Records a previously agreed federal feature without configuring reporting. */
final class HistoricalPosFederalAddon
{
    private const VERSION = 1;

    public static function available(): bool
    {
        return Schema::hasColumn('pos_addons', 'historical_purchase')
            && Schema::hasColumn('subscription_invoices', 'payment_proof_id')
            && PaymentProof::addonCodesColumnExists();
    }

    public static function currentTerm(Company $company): ?Subscription
    {
        $active = Subscription::where('company_id', $company->id)->where('active', true)->limit(2)->get();

        return $active->count() === 1 ? $active->first() : null;
    }

    public static function grant(Company $company): ?PosAddon
    {
        return PosAddon::where('company_id', $company->id)
            ->whereIn('addon_code', PosFederalAddonAccess::CODES)->orderBy('id')->first();
    }

    public static function blocker(Company $company, ?Subscription $term, ?PosAddon $grant): ?string
    {
        if (!self::available()) {
            return 'Historical FBR add-on records are unavailable until billing migrations are applied.';
        }
        if ($company->product_type !== 'pos' || !PosAddonService::isPraCompany($company)
            || $company->is_internal_account || $company->status !== 'approved' || $company->company_status !== 'active') {
            return 'Select an approved, active PRA POS customer account.';
        }
        if (!$term || !in_array($term->billing_cycle, ['annual', 'yearly'], true)
            || !$term->start_date || $term->start_date->isFuture() || !$term->end_date || !$term->end_date->isFuture()
            || !PosFederalAddonAccess::hasPaidPackage($company)
            || !in_array($company->id, WebsiteClientPaidSubscription::eligibleCompanyIds([$company->id]), true)) {
            return 'Record the payment for this customer’s current annual package before recording its FBR add-on.';
        }
        if ($grant) {
            return 'This customer already has an FBR add-on record. Review it before recording another.';
        }
        if (array_intersect(PosAddonService::pendingCodes($company), PosFederalAddonAccess::CODES)
            || self::hasPendingFederalSignup($company)) {
            return 'An FBR add-on request is awaiting payment review. Review the original request first.';
        }
        if (self::hasRetainedFederalPayment($company, $term)) {
            return 'A verified FBR add-on payment already exists for this term or needs allocation. Review the existing payment before recording another.';
        }

        return null;
    }

    /** @param array{subscription_id:int,addon_code:string,purchase_mode:string,evidence_reference:string,idempotency_key:string,received_amount?:string,payment_date?:string,payment_method?:string,payment_reference?:string} $input */
    public static function record(int $companyId, array $input): PosAddon
    {
        $actor = AdminUser::requireBillingAdministrator();
        if (!self::available()) {
            self::invalid('Historical FBR add-on records are unavailable until billing migrations are applied.');
        }
        if (!PosFederalAddonAccess::isFederalCode($input['addon_code'])
            || !in_array($input['purchase_mode'], ['included', 'separate'], true)) {
            self::invalid('Select a single federal add-on and its original purchase type.');
        }

        $evidence = trim($input['evidence_reference']);
        if ($evidence === '') {
            self::invalid('Enter the original sale or collection reference.');
        }
        $separate = $input['purchase_mode'] === 'separate';
        $money = $separate ? number_format((float) $input['received_amount'], 2, '.', '') : '0.00';
        $payment = $separate ? [
            'amount' => $money,
            'date' => $input['payment_date'],
            'method' => $input['payment_method'],
            'reference' => trim($input['payment_reference'] ?? '') ?: null,
        ] : null;
        $fingerprint = hash('sha256', json_encode([
            $companyId, (int) $input['subscription_id'], $input['addon_code'], $input['purchase_mode'], $evidence, $payment,
        ], JSON_THROW_ON_ERROR));

        $result = DB::transaction(function () use ($companyId, $input, $actor, $separate, $payment, $money, $evidence, $fingerprint) {
            $company = Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
            $grant = self::grant($company);
            if ($grant && self::isReplay($grant, $input['idempotency_key'], $fingerprint)) {
                return $grant;
            }

            // Lock ordering follows package assignment and add-on proof submission.
            $selected = Subscription::where('company_id', $company->id)->whereKey($input['subscription_id'])
                ->lockForUpdate()->firstOrFail();
            $term = self::currentTerm($company);
            if (!$term || $selected->id !== $term->id) {
                self::invalid('The active package changed. Open the customer’s current term and review it again.');
            }
            if ($blocker = self::blocker($company, $term, $grant)) {
                self::invalid($blocker);
            }
            $code = $input['addon_code'];
            if (!PosFederalAddonAccess::selectionAllowed($company, [$code])) {
                self::invalid('The other FBR channel is already configured for this customer. Review that channel before recording this add-on.');
            }

            $proof = $separate
                ? self::recordCollection($company, $term, $code, $payment, $evidence, $actor->id)
                : self::includedPackageProof($company, $term);

            $grant = PosAddon::create([
                'company_id' => $company->id, 'subscription_id' => $term->id, 'addon_code' => $code,
                'payment_proof_id' => $proof->id, 'billing_cycle' => 'annual', 'active' => true,
                'amount' => $money, 'starts_at' => max($term->start_date->toDateString(), $proof->payment_date->toDateString()),
                'ends_at' => $term->end_date->toDateString(),
                'historical_purchase' => [
                    'version' => self::VERSION, 'mode' => $input['purchase_mode'], 'evidence_reference' => $evidence,
                    'recorded_by' => $actor->id, 'recorded_at' => now()->toIso8601String(),
                    'idempotency_key' => $input['idempotency_key'], 'fingerprint' => $fingerprint,
                ],
            ]);
            AdminAuditLog::log($actor->id, 'Historical PRA POS FBR add-on recorded', 'PosAddon', $grant->id, [
                'company_id' => $company->id, 'subscription_id' => $term->id, 'payment_proof_id' => $proof->id,
                'addon_code' => $code, 'mode' => $input['purchase_mode'], 'amount' => $money,
                'evidence_reference' => $evidence,
            ]);

            return $grant;
        }, 3);

        PosFeatureService::flushGateCaches();

        return $result;
    }

    public static function coversIncluded(PosAddon $grant, PaymentProof $proof): bool
    {
        $purchase = $grant->historical_purchase;

        return (float) $grant->amount === 0.0 && is_array($purchase)
            && ($purchase['version'] ?? null) === self::VERSION && ($purchase['mode'] ?? null) === 'included'
            && !empty($purchase['recorded_by']) && filled($purchase['evidence_reference'] ?? null)
            && $proof->kind() === 'subscription' && $proof->status === 'verified' && $proof->verified_by
            && $proof->payment_date && !$proof->payment_date->isFuture()
            && (float) ($proof->distributor_net_amount ?? $proof->amount) > 0;
    }

    private static function includedPackageProof(Company $company, Subscription $term): PaymentProof
    {
        $proofs = PaymentProof::subscriptionKind()->where('company_id', $company->id)->where('subscription_id', $term->id)
            ->where('pricing_plan_id', $term->pricing_plan_id)->where('status', 'verified')
            ->where('verified_by', '>', 0)->where('verified_at', '<=', now())->where('payment_date', '<=', today())
            ->get()->filter(fn (PaymentProof $proof) => (float) ($proof->distributor_net_amount ?? $proof->amount) > 0);
        $invoices = SubscriptionInvoice::packageCharge()->where('company_id', $company->id)->where('subscription_id', $term->id)->get();
        $proof = $proofs->count() === 1 ? $proofs->first() : null;
        if ($proof) {
            if ($invoices->isEmpty() && (float) ($proof->distributor_net_amount ?? $proof->amount) >= (float) $term->final_price) {
                return $proof;
            }
            if ($invoices->contains(fn (SubscriptionInvoice $invoice) => $invoice->isPaid()
                && $invoice->paid_at && !$invoice->paid_at->isFuture()
                && ((int) $invoice->payment_proof_id === $proof->id || (int) $proof->admin_settlement_invoice_id === $invoice->id))) {
                return $proof;
            }
        }

        self::invalid('Identify the verified payment for this exact package term before recording an included add-on.');
    }

    private static function recordCollection(Company $company, Subscription $term, string $code, array $payment, string $evidence, int $actor): PaymentProof
    {
        $date = Carbon::parse($payment['date'], config('app.timezone'))->startOfDay();
        $proof = PaymentProof::create([
            'company_id' => $company->id, 'subscription_id' => $term->id, 'pricing_plan_id' => $term->pricing_plan_id,
            'request_type' => 'pos_addon', 'billing_cycle' => 'annual', 'addon_codes' => json_encode([$code], JSON_THROW_ON_ERROR),
            'amount' => $payment['amount'], 'payment_method' => $payment['method'], 'payment_date' => $date,
            'reference' => $payment['reference'], 'notes' => $evidence, 'proof_path' => '',
            'status' => 'verified', 'verified_by' => $actor, 'verified_at' => now(),
        ]);
        $amount = (float) $payment['amount'];
        $document = VerifiedSubscriptionDocuments::recordAddon($proof, $term, $amount, $amount, 'pos_addon', [
            'label' => 'Historical PRA POS FBR add-on', 'codes' => [$code], 'evidence_reference' => $evidence,
        ], paidOn: $date);
        if (!$document) {
            self::invalid('Billing document storage is unavailable. No add-on payment was recorded.');
        }

        return $proof;
    }

    private static function hasPendingFederalSignup(Company $company): bool
    {
        return (PosSignupBundle::pending($company)?->quote_snapshot[PosSignupBundle::KEY]['federal'] ?? null) !== null;
    }

    private static function hasRetainedFederalPayment(Company $company, Subscription $term): bool
    {
        $proofs = PaymentProof::posAddonKind()->where('company_id', $company->id)->where('status', 'verified')
            ->where(fn ($scope) => $scope->where('subscription_id', $term->id)->orWhereNull('subscription_id'))->get();
        if ($proofs->contains(fn (PaymentProof $proof) => self::includesFederalCode($proof->addonCodeList())
            || self::includesFederalCode($proof->addon_quote_snapshot['codes'] ?? null))) {
            return true;
        }

        // Some older financial imports retained a receipt but lost its proof or entitlement link.
        return SubscriptionInvoice::where('company_id', $company->id)->where('document_snapshot->kind', 'pos_addon')
            ->where(fn ($scope) => $scope->where('subscription_id', $term->id)->orWhereNull('subscription_id'))
            ->where(fn ($settled) => $settled->where(fn ($invoice) => $invoice->where('status', 'paid')->whereNotNull('paid_at'))
                ->orWhereHas('payments', fn ($receipt) => $receipt->where('amount', '>', 0)->whereNotNull('paid_at')))
            ->get()->contains(fn (SubscriptionInvoice $invoice) =>
                self::includesFederalCode($invoice->document_snapshot['purchase']['codes'] ?? null)
                    || self::includesFederalCode($invoice->document_snapshot['purchase']['quote']['codes'] ?? null));
    }

    private static function includesFederalCode(mixed $codes): bool
    {
        return is_array($codes) && array_intersect(PosFederalAddonAccess::CODES, array_filter($codes, 'is_string')) !== [];
    }

    private static function isReplay(PosAddon $grant, string $key, string $fingerprint): bool
    {
        $purchase = $grant->historical_purchase;
        if (!is_array($purchase) || ($purchase['idempotency_key'] ?? null) !== $key) {
            return false;
        }
        if (!hash_equals((string) ($purchase['fingerprint'] ?? ''), $fingerprint)) {
            self::invalid('This confirmation was already used with different sale details. Reload the record before making changes.');
        }

        return true;
    }

    private static function invalid(string $message): never
    {
        throw ValidationException::withMessages(['historical_addon' => $message]);
    }
}
