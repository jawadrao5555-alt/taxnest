<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PosDayCloseReport;
use App\Models\PosRider;
use App\Models\PosTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class PosDeliveryAccount
{
    public static function ready(): bool
    {
        return Schema::hasTable('pos_delivery_accounts')
            && Schema::hasTable('pos_delivery_account_events')
            && Schema::hasColumn('pos_transactions', 'delivery_credit_amount');
    }

    /** Called in the same transaction as delivery completion, with the bill locked. */
    public static function record(PosTransaction $bill, PosRider $rider, string $received): void
    {
        abort_unless(self::ready(), 503, 'Delivery payments are temporarily unavailable.');
        $cash = self::cents($received);
        $due = self::cents($bill->rider_collection_amount ?? $bill->total_amount);
        if ($bill->status !== 'completed' || $bill->payment_method !== 'cash'
            || $bill->transaction_type === 'return' || $bill->rider_settlement_id
            || (float) $bill->rider_partial_paid > 0 || $bill->invoice_mode === 'local'
            || $due <= 0 || $cash > $due || (int) $bill->rider_id !== (int) $rider->id
            || (int) $bill->company_id !== (int) $rider->company_id) {
            throw ValidationException::withMessages(['received_amount' => 'Check the bill and the cash received before completing the delivery.']);
        }

        $credit = $due - $cash;
        if ($credit && !filled($bill->customer_name) && !filled($bill->customer_phone)) {
            throw ValidationException::withMessages(['received_amount' => 'Add the customer name or phone to the bill before recording udhaar.']);
        }

        DB::table('pos_delivery_accounts')->insert([
            'company_id' => $bill->company_id,
            'branch_id' => $bill->branch_id,
            'transaction_id' => $bill->id,
            'rider_id' => $rider->id,
            'rider_name' => $rider->name,
            'customer_name' => $bill->customer_name,
            'customer_phone' => $bill->customer_phone,
            'business_date' => $bill->business_date ?: $bill->created_at->toDateString(),
            'received_amount' => $cash / 100,
            'credit_amount' => $credit / 100,
            'outstanding_amount' => $credit / 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $bill->forceFill(['delivery_credit_amount' => $credit / 100])->save();
    }

    public static function scopedAccounts(int $companyId)
    {
        return app(BranchContextService::class)->applyToQuery(
            DB::table('pos_delivery_accounts')->where('company_id', $companyId)
        );
    }

    public static function collect(int $companyId, int $accountId, int $userId, string $requestId, string $amount): void
    {
        $received = self::cents($amount);
        DB::transaction(function () use ($companyId, $accountId, $userId, $requestId, $received) {
            Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
            $account = self::scopedAccounts($companyId)->where('id', $accountId)->lockForUpdate()->first();
            abort_unless($account, 404);
            $prior = DB::table('pos_delivery_account_events')->where('account_id', $accountId)
                ->where('request_id', $requestId)->first();
            if ($prior) {
                abort_unless(self::cents($prior->amount) === $received, 409);
                return;
            }

            $businessDate = PosBusinessDay::current($companyId);
            $branchId = $account->branch_id === null ? null : (int) $account->branch_id;
            if (PosDayCloseReport::where('company_id', $companyId)->forBranch($branchId)
                ->whereDate('report_date', $businessDate)->exists()
                || PosCounterDrawer::closes($companyId, $branchId, $businessDate)->has(PosCounterDrawer::SHOP_DRAWER)) {
                throw ValidationException::withMessages(['amount' => 'Open the shop drawer before recording a recovery.']);
            }

            $outstanding = self::cents($account->outstanding_amount);
            if ($received <= 0 || $received > $outstanding) {
                throw ValidationException::withMessages(['amount' => 'Enter a positive amount up to the current balance.']);
            }

            DB::table('pos_delivery_account_events')->insert([
                'account_id' => $accountId,
                'kind' => 'collection',
                'request_id' => $requestId,
                'recorded_by' => $userId,
                'amount' => $received / 100,
                'business_date' => $businessDate,
                'created_at' => now(),
            ]);
            DB::table('pos_delivery_accounts')->where('id', $accountId)->update([
                'outstanding_amount' => ($outstanding - $received) / 100,
                'updated_at' => now(),
            ]);
        });
    }

    /** The return and credit adjustment commit together under the original bill lock. */
    public static function offsetReturn(PosTransaction $original, PosTransaction $return, ?int $userId): void
    {
        if ((float) $original->delivery_credit_amount <= 0) {
            return;
        }

        $account = DB::table('pos_delivery_accounts')
            ->where('company_id', $original->company_id)
            ->where('transaction_id', $original->id)->lockForUpdate()->first();
        abort_unless($account, 409, 'The delivery credit record is missing.');
        $outstanding = self::cents($account->outstanding_amount);
        $offset = min($outstanding, self::cents($return->total_amount));
        if ($offset <= 0) {
            return;
        }

        DB::table('pos_delivery_account_events')->insert([
            'account_id' => $account->id,
            'kind' => 'return',
            'recorded_by' => $userId,
            'return_transaction_id' => $return->id,
            'amount' => $offset / 100,
            'business_date' => $return->business_date ?: $return->created_at->toDateString(),
            'created_at' => now(),
        ]);
        DB::table('pos_delivery_accounts')->where('id', $account->id)->update([
            'outstanding_amount' => ($outstanding - $offset) / 100,
            'updated_at' => now(),
        ]);
        $return->forceFill(['delivery_credit_amount' => $offset / 100])->save();
    }

    public static function recoveries(int $companyId, string $date, ?int $branchId): float
    {
        if (!self::ready()) {
            return 0.0;
        }

        return (float) DB::table('pos_delivery_account_events as events')
            ->join('pos_delivery_accounts as accounts', 'accounts.id', '=', 'events.account_id')
            ->where('accounts.company_id', $companyId)
            ->when($branchId, fn ($q) => $q->where(fn ($scope) =>
                $scope->where('accounts.branch_id', $branchId)->orWhereNull('accounts.branch_id')))
            ->where('events.business_date', $date)
            ->where('events.kind', 'collection')
            ->sum('events.amount');
    }

    private static function cents($amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
