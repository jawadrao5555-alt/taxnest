<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PosCustomer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer lifetime-spend support for local bills deliberately deleted at
 * day-close. A deleted bill must never come back as a purchase/history row,
 * but a shop may still choose to retain its value in the customer's spend
 * total. Keep that narrow distinction in one place.
 */
class PosCustomerSpend
{
    /**
     * Amount retained from deleted local bills for one customer.
     *
     * Linked rows take precedence over phone matching so a bill carrying both
     * customer_id and phone is counted once. This amount intentionally has no
     * date, order count, or bill detail semantics.
     */
    public static function deletedLocalTotal(int $companyId, PosCustomer $customer): float
    {
        try {
            $company = Company::find($companyId);
            if (!$company || !(bool) ($company->pos_customer_spend_persist ?? true)
                || !Schema::hasTable('pos_customer_spend_snapshots')) {
                return 0.0;
            }

            return round((float) DB::table('pos_customer_spend_snapshots')
                ->where('company_id', $companyId)
                ->where(function ($query) use ($customer) {
                    $query->where('customer_id', $customer->id);
                    if (!empty($customer->phone)) {
                        $query->orWhere(function ($byPhone) use ($customer) {
                            $byPhone->whereNull('customer_id')
                                ->where('customer_phone', $customer->phone);
                        });
                    }
                })
                ->sum('total_amount'), 2);
        } catch (\Throwable $e) {
            // A mid-deploy schema must not stop a cashier from selecting a customer.
            return 0.0;
        }
    }
}