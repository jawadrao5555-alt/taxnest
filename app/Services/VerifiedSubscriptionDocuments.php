<?php

namespace App\Services;

use App\Models\{Company, PaymentProof, Subscription, SubscriptionInvoice, SubscriptionPayment};
use Illuminate\Support\Facades\{DB, Schema};

/** Documents the existing reviewed assignment; does not grant access or move money. */
final class VerifiedSubscriptionDocuments
{
    public static function record(PaymentProof $proof, Subscription $subscription, float $received): ?SubscriptionInvoice
    {
        // Keep the existing deploy-before-migrate / minimal legacy-schema path.
        // A partially migrated document schema must fail and roll back approval.
        if (!Schema::hasColumn('subscription_invoices', 'payment_proof_id')) return null;
        if (DB::transactionLevel() < 1 || $proof->status !== 'verified' || !$proof->verified_by
            || (int) $proof->company_id !== (int) $subscription->company_id
            || (int) $proof->subscription_id !== (int) $subscription->id || !is_finite($received) || $received <= 0) {
            throw new \LogicException('Verified subscription documents require an atomic, scoped reviewed payment.');
        }
        return self::recordDocument($proof, $subscription, $received,
            round((float) (($proof->distributor_quote_snapshot)['net_quote'] ?? $subscription->final_price), 2),
            'subscription', $subscription->purchase_snapshot, $proof->distributor_quote_snapshot);
    }

    public static function recordAddon(
        PaymentProof $proof,
        ?Subscription $subscription,
        float $received,
        float $expected,
        string $kind,
        array $purchase
    ): ?SubscriptionInvoice {
        if (!in_array($kind, ['extra_branch', 'pos_addon', 'ai_pages'], true)) {
            throw new \LogicException('Unknown verified add-on document kind.');
        }
        if (!Schema::hasColumn('subscription_invoices', 'payment_proof_id')) return null;
        if (DB::transactionLevel() < 1 || $proof->status !== 'verified' || !$proof->verified_by
            || !is_finite($received) || $received <= 0 || !is_finite($expected) || $expected < 0
            || ($subscription && (int) $subscription->company_id !== (int) $proof->company_id)) {
            throw new \LogicException('Verified add-on documents require an atomic, scoped reviewed payment.');
        }

        return self::recordDocument($proof, $subscription, $received, $expected, $kind, $purchase, null);
    }

    private static function recordDocument(
        PaymentProof $proof,
        ?Subscription $subscription,
        float $received,
        float $expected,
        string $kind,
        ?array $purchase,
        ?array $quote
    ): SubscriptionInvoice {
        $existing = SubscriptionInvoice::where('payment_proof_id', $proof->id)->lockForUpdate()->first();
        if ($existing) {
            if ((int) $existing->company_id !== (int) $proof->company_id
                || ($subscription && (int) $existing->subscription_id !== (int) $subscription->id)
                || (!$subscription && $existing->subscription_id !== null)) {
                throw new \LogicException('Payment document attribution does not match.');
            }
            return $existing;
        }
        $company = Company::findOrFail($proof->company_id);
        $expected = round($expected, 2);
        $received = round($received, 2);
        if (!is_finite($expected) || $expected < 0) throw new \LogicException('Invalid subscription document amount.');
        $balance = max(0, round($expected - $received, 2));
        $excess = max(0, round($received - $expected, 2));
        $when = $proof->verified_at ?? now();
        $suffix = $when->format('Y').'-'.str_pad((string) $proof->id, 10, '0', STR_PAD_LEFT);
        $common = ['version' => 1, 'currency' => 'PKR', 'kind' => $kind,
            'company' => ['id' => (int) $company->id, 'name' => $company->name, 'product_type' => $company->product_type],
            'payment_proof_id' => (int) $proof->id, 'subscription_id' => $subscription?->id,
            'review' => ['admin_id' => (int) $proof->verified_by, 'at' => $when->toIso8601String()],
            'amount_due' => number_format($expected, 2, '.', ''), 'verified_received' => number_format($received, 2, '.', ''),
            'allocated' => number_format(min($expected, $received), 2, '.', ''),
            'remaining_balance' => number_format($balance, 2, '.', ''),
            // Evidence only: this is NOT an automatic reusable credit/refund.
            'unallocated_excess' => number_format($excess, 2, '.', '')];
        $prefix = $kind === 'subscription' ? 'SUB-' : 'ADD-';
        $invoice = SubscriptionInvoice::create(['company_id' => $company->id, 'subscription_id' => $subscription?->id,
            'payment_proof_id' => $proof->id, 'document_number' => $prefix.$suffix, 'amount' => $expected,
            'status' => $balance > 0 ? 'part_paid' : 'paid', 'due_date' => $when->toDateString(),
            'paid_at' => $balance > 0 ? null : $when,
            'document_snapshot' => $common + ['purchase' => $purchase, 'distributor_quote' => $quote]]);
        SubscriptionPayment::create(['subscription_invoice_id' => $invoice->id, 'amount' => $received,
            'payment_method' => $proof->payment_method, 'transaction_ref' => $proof->reference, 'paid_at' => $when,
            'receipt_number' => 'RCP-'.$suffix,
            'receipt_snapshot' => $common + ['invoice_number' => $invoice->document_number,
                'payment_method' => $proof->payment_method, 'transaction_ref' => $proof->reference]]);
        return $invoice;
    }
}
