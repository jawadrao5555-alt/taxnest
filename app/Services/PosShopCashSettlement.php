<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PosRider;
use App\Models\PosRiderSettlement;
use App\Models\PosTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PosShopCashSettlement
{
    public static function authorize(int $companyId, ?User $actor): void
    {
        abort_unless($actor && (int) $actor->company_id === $companyId
            && ($actor->role === 'company_admin' || in_array($actor->pos_role, ['pos_admin', 'pos_manager', 'pos_cashier', 'pos_delivery'], true))
            && !session()->has('impersonation'), 403);
        abort_unless(Schema::hasColumn('pos_rider_settlements', 'receipt_key'), 503, 'Cash receipt update is not available yet.');
    }

    public static function eligible(int $companyId, User $actor)
    {
        self::authorize($companyId, $actor);
        $query = PosTransaction::withoutGlobalScope('hide_archived')->where('company_id', $companyId)
            ->whereNull('rider_id')->whereNull('rider_settlement_id')->where('payment_method', 'cash')
            ->where('status', 'completed')
            ->where(fn ($q) => $q->whereNull('transaction_type')->orWhere('transaction_type', 'sale'))
            ->where(fn ($q) => $q->whereNull('invoice_mode')->orWhere('invoice_mode', '!=', 'local'))
            ->where(fn ($q) => $q->whereNull('delivery_status')->orWhere('delivery_status', '!=', 'returned'))
            ->where(function ($q) {
                $q->where('order_type', 'delivery');
                if (Schema::hasColumn('pos_transactions', 'has_delivery_items')) $q->orWhere('has_delivery_items', true);
            })
            ->whereRaw(PosRider::remainingExpr('pos_transactions') . ' > 0');
        if (Schema::hasColumn('pos_transactions', 'branch_id')) app(BranchContextService::class)->applyToQuery($query);
        $scope = $actor->posBillingScopeExplicit();
        if ($scope === 'local') {
            $query->whereNull('pra_status')->whereNull('pra_invoice_number');
        } elseif ($scope === 'pra') {
            $query->where(fn ($q) => $q->whereNotNull('pra_status')->orWhereNotNull('pra_invoice_number'));
        }

        return $query;
    }

    public static function receive(int $companyId, User $actor, array $ids, float $expectedAmount, string $key): PosRiderSettlement
    {
        self::authorize($companyId, $actor);
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        if (!$ids || count($ids) > 200 || $expectedAmount <= 0 || !is_finite($expectedAmount)) {
            throw ValidationException::withMessages(['bill_ids' => 'Select eligible bills and confirm their exact cash amount.']);
        }

        return DB::transaction(function () use ($companyId, $actor, $ids, $expectedAmount, $key) {
            // Serialize duplicate keys and day-close deletion before locking bills in ID order.
            Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
            $prior = PosRiderSettlement::where('company_id', $companyId)->where('receipt_key', $key)->first();
            if ($prior) {
                $priorIds = array_column($prior->allocation ?? [], 'bill_id');
                sort($priorIds);
                if ($prior->receipt_kind !== 'shop' || (int) $prior->settled_by !== (int) $actor->id
                    || $priorIds !== $ids || abs((float) $prior->total_amount - $expectedAmount) > .001) {
                    throw ValidationException::withMessages(['receipt_key' => 'This receipt request was already used for a different selection.']);
                }
                return $prior;
            }
            $bills = self::eligible($companyId, $actor)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $amount = round((float) $bills->sum(fn ($bill) => PosRider::remainingAmount($bill)), 2);
            if ($bills->count() !== count($ids) || abs($amount - $expectedAmount) > .001) {
                throw ValidationException::withMessages(['bill_ids' => 'Selection changed or includes ineligible bills. Refresh the preview; no cash was recorded.']);
            }
            if ($bills->pluck('branch_id')->unique()->count() > 1) {
                throw ValidationException::withMessages(['bill_ids' => 'Receive cash for one branch at a time.']);
            }
            $receipt = PosRiderSettlement::create([
                'company_id' => $companyId, 'rider_id' => null, 'settled_by' => $actor->id,
                'receipt_kind' => 'shop', 'receipt_key' => $key, 'panel' => 'pra',
                'business_date' => PosBusinessDay::current($companyId),
                'branch_id' => $bills->first()->branch_id,
                'total_amount' => $amount, 'bill_count' => $bills->count(),
                'notes' => 'Cash received at shop for selected unassigned final delivery bills.',
                'allocation' => $bills->map(fn ($bill) => [
                    'bill_id' => (int) $bill->id, 'invoice_number' => $bill->invoice_number,
                    'amount' => PosRider::remainingAmount($bill), 'business_date' => $bill->business_date,
                    'branch_id' => $bill->branch_id, 'created_by' => $bill->created_by,
                ])->all(),
            ]);
            PosTransaction::withoutGlobalScope('hide_archived')->where('company_id', $companyId)->whereIn('id', $ids)
                ->update(['rider_settlement_id' => $receipt->id, 'rider_settled_at' => now()]);

            return $receipt;
        });
    }
}
