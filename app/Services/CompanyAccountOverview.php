<?php

namespace App\Services;

use App\Models\{AdminAuditLog, Branch, Company, PaymentProof, Subscription, SubscriptionInvoice, SubscriptionPayment};
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

/** Read-only company-linked source records; no new billing/access policy. */
final class CompanyAccountOverview
{
    public static function for(Company $company): array
    {
        $id = $company->id;
        $subscriptionsAvailable = Schema::hasTable('subscriptions');
        $auditAvailable = Schema::hasTable('admin_audit_logs');
        $current = $subscriptionsAvailable
            ? Subscription::where('company_id', $id)->where('active', true)->orderByDesc('id')->with('pricingPlan')->first() : null;
        $history = $subscriptionsAvailable
            ? Subscription::where('company_id', $id)->with('pricingPlan')->orderByDesc('id')->paginate(10, ['*'], 'subscription_page')->withQueryString() : collect();
        $access = $subscriptionsAvailable ? SubscriptionAccessService::hasAccess($company)
            : ['allowed' => null, 'reason' => 'Subscription records are unavailable.', 'override' => null];
        $control = match (true) {
            $company->trashed() => 'bin',
            $company->status === 'suspended' || $company->company_status === 'suspended' => 'suspended',
            $company->status === 'rejected' || $company->company_status === 'rejected' => 'rejected',
            $company->status === 'pending' || $company->company_status === 'pending' => 'pending',
            $company->company_status !== 'active' => 'inactive',
            default => 'active',
        };

        $events = $auditAvailable ? AdminAuditLog::with('admin')->where(function ($query) use ($id, $subscriptionsAvailable) {
            $query->where(fn ($q) => $q->where('target_type', 'Company')->where('target_id', $id));
            if ($subscriptionsAvailable) {
                $query->orWhere(fn ($q) => $q->where('target_type', 'Subscription')
                    ->whereIn('target_id', Subscription::where('company_id', $id)->select('id')));
            }
        })->orderByDesc('id')->paginate(15, ['*'], 'account_history_page')->withQueryString() : collect();
        $suspension = $control === 'suspended' && $auditAvailable
            ? AdminAuditLog::with('admin')->where('target_type', 'Company')->where('target_id', $id)
                ->where('action', 'Company suspended')->orderByDesc('id')->first() : null;
        if ($suspension && !self::matchesSuspension($company, $suspension)) {
            $suspension = null;
        }

        return [
            'current' => $current, 'history' => $history, 'access' => $access, 'control' => $control,
            'active_count' => $subscriptionsAvailable ? Subscription::where('company_id', $id)->where('active', true)->count() : null,
            'subscriptions_available' => $subscriptionsAvailable, 'audit_available' => $auditAvailable,
            'events' => $events, 'suspension' => $suspension,
            'state_token' => $auditAvailable ? CompanyAccountStateService::token($company) : null,
            'branches' => Schema::hasTable('branches') ? Branch::where('company_id', $id)->count() : null,
            'proofs_available' => Schema::hasTable('payment_proofs'),
            'proofs' => Schema::hasTable('payment_proofs') ? PaymentProof::where('company_id', $id)->orderByDesc('id')->paginate(10, ['*'], 'proof_page')->withQueryString() : collect(),
            'charges_available' => Schema::hasTable('subscription_invoices'),
            'charges' => Schema::hasTable('subscription_invoices') ? SubscriptionInvoice::where('company_id', $id)->orderByDesc('id')->paginate(10, ['*'], 'charge_page')->withQueryString() : collect(),
            'payments_available' => Schema::hasTable('subscription_invoices') && Schema::hasTable('subscription_payments'),
            'payments' => Schema::hasTable('subscription_invoices') && Schema::hasTable('subscription_payments')
                ? SubscriptionPayment::whereHas('invoice', fn ($q) => $q->where('company_id', $id))->orderByDesc('id')->paginate(10, ['*'], 'payment_page')->withQueryString() : collect(),
            'as_of' => now(),
        ];
    }

    private static function matchesSuspension(Company $company, AdminAuditLog $event): bool
    {
        $recorded = $event->metadata['after']['suspended_at'] ?? null;
        if (!$company->suspended_at || !is_string($recorded)) return false;
        try {
            return Carbon::parse($recorded)->equalTo($company->suspended_at);
        } catch (\Throwable) {
            return false;
        }
    }
}
