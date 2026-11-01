<?php

namespace App\Services\Reporting;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Reads frozen PRA cash evidence. Never runs close/finalize/rebuild or writes business rows. */
final class DayCloseEvidence
{
    private const STREAMS = [
        'reports' => ['pos_day_close_reports', 'report_date', ['opening_float', 'cash_amount', 'card_amount', 'other_amount', 'expected_cash', 'counted_cash', 'cash_variance', 'total_amount', 'returns_amount', 'total_invoices', 'returns_count', 'closed_by']],
        'openings' => ['pos_day_openings', 'business_date', ['terminal_id', 'opening_cash', 'entered_by']],
        'counter_closes' => ['pos_counter_closes', 'business_date', ['terminal_id', 'opening_float', 'cash_sales', 'expected_cash', 'counted_cash', 'cash_variance', 'bills_count', 'total_sales', 'closed_by']],
    ];

    public function read(int $companyId, array $args): array
    {
        $v = validator($args, [
            'date_from' => 'nullable|date_format:Y-m-d', 'date_to' => 'nullable|date_format:Y-m-d',
            'stream' => 'nullable|in:reports,openings,counter_closes',
            'branch_id' => 'nullable|integer|min:0', 'terminal_id' => 'nullable|integer|min:0',
            'after_id' => 'nullable|integer|min:0', 'limit' => 'nullable|integer|min:1|max:50',
        ])->validate();
        $company = DB::table('companies')->where('id', $companyId)->where('product_type', 'pos')
            ->when(Schema::hasColumn('companies', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))->first(['id', 'name', 'product_type']);
        if (! $company) {
            throw ValidationException::withMessages(['company' => 'PRA Day Close evidence is unavailable for this company/product.']);
        }
        $tz = 'Asia/Karachi';
        $today = Carbon::now($tz)->toDateString();
        $from = Carbon::parse($v['date_from'] ?? $today, $tz)->startOfDay();
        $to = Carbon::parse($v['date_to'] ?? $v['date_from'] ?? $today, $tz)->startOfDay();
        if ($from->gt($to) || $from->diffInDays($to) > 30) {
            throw ValidationException::withMessages(['date_from' => 'Use an ordered range of at most 31 saved business dates.']);
        }
        $stream = $v['stream'] ?? 'reports';
        [$table, $date, $money] = self::STREAMS[$stream];
        if ($stream === 'reports' && isset($v['terminal_id'])) {
            throw ValidationException::withMessages(['terminal_id' => 'Use counter_closes for a single drawer; a shop report cannot be filtered into a counter report.']);
        }
        $limit = (int) ($v['limit'] ?? 25);
        $result = [
            'company' => ['id' => (int) $company->id, 'name' => $company->name, 'product' => 'pos'],
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'basis' => 'stored_business_date', 'timezone' => $tz],
            'as_of' => Carbon::now($tz)->toIso8601String(), 'currency' => 'PKR', 'stream' => $stream,
            'source' => $table, 'filters' => array_intersect_key($v, array_flip(['branch_id', 'terminal_id'])),
            'coverage' => ['status' => 'UNAVAILABLE', 'complete' => false, 'retention_verified' => false, 'historical_cutoff' => 'UNKNOWN'],
            'records' => [], 'days' => [], 'pagination' => ['limit' => $limit, 'has_more' => false, 'next_after_id' => null],
            'notes' => [
                'Saved business dates are used literally, including closes made after midnight. Historical cutoff times are not inferred from current settings.',
                'Report money is the frozen close snapshot. Current invoice rows are not used to rebuild or change it; archived/deleted bills may be absent.',
                'Arithmetic checks validate stored inputs only, not physical cash, refund execution, rider handover or completeness of the original calculation.',
                'Opening records and counter closes are separate evidence streams. Do not add these to a shop report; they can describe the same cash.',
                'Missing values remain unknown. Missing close rows do not establish that a day is open, inactive or deleted. A current date is not a completed day.',
                'Full payment/refund/rider event reconciliation, manual overrides and all-feature coverage are not implemented by this reader.',
            ],
        ];
        if (! Schema::hasTable($table)) {
            $result['coverage']['reason'] = 'Source table is unavailable; no zero balance can be inferred.';

            return $result;
        }
        $columns = Schema::getColumnListing($table);
        if (array_diff(['id', 'company_id', $date], $columns)) {
            $result['coverage']['reason'] = 'Required scope/date columns are unavailable.';

            return $result;
        }
        $q = DB::table($table)->where('company_id', $companyId)->whereBetween($date, [$from->toDateString(), $to->toDateString()]);
        foreach (['branch_id', 'terminal_id'] as $filter) {
            if (isset($v[$filter])) {
                if (! in_array($filter, $columns, true)) {
                    throw ValidationException::withMessages([$filter => 'This source cannot establish the requested filter.']);
                }
                $q->where($filter, $v[$filter]);
            }
        }
        $counts = (clone $q)->select($date)->selectRaw('COUNT(*) AS count')->groupBy($date)->pluck('count', $date);
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $key = $day->toDateString();
            $n = (int) ($counts[$key] ?? 0);
            $result['days'][] = ['business_date' => $key, 'stored_records' => $n, 'status' => $n ? 'STORED_EVIDENCE' : 'NO_RECORD', 'current_calendar_date' => $key === $today];
        }
        $fields = array_merge(['id', 'branch_id', $date, 'created_at', 'updated_at', 'closed_at'], $money);
        $selected = array_values(array_intersect($fields, $columns));
        // JSON has a database-side size ceiling, and only numeric allow-listed values leave this reader.
        foreach ($stream === 'reports' ? ['rider_summary', 'counter_summary'] : [] as $json) {
            if (in_array($json, $columns, true)) {
                $selected[] = DB::raw("CASE WHEN LENGTH($json) <= 65536 THEN $json ELSE NULL END AS $json");
            }
        }
        $rows = (clone $q)->where('id', '>', (int) ($v['after_id'] ?? 0))->orderBy('id')->limit($limit + 1)->get($selected);
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);
        foreach ($rows as $row) {
            $r = ['id' => (int) $row->id, 'branch_id' => isset($row->branch_id) ? (int) $row->branch_id : null, 'business_date' => (string) $row->$date];
            foreach ($money as $field) {
                $r[$field] = in_array($field, ['terminal_id', 'entered_by', 'closed_by', 'total_invoices', 'returns_count', 'bills_count'], true)
                    ? (isset($row->$field) ? (int) $row->$field : null) : $this->number($row->$field ?? null);
            }
            foreach (['created_at', 'updated_at', 'closed_at'] as $field) {
                $r[$field] = $row->$field ?? null;
            }
            if ($stream === 'reports') {
                $rider = $this->decode($row->rider_summary ?? null);
                $r['rider_cash_out'] = $this->number($rider['cash_out'] ?? null);
                $r['rider_cash_in'] = $this->number($rider['cash_in'] ?? null);
                $r['arithmetic'] = $this->arithmetic($r['opening_float'], $r['cash_amount'], $r['rider_cash_out'], $r['rider_cash_in'], $r['expected_cash'], $r['counted_cash'], $r['cash_variance']);
                $counters = $this->decode($row->counter_summary ?? null);
                $r['counter_snapshot'] = [];
                foreach (array_slice($counters ?? [], 0, 25) as $counter) {
                    if (! is_array($counter)) {
                        continue;
                    }
                    $clean = [];
                    foreach (['terminal_id', 'opening', 'cash_sales', 'rider_out', 'rider_in', 'expected', 'counted', 'variance', 'bills', 'total'] as $key) {
                        $clean[$key] = in_array($key, ['terminal_id', 'bills'], true)
                            ? (isset($counter[$key]) && is_numeric($counter[$key]) ? (int) $counter[$key] : null) : $this->number($counter[$key] ?? null);
                    }
                    $clean['arithmetic'] = $this->arithmetic($clean['opening'], $clean['cash_sales'], $clean['rider_out'], $clean['rider_in'], $clean['expected'], $clean['counted'], $clean['variance']);
                    $r['counter_snapshot'][] = $clean;
                }
                $r['counter_snapshot_coverage'] = ['status' => $counters === null ? 'UNAVAILABLE' : (count($counters) > 25 ? 'PARTIAL' : 'STORED_EVIDENCE'), 'returned' => count($r['counter_snapshot']), 'total' => $counters === null ? null : count($counters)];
            } elseif ($stream === 'counter_closes') {
                // Separate counter-close rows do not store rider in/out. Never invent those inputs.
                $r['arithmetic'] = $this->arithmetic($r['opening_float'], $r['cash_sales'], null, null, $r['expected_cash'], $r['counted_cash'], $r['cash_variance']);
            }
            $result['records'][] = $r;
        }
        $result['coverage'] += ['total_matching' => (int) $counts->sum(), 'missing_columns' => array_values(array_diff($fields, $columns)), 'scope_note' => 'Branch 0 means stored no-branch/legacy/company-wide scope; it is not a current branch attribution.'];
        $result['coverage']['status'] = $counts->sum() ? 'PARTIAL' : 'NO_ACTIVITY';
        $result['pagination'] = ['limit' => $limit, 'has_more' => $hasMore, 'next_after_id' => $hasMore ? (int) $rows->last()->id : null];

        return $result;
    }

    private function decode(?string $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        $decoded = json_decode($raw, true, 16);

        return is_array($decoded) ? $decoded : null;
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? round((float) $value, 2) : null;
    }

    private function arithmetic(?float $opening, ?float $cash, ?float $out, ?float $in, ?float $expected, ?float $counted, ?float $variance): array
    {
        $calculated = in_array(null, [$opening, $cash, $out, $in], true) ? null : round($opening + $cash - $out + $in, 2);
        $calculatedVariance = $expected !== null && $counted !== null ? round($counted - $expected, 2) : null;
        $difference = $calculated !== null && $expected !== null ? round($expected - $calculated, 2) : null;
        $varianceDifference = $calculatedVariance !== null && $variance !== null ? round($variance - $calculatedVariance, 2) : null;

        return ['expected_formula' => 'opening + net cash sales - rider cash out + prior-bill cash in',
            'calculated_expected' => $calculated, 'stored_minus_calculated' => $difference,
            'expected_check' => $difference === null ? 'UNAVAILABLE' : (abs($difference) < 0.005 ? 'PASS' : 'ISSUE'),
            'calculated_variance' => $calculatedVariance, 'variance_difference' => $varianceDifference,
            'variance_check' => $varianceDifference === null ? 'UNAVAILABLE' : (abs($varianceDifference) < 0.005 ? 'PASS' : 'ISSUE')];
    }
}
