<?php

namespace App\Services\Reporting;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Validation\ValidationException;

/** Registered, SELECT-only source metadata. Never returns stored payloads. */
final class DiagnosticSourceEvidence
{
    public const SOURCES = [
        'pra.fiscal' => ['pos', 'pra_logs', 'created_at'],
        'di.fiscal' => ['di', 'fbr_logs', 'created_at'],
        'fbrpos.fiscal' => ['fbrpos', 'fbr_pos_logs', 'created_at'],
        'health.audit' => ['erps', 'health_audit_events', 'occurred_at'],
    ];

    public function read(array $args, ?array $allowed): array
    {
        if (array_diff(array_keys($args), ['company_name', 'company_id', 'date_from', 'date_to', 'source', 'after_id', 'limit'])) {
            throw ValidationException::withMessages(['arguments' => 'Unsupported source evidence filter.']);
        }
        $v = validator($args, ['company_name' => 'required|string|min:2|max:150', 'company_id' => 'nullable|integer|min:1',
            'date_from' => 'required|date_format:Y-m-d', 'date_to' => 'nullable|date_format:Y-m-d',
            'source' => 'required|in:'.implode(',', array_keys(self::SOURCES)),
            'after_id' => 'nullable|integer|min:0', 'limit' => 'nullable|integer|min:1|max:50'])->validate();
        $tz = config('reporting_connector.timezone', 'Asia/Karachi');
        $from = Carbon::parse($v['date_from'], $tz)->startOfDay();
        $to = Carbon::parse($v['date_to'] ?? $v['date_from'], $tz)->endOfDay();
        if ($from->gt($to) || $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) > 6) {
            throw ValidationException::withMessages(['date_from' => 'Use at most seven ordered calendar dates.']);
        }
        // Existing OAuth grant scope is authoritative. Exact ID/name resolution
        // also supports ERPS without broadening the older billing adapters.
        $companies = DB::table('companies')->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($v['company_name']))]);
        if (Schema::hasColumn('companies', 'deleted_at')) $companies->whereNull('deleted_at');
        if ($allowed !== null) $companies->whereIn('id', $allowed);
        if (isset($v['company_id'])) $companies->where('id', $v['company_id']);
        $matches = $companies->limit(2)->get(['id', 'name', 'product_type']);
        if ($matches->count() !== 1) {
            throw ValidationException::withMessages(['company_name' => 'Exact company is ambiguous, unavailable or outside this connection scope.']);
        }
        $company = $matches->sole();
        [$product, $table, $stamp] = self::SOURCES[$v['source']];
        if ($company->product_type !== $product) {
            throw ValidationException::withMessages(['source' => 'This source does not apply to the selected product.']);
        }
        $meta = ['company' => ['id' => (int) $company->id, 'name' => $company->name, 'product' => $company->product_type],
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'basis' => 'stored_server_calendar', 'timezone' => $tz],
            'as_of' => Carbon::now($tz)->toIso8601String(), 'registry_version' => 1, 'source' => $v['source'],
            'coverage' => ['complete' => false, 'seven_day_complete' => false, 'collection_status' => 'UNKNOWN',
                'deleted_or_never_collected_events' => 'UNKNOWN', 'sequence_gaps' => 'UNAVAILABLE', 'client_clock_skew' => 'UNAVAILABLE'],
            'notes' => ['Stored event presence does not establish complete collection, regulator acceptance or product health.',
                'Missing tables are unavailable; an empty window proves only that no retained rows matched.',
                'Rows contain IDs and timestamps only. Regulatory payloads, patient data, customer details and credentials are excluded.',
                'Pages use immutable IDs, not a database snapshot; re-read for late arrivals. DI logs whose source invoice was deleted cannot be attributed.']];
        foreach (['id', $stamp, $v['source'] === 'di.fiscal' ? 'invoice_id' : 'company_id'] as $column) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
                return $meta + ['status' => 'UNAVAILABLE', 'observed' => null, 'rows' => [], 'pagination' => ['has_more' => false, 'next_after_id' => null]];
            }
        }
        $q = DB::table($table.' as e');
        if ($v['source'] === 'di.fiscal') {
            if (!Schema::hasTable('invoices') || !Schema::hasColumn('invoices', 'company_id')) {
                return $meta + ['status' => 'UNAVAILABLE', 'observed' => null, 'rows' => [], 'pagination' => ['has_more' => false, 'next_after_id' => null]];
            }
            $q->join('invoices as i', 'i.id', '=', 'e.invoice_id')->where('i.company_id', $company->id);
        } else {
            $q->where('e.company_id', $company->id);
        }
        $q->whereBetween('e.'.$stamp, [$from->copy()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
            $to->copy()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s')]);
        $limit = (int) ($v['limit'] ?? 25);
        $rows = $q->where('e.id', '>', (int) ($v['after_id'] ?? 0))->orderBy('e.id')->limit($limit + 1)
            ->get(['e.id', 'e.'.$stamp.' as event_at']);
        $page = $rows->take($limit);
        return $meta + ['status' => $rows->isEmpty() ? 'NO_RETAINED_EVENTS' : 'PARTIAL',
            'observed' => ['page_rows' => $page->count(), 'earliest_on_page' => $page->min('event_at'), 'latest_on_page' => $page->max('event_at'),
                'timestamp_storage_timezone' => config('app.timezone')],
            'rows' => $page->map(fn ($r) => ['id' => (int) $r->id,
                'event_at' => Carbon::parse($r->event_at, config('app.timezone'))->setTimezone($tz)->toIso8601String()])->values()->all(),
            'pagination' => ['has_more' => $rows->count() > $limit, 'next_after_id' => $rows->count() > $limit ? (int) $page->last()->id : null]];
    }
}
