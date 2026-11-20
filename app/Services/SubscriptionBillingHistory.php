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
            ->where(fn ($query) => $query
                ->where(fn ($issued) => $issued->whereNotNull('document_number')->whereNotNull('document_snapshot'))
                ->orWhereHas('payments', fn ($payment) => $payment->downloadableReceipt()))
            ->with(['subscription' => fn ($query) => $query->where('company_id', $companyId)->with('pricingPlan'),
                'payments' => fn ($query) => $query->downloadableReceipt()->orderBy('id')])
            ->orderByDesc('id')
            ->paginate(10, ['*'], $pageName)
            ->withQueryString();
    }
}

