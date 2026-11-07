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
        $existing = SubscriptionInvoice::where('payment_proof_id', $proof->id)->lockForUpdate()->first();
        if ($existing) {
            if ((int) $existing->company_id !== (int) $proof->company_id || (int) $existing->subscription_id !== (int) $subscription->id) {
                throw new \LogicException('Payment document attribution does not match.');
            }
            return $existing;
        }
        $company = Company::findOrFail($proof->company_id);
        $catalogue = $subscription->purchase_snapshot;
        $quote = $proof->distributor_quote_snapshot;
        // A distributor discount is already validated by the approval path.
        // Never substitute the customer's unverified entered proof amount.
        $expected = round((float) ($quote['net_quote'] ?? $subscription->final_price), 2);
        $received = round($received, 2);
        if (!is_finite($expected) || $expected < 0) throw new \LogicException('Invalid subscription document amount.');
        $balance = max(0, round($expected - $received, 2));
        $excess = max(0, round($received - $expected, 2));
        $when = $proof->verified_at ?? now();
        $suffix = $when->format('Y').'-'.str_pad((string) $proof->id, 10, '0', STR_PAD_LEFT);
        $common = ['version' => 1, 'currency' => 'PKR', 'kind' => 'subscription',
            'company' => ['id' => (int) $company->id, 'name' => $company->name, 'product_type' => $company->product_type],
            'payment_proof_id' => (int) $proof->id, 'subscription_id' => (int) $subscription->id,
            'review' => ['admin_id' => (int) $proof->verified_by, 'at' => $when->toIso8601String()],
            'amount_due' => number_format($expected, 2, '.', ''), 'verified_received' => number_format($received, 2, '.', ''),
            'allocated' => number_format(min($expected, $received), 2, '.', ''),
            'remaining_balance' => number_format($balance, 2, '.', ''),
            // Evidence only: this is NOT an automatic reusable credit/refund.
            'unallocated_excess' => number_format($excess, 2, '.', '')];
        $invoice = SubscriptionInvoice::create(['company_id' => $company->id, 'subscription_id' => $subscription->id,
            'payment_proof_id' => $proof->id, 'document_number' => 'SUB-'.$suffix, 'amount' => $expected,
            'status' => $balance > 0 ? 'part_paid' : 'paid', 'due_date' => $when->toDateString(),
            'paid_at' => $balance > 0 ? null : $when,
            'document_snapshot' => $common + ['purchase' => $catalogue, 'distributor_quote' => $quote]]);
        SubscriptionPayment::create(['subscription_invoice_id' => $invoice->id, 'amount' => $received,
            'payment_method' => $proof->payment_method, 'transaction_ref' => $proof->reference, 'paid_at' => $when,
            'receipt_number' => 'RCP-'.$suffix,
            'receipt_snapshot' => $common + ['invoice_number' => $invoice->document_number,
                'payment_method' => $proof->payment_method, 'transaction_ref' => $proof->reference]]);
        return $invoice;
    }
}
