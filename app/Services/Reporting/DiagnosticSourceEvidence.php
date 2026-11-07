<?php

namespace App\Services\Reporting;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Registered, SELECT-only source metadata. Never returns stored payloads. */
final class DiagnosticSourceEvidence
{
    public const REGISTRY_VERSION = 6;

    public const SOURCES = [
        'pra.fiscal' => ['pos', 'pra_logs', 'created_at'],
        'pos.sales' => ['pos', 'pos_transactions', 'created_at'],
        'pos.print_attempts' => ['pos', 'pos_print_jobs', 'created_at'],
        'pos.stock_movements' => ['pos', 'inventory_movements', 'created_at'],
        'pos.print_receipts' => ['pos', 'pos_print_evidence', 'received_at', ['product_type' => 'pos']],
        'pos.offline_samples' => ['pos', 'pra_diagnostic_samples', 'received_at'],
        'pos.ims' => ['pos', 'pos_ims_submissions', 'created_at'],
        'pos.di' => ['pos', 'pos_di_sandbox_submissions', 'created_at'],
        'pos.orders' => ['pos', 'restaurant_orders', 'created_at'],
        'di.fiscal' => ['di', 'fbr_logs', 'created_at'],
        'di.sales' => ['di', 'invoices', 'created_at'],
        'fbrpos.fiscal' => ['fbrpos', 'fbr_pos_logs', 'created_at'],
        'fbrpos.sales' => ['fbrpos', 'fbr_pos_transactions', 'created_at'],
        'fbrpos.callbacks' => ['fbrpos', 'fbr_pos_callback_diagnostics', 'callback_received_at'],
        'fbrpos.print_receipts' => ['fbrpos', 'pos_print_evidence', 'received_at', ['product_type' => 'fbrpos']],
        'health.audit' => ['erps', 'health_audit_events', 'occurred_at'],
        'health.billing' => ['erps', 'health_bills', 'created_at'],
        'health.payments' => ['erps', 'health_payments', 'created_at'],
        'health.federal' => ['erps', 'health_fbr_submissions', 'created_at'],
        'health.stock' => ['erps', 'health_batch_movements', 'created_at'],
        'pos.platform_audit' => ['pos', 'audit_logs', 'created_at'],
        'pos.notifications' => ['pos', 'notifications', 'created_at'],
        'pos.branches' => ['pos', 'branches', 'created_at'],
        'pos.support_cases' => ['pos', 'support_cases', 'updated_at'],
        'di.platform_audit' => ['di', 'audit_logs', 'created_at'],
        'di.notifications' => ['di', 'notifications', 'created_at'],
        'di.branches' => ['di', 'branches', 'created_at'],
        'di.support_cases' => ['di', 'support_cases', 'updated_at'],
        'fbrpos.platform_audit' => ['fbrpos', 'audit_logs', 'created_at'],
        'fbrpos.notifications' => ['fbrpos', 'notifications', 'created_at'],
        'fbrpos.branches' => ['fbrpos', 'branches', 'created_at'],
        'fbrpos.support_cases' => ['fbrpos', 'support_cases', 'updated_at'],
        'health.platform_audit' => ['erps', 'audit_logs', 'created_at'],
        'health.notifications' => ['erps', 'notifications', 'created_at'],
        'health.branches' => ['erps', 'branches', 'created_at'],
        'health.support_cases' => ['erps', 'support_cases', 'updated_at'],
        'di.import_batches' => ['di', 'invoice_import_batches', 'created_at'],
        'di.audit_exports' => ['di', 'audit_packs', 'created_at'],
        'health.prescriptions' => ['erps', 'health_prescriptions', 'created_at'],
        'health.pharmacy_sales' => ['erps', 'health_pharmacy_sales', 'created_at'],
    ];

    public function read(array $args, ?array $allowed): array
    {
        if (array_diff(array_keys($args), ['company_name', 'company_id', 'branch_id', 'date_from', 'date_to', 'source', 'after_id', 'limit'])) {
            throw ValidationException::withMessages(['arguments' => 'Unsupported source evidence filter.']);
        }
        $v = validator($args, ['company_name' => 'required|string|min:2|max:150', 'company_id' => 'nullable|integer|min:1',
            'branch_id' => 'nullable|integer|min:1',
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
        if (Schema::hasColumn('companies', 'deleted_at')) {
            $companies->whereNull('deleted_at');
        }
        if ($allowed !== null) {
            $companies->whereIn('id', $allowed);
        }
        if (isset($v['company_id'])) {
            $companies->where('id', $v['company_id']);
        }
        $matches = $companies->limit(2)->get(['id', 'name', 'product_type']);
        if ($matches->count() !== 1) {
            throw ValidationException::withMessages(['company_name' => 'Exact company is ambiguous, unavailable or outside this connection scope.']);
        }
        $company = $matches->sole();
        $branchId = isset($v['branch_id']) ? (int) $v['branch_id'] : null;
        if ($branchId !== null) {
            if (! Schema::hasTable('branches') || ! Schema::hasColumn('branches', 'id')
                || ! Schema::hasColumn('branches', 'company_id')
                || ! DB::table('branches')->where('id', $branchId)->where('company_id', $company->id)->exists()) {
                throw ValidationException::withMessages(['branch_id' => 'Branch is unavailable or outside the selected company.']);
            }
        }
        [$product, $table, $stamp] = self::SOURCES[$v['source']];
        $fixed = self::SOURCES[$v['source']][3] ?? [];
        if (\App\Support\NestErps::canonicalProductType($company->product_type) !== $product) {
            throw ValidationException::withMessages(['source' => 'This source does not apply to the selected product.']);
        }
        $meta = ['company' => ['id' => (int) $company->id, 'name' => $company->name, 'product' => $company->product_type],
            'branch' => $branchId === null ? null : ['id' => $branchId, 'scope' => 'exact'],
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'basis' => 'stored_server_calendar', 'timezone' => $tz],
            'as_of' => Carbon::now($tz)->toIso8601String(), 'registry_version' => self::REGISTRY_VERSION, 'source' => $v['source'],
            'collection_observation' => $branchId === null
                ? app(DiagnosticSourceCollection::class)->latest((int) $company->id, $v['source'], $from->toDateString(), $to->toDateString())
                : ['status' => 'UNAVAILABLE_FOR_BRANCH_SCOPE',
                    'reason' => 'Stored collection observations are company-wide and are not proof of branch collection.'],
            'coverage' => ['complete' => false, 'seven_day_complete' => false, 'collection_status' => 'UNKNOWN',
                'deleted_or_never_collected_events' => 'UNKNOWN', 'sequence_gaps' => 'UNAVAILABLE', 'client_clock_skew' => 'UNAVAILABLE'],
            'notes' => ['Stored event presence does not establish complete collection, regulator acceptance or product health.',
                'Missing tables are unavailable; an empty window proves only that no retained rows matched.',
                'Rows contain IDs and timestamps only. Regulatory payloads, patient data, customer details and credentials are excluded.',
                'Branch filtering is applied only through an exact retained branch key; sources without one return BRANCH_SCOPE_UNAVAILABLE.',
                'Pages use immutable IDs, not a database snapshot; re-read for late arrivals. DI logs whose source invoice was deleted cannot be attributed.']];
        if ($v['source'] === 'pos.offline_samples') {
            $meta['related_operation'] = ['name' => 'get_pra_operations_evidence', 'stream' => 'agent_collection',
                'purpose' => 'Retained device sequence gaps, source/receipt clock-or-upload difference, stale streams, pending/dropped/reset counts and storage status.'];
        }
        try {
            foreach (array_merge(['id', $stamp, $v['source'] === 'di.fiscal' ? 'invoice_id' : 'company_id'], array_keys($fixed)) as $column) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                    return $meta + ['status' => 'UNAVAILABLE', 'observed' => null, 'rows' => [], 'pagination' => ['has_more' => false, 'next_after_id' => null]];
                }
            }
            $q = DB::table($table.' as e');
            if ($v['source'] === 'di.fiscal') {
                if (! Schema::hasTable('invoices') || ! Schema::hasColumn('invoices', 'company_id')) {
                    return $meta + ['status' => 'UNAVAILABLE', 'observed' => null, 'rows' => [], 'pagination' => ['has_more' => false, 'next_after_id' => null]];
                }
                $q->join('invoices as i', 'i.id', '=', 'e.invoice_id')->where('i.company_id', $company->id);
            } else {
                $q->where('e.company_id', $company->id);
            }
            if ($branchId !== null) {
                if ($table === 'branches') {
                    $q->where('e.id', $branchId);
                } elseif ($v['source'] === 'di.fiscal' && Schema::hasColumn('invoices', 'branch_id')) {
                    $q->where('i.branch_id', $branchId);
                } elseif (Schema::hasColumn($table, 'branch_id')) {
                    $q->where('e.branch_id', $branchId);
                } else {
                    return $meta + ['status' => 'BRANCH_SCOPE_UNAVAILABLE', 'observed' => null, 'rows' => [],
                        'pagination' => ['has_more' => false, 'next_after_id' => null]];
                }
            }
            foreach ($fixed as $column => $value) {
                $q->where('e.'.$column, $value);
            }
            $q->whereBetween('e.'.$stamp, [$from->copy()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
                $to->copy()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s')]);
            $limit = (int) ($v['limit'] ?? 25);
            $extent = (clone $q)->selectRaw('COUNT(*) AS retained_rows, MIN(e.'.$stamp.') AS first_at, MAX(e.'.$stamp.') AS last_at')->first();
            $rows = $q->where('e.id', '>', (int) ($v['after_id'] ?? 0))->orderBy('e.id')->limit($limit + 1)
                ->get(['e.id', 'e.'.$stamp.' as event_at']);
            $page = $rows->take($limit);

            return $meta + ['status' => (int) $extent->retained_rows === 0 ? 'NO_RETAINED_EVENTS' : 'PARTIAL',
                'observed' => ['retained_rows_in_window' => (int) $extent->retained_rows,
                    'earliest_in_window' => $extent->first_at, 'latest_in_window' => $extent->last_at,
                    'timestamp_column' => $stamp, 'page_rows' => $page->count(), 'earliest_on_page' => $page->min('event_at'), 'latest_on_page' => $page->max('event_at'),
                    'timestamp_storage_timezone' => config('app.timezone')],
                'rows' => $page->map(fn ($r) => ['id' => (int) $r->id,
                    'event_at' => Carbon::parse($r->event_at, config('app.timezone'))->setTimezone($tz)->toIso8601String()])->values()->all(),
                'pagination' => ['has_more' => $rows->count() > $limit, 'next_after_id' => $rows->count() > $limit ? (int) $page->last()->id : null]];
        } catch (\Illuminate\Database\QueryException) {
            return $meta + ['status' => 'SOURCE_QUERY_FAILED', 'observed' => null, 'rows' => [],
                'pagination' => ['has_more' => false, 'next_after_id' => null]];
        }
    }
}
