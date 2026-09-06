<?php

namespace App\Services\HealthAudit\Checks;

use App\Services\HealthAudit\HealthAuditContext;
use App\Services\HealthAudit\HealthAuditRules;
use Illuminate\Support\Facades\DB;

/**
 * Pharmacy stock checks (Task 1554).
 *
 * A hospital pharmacy is the one place where the thing being audited walks out
 * of the building in a bag. The movement ledger already records every in and
 * out; what these rules do is compare that ledger against the number the shelf
 * card claims, and against the two dates that matter — the expiry, and the day
 * it was handed over.
 */
class StockChecks extends BaseChecks
{
    /**
     * The batch's own quantity does not agree with its movement history.
     *
     * received + every IN − every OUT should be exactly what the batch says it
     * holds. When it is not, either stock left without a movement or a movement
     * was written for stock that never moved, and both are real.
     *
     * Batches are only reported when the period actually TOUCHED them —
     * otherwise one old discrepancy would appear in every audit forever.
     */
    public static function batchStockVariance(HealthAuditContext $ctx): array
    {
        if (self::tableMissing('health_medicine_batches', 'health_batch_movements')) {
            return [];
        }

        $touched = DB::table('health_batch_movements')
            ->select('batch_id')
            ->where('company_id', $ctx->companyId)
            ->whereBetween('created_at', [$ctx->fromStart(), $ctx->toEnd()])
            ->whereNotNull('batch_id')
            ->distinct();

        $movements = DB::table('health_batch_movements')
            ->select('batch_id', DB::raw("SUM(CASE WHEN direction = 'out' THEN -quantity ELSE quantity END) as net_moved"))
            ->where('company_id', $ctx->companyId)
            ->whereNotNull('batch_id')
            ->groupBy('batch_id');

        $query = DB::table('health_medicine_batches as b')
            ->joinSub($touched, 't', 't.batch_id', '=', 'b.id')
            ->leftJoinSub($movements, 'm', 'm.batch_id', '=', 'b.id')
            ->where('b.company_id', $ctx->companyId)
            ->whereRaw('ABS(COALESCE(b.quantity,0) - COALESCE(m.net_moved,0)) > 0')
            ->select('b.*', DB::raw('COALESCE(m.net_moved,0) as net_moved'));

        $ctx->applyBranch($query, 'b.branch_id');
        $ctx->applySubject($query, ['b.created_by']);

        $rows = $query->orderBy('b.id')->limit(HealthAuditRules::PER_RULE_CAP)->get();

        $medicineNames = self::medicineNames($rows->pluck('medicine_id')->all());

        return array_map(function ($row) use ($medicineNames) {
            $variance = (float) $row->quantity - (float) $row->net_moved;

            return [
                'occurred_on' => null,
                'branch_id' => $row->branch_id,
                'subject_user_id' => $row->created_by,
                'subject_name' => self::userName($row->created_by),
                'entity_type' => 'health_medicine_batches',
                'entity_id' => (int) $row->id,
                'entity_label' => (string) ($row->batch_no ?: ('#' . $row->id)),
                'variance' => $variance,
                'params' => [
                    'medicine' => $medicineNames[(int) $row->medicine_id] ?? ('#' . $row->medicine_id),
                    'batch' => (string) ($row->batch_no ?: ('#' . $row->id)),
                    'on_hand' => (float) $row->quantity,
                    'ledger' => (float) $row->net_moved,
                    'variance' => $variance,
                ],
                'evidence' => [
                    'batch' => [
                        'id' => (int) $row->id,
                        'batch_no' => $row->batch_no,
                        'medicine' => $medicineNames[(int) $row->medicine_id] ?? null,
                        'expiry_date' => self::dateOnly($row->expiry_date),
                        'received_quantity' => (float) $row->received_quantity,
                        'quantity_on_card' => (float) $row->quantity,
                        'quantity_from_movements' => (float) $row->net_moved,
                        'difference' => $variance,
                        'status' => $row->status,
                    ],
                    'link' => self::link('health.pharmacy.movements', ['batch_id' => (int) $row->id], 'pharmacy.view'),
                ],
            ];
        }, $rows->all());
    }

    /**
     * Medicine handed over after its expiry date.
     *
     * The sale line freezes the expiry it was sold against, so this is decided
     * from the record of the sale itself rather than from today's shelf.
     */
    public static function expiredStockDispensed(HealthAuditContext $ctx): array
    {
        if (self::tableMissing('health_pharmacy_sales', 'health_pharmacy_sale_items')) {
            return [];
        }

        $query = DB::table('health_pharmacy_sale_items as i')
            ->join('health_pharmacy_sales as s', 's.id', '=', 'i.sale_id')
            ->where('s.company_id', $ctx->companyId)
            ->whereBetween('s.business_date', [$ctx->from, $ctx->to])
            ->whereNotNull('i.expiry_date')
            ->whereColumn('i.expiry_date', '<', 's.business_date')
            ->where('i.quantity', '>', 0)
            ->select(
                'i.id as item_id',
                'i.item_name',
                'i.batch_no',
                'i.expiry_date',
                'i.quantity',
                'i.line_total',
                's.id as sale_id',
                's.sale_number',
                's.business_date',
                's.branch_id',
                's.health_department_id',
                's.created_by',
                's.status'
            );

        $ctx->applyBranch($query, 's.branch_id');
        $ctx->applyDepartment($query, 's.health_department_id');
        $ctx->applySubject($query, ['s.created_by']);

        $rows = $query->orderBy('s.business_date')->orderBy('i.id')
            ->limit(HealthAuditRules::PER_RULE_CAP)->get();

        return array_map(fn ($row) => [
            'occurred_on' => self::dateOnly($row->business_date),
            'branch_id' => $row->branch_id,
            'health_department_id' => $row->health_department_id,
            'subject_user_id' => $row->created_by,
            'subject_name' => self::userName($row->created_by),
            'entity_type' => 'health_pharmacy_sale_items',
            'entity_id' => (int) $row->item_id,
            'entity_label' => (string) ($row->sale_number ?: $row->sale_id),
            'amount' => $row->line_total,
            'params' => [
                'medicine' => (string) $row->item_name,
                'sale' => (string) ($row->sale_number ?: $row->sale_id),
                'expiry' => self::dateOnly($row->expiry_date),
                'date' => self::dateOnly($row->business_date),
            ],
            'evidence' => [
                'sale_item' => [
                    'id' => (int) $row->item_id,
                    'sale_id' => (int) $row->sale_id,
                    'sale_number' => $row->sale_number,
                    'item_name' => $row->item_name,
                    'batch_no' => $row->batch_no,
                    'expiry_date' => self::dateOnly($row->expiry_date),
                    'sold_on' => self::dateOnly($row->business_date),
                    'quantity' => (float) $row->quantity,
                    'line_total' => self::money($row->line_total),
                    'sold_by' => self::userName($row->created_by),
                ],
                'link' => self::link('health.pharmacy.sales.show', ['id' => (int) $row->sale_id], 'pharmacy.view'),
            ],
        ], $rows->all());
    }

    /** Expired stock still sitting on the shelf as sellable. */
    public static function expiredBatchStillSellable(HealthAuditContext $ctx): array
    {
        if (self::tableMissing('health_medicine_batches')) {
            return [];
        }

        $query = DB::table('health_medicine_batches')
            ->where('company_id', $ctx->companyId)
            ->where('status', 'active')
            ->where('quantity', '>', 0)
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', $ctx->to);

        $ctx->applyBranch($query);

        $rows = $query->orderBy('expiry_date')->orderBy('id')
            ->limit(HealthAuditRules::PER_RULE_CAP)->get();

        $medicineNames = self::medicineNames($rows->pluck('medicine_id')->all());

        return array_map(fn ($row) => [
            'occurred_on' => self::dateOnly($row->expiry_date),
            'branch_id' => $row->branch_id,
            'entity_type' => 'health_medicine_batches',
            'entity_id' => (int) $row->id,
            'entity_label' => (string) ($row->batch_no ?: ('#' . $row->id)),
            'params' => [
                'medicine' => $medicineNames[(int) $row->medicine_id] ?? ('#' . $row->medicine_id),
                'batch' => (string) ($row->batch_no ?: ('#' . $row->id)),
                'expiry' => self::dateOnly($row->expiry_date),
                'quantity' => (float) $row->quantity,
            ],
            'evidence' => [
                'batch' => [
                    'id' => (int) $row->id,
                    'batch_no' => $row->batch_no,
                    'medicine' => $medicineNames[(int) $row->medicine_id] ?? null,
                    'expiry_date' => self::dateOnly($row->expiry_date),
                    'quantity' => (float) $row->quantity,
                    'status' => $row->status,
                ],
                'link' => self::link('health.pharmacy.stock', [], 'pharmacy.view'),
            ],
        ], $rows->all());
    }

    /**
     * Stock moved by hand rather than by a sale, a purchase or a return.
     *
     * Every pharmacy needs adjustments — breakage, a miscount, a doctor's
     * sample. Every pharmacy also loses stock through them. The rule lists
     * them so the owner sees the pattern rather than the individual rupee.
     */
    public static function stockManualAdjustment(HealthAuditContext $ctx): array
    {
        if (self::tableMissing('health_batch_movements')) {
            return [];
        }

        $query = DB::table('health_batch_movements')
            ->where('company_id', $ctx->companyId)
            ->whereBetween('created_at', [$ctx->fromStart(), $ctx->toEnd()])
            ->whereIn('type', ['adjustment', 'write_off', 'opening', 'quarantine']);

        $ctx->applyBranch($query);
        $ctx->applySubject($query, ['created_by']);

        $rows = $query->orderBy('created_at')->orderBy('id')
            ->limit(HealthAuditRules::PER_RULE_CAP)->get();

        $medicineNames = self::medicineNames($rows->pluck('medicine_id')->all());

        return array_map(fn ($row) => [
            'occurred_on' => self::dateOnly($row->created_at),
            'branch_id' => $row->branch_id,
            'subject_user_id' => $row->created_by,
            'subject_name' => self::userName($row->created_by),
            'entity_type' => 'health_batch_movements',
            'entity_id' => (int) $row->id,
            'entity_label' => (string) ($row->reference_number ?: ('#' . $row->id)),
            'variance' => $row->direction === 'out' ? -(float) $row->quantity : (float) $row->quantity,
            'severity' => ($row->reason === null || $row->reason === '') ? 'critical' : 'warning',
            'params' => [
                'medicine' => $medicineNames[(int) $row->medicine_id] ?? ('#' . $row->medicine_id),
                'type' => (string) $row->type,
                'quantity' => (float) $row->quantity,
                'direction' => (string) $row->direction,
                'by' => self::userName($row->created_by) ?? '—',
            ],
            'evidence' => [
                'movement' => [
                    'id' => (int) $row->id,
                    'batch_id' => $row->batch_id,
                    'medicine' => $medicineNames[(int) $row->medicine_id] ?? null,
                    'type' => $row->type,
                    'direction' => $row->direction,
                    'quantity' => (float) $row->quantity,
                    'balance_after' => (float) $row->balance_after,
                    'reason' => $row->reason ?: null,
                    'notes' => $row->notes,
                    'at' => $row->created_at,
                    'by' => self::userName($row->created_by),
                ],
                'link' => self::link('health.pharmacy.movements', ['batch_id' => (int) $row->batch_id], 'pharmacy.view'),
            ],
        ], $rows->all());
    }

    /** Counter sales that were refunded. */
    public static function pharmacySaleRefunded(HealthAuditContext $ctx): array
    {
        if (self::tableMissing('health_pharmacy_sales')) {
            return [];
        }

        $query = DB::table('health_pharmacy_sales')
            ->where('company_id', $ctx->companyId)
            ->whereBetween('business_date', [$ctx->from, $ctx->to])
            ->where('refunded_amount', '>', 0);

        $ctx->applyBranch($query);
        $ctx->applyDepartment($query);
        $ctx->applySubject($query, ['created_by']);

        $rows = $query->orderByDesc('refunded_amount')->orderBy('id')
            ->limit(HealthAuditRules::PER_RULE_CAP)->get();

        return array_map(fn ($row) => [
            'occurred_on' => self::dateOnly($row->business_date),
            'branch_id' => $row->branch_id,
            'health_department_id' => $row->health_department_id,
            'subject_user_id' => $row->created_by,
            'subject_name' => self::userName($row->created_by),
            'entity_type' => 'health_pharmacy_sales',
            'entity_id' => (int) $row->id,
            'entity_label' => (string) ($row->sale_number ?: $row->id),
            'amount' => $row->refunded_amount,
            'params' => [
                'sale' => (string) ($row->sale_number ?: $row->id),
                'amount' => self::money($row->refunded_amount),
                'date' => self::dateOnly($row->business_date),
            ],
            'evidence' => [
                'sale' => [
                    'id' => (int) $row->id,
                    'sale_number' => $row->sale_number,
                    'business_date' => self::dateOnly($row->business_date),
                    'total_amount' => self::money($row->total_amount),
                    'refunded_amount' => self::money($row->refunded_amount),
                    'status' => $row->status,
                    'sold_by' => self::userName($row->created_by),
                ],
                'link' => self::link('health.pharmacy.sales.show', ['id' => (int) $row->id], 'pharmacy.view'),
            ],
        ], $rows->all());
    }

    /** medicine id => name, resolved in one query per rule. */
    protected static function medicineNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if (empty($ids) || self::tableMissing('health_medicines')) {
            return [];
        }

        return DB::table('health_medicines')
            ->whereIn('id', $ids)
            ->pluck('name', 'id')
            ->map(fn ($n) => (string) $n)
            ->all();
    }
}
