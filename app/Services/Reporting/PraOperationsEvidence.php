<?php

namespace App\Services\Reporting;

use App\Services\PraAgentDiagnosticEvidence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** One scoped entrypoint for the B06/B03 operational evidence batch. */
final class PraOperationsEvidence
{
    public function read(array $args, ?array $allowed): array
    {
        if (array_diff(array_keys($args), ['company_name', 'company_id', 'date_from', 'date_to', 'stream', 'branch_id', 'after_id', 'limit'])) {
            throw ValidationException::withMessages(['arguments' => 'Unsupported operational evidence filter.']);
        }
        $v = validator($args, ['company_name' => 'required|string|min:2|max:150', 'company_id' => 'nullable|integer|min:1',
            'date_from' => 'required|date_format:Y-m-d', 'date_to' => 'nullable|date_format:Y-m-d',
            'stream' => 'required|in:cash_reconciliation,print_chains,agent_collection',
            'branch_id' => 'nullable|integer|min:0', 'after_id' => 'nullable|integer|min:0', 'limit' => 'nullable|integer|min:1|max:50'])->validate();
        $v['date_to'] ??= $v['date_from'];
        $from = Carbon::parse($v['date_from'], 'Asia/Karachi')->startOfDay();
        $to = Carbon::parse($v['date_to'], 'Asia/Karachi')->startOfDay();
        if ($from->gt($to) || $from->diffInDays($to) > 6) throw ValidationException::withMessages(['date_from' => 'Use at most seven ordered dates.']);
        if ($v['stream'] === 'cash_reconciliation' && ($v['limit'] ?? 5) > 10) throw ValidationException::withMessages(['limit' => 'At most ten frozen reports per cash page.']);
        if (isset($v['branch_id']) && $v['stream'] !== 'cash_reconciliation') throw ValidationException::withMessages(['branch_id' => 'Agent/print collection is company-scoped; current device branches cannot establish historical scope.']);
        $company = app(CompanyReadReports::class)->company($v['company_name'], $allowed, isset($v['company_id']) ? (int) $v['company_id'] : null);
        if ($company->product_type !== 'pos') throw ValidationException::withMessages(['company_name' => 'This evidence adapter supports PRA POS only.']);
        $meta = ['company' => ['id' => (int) $company->id, 'name' => $company->name, 'product' => 'pos'],
            'period' => ['from' => $v['date_from'], 'to' => $v['date_to'], 'timezone' => 'Asia/Karachi',
                'basis' => $v['stream'] === 'cash_reconciliation' ? 'stored_business_date' : 'server_received_calendar'],
            'stream' => $v['stream'], 'as_of' => now()->toIso8601String(), 'currency' => 'PKR'];
        $bounds = [$from->setTimezone(config('app.timezone'))->toDateTimeString(), $to->endOfDay()->setTimezone(config('app.timezone'))->toDateTimeString()];
        return $meta + match ($v['stream']) {
            'cash_reconciliation' => app(PraCashReconciliation::class)->read((int) $company->id, $v),
            'print_chains' => $this->printing((int) $company->id, $v, $bounds),
            'agent_collection' => $this->collection((int) $company->id, $v, $bounds),
        };
    }

    private function printing(int $companyId, array $v, array $bounds): array
    {
        $out = ['status' => 'UNAVAILABLE', 'complete' => false, 'physical_print' => 'unknown', 'chains' => [],
            'pagination' => ['has_more' => false, 'next_after_id' => null],
            'notes' => ['Jobs are selected by server receipt date. Their retained events across the retention window are then compared by attempt.',
                'A missing result after content fetch means output unknown. Never automatically reprint from this report.',
                'Enqueue, claim, content and acknowledgement are different facts. Spool acceptance does not prove paper.',
                'No retained job/evidence is not proof that no print was requested. Browser prints and purged/unrecorded events remain unknown.']];
        if (!Schema::hasTable('pos_print_evidence') || array_diff(['company_id','product_type','received_at','job_id','attempt','event','transaction_id','order_id','context'], Schema::getColumnListing('pos_print_evidence'))) return $out;
        $limit = (int) ($v['limit'] ?? 25);
        $q = DB::table('pos_print_evidence')->where('company_id', $companyId)->where('product_type', 'pos')->whereBetween('received_at', $bounds);
        $out['unlinked_events'] = (clone $q)->whereNull('job_id')->count();
        $ids = (clone $q)->where('job_id', '>', $v['after_id'] ?? 0)->distinct()->orderBy('job_id')->limit($limit + 1)->pluck('job_id');
        $more = $ids->count() > $limit; $ids = $ids->take($limit);
        foreach ($ids as $id) {
            $rows = DB::table('pos_print_evidence')->where('company_id', $companyId)->where('product_type', 'pos')->where('job_id', $id)
                ->orderBy('id')->limit(501)->get(['id', 'event', 'attempt', 'transaction_id', 'order_id', 'received_at', DB::raw('CASE WHEN LENGTH(context) <= 8192 THEN context ELSE NULL END AS context')]);
            $chain = ['job_id' => (int) $id, 'truncated' => $rows->count() > 500, 'enqueue_observed' => false, 'bill_ids' => [], 'order_ids' => [], 'attempts' => []];
            $attempts = [];
            foreach ($rows->take(500) as $row) {
                if ($row->event === 'enqueued') $chain['enqueue_observed'] = true;
                if ($row->transaction_id) $chain['bill_ids'][] = (int) $row->transaction_id;
                if ($row->order_id) $chain['order_ids'][] = (int) $row->order_id;
                if (!in_array($row->event, ['claimed', 'content_requested', 'no_document', 'result_received'], true)) continue;
                $key = $row->attempt === null ? 'unknown' : (string) $row->attempt;
                $a = $attempts[$key] ?? ['attempt' => $row->attempt === null ? null : (int) $row->attempt, 'claim_observed' => false, 'content_observed' => false, 'result_observed' => false, 'outcomes' => [], 'last_event_at' => null];
                $a['claim_observed'] = $a['claim_observed'] || $row->event === 'claimed';
                $a['content_observed'] = $a['content_observed'] || $row->event === 'content_requested';
                if ($row->event === 'result_received') {
                    $a['result_observed'] = true;
                    $context = json_decode($row->context ?? '', true);
                    $value = is_array($context) ? ($context['outcome'] ?? null) : null;
                    $a['outcomes'][] = in_array($value, ['spool_accepted', 'pre_spool_failed', 'output_unknown', 'no_document', 'agent_acknowledged'], true) ? $value : 'unknown';
                }
                if ($row->event === 'no_document') $a['outcomes'][] = 'no_document';
                $a['last_event_at'] = Carbon::parse($row->received_at)->toIso8601String(); $attempts[$key] = $a;
            }
            foreach ($attempts as $a) {
                $a['outcomes'] = array_values(array_unique($a['outcomes']));
                $a['state'] = count($a['outcomes']) > 1 ? 'CONFLICTING_OBSERVATIONS' : (in_array('no_document', $a['outcomes'], true) ? 'NO_DOCUMENT_OBSERVED' : ($a['result_observed'] ? strtoupper($a['outcomes'][0] ?? 'unknown')
                    : ($a['content_observed'] ? 'OUTPUT_UNKNOWN' : ($a['claim_observed'] ? 'CLAIM_WITHOUT_CONTENT_EVIDENCE' : 'INCOMPLETE'))));
                $chain['attempts'][] = $a;
            }
            $chain['bill_ids'] = array_values(array_unique($chain['bill_ids']));
            $chain['order_ids'] = array_values(array_unique($chain['order_ids']));
            $out['chains'][] = $chain;
        }
        $out['status'] = $ids->isEmpty() ? 'NO_RETAINED_JOB_EVIDENCE' : 'PARTIAL';
        $out['pagination'] = ['has_more' => $more, 'next_after_id' => $more ? (int) $ids->last() : null, 'cursor_basis' => 'job_id'];
        return $out;
    }

    private function collection(int $companyId, array $v, array $bounds): array
    {
        $out = ['status' => 'UNAVAILABLE', 'complete' => false, 'seven_day_complete_record_proven' => false,
            'retention_policy_days' => PraAgentDiagnosticEvidence::RETENTION_DAYS, 'samples' => [], 'streams' => [],
            'pagination' => ['has_more' => false, 'next_after_id' => null],
            'notes' => ['Five-minute operational samples while the upgraded paired Agent runs; not full event logs or proof of all activity.',
                'Source time is the device clock. Receipt time is the server clock. Late uploads appear under receipt date and retain their original source time.',
                'Sequence gaps, dropped samples, restarts and stale receipt times are visible. Missing samples cannot distinguish a stopped PC from lost collection.',
                'Local buffer is bounded to fourteen days/4096 samples. Acknowledgements remove only persisted exact sequences; no fiscal or print replay occurs.',
                'Only observed streams are listed, not a complete device inventory. Empty streams expire after 90 days. Older Agents without this capability remain unknown. Actual installed rollout, host capacity and scheduler retention require field acceptance.']];
        if (!Schema::hasTable('pra_diagnostic_samples') || !Schema::hasTable('pra_diagnostic_streams')) return $out;
        $limit = (int) ($v['limit'] ?? 25);
        $q = DB::table('pra_diagnostic_samples')->where('company_id', $companyId)->whereBetween('received_at', $bounds);
        $extent = (clone $q)->selectRaw('COUNT(*) AS samples, MIN(received_at) AS earliest, MAX(received_at) AS latest')->first();
        $rows = (clone $q)->where('id', '>', $v['after_id'] ?? 0)->orderBy('id')->limit($limit + 1)->get();
        foreach ($rows->take($limit) as $row) {
            $facts = json_decode($row->facts, true);
            $out['samples'][] = ['id' => (int) $row->id, 'stream_id' => (int) $row->diagnostic_stream_id, 'sequence' => (int) $row->sequence,
                'source_event_at' => Carbon::parse($row->source_event_at)->toIso8601String(), 'received_at' => Carbon::parse($row->received_at)->toIso8601String(),
                'clock_or_upload_delay_seconds' => (int) Carbon::parse($row->source_event_at)->diffInSeconds(Carbon::parse($row->received_at), false),
                'facts' => is_array($facts) ? PraAgentDiagnosticEvidence::facts($facts) : null];
        }
        // Include stale known streams; absence of recent samples must not hide a device.
        $streams = DB::table('pra_diagnostic_streams')->where('company_id', $companyId)->orderBy('id')->limit(101)->get();
        foreach ($streams->take(100) as $stream) {
            $stats = DB::table('pra_diagnostic_samples')->where('company_id', $companyId)->where('diagnostic_stream_id', $stream->id)
                ->selectRaw('COUNT(*) AS samples, MIN(sequence) AS first_sequence, MAX(sequence) AS last_sequence')->first();
            $out['streams'][] = ['id' => (int) $stream->id, 'first_received_at' => $stream->first_received_at, 'last_received_at' => $stream->last_received_at,
                'reported_pending_at_last_receipt' => $stream->pending === null ? null : (int) $stream->pending, 'dropped' => (int) $stream->dropped, 'resets' => (int) $stream->resets,
                'storage_status' => $stream->storage_status, 'retained_samples' => (int) $stats->samples,
                'missing_sequences_between_retained_endpoints' => $stats->samples ? (int) $stats->last_sequence - (int) $stats->first_sequence + 1 - (int) $stats->samples : null,
                'stale_now' => Carbon::parse($stream->last_received_at)->lt(now()->subMinutes(10))];
        }
        $out['received_days'] = [];
        for ($day = Carbon::parse($v['date_from'], 'Asia/Karachi'); $day->toDateString() <= $v['date_to']; $day->addDay()) {
            $dayBounds = [$day->copy()->setTimezone(config('app.timezone'))->toDateTimeString(), $day->copy()->endOfDay()->setTimezone(config('app.timezone'))->toDateTimeString()];
            $out['received_days'][] = ['date' => $day->toDateString(), 'samples' => DB::table('pra_diagnostic_samples')->where('company_id', $companyId)->whereBetween('received_at', $dayBounds)->count(), 'complete' => false];
        }
        $out['stream_inventory_truncated'] = $streams->count() > 100;
        $out['observed'] = ['matching_samples' => (int) $extent->samples, 'earliest_received_at' => $extent->earliest, 'latest_received_at' => $extent->latest];
        $out['status'] = $extent->samples ? 'PARTIAL' : 'NO_RETAINED_SAMPLES';
        $out['pagination'] = ['has_more' => $rows->count() > $limit, 'next_after_id' => $rows->count() > $limit ? (int) $rows[$limit - 1]->id : null];
        return $out;
    }
}
