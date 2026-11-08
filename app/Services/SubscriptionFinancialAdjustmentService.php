<?php

namespace App\Services;

use App\Models\{
    AdminAuditLog, AdminUser, Company, SubscriptionFinancialAdjustment,
    SubscriptionInvoice, SubscriptionPayment
};
use Illuminate\Support\Facades\DB;

/**
 * Records reviewed evidence only. It never changes an invoice, receipt,
 * subscription, entitlement, balance, price or real-world bank transaction.
 */
final class SubscriptionFinancialAdjustmentService
{
    public static function record(array $input, int $actorId): SubscriptionFinancialAdjustment
    {
        return DB::transaction(function () use ($input, $actorId) {
            $actor = AdminUser::query()->lockForUpdate()->findOrFail($actorId);
            if (!in_array($actor->role, ['admin', 'super_admin'], true)) {
                throw new \DomainException('Billing administrator permission is required.');
            }

            $kind = (string) ($input['kind'] ?? '');
            if (!in_array($kind, [
                SubscriptionFinancialAdjustment::CREDIT,
                SubscriptionFinancialAdjustment::REFUND,
                SubscriptionFinancialAdjustment::REVERSAL,
            ], true)) {
                throw new \DomainException('Unknown financial adjustment type.');
            }
            $amount = round((float) ($input['amount'] ?? 0), 2);
            $reason = trim((string) ($input['reason'] ?? ''));
            $key = strtolower(trim((string) ($input['idempotency_key'] ?? '')));
            $paymentId = !empty($input['subscription_payment_id']) ? (int) $input['subscription_payment_id'] : null;
            $reversesId = !empty($input['reverses_adjustment_id']) ? (int) $input['reverses_adjustment_id'] : null;
            $externalReference = trim((string) ($input['external_reference'] ?? '')) ?: null;
            if (!is_finite($amount) || $amount <= 0 || $reason === '' || mb_strlen($reason) > 500
                || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $key)) {
                throw new \DomainException('Complete valid adjustment evidence is required.');
            }

            $existing = SubscriptionFinancialAdjustment::query()->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing) {
                if ((int) $existing->admin_id !== $actorId
                    || (int) $existing->company_id !== (int) ($input['company_id'] ?? 0)
                    || (int) $existing->subscription_invoice_id !== (int) ($input['subscription_invoice_id'] ?? 0)
                    || ($existing->subscription_payment_id === null ? null : (int) $existing->subscription_payment_id) !== $paymentId
                    || ($existing->reverses_adjustment_id === null ? null : (int) $existing->reverses_adjustment_id) !== $reversesId
                    || $existing->kind !== $kind || round((float) $existing->amount, 2) !== $amount
                    || $existing->reason !== $reason || $existing->external_reference !== $externalReference) {
                    throw new \DomainException('Adjustment retry does not match its original evidence.');
                }
                return $existing;
            }

            $company = Company::query()->lockForUpdate()->findOrFail((int) ($input['company_id'] ?? 0));
            $invoice = SubscriptionInvoice::query()->lockForUpdate()
                ->where('company_id', $company->id)->findOrFail((int) ($input['subscription_invoice_id'] ?? 0));
            $payment = null;
            if ($paymentId !== null) {
                $payment = SubscriptionPayment::query()->lockForUpdate()
                    ->where('subscription_invoice_id', $invoice->id)
                    ->findOrFail($paymentId);
            }

            $reverses = null;
            if ($kind === SubscriptionFinancialAdjustment::REVERSAL) {
                $reverses = SubscriptionFinancialAdjustment::query()->lockForUpdate()
                    ->where('company_id', $company->id)
                    ->where('subscription_invoice_id', $invoice->id)
                    ->whereIn('kind', [SubscriptionFinancialAdjustment::CREDIT, SubscriptionFinancialAdjustment::REFUND])
                    ->findOrFail((int) $reversesId);
                if (SubscriptionFinancialAdjustment::where('reverses_adjustment_id', $reverses->id)->lockForUpdate()->exists()) {
                    throw new \DomainException('This adjustment already has a retained reversal.');
                }
                if ($amount !== round((float) $reverses->amount, 2)
                    || (int) ($payment?->id ?? 0) !== (int) ($reverses->subscription_payment_id ?? 0)) {
                    throw new \DomainException('A reversal must exactly identify and offset the original evidence.');
                }
            } elseif ($reversesId !== null) {
                throw new \DomainException('Only a reversal may identify original adjustment evidence.');
            }

            if ($kind === SubscriptionFinancialAdjustment::CREDIT) {
                if ($payment) throw new \DomainException('A credit note is invoice evidence and must not be attributed to a payment.');
                $activeCredits = self::activeTotal($invoice->id, null, SubscriptionFinancialAdjustment::CREDIT);
                if (round($activeCredits + $amount, 2) > round((float) $invoice->amount, 2)) {
                    throw new \DomainException('Retained credit-note evidence cannot exceed the issued invoice.');
                }
            }
            if ($kind === SubscriptionFinancialAdjustment::REFUND) {
                if (!$payment || !$externalReference) {
                    throw new \DomainException('A refund record requires its exact receipt and external reference.');
                }
                $activeRefunds = self::activeTotal($invoice->id, $payment->id, SubscriptionFinancialAdjustment::REFUND);
                if (round($activeRefunds + $amount, 2) > round((float) $payment->amount, 2)) {
                    throw new \DomainException('Retained refund evidence cannot exceed the reviewed receipt.');
                }
            }

            $occurredAt = now();
            $adjustment = SubscriptionFinancialAdjustment::create([
                'company_id' => $company->id,
                'subscription_invoice_id' => $invoice->id,
                'subscription_payment_id' => $payment?->id,
                'reverses_adjustment_id' => $reverses?->id,
                'admin_id' => $actor->id,
                'kind' => $kind,
                'amount' => $amount,
                'currency' => 'PKR',
                'reason' => $reason,
                'external_reference' => $externalReference,
                'idempotency_key' => $key,
                'occurred_at' => $occurredAt,
                'evidence_snapshot' => [
                    'version' => 1,
                    'effect' => 'evidence_only',
                    'company_id' => (int) $company->id,
                    'invoice_id' => (int) $invoice->id,
                    'invoice_number' => $invoice->document_number,
                    'invoice_amount' => number_format((float) $invoice->amount, 2, '.', ''),
                    'payment_id' => $payment?->id,
                    'receipt_number' => $payment?->receipt_number,
                    'payment_amount' => $payment ? number_format((float) $payment->amount, 2, '.', '') : null,
                    'reverses_adjustment_id' => $reverses?->id,
                    'actor_id' => (int) $actor->id,
                    'recorded_at' => $occurredAt->toIso8601String(),
                    'does_not' => ['move_money', 'change_access', 'change_price', 'rewrite_invoice', 'forgive_balance'],
                ],
            ]);
            AdminAuditLog::log($actor->id, 'Subscription financial adjustment recorded',
                'SubscriptionFinancialAdjustment', $adjustment->id, [
                    'company_id' => (int) $company->id,
                    'invoice_id' => (int) $invoice->id,
                    'payment_id' => $payment?->id,
                    'kind' => $kind,
                    'amount' => number_format($amount, 2, '.', ''),
                    'reverses_adjustment_id' => $reverses?->id,
                    'effect' => 'evidence_only',
                ]);
            return $adjustment;
        }, 3);
    }

    private static function activeTotal(int $invoiceId, ?int $paymentId, string $kind): float
    {
        $rows = SubscriptionFinancialAdjustment::query()
            ->where('subscription_invoice_id', $invoiceId)
            ->where('kind', $kind)
            ->when($paymentId === null,
                fn ($q) => $q->whereNull('subscription_payment_id'),
                fn ($q) => $q->where('subscription_payment_id', $paymentId))
            ->lockForUpdate()->get(['id', 'amount']);
        if ($rows->isEmpty()) return 0.0;
        $reversed = SubscriptionFinancialAdjustment::query()
            ->whereIn('reverses_adjustment_id', $rows->pluck('id'))
            ->lockForUpdate()->pluck('reverses_adjustment_id')->all();
        return round((float) $rows->whereNotIn('id', $reversed)->sum('amount'), 2);
    }
}
