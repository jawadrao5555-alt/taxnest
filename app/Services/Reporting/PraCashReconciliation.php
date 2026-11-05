<?php

namespace App\Services\Reporting;

use App\Support\PosPaymentBuckets;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Independent SELECTs compared with a frozen close. Never invokes the close calculator. */
final class PraCashReconciliation
{
    private const BILL_LIMIT = 5000;
    private const RECEIPT_LIMIT = 200;
    private const ALLOCATION_LIMIT = 2000;

    public function read(int $companyId, array $v): array
    {
        $base = ['source' => 'stored_business_date', 'physical_cash_verified' => false, 'complete' => false,
            'records' => [], 'pagination' => ['has_more' => false, 'next_after_id' => null],
            'notes' => ['Invoice payment buckets are recorded money classifications, not independent bank or physical-cash confirmation.',
                'Archived rows are included. Deleted or changed source rows cannot be reconstructed from the frozen total.',
                'A difference is evidence for investigation, not proof of loss. Opening overrides and later settlements can be legitimate.',
                'PRA-mode finals include reporting-OFF finals. Deliberate local provisionals are excluded from the frozen PRA close comparison.',
                'Branch closes include legacy null-branch bills as the existing close path does; these are explicitly counted, not reassigned.']];
        if (!$this->has('pos_day_close_reports', ['id', 'company_id', 'report_date', 'branch_id'])) return $base + ['status' => 'UNAVAILABLE'];
        $limit = (int) ($v['limit'] ?? 5);
        $q = DB::table('pos_day_close_reports')->where('company_id', $companyId)->whereBetween('report_date', [$v['date_from'], $v['date_to']]);
        if (isset($v['branch_id'])) $q->where('branch_id', $v['branch_id']);
        $columns = array_intersect(['id', 'branch_id', 'report_date', 'created_at', 'total_invoices', 'returns_count', 'cash_amount', 'card_amount', 'other_amount', 'opening_float', 'expected_cash', 'counted_cash'], Schema::getColumnListing('pos_day_close_reports'));
        $rows = $q->where('id', '>', $v['after_id'] ?? 0)->orderBy('id')->limit($limit + 1)->get($columns);
        foreach ($rows->take($limit) as $report) $base['records'][] = $this->report($companyId, $report);
        $base['pagination'] = ['has_more' => $rows->count() > $limit, 'next_after_id' => $rows->count() > $limit ? (int) $rows[$limit - 1]->id : null];
        return $base + ['status' => $rows->isEmpty() ? 'NO_STORED_CLOSE' : 'PARTIAL'];
    }

    private function report(int $companyId, object $report): array
    {
        $r = ['report_id' => (int) $report->id, 'business_date' => $report->report_date, 'branch_id' => (int) $report->branch_id,
            'frozen_at' => $report->created_at ?? null, 'status' => 'UNAVAILABLE', 'gaps' => [], 'findings' => []];
        $required = ['id', 'company_id', 'business_date', 'status', 'transaction_type', 'invoice_mode', 'payment_method', 'total_amount', 'branch_id'];
        if (!$this->has('pos_transactions', $required)) return $r + ['reason' => 'Required transaction evidence is unavailable.'];
        $fields = array_intersect(array_merge($required, ['updated_at', 'created_at', 'rider_id', 'rider_collection_amount', 'rider_partial_paid', 'rider_settlement_id', 'rider_settled_at', 'order_type', 'delivery_status', 'hotel_money_from_folio']), Schema::getColumnListing('pos_transactions'));
        $q = DB::table('pos_transactions')->where('company_id', $companyId)->where('business_date', $report->report_date)
            ->where('status', 'completed')->where(fn ($b) => $b->whereNull('invoice_mode')->orWhere('invoice_mode', 'pra'));
        $this->branch($q, (int) $report->branch_id);
        $rows = $q->orderBy('id')->limit(self::BILL_LIMIT + 1)->get($fields);
        if ($rows->count() > self::BILL_LIMIT) $r['gaps'][] = 'transaction_row_limit';
        $rows = $rows->take(self::BILL_LIMIT);
        $sales = $refunds = ['cash' => 0, 'card' => 0, 'other' => 0];
        $counts = ['sales' => 0, 'returns' => 0, 'legacy_null_branch' => 0, 'updated_after_close' => 0];
        $cashOut = 0;
        if (!isset($report->created_at) || !in_array('updated_at', $fields, true)) $r['gaps'][] = 'source_change_time_unavailable';
        foreach ($rows as $bill) {
            $counts['legacy_null_branch'] += $bill->branch_id === null ? 1 : 0;
            if (isset($bill->updated_at, $report->created_at) && $bill->updated_at > $report->created_at) $counts['updated_after_close']++;
            $return = $bill->transaction_type === 'return';
            if (!in_array($bill->transaction_type, [null, '', 'sale', 'return'], true)) { $r['gaps'][] = 'unsupported_transaction_type'; continue; }
            $counts[$return ? 'returns' : 'sales']++;
            $money = $this->cents($bill->total_amount);
            if ($money === null || !is_string($bill->payment_method) || $bill->payment_method === '') { $r['gaps'][] = 'missing_bill_money_or_method'; continue; }
            if ($bill->payment_method !== 'hotel_credit_note' && !($bill->hotel_money_from_folio ?? false)) {
                $bucket = PosPaymentBuckets::bucket($bill->payment_method);
                if ($return) $refunds[$bucket] += $money; else $sales[$bucket] += $money;
            }
            if (!$return && $bill->payment_method === 'cash' && (($bill->rider_id ?? null) || ($bill->order_type ?? null) === 'delivery' || ($bill->rider_collection_amount ?? 0) > 0)
                && ($bill->delivery_status ?? null) !== 'returned') {
                if (!array_key_exists('rider_settlement_id', (array) $bill) || !array_key_exists('rider_partial_paid', (array) $bill)) { $r['gaps'][] = 'rider_fields_unavailable'; continue; }
                if ($bill->rider_settlement_id) {
                    if (!isset($bill->rider_settled_at, $report->created_at) || $bill->rider_settled_at > $report->created_at) $r['gaps'][] = 'rider_settlement_after_close_or_undated';
                    continue;
                }
                $amount = $this->cents($bill->rider_collection_amount ?? $bill->total_amount);
                $partial = $this->cents($bill->rider_partial_paid ?? 0);
                if ($amount === null || $partial === null || $partial > $amount || $partial < 0) { $r['gaps'][] = 'invalid_rider_balance'; continue; }
                $cashOut += $amount - $partial;
            }
        }
        if ($counts['updated_after_close']) $r['gaps'][] = 'source_rows_updated_after_close';
        if (!isset($report->total_invoices, $report->returns_count) || (int) $report->total_invoices !== $counts['sales'] || (int) $report->returns_count !== $counts['returns']) $r['gaps'][] = 'frozen_and_available_document_counts_differ';
        $hotel = $this->hotel($companyId, $report, $r['gaps']);
        $receipts = $this->receipts($companyId, $report, $r['gaps']);
        $net = [];
        foreach (['cash', 'card', 'other'] as $bucket) {
            $net[$bucket] = $hotel === null ? null : $sales[$bucket] - $refunds[$bucket] + $hotel[$bucket];
            $stored = $this->cents($report->{$bucket.'_amount'} ?? null);
            $r['payments'][$bucket] = ['sales' => $sales[$bucket] / 100, 'returns' => $refunds[$bucket] / 100, 'hotel_net_movements' => $hotel === null ? null : $hotel[$bucket] / 100,
                'observed_net' => $net[$bucket] === null ? null : $net[$bucket] / 100, 'frozen_net' => $stored === null ? null : $stored / 100,
                'frozen_minus_observed' => $stored === null || $net[$bucket] === null ? null : ($stored - $net[$bucket]) / 100];
            if ($stored === null) $r['gaps'][] = 'frozen_payment_amount_unavailable';
            if ($stored !== null && $net[$bucket] !== null && $stored !== $net[$bucket]) $r['findings'][] = $bucket.'_stored_source_difference';
        }
        $opening = null;
        if ($this->has('pos_day_openings', ['company_id', 'business_date', 'branch_id', 'opening_cash'])) {
            $oq = DB::table('pos_day_openings')->where('company_id', $companyId)->where('business_date', $report->report_date);
            if ($report->branch_id) $oq->where('branch_id', $report->branch_id);
            $o = $oq->selectRaw('COUNT(*) AS records, COUNT(opening_cash) AS known, SUM(opening_cash) AS amount')->first();
            if ($o->records > 0 && (int) $o->records === (int) $o->known) $opening = $this->cents($o->amount);
        }
        if ($opening === null) $r['gaps'][] = 'opening_source_unavailable';
        $storedOpening = $this->cents($report->opening_float ?? null);
        if ($opening !== null && $storedOpening !== null && $opening !== $storedOpening) $r['findings'][] = 'opening_override_or_difference';
        $expected = $opening === null || $net['cash'] === null || !$receipts['available'] ? null : $opening + $net['cash'] - $cashOut + $receipts['prior_bill_cash_in'];
        $storedExpected = $this->cents($report->expected_cash ?? null);
        $r['cash'] = ['source_opening' => $opening === null ? null : $opening / 100, 'frozen_opening' => $storedOpening === null ? null : $storedOpening / 100,
            'observed_unsettled_delivery_cash' => $cashOut / 100, 'observed_prior_bill_cash_in' => $receipts['available'] ? $receipts['prior_bill_cash_in'] / 100 : null,
            'observed_expected' => $expected === null ? null : $expected / 100, 'frozen_expected' => $storedExpected === null ? null : $storedExpected / 100,
            'frozen_minus_observed' => $expected === null || $storedExpected === null ? null : ($storedExpected - $expected) / 100];
        if ($expected !== null && $storedExpected !== null && $expected !== $storedExpected) $r['findings'][] = 'expected_cash_stored_source_difference';
        $r['receipts'] = ['scope' => 'company; prior_bill_cash_in follows report branch', 'available' => $receipts['available'], 'matching' => $receipts['count'], 'amount' => $receipts['total'] / 100, 'allocated' => $receipts['allocated'] / 100];
        $r['document_counts'] = $counts + ['frozen_sales' => $report->total_invoices ?? null, 'frozen_returns' => $report->returns_count ?? null];
        $r['gaps'] = array_values(array_unique($r['gaps']));
        $r['findings'] = array_values(array_unique($r['findings']));
        $r['status'] = $r['gaps'] ? 'PARTIAL' : ($r['findings'] ? 'STORED_SOURCE_DIFFERENCE' : 'STORED_SOURCES_MATCH');
        return $r;
    }

    private function receipts(int $companyId, object $report, array &$gaps): array
    {
        $out = ['available' => true, 'prior_bill_cash_in' => 0, 'total' => 0, 'allocated' => 0, 'count' => 0];
        if (!$this->has('pos_rider_settlements', ['company_id', 'panel', 'business_date', 'allocation', 'total_amount', 'created_at'])) { $gaps[] = 'receipt_source_unavailable'; $out['available'] = false; return $out; }
        $start = Carbon::parse($report->report_date, 'Asia/Karachi')->setTimezone(config('app.timezone'));
        $legacy = DB::table('pos_rider_settlements')->where('company_id', $companyId)->where('panel', 'pra')->whereNull('business_date')
            ->whereBetween('created_at', [$start->toDateTimeString(), $start->copy()->addDays(2)->toDateTimeString()])->exists();
        if ($legacy) $gaps[] = 'legacy_receipt_business_date_unknown';
        $q = DB::table('pos_rider_settlements')->where('company_id', $companyId)->where('panel', 'pra')->where('business_date', $report->report_date);
        if (isset($report->created_at)) $q->where('created_at', '<=', $report->created_at); else $gaps[] = 'close_timestamp_unavailable';
        $rows = $q->orderBy('id')->limit(self::RECEIPT_LIMIT + 1)->get(['id', 'total_amount', DB::raw('CASE WHEN LENGTH(allocation) <= 65536 THEN allocation ELSE NULL END AS allocation')]);
        if ($rows->count() > self::RECEIPT_LIMIT) $gaps[] = 'receipt_row_limit';
        $budget = self::ALLOCATION_LIMIT;
        foreach ($rows->take(self::RECEIPT_LIMIT) as $receipt) {
            $entries = json_decode($receipt->allocation ?? '', true, 16);
            if (!is_array($entries) || !array_is_list($entries) || count($entries) > 500) { $gaps[] = 'receipt_allocation_unavailable'; continue; }
            if (count($entries) > $budget) { $gaps[] = 'receipt_allocation_limit'; break; }
            $budget -= count($entries);
            $ids = array_values(array_filter(array_column($entries, 'bill_id'), fn ($id) => filter_var($id, FILTER_VALIDATE_INT) !== false && $id > 0));
            $bills = DB::table('pos_transactions')->where('company_id', $companyId)->whereIn('id', $ids)
                ->get(['id', 'business_date', 'branch_id', 'invoice_mode', 'payment_method', 'transaction_type'])->keyBy('id');
            $out['count']++; $amount = $this->cents($receipt->total_amount); $allocated = 0; $seen = [];
            foreach ($entries as $entry) {
                if (!is_array($entry) || filter_var($entry['bill_id'] ?? null, FILTER_VALIDATE_INT) === false || (int) ($entry['bill_id'] ?? 0) < 1 || isset($seen[(int) $entry['bill_id']])) { $gaps[] = 'invalid_or_duplicate_receipt_allocation'; continue; }
                $seen[(int) $entry['bill_id']] = true;
                $money = $this->cents($entry['amount'] ?? null);
                if ($money === null || $money < 0) { $gaps[] = 'invalid_receipt_amount'; continue; }
                $allocated += $money;
                $bill = $bills->get((int) $entry['bill_id']);
                if (!$bill) { $gaps[] = 'receipt_bill_unavailable'; continue; }
                if ($report->branch_id && $bill->branch_id !== null && (int) $bill->branch_id !== (int) $report->branch_id) continue;
                if ($bill->business_date !== ($entry['business_date'] ?? null)) { $gaps[] = 'allocation_business_date_mismatch'; continue; }
                if ($bill->payment_method === 'cash' && in_array($bill->invoice_mode, [null, 'pra'], true) && in_array($bill->transaction_type, [null, '', 'sale'], true)
                    && $bill->business_date < $report->report_date) $out['prior_bill_cash_in'] += $money;
            }
            $out['total'] += $amount ?? 0; $out['allocated'] += $allocated;
            if ($amount === null || $amount !== $allocated) $gaps[] = 'receipt_allocation_total_mismatch';
        }
        return $out;
    }

    private function hotel(int $companyId, object $report, array &$gaps): ?array
    {
        $out = ['cash' => 0, 'card' => 0, 'other' => 0];
        if (!Schema::hasTable('hotel_folio_entries')) return $out;
        $required = ['id', 'company_id', 'stay_id', 'pos_transaction_id', 'entry_type', 'amount', 'payment_method',
            'settlement_business_date', 'settlement_branch_id', 'refund_business_date', 'refund_branch_id', 'created_at'];
        if (!$this->has('hotel_folio_entries', $required) || !$this->has('hotel_stays', ['id', 'company_id', 'hotel_money_from_folio'])
            || !$this->has('hotel_credit_notes', ['company_id', 'credit_transaction_id'])) {
            $gaps[] = 'hotel_settlement_schema_unavailable'; return null;
        }
        // Independent settlement SELECTs: money-from-folio movements and credit-note
        // refunds are disjoint. Local settlement classifications also affect the drawer.
        $creditIds = DB::table('hotel_credit_notes')->where('company_id', $companyId)->whereNotNull('credit_transaction_id')->select('credit_transaction_id');
        $money = DB::table('hotel_folio_entries')->where('company_id', $companyId)->whereIn('entry_type', ['payment', 'refund'])
            ->where('settlement_business_date', $report->report_date)
            ->whereIn('stay_id', DB::table('hotel_stays')->where('company_id', $companyId)->where('hotel_money_from_folio', true)->select('id'))
            ->where(fn ($q) => $q->whereNull('pos_transaction_id')->orWhereNotIn('pos_transaction_id', $creditIds));
        $refund = DB::table('hotel_folio_entries')->where('company_id', $companyId)->where('entry_type', 'refund')
            ->where('refund_business_date', $report->report_date)->whereIn('pos_transaction_id', $creditIds);
        if ($report->branch_id) {
            $money->where(fn ($q) => $q->where('settlement_branch_id', $report->branch_id)->orWhereNull('settlement_branch_id'));
            $refund->where(fn ($q) => $q->where('refund_branch_id', $report->branch_id)->orWhereNull('refund_branch_id'));
        }
        foreach ([$money, $refund] as $q) {
            $rows = $q->orderBy('id')->limit(self::BILL_LIMIT + 1)->get(['entry_type', 'amount', 'payment_method', 'created_at']);
            if ($rows->count() > self::BILL_LIMIT) { $gaps[] = 'hotel_movement_limit'; return null; }
            foreach ($rows as $row) {
                if (!isset($row->created_at, $report->created_at) || $row->created_at > $report->created_at) $gaps[] = 'hotel_movement_after_close_or_undated';
                $amount = $this->cents($row->amount);
                if ($amount === null || $amount < 0 || !$row->payment_method) { $gaps[] = 'hotel_movement_amount_or_method_unavailable'; return null; }
                $out[PosPaymentBuckets::bucket($row->payment_method)] += $amount * ($row->entry_type === 'payment' ? 1 : -1);
            }
        }
        return $out;
    }

    private function branch($query, int $branch): void
    {
        if ($branch > 0) $query->where(fn ($q) => $q->where('branch_id', $branch)->orWhereNull('branch_id'));
    }

    private function has(string $table, array $columns): bool
    {
        return Schema::hasTable($table) && !array_diff($columns, Schema::getColumnListing($table));
    }

    private function cents(mixed $value): ?int
    {
        return is_numeric($value) && is_finite((float) $value) && abs((float) $value) < 1000000000000 ? (int) round((float) $value * 100) : null;
    }
}
