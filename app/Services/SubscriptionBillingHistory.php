<?php

namespace App\Services;

use App\Models\SubscriptionInvoice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;

final class SubscriptionBillingHistory
{
    public static function forCompany(int $companyId, string $pageName = 'billing_history_page'): ?LengthAwarePaginator
    {
        if (!Schema::hasTable('subscription_invoices')
            || !Schema::hasColumn('subscription_invoices', 'document_number')
            || !Schema::hasTable('subscription_payments')) {
            return null;
        }

        return SubscriptionInvoice::query()
            ->where('company_id', $companyId)
            ->whereNotNull('document_number')
            ->whereNotNull('document_snapshot')
            ->with(['subscription.pricingPlan', 'payments' => fn ($query) => $query
                ->whereNotNull('receipt_number')->whereNotNull('receipt_snapshot')->orderBy('id')])
            ->orderByDesc('id')
            ->paginate(10, ['*'], $pageName)
            ->withQueryString();
    }
}
