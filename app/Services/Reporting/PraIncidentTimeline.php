<?php

namespace App\Services\Reporting;

use App\Services\PrintJobEvidence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** SELECT-only, bounded evidence. Agent acknowledgements never prove physical paper. */
final class PraIncidentTimeline
{
    public function read(array $args, ?array $allowed): array
    {
        $keys = ['company_name', 'company_id', 'date_from', 'date_to', 'branch_id', 'bill_id', 'order_id', 'job_id', 'after_id', 'limit'];
        if (array_diff(array_keys($args), $keys)) {
            throw ValidationException::withMessages(['arguments' => 'Unsupported diagnostic filter. Calendar dates only; no arbitrary queries.']);
        }
        $v = validator($args, ['company_name' => 'required|string|min:2|max:150', 'company_id' => 'nullable|integer|min:1',
            'date_from' => 'nullable|date_format:Y-m-d', 'date_to' => 'nullable|date_format:Y-m-d',
            'branch_id' => 'nullable|integer|min:1', 'bill_id' => 'nullable|integer|min:1', 'order_id' => 'nullable|integer|min:1',
            'job_id' => 'nullable|integer|min:1', 'after_id' => 'nullable|integer|min:0', 'limit' => 'nullable|integer|min:1|max:50'])->validate();
        $c = app(CompanyReadReports::class)->context(array_intersect_key($v, array_flip(['company_name', 'company_id', 'date_from', 'date_to'])), $allowed);
        if ($c['company']->product_type !== 'pos') {
            throw ValidationException::withMessages(['company_name' => 'This diagnostic adapter currently supports PRA POS only. Other modules are not covered.']);
        }
        if (Carbon::parse($c['meta']['period']['from'])->diffInDays(Carbon::parse($c['meta']['period']['to'])) > 6) {
            throw ValidationException::withMessages(['date_from' => 'Use at most seven calendar days per incident query.']);
        }
        $meta = $c['meta'];
        $meta['filters'] = array_intersect_key($v, array_flip(['branch_id', 'bill_id', 'order_id', 'job_id']));
        $meta['period']['basis'] = 'server_received_calendar';
        $meta['source'] = ['name' => 'pos_print_evidence', 'adapter' => 'pra-print-v1', 'clock' => 'server',
            'timestamp_storage_timezone' => config('app.timezone'), 'client_clock_skew' => 'unavailable'];
        $available = Schema::hasTable('pos_print_evidence') && Schema::hasColumn('pos_print_evidence', 'product_type');
        $health = Schema::hasTable('pos_print_evidence_health')
            ? DB::table('pos_print_evidence_health')->where('company_id', $c['company']->id)->first() : null;
        $coverage = ['status' => $available ? 'partial' : 'unavailable', 'complete' => false,
            'retention_policy_days' => PrintJobEvidence::RETENTION_DAYS, 'minimum_requested_days' => 7,
            'seven_day_complete_record_proven' => false,
            'first_correlated_record_at' => $this->time($health?->first_recorded_at),
            'last_recorded_at' => $this->time($health?->last_recorded_at),
            'last_recording_failure_at' => $this->time($health?->last_failure_at),
            'recording_failure_count' => $health ? (int) $health->failure_count : null,
            'capacity_and_scheduler_live_acceptance' => 'unverified',
            'gaps' => ['Events absent before collection cannot be recovered. Legacy rows lack immutable bill/branch/product attribution.',
                'Only server enqueue/rejection, saved order-to-bill links, claim, content request/no-document, accepted result and device-change hooks are recorded. Direct SQL housekeeping transitions, browser printing, disconnected Agent local activity and full sale/item/fiscal lifecycles are not complete streams.',
                'No device upload sequence or heartbeat history guarantee exists. Missing callbacks are missing evidence, not proof of missing paper.',
                'Storage failures are best-effort counted; database failure or enclosing transaction rollback can prevent that counter too. Absence of failures is not proof of complete capture.',
                'Retention is a code policy for successfully stored rows. Actual host capacity, backups, scheduler operation and connected-client acceptance require separate verification.']];
        $base = $meta + ['physical_print' => 'unknown', 'coverage' => $coverage,
            'unsupported_adapters' => ['fbrpos', 'di', 'healthcare', 'other_erp', 'platform_logs', 'agent_local_logs'],
            'notes' => ['Events are observed server facts, not a root-cause verdict. Spool accepted or legacy agent acknowledged does not prove physical paper.',
                'Each result is paginated by immutable event ID. Re-run after late arrivals; pages are not a database snapshot. No reprint, retry, fiscal posting or settings changes occur.',
                'Bill filtering also includes orders with a retained observed order-to-bill link. Earlier order events retain their original null bill_id; the link is evidence of association, not proof every attempt belongs to a final bill or that an order was never reassigned.',
                'Context strings, customer data, printer/host names, raw errors, claim tokens and regulatory payloads are excluded.']];
        if (!$available) return $base + ['events' => [], 'pagination' => ['next_after_id' => null, 'has_more' => false], 'observed' => null];
        $q = DB::table('pos_print_evidence')->where('company_id', $c['company']->id)->where('product_type', 'pos')
            ->whereBetween('received_at', $c['calendarBounds']);
        foreach (['branch_id' => 'branch_id', 'order_id' => 'order_id', 'job_id' => 'job_id'] as $key => $column) {
            if (isset($v[$key])) $q->where($column, $v[$key]);
        }
        if (isset($v['bill_id'])) {
            $links = DB::table('pos_print_evidence as link')->where('link.company_id', $c['company']->id)
                ->where('link.product_type', 'pos')->where('link.transaction_id', $v['bill_id'])->whereNotNull('link.order_id')->select('link.order_id');
            $q->where(fn ($query) => $query->where('transaction_id', $v['bill_id'])->orWhereIn('order_id', $links));
        }
        $extent = (clone $q)->selectRaw('COUNT(*) as events, MIN(received_at) as earliest, MAX(received_at) as latest')->first();
        $limit = (int) ($v['limit'] ?? 50);
        $rows = (clone $q)->where('id', '>', (int) ($v['after_id'] ?? 0))->orderBy('id')->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $page = $rows->take($limit);
        $events = $page->map(function ($row) {
            $raw = json_decode($row->context, true);
            $raw = is_array($raw) ? $raw : [];
            $details = [];
            foreach (['content_ms', 'content_attempts', 'print_ms', 'gap_seconds'] as $key) {
                if (isset($raw[$key]) && is_numeric($raw[$key]) && $raw[$key] >= 0 && $raw[$key] <= 31536000) $details[$key] = (int) $raw[$key];
            }
            foreach (['outcome' => ['no_document', 'spool_accepted', 'pre_spool_failed', 'output_unknown', 'agent_acknowledged'],
                'stage' => ['content_fetch', 'content_received', 'print_call', 'result_report'],
                'type' => ['kot', 'bill', 'proof', 'receipt', 'store_slip']] as $key => $values) {
                if (isset($raw[$key]) && in_array($raw[$key], $values, true)) $details[$key] = $raw[$key];
            }
            $known = ['enqueued', 'enqueue_rejected', 'order_bill_linked', 'claimed', 'content_requested', 'no_document', 'result_received',
                'device_seen_after_gap', 'device_printer_list_changed', 'device_version_changed'];
            return ['id' => (int) $row->id, 'event' => in_array($row->event, $known, true) ? $row->event : 'unsupported_event',
                'job_id' => $row->job_id ? (int) $row->job_id : null, 'attempt' => $row->attempt === null ? null : (int) $row->attempt,
                'bill_id' => $row->transaction_id ? (int) $row->transaction_id : null,
                'order_id' => $row->order_id ? (int) $row->order_id : null, 'branch_id' => $row->branch_id ? (int) $row->branch_id : null,
                'received_at' => $this->time($row->received_at), 'details' => $details];
        })->values()->all();
        return $base + ['events' => $events, 'pagination' => ['has_more' => $more, 'next_after_id' => $more ? (int) $page->last()->id : null],
            'observed' => ['matching_events' => (int) $extent->events, 'earliest' => $this->time($extent->earliest), 'latest' => $this->time($extent->latest),
                'legacy_unattributed_events_in_company_period' => DB::table('pos_print_evidence')->where('company_id', $c['company']->id)
                    ->whereNull('product_type')->whereBetween('received_at', $c['calendarBounds'])->count()]];
    }

    private function time(?string $value): ?string
    {
        return $value ? Carbon::parse($value, config('app.timezone'))->setTimezone(config('reporting_connector.timezone'))->toIso8601String() : null;
    }
}
