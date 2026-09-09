<?php

namespace App\Services\LiveOps;

use App\Models\Company;
use App\Models\LiveOpsAgentCommand;
use App\Models\LiveOpsDiagnosticReport;
use App\Models\PosAgentDevice;
use App\Models\PosPrintJob;
use App\Models\PosTransaction;
use App\Models\PraLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Allow-listed, tenant-isolated, redacted Live Ops diagnostics reader.
 * Never accepts SQL. NestPOS PRA (product_type=pos) focus.
 */
class LiveOpsDiagnosticsService
{
    public function __construct(
        private LiveOpsRedactor $redactor,
        private LiveOpsAuditService $audit,
        private LiveOpsPlatformHealth $platform,
    ) {
    }

    public function run(string $operation, array $input = []): array
    {
        $operation = strtoupper(trim($operation));
        $allowed = config('live_ops.diagnostic_operations', []);
        if (!in_array($operation, $allowed, true)) {
            throw new \InvalidArgumentException("Unknown or disallowed diagnostic operation: {$operation}");
        }

        [$dateFrom, $dateTo] = $this->resolveDateRange($input);
        $requester = isset($input['requester']) ? mb_substr((string) $input['requester'], 0, 120) : null;
        $companyId = isset($input['company_id']) ? (int) $input['company_id'] : null;
        $companyName = isset($input['company_name']) ? trim((string) $input['company_name']) : null;

        if ($companyName && !$companyId) {
            $companyId = $this->resolveCompanyIdByName($companyName);
        }

        $payload = match ($operation) {
            'COMPANY_HEALTH' => $this->companyHealth($this->requireCompanyId($companyId)),
            'BILLING_SUMMARY' => $this->billingSummary($dateFrom, $dateTo, $companyId),
            'BILLING_BY_COMPANY' => $this->billingByCompany($dateFrom, $dateTo),
            'PRA_HEALTH' => $companyId
                ? $this->praHealth($this->requireCompanyId($companyId), $dateFrom, $dateTo)
                : $this->praHealthFleet($dateFrom, $dateTo),
            'AGENT_HEALTH' => $companyId
                ? $this->agentHealth($companyId)
                : $this->agentFleetHealth(),
            'PRINTER_HEALTH' => $this->printerHealth($this->requireCompanyId($companyId)),
            'ERROR_SUMMARY' => $companyId
                ? $this->errorSummary($this->requireCompanyId($companyId), $dateFrom, $dateTo)
                : $this->errorSummaryFleet($dateFrom, $dateTo),
            'COMPANY_DIAGNOSTIC' => $this->companyDiagnostic($this->requireCompanyId($companyId), $dateFrom, $dateTo),
            'PROBLEMATIC_COMPANIES' => $this->problematicCompanies($dateFrom, $dateTo),
            'SERVER_HEALTH' => $this->serverHealth(),
            'DAILY_OPS' => $this->dailyOps($dateFrom, $dateTo),
            default => throw new \InvalidArgumentException("Unhandled operation: {$operation}"),
        };

        $reportId = (string) Str::ulid();
        $envelope = [
            'report_id' => $reportId,
            'operation' => $operation,
            'timestamp' => now()->toIso8601String(),
            'scope' => [
                'product_types' => config('live_ops.product_types', ['pos']),
                'company_id' => $companyId,
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
            ],
            'result_count' => $this->countResults($payload),
            'summary_text' => $this->humanSummary($operation, $payload),
            'data' => $this->redactor->redact($payload),
        ];
        $envelope['artifact_digest'] = $this->redactor->artifactDigest($envelope['data']);

        if (Schema::hasTable('live_ops_diagnostic_reports')) {
            LiveOpsDiagnosticReport::create([
                'report_id' => $reportId,
                'operation' => $operation,
                'company_id' => $companyId,
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'requester' => $requester,
                'artifact_digest' => $envelope['artifact_digest'],
                'result_count' => $envelope['result_count'],
                'summary' => ['text' => $envelope['summary_text']],
                'payload' => $envelope['data'],
            ]);
        }

        $this->audit->record(
            eventType: 'diagnostic.request',
            requester: $requester,
            companyId: $companyId,
            operation: $operation,
            reportId: $reportId,
            params: [
                'company_id' => $companyId,
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
            ],
            artifactDigest: $envelope['artifact_digest'],
            resultStatus: 'ok',
            metadata: ['result_count' => $envelope['result_count']],
            adminId: isset($input['admin_id']) ? (int) $input['admin_id'] : null,
        );

        return $envelope;
    }

    private function requireCompanyId(?int $companyId): int
    {
        if (!$companyId || $companyId < 1) {
            throw new \InvalidArgumentException('company_id (or resolvable company_name) is required');
        }

        return $companyId;
    }

    private function resolveDateRange(array $input): array
    {
        $maxDays = (int) config('live_ops.limits.max_date_range_days', 31);
        $to = !empty($input['date_to'])
            ? Carbon::parse($input['date_to'])->startOfDay()
            : now()->startOfDay();
        $from = !empty($input['date_from'])
            ? Carbon::parse($input['date_from'])->startOfDay()
            : $to->copy();

        if ($from->gt($to)) {
            throw new \InvalidArgumentException('date_from must be <= date_to');
        }
        if ($from->diffInDays($to) > $maxDays) {
            throw new \InvalidArgumentException("date range exceeds max {$maxDays} days");
        }

        return [$from, $to];
    }

    private function resolveCompanyIdByName(string $name): int
    {
        $q = $this->posCompaniesQuery()
            ->where(function ($w) use ($name) {
                $w->where('name', $name);
                if (ctype_digit($name)) {
                    $w->orWhere('id', (int) $name);
                }
                if (Schema::hasColumn('companies', 'account_code')) {
                    $w->orWhere('account_code', $name);
                }
            });

        $ids = $q->limit(5)->pluck('id');
        if ($ids->count() === 1) {
            return (int) $ids->first();
        }
        if ($ids->isEmpty()) {
            throw new \InvalidArgumentException("Company not found: {$name}");
        }
        throw new \InvalidArgumentException("Ambiguous company name: {$name} (matches {$ids->count()} companies — use company_id)");
    }

    private function posCompaniesQuery()
    {
        $suffix = config('live_ops.exclude_email_suffix', '@scaletest.pk');

        return Company::query()
            ->whereIn('product_type', config('live_ops.product_types', ['pos']))
            ->where(function ($q) use ($suffix) {
                $q->whereNull('email')->orWhere('email', 'not like', '%'.$suffix);
            });
    }

    private function loadCompany(int $companyId): Company
    {
        $company = $this->posCompaniesQuery()->where('id', $companyId)->first();
        if (!$company) {
            throw new \InvalidArgumentException("Company {$companyId} not found in NestPOS PRA scope");
        }

        return $company;
    }

    private function companyHealth(int $companyId): array
    {
        $c = $this->loadCompany($companyId);
        $devices = [];
        if (Schema::hasTable('pos_agent_devices')) {
            $devices = PosAgentDevice::where('company_id', $c->id)
                ->orderByDesc('last_seen_at')
                ->limit((int) config('live_ops.limits.max_devices', 20))
                ->get()
                ->map(fn (PosAgentDevice $d) => [
                    'device_uid' => $d->device_uid,
                    'hostname' => $d->hostname,
                    'name' => $d->name,
                    'agent_version' => $d->agent_version,
                    'last_seen_at' => optional($d->last_seen_at)?->toIso8601String(),
                    'online' => $d->isOnline(),
                    'receipt_printer' => $d->receipt_printer,
                    'printer_count' => is_array($d->printers) ? count($d->printers) : 0,
                ])->all();
        }

        return [
            'company' => [
                'id' => $c->id,
                'name' => $c->name,
                'account_code' => $c->account_code ?? null,
                'product_type' => $c->product_type,
                'status' => $c->status ?? null,
                'company_status' => $c->company_status ?? null,
                'pra_reporting_enabled' => (bool) ($c->pra_reporting_enabled ?? false),
                'pra_environment' => $c->pra_environment ?? null,
                'pra_pos_id' => $c->pra_pos_id ?? null,
                'pra_connection_mode' => $c->pra_connection_mode ?? null,
                'agent_enabled' => (bool) ($c->agent_enabled ?? false),
                'agent_submits_pra' => method_exists($c, 'agentHandlesPra') ? $c->agentHandlesPra() : null,
                'agent_online' => $c->agentOnline(),
                'agent_last_seen' => optional($c->agent_last_seen)?->toIso8601String(),
                'agent_version' => $c->agent_version,
                'silent_print_enabled' => $c->printerSettings()['silent_print_enabled'],
            ],
            'devices' => $devices,
        ];
    }

    private function billingDayExpr(string $alias = 't'): string
    {
        $prefix = $alias !== '' ? $alias.'.' : '';
        return Schema::hasColumn('pos_transactions', 'business_date')
            ? "COALESCE({$prefix}business_date, DATE({$prefix}created_at))"
            : "DATE({$prefix}created_at)";
    }

    /**
     * Authoritative NestPOS billing: completed rows, stored totals (PosTaxMath at sale time).
     * Excludes cancelled. Reporting-OFF finals (NULL pra_status) counted as local/reporting-off.
     */
    private function billingSummary(?Carbon $from, ?Carbon $to, ?int $companyId): array
    {
        if (!Schema::hasTable('pos_transactions')) {
            return ['companies' => [], 'totals' => $this->emptyTotals()];
        }

        $dateExpr = $this->billingDayExpr();
        $q = DB::table('pos_transactions as t')
            ->join('companies as c', 'c.id', '=', 't.company_id')
            ->whereIn('c.product_type', config('live_ops.product_types', ['pos']))
            ->where(function ($w) {
                $suffix = config('live_ops.exclude_email_suffix', '@scaletest.pk');
                $w->whereNull('c.email')->orWhere('c.email', 'not like', '%'.$suffix);
            })
            ->where('t.status', 'completed')
            ->whereBetween(DB::raw($dateExpr), [$from->toDateString(), $to->toDateString()]);

        if ($companyId) {
            $this->loadCompany($companyId);
            $q->where('t.company_id', $companyId);
        }

        // Soft-deleted companies
        if (Schema::hasColumn('companies', 'deleted_at')) {
            $q->whereNull('c.deleted_at');
        }

        $rows = $q->select(
            't.company_id',
            'c.name as company_name',
            DB::raw('COUNT(*) as invoice_count'),
            DB::raw('COALESCE(SUM(t.total_amount),0) as gross_total'),
            DB::raw('COALESCE(SUM(t.subtotal),0) as net_subtotal'),
            DB::raw('COALESCE(SUM(t.tax_amount),0) as tax_total'),
            DB::raw("SUM(CASE WHEN t.pra_status = 'submitted' THEN 1 ELSE 0 END) as pra_submitted"),
            DB::raw("SUM(CASE WHEN t.pra_status IN ('pending','failed','offline') THEN 1 ELSE 0 END) as pra_open"),
            DB::raw("SUM(CASE WHEN t.pra_status = 'local' OR t.pra_status IS NULL THEN 1 ELSE 0 END) as reporting_off_or_local"),
            DB::raw("SUM(CASE WHEN t.pra_status = 'failed' THEN 1 ELSE 0 END) as pra_failed"),
        )
            ->groupBy('t.company_id', 'c.name')
            ->orderByDesc('gross_total')
            ->limit((int) config('live_ops.limits.max_companies_in_report', 200))
            ->get();

        $companies = $rows->map(fn ($r) => [
            'company_id' => (int) $r->company_id,
            'company_name' => $r->company_name,
            'invoice_count' => (int) $r->invoice_count,
            'gross_total' => round((float) $r->gross_total, 2),
            'net_subtotal' => round((float) $r->net_subtotal, 2),
            'tax_total' => round((float) $r->tax_total, 2),
            'pra_submitted' => (int) $r->pra_submitted,
            'pra_open' => (int) $r->pra_open,
            'pra_failed' => (int) $r->pra_failed,
            'reporting_off_or_local' => (int) $r->reporting_off_or_local,
        ])->all();

        return [
            'companies' => $companies,
            'totals' => [
                'company_count' => count($companies),
                'invoice_count' => array_sum(array_column($companies, 'invoice_count')),
                'gross_total' => round(array_sum(array_column($companies, 'gross_total')), 2),
                'net_subtotal' => round(array_sum(array_column($companies, 'net_subtotal')), 2),
                'tax_total' => round(array_sum(array_column($companies, 'tax_total')), 2),
                'pra_submitted' => array_sum(array_column($companies, 'pra_submitted')),
                'pra_failed' => array_sum(array_column($companies, 'pra_failed')),
            ],
            'zero_billing_note' => $companyId ? null : 'Use PROBLEMATIC_COMPANIES for zero-billing list',
        ];
    }

    private function emptyTotals(): array
    {
        return [
            'company_count' => 0,
            'invoice_count' => 0,
            'gross_total' => 0.0,
            'net_subtotal' => 0.0,
            'tax_total' => 0.0,
            'pra_submitted' => 0,
            'pra_failed' => 0,
        ];
    }

    private function billingByCompany(Carbon $from, Carbon $to): array
    {
        return $this->billingSummary($from, $to, null);
    }

    private function praHealth(int $companyId, Carbon $from, Carbon $to): array
    {
        $this->loadCompany($companyId);
        if (!Schema::hasTable('pos_transactions')) {
            return ['pending' => [], 'failed' => [], 'recent_submitted' => [], 'counts' => []];
        }

        $base = PosTransaction::query()
            ->where('company_id', $companyId)
            ->where('status', 'completed')
            ->whereBetween(DB::raw($this->billingDayExpr('')), [$from->toDateString(), $to->toDateString()]);

        $counts = [
            'pending' => (clone $base)->where('pra_status', 'pending')->count(),
            'failed' => (clone $base)->where('pra_status', 'failed')->count(),
            'offline' => (clone $base)->where('pra_status', 'offline')->count(),
            'submitted' => (clone $base)->where('pra_status', 'submitted')->count(),
            'local_or_null' => (clone $base)->where(function ($q) {
                $q->where('pra_status', 'local')->orWhereNull('pra_status');
            })->count(),
        ];

        $mapTxn = function ($t) {
            return [
                'id' => $t->id,
                'created_at' => optional($t->created_at)?->toIso8601String(),
                'business_date' => $t->business_date ?? null,
                'total_amount' => $t->total_amount,
                'pra_status' => $t->pra_status,
                'pra_response_code' => $t->pra_response_code,
                'pra_error_message' => $this->redactor->redactString((string) ($t->pra_error_message ?? '')),
                'has_pra_invoice_number' => !empty(trim((string) ($t->pra_invoice_number ?? ''))),
                'invoice_mode' => $t->invoice_mode ?? null,
            ];
        };

        $failed = PosTransaction::where('company_id', $companyId)
            ->where('pra_status', 'failed')
            ->orderByDesc('id')
            ->limit((int) config('live_ops.limits.max_pra_failures', 25))
            ->get()
            ->map($mapTxn)
            ->all();

        $pending = PosTransaction::where('company_id', $companyId)
            ->whereIn('pra_status', ['pending', 'offline'])
            ->orderByDesc('id')
            ->limit(25)
            ->get()
            ->map($mapTxn)
            ->all();

        $recentSubmitted = PosTransaction::where('company_id', $companyId)
            ->where('pra_status', 'submitted')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map($mapTxn)
            ->all();

        $praLogs = [];
        if (Schema::hasTable('pra_logs')) {
            $praLogs = PraLog::query()
                ->where('company_id', $companyId)
                ->orderByDesc('id')
                ->limit(15)
                ->get()
                ->map(function ($log) {
                    return $this->redactor->redact([
                        'id' => $log->id,
                        'transaction_id' => $log->transaction_id ?? null,
                        'status' => $log->status ?? null,
                        'response_code' => $log->response_code ?? null,
                        'created_at' => optional($log->created_at)?->toIso8601String(),
                    ]);
                })->all();
        }

        return [
            'counts' => $counts,
            'failed' => $failed,
            'pending' => $pending,
            'recent_submitted' => $recentSubmitted,
            'pra_log_excerpts' => $praLogs,
        ];
    }

    private function agentHealth(int $companyId): array
    {
        $pack = $this->companyHealth($companyId);
        $c = $this->loadCompany($companyId);

        $pendingCommands = [];
        if (Schema::hasTable('live_ops_agent_commands')) {
            $pendingCommands = LiveOpsAgentCommand::where('company_id', $companyId)
                ->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->orderByDesc('id')
                ->limit(10)
                ->get(['command_id', 'command_type', 'device_uid', 'status', 'expires_at', 'created_at'])
                ->map(fn ($cmd) => [
                    'command_id' => $cmd->command_id,
                    'command_type' => $cmd->command_type,
                    'device_uid' => $cmd->device_uid,
                    'status' => $cmd->status,
                    'expires_at' => optional($cmd->expires_at)?->toIso8601String(),
                    'created_at' => optional($cmd->created_at)?->toIso8601String(),
                ])->all();
        }

        return $pack + [
            'update_telemetry' => [
                'agent_update_target' => $c->agent_update_target ?? null,
                'agent_update_stage' => $c->agent_update_stage ?? null,
                'agent_update_error' => $this->redactor->redactString((string) ($c->agent_update_error ?? '')),
                'agent_update_at' => optional($c->agent_update_at ?? null)?->toIso8601String(),
                'agent_force_update_at' => Schema::hasColumn('companies', 'agent_force_update_at')
                    ? optional($c->agent_force_update_at)?->toIso8601String()
                    : null,
            ],
            'offline_mode' => $c->agent_offline_mode ?? null,
            'pending_commands' => $pendingCommands,
        ];
    }

    private function agentFleetHealth(): array
    {
        $limit = (int) config('live_ops.limits.max_companies_in_report', 200);
        $companies = $this->posCompaniesQuery()
            ->where('agent_enabled', true)
            ->orderByDesc('agent_last_seen')
            ->limit($limit)
            ->get(['id', 'name', 'agent_last_seen', 'agent_version', 'agent_enabled', 'pos_printer_settings', 'agent_update_stage', 'agent_update_error']);

        $rows = $companies->map(function (Company $c) {
            return [
                'company_id' => $c->id,
                'name' => $c->name,
                'online' => $c->agentOnline(),
                'long_offline' => $c->agentLongOffline(),
                'last_seen' => optional($c->agent_last_seen)?->toIso8601String(),
                'version' => $c->agent_version,
                'silent_print' => $c->printerSettings()['silent_print_enabled'],
                'update_stage' => $c->agent_update_stage ?? null,
                'update_error' => $this->redactor->redactString((string) ($c->agent_update_error ?? '')),
            ];
        })->all();

        return [
            'agents' => $rows,
            'counts' => [
                'enabled' => count($rows),
                'online' => count(array_filter($rows, fn ($r) => $r['online'])),
                'long_offline' => count(array_filter($rows, fn ($r) => $r['long_offline'])),
            ],
        ];
    }

    private function printerHealth(int $companyId): array
    {
        $c = $this->loadCompany($companyId);
        $settings = $c->printerSettings();
        $jobs = [];
        if (Schema::hasTable('pos_print_jobs')) {
            $jobs = PosPrintJob::where('company_id', $companyId)
                ->orderByDesc('id')
                ->limit((int) config('live_ops.limits.max_print_jobs', 25))
                ->get()
                ->map(fn (PosPrintJob $j) => [
                    'id' => $j->id,
                    'type' => $j->type,
                    'target_printer' => $j->target_printer,
                    'status' => $j->status,
                    'device_uid' => $j->device_uid,
                    'error' => $this->redactor->redactString((string) ($j->error ?? '')),
                    'attempts' => $j->attempts,
                    'created_at' => optional($j->created_at)?->toIso8601String(),
                    'updated_at' => optional($j->updated_at)?->toIso8601String(),
                ])->all();
        }

        $devices = [];
        if (Schema::hasTable('pos_agent_devices')) {
            $devices = PosAgentDevice::where('company_id', $companyId)
                ->orderByDesc('last_seen_at')
                ->limit((int) config('live_ops.limits.max_devices', 20))
                ->get()
                ->map(fn (PosAgentDevice $d) => [
                    'device_uid' => $d->device_uid,
                    'name' => $d->label(),
                    'online' => $d->isOnline(),
                    'receipt_printer' => $d->receipt_printer,
                    'discovered_printers' => collect($d->printers ?? [])->pluck('name')->values()->all(),
                    'printers_reported_at' => optional($d->printers_reported_at)?->toIso8601String(),
                ])->all();
        }

        return [
            'silent_print_enabled' => $settings['silent_print_enabled'],
            'assigned_receipt_printer' => $settings['receipt_printer'],
            'discovered_printers' => collect($settings['available_printers'] ?? [])->map(fn ($p) => [
                'name' => $p['name'] ?? null,
                'displayName' => $p['displayName'] ?? null,
                'isDefault' => (bool) ($p['isDefault'] ?? false),
                'isTextOnly' => (bool) ($p['isTextOnly'] ?? false),
            ])->values()->all(),
            'printers_reported_at' => $settings['printers_reported_at'],
            'agent_online' => $c->agentOnline(),
            'devices' => $devices,
            'recent_jobs' => $jobs,
            'failed_job_count' => collect($jobs)->where('status', 'failed')->count(),
        ];
    }

    private function errorSummary(int $companyId, Carbon $from, Carbon $to): array
    {
        $pra = $this->praHealth($companyId, $from, $to);
        $printer = $this->printerHealth($companyId);
        $agent = $this->agentHealth($companyId);

        $errors = [];
        foreach ($pra['failed'] as $f) {
            if (!empty($f['pra_error_message'])) {
                $errors[] = [
                    'source' => 'pra',
                    'ref' => 'txn:'.$f['id'],
                    'message' => $f['pra_error_message'],
                    'at' => $f['created_at'],
                ];
            }
        }
        foreach ($printer['recent_jobs'] as $j) {
            if (($j['status'] ?? '') === 'failed' && !empty($j['error'])) {
                $errors[] = [
                    'source' => 'print',
                    'ref' => 'job:'.$j['id'],
                    'message' => $j['error'],
                    'at' => $j['created_at'],
                ];
            }
        }
        if (!empty($agent['update_telemetry']['agent_update_error'])) {
            $errors[] = [
                'source' => 'agent_update',
                'ref' => 'company:'.$companyId,
                'message' => $agent['update_telemetry']['agent_update_error'],
                'at' => $agent['update_telemetry']['agent_update_at'],
            ];
        }

        $max = (int) config('live_ops.limits.max_error_lines', 40);

        return [
            'errors' => array_slice($errors, 0, $max),
            'agent_online' => $agent['company']['agent_online'] ?? false,
            'pra_failed_count' => $pra['counts']['failed'] ?? 0,
            'print_failed_count' => $printer['failed_job_count'] ?? 0,
        ];
    }

    private function companyDiagnostic(int $companyId, Carbon $from, Carbon $to): array
    {
        $company = $this->companyHealth($companyId);
        $billing = $this->billingSummary($from, $to, $companyId);
        $pra = $this->praHealth($companyId, $from, $to);
        $agent = $this->agentHealth($companyId);
        $printer = $this->printerHealth($companyId);
        $errors = $this->errorSummary($companyId, $from, $to);

        $likely = $this->inferRootCause($company, $pra, $agent, $printer, $errors);
        $recommend = $this->recommendFix($likely);

        return [
            'company' => $company['company'],
            'devices' => $company['devices'],
            'billing' => $billing,
            'pra' => $pra,
            'agent' => [
                'online' => $company['company']['agent_online'],
                'last_seen' => $company['company']['agent_last_seen'],
                'version' => $company['company']['agent_version'],
                'update_telemetry' => $agent['update_telemetry'],
                'pending_commands' => $agent['pending_commands'],
            ],
            'printer' => $printer,
            'errors' => $errors['errors'],
            'likely_root_cause' => $likely,
            'recommended_fix' => $recommend,
            'remediation_note' => 'Diagnosis never executes fixes. Owner must explicitly approve a low/medium allow-listed remediation.',
        ];
    }

    private function problematicCompanies(Carbon $from, Carbon $to): array
    {
        $billing = $this->billingSummary($from, $to, null);
        $billedIds = collect($billing['companies'])->pluck('company_id')->all();

        $all = $this->posCompaniesQuery()
            ->where(function ($q) {
                $q->where('status', 'approved')
                    ->orWhereNull('status');
            })
            ->limit((int) config('live_ops.limits.max_companies_in_report', 200))
            ->get(['id', 'name', 'agent_enabled', 'agent_last_seen', 'pos_printer_settings']);

        $zeroBilling = $all->reject(fn ($c) => in_array($c->id, $billedIds, true))
            ->take(100)
            ->map(fn ($c) => ['company_id' => $c->id, 'name' => $c->name])
            ->values()->all();

        $offlineAgents = $all->filter(fn (Company $c) => $c->agent_enabled && !$c->agentOnline())
            ->take(100)
            ->map(fn (Company $c) => [
                'company_id' => $c->id,
                'name' => $c->name,
                'last_seen' => optional($c->agent_last_seen)?->toIso8601String(),
                'long_offline' => $c->agentLongOffline(),
            ])->values()->all();

        $praFails = [];
        if (Schema::hasTable('pos_transactions')) {
            $praFails = DB::table('pos_transactions as t')
                ->join('companies as c', 'c.id', '=', 't.company_id')
                ->whereIn('c.product_type', config('live_ops.product_types', ['pos']))
                ->where('t.pra_status', 'failed')
                ->whereBetween(DB::raw($this->billingDayExpr()), [$from->toDateString(), $to->toDateString()])
                ->select('t.company_id', 'c.name', DB::raw('COUNT(*) as fail_count'))
                ->groupBy('t.company_id', 'c.name')
                ->orderByDesc('fail_count')
                ->limit(50)
                ->get()
                ->map(fn ($r) => [
                    'company_id' => (int) $r->company_id,
                    'name' => $r->name,
                    'fail_count' => (int) $r->fail_count,
                ])->all();
        }

        $printFails = [];
        if (Schema::hasTable('pos_print_jobs')) {
            $printFails = DB::table('pos_print_jobs as j')
                ->join('companies as c', 'c.id', '=', 'j.company_id')
                ->whereIn('c.product_type', config('live_ops.product_types', ['pos']))
                ->where('j.status', 'failed')
                ->where('j.created_at', '>=', $from->copy()->startOfDay())
                ->where('j.created_at', '<=', $to->copy()->endOfDay())
                ->select('j.company_id', 'c.name', DB::raw('COUNT(*) as fail_count'))
                ->groupBy('j.company_id', 'c.name')
                ->orderByDesc('fail_count')
                ->limit(50)
                ->get()
                ->map(fn ($r) => [
                    'company_id' => (int) $r->company_id,
                    'name' => $r->name,
                    'fail_count' => (int) $r->fail_count,
                ])->all();
        }

        return [
            'billing_today_or_range' => $billing['totals'],
            'top_billing' => array_slice($billing['companies'], 0, 10),
            'bottom_billing' => array_slice(array_reverse($billing['companies']), 0, 10),
            'zero_billing' => $zeroBilling,
            'offline_agents' => $offlineAgents,
            'pra_failures' => $praFails,
            'print_failures' => $printFails,
        ];
    }

    /**
     * Fleet ERROR_SUMMARY — PRA/print/agent errors plus application/queue signals.
     * Company attribution only; no cross-tenant payload dumps.
     */
    private function errorSummaryFleet(Carbon $from, Carbon $to): array
    {
        $max = (int) config('live_ops.limits.max_error_lines', 40);
        $errors = [];
        $praFailedByCompany = [];
        $printFailedByCompany = [];

        if (Schema::hasTable('pos_transactions')) {
            $praRows = DB::table('pos_transactions as t')
                ->join('companies as c', 'c.id', '=', 't.company_id')
                ->whereIn('c.product_type', config('live_ops.product_types', ['pos']))
                ->where(function ($w) {
                    $suffix = config('live_ops.exclude_email_suffix', '@scaletest.pk');
                    $w->whereNull('c.email')->orWhere('c.email', 'not like', '%'.$suffix);
                })
                ->where('t.status', 'completed')
                ->where('t.pra_status', 'failed')
                ->whereBetween(DB::raw($this->billingDayExpr()), [$from->toDateString(), $to->toDateString()])
                ->orderByDesc('t.id')
                ->limit($max)
                ->get(['t.id', 't.company_id', 'c.name as company_name', 't.pra_error_message', 't.created_at']);

            foreach ($praRows as $row) {
                $cid = (int) $row->company_id;
                $praFailedByCompany[$cid] = ($praFailedByCompany[$cid] ?? 0) + 1;
                $msg = $this->redactor->redactString((string) ($row->pra_error_message ?? ''));
                if ($msg !== '') {
                    $errors[] = [
                        'source' => 'pra',
                        'company_id' => $cid,
                        'company_name' => $row->company_name,
                        'ref' => 'txn:'.$row->id,
                        'message' => $msg,
                        'at' => $row->created_at,
                    ];
                }
            }
        }

        if (Schema::hasTable('pos_print_jobs')) {
            $printRows = DB::table('pos_print_jobs as j')
                ->join('companies as c', 'c.id', '=', 'j.company_id')
                ->whereIn('c.product_type', config('live_ops.product_types', ['pos']))
                ->where('j.status', 'failed')
                ->where('j.created_at', '>=', $from->copy()->startOfDay())
                ->where('j.created_at', '<=', $to->copy()->endOfDay())
                ->orderByDesc('j.id')
                ->limit($max)
                ->get(['j.id', 'j.company_id', 'c.name as company_name', 'j.error', 'j.created_at']);

            foreach ($printRows as $row) {
                $cid = (int) $row->company_id;
                $printFailedByCompany[$cid] = ($printFailedByCompany[$cid] ?? 0) + 1;
                $msg = $this->redactor->redactString((string) ($row->error ?? ''));
                if ($msg !== '') {
                    $errors[] = [
                        'source' => 'print',
                        'company_id' => $cid,
                        'company_name' => $row->company_name,
                        'ref' => 'job:'.$row->id,
                        'message' => $msg,
                        'at' => $row->created_at,
                    ];
                }
            }
        }

        $agentUpdateErrors = $this->posCompaniesQuery()
            ->whereNotNull('agent_update_error')
            ->where('agent_update_error', '!=', '')
            ->limit(20)
            ->get(['id', 'name', 'agent_update_error', 'agent_update_at']);
        foreach ($agentUpdateErrors as $c) {
            $errors[] = [
                'source' => 'agent_update',
                'company_id' => $c->id,
                'company_name' => $c->name,
                'ref' => 'company:'.$c->id,
                'message' => $this->redactor->redactString((string) $c->agent_update_error),
                'at' => optional($c->agent_update_at)?->toIso8601String(),
            ];
        }

        $app = $this->platform->application();
        $queue = $this->platform->queue();

        return [
            'scope' => 'global',
            'errors' => array_slice($errors, 0, $max),
            'pra_failed_companies' => count($praFailedByCompany),
            'pra_failed_count' => array_sum($praFailedByCompany),
            'print_failed_companies' => count($printFailedByCompany),
            'print_failed_count' => array_sum($printFailedByCompany),
            'agent_update_error_count' => $agentUpdateErrors->count(),
            'application' => [
                'laravel_log' => $app['laravel_log'],
                'log_health_failure' => $app['log_health_failure'],
            ],
            'failed_jobs_count' => $queue['failed_jobs_count'],
            'failed_jobs_availability' => $queue['failed_jobs_availability'],
            'failed_job_samples' => $queue['failed_job_samples'],
        ];
    }

    private function praHealthFleet(Carbon $from, Carbon $to): array
    {
        if (!Schema::hasTable('pos_transactions')) {
            return [
                'scope' => 'global',
                'counts' => [],
                'companies_with_failures' => [],
                'companies_with_pending' => [],
                'duplicate_invoice_numbers' => [],
            ];
        }

        $dateExpr = $this->billingDayExpr();
        $base = DB::table('pos_transactions as t')
            ->join('companies as c', 'c.id', '=', 't.company_id')
            ->whereIn('c.product_type', config('live_ops.product_types', ['pos']))
            ->where(function ($w) {
                $suffix = config('live_ops.exclude_email_suffix', '@scaletest.pk');
                $w->whereNull('c.email')->orWhere('c.email', 'not like', '%'.$suffix);
            })
            ->where('t.status', 'completed')
            ->whereBetween(DB::raw($dateExpr), [$from->toDateString(), $to->toDateString()]);

        $row = (clone $base)->selectRaw("
            SUM(CASE WHEN t.pra_status = 'submitted' THEN 1 ELSE 0 END) as submitted,
            SUM(CASE WHEN t.pra_status = 'failed' THEN 1 ELSE 0 END) as failed,
            SUM(CASE WHEN t.pra_status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN t.pra_status = 'offline' THEN 1 ELSE 0 END) as offline,
            SUM(CASE WHEN t.pra_status = 'local' OR t.pra_status IS NULL THEN 1 ELSE 0 END) as local_or_null,
            COUNT(*) as completed
        ")->first();

        $failCompanies = (clone $base)
            ->where('t.pra_status', 'failed')
            ->select('t.company_id', 'c.name', DB::raw('COUNT(*) as fail_count'))
            ->groupBy('t.company_id', 'c.name')
            ->orderByDesc('fail_count')
            ->limit(50)
            ->get()
            ->map(fn ($r) => [
                'company_id' => (int) $r->company_id,
                'name' => $r->name,
                'fail_count' => (int) $r->fail_count,
            ])->all();

        $pendingCompanies = (clone $base)
            ->whereIn('t.pra_status', ['pending', 'offline'])
            ->select('t.company_id', 'c.name', 't.pra_status', DB::raw('COUNT(*) as open_count'))
            ->groupBy('t.company_id', 'c.name', 't.pra_status')
            ->orderByDesc('open_count')
            ->limit(50)
            ->get()
            ->map(fn ($r) => [
                'company_id' => (int) $r->company_id,
                'name' => $r->name,
                'pra_status' => $r->pra_status,
                'open_count' => (int) $r->open_count,
            ])->all();

        $duplicates = [];
        if (Schema::hasColumn('pos_transactions', 'pra_invoice_number')) {
            $duplicates = (clone $base)
                ->whereNotNull('t.pra_invoice_number')
                ->where('t.pra_invoice_number', '!=', '')
                ->select('t.company_id', 'c.name', 't.pra_invoice_number', DB::raw('COUNT(*) as dup_count'))
                ->groupBy('t.company_id', 'c.name', 't.pra_invoice_number')
                ->havingRaw('COUNT(*) > 1')
                ->orderByDesc('dup_count')
                ->limit(20)
                ->get()
                ->map(fn ($r) => [
                    'company_id' => (int) $r->company_id,
                    'name' => $r->name,
                    'pra_invoice_number' => $r->pra_invoice_number,
                    'dup_count' => (int) $r->dup_count,
                ])->all();
        }

        return [
            'scope' => 'global',
            'counts' => [
                'completed' => (int) ($row->completed ?? 0),
                'submitted' => (int) ($row->submitted ?? 0),
                'failed' => (int) ($row->failed ?? 0),
                'pending' => (int) ($row->pending ?? 0),
                'offline' => (int) ($row->offline ?? 0),
                'local_or_null' => (int) ($row->local_or_null ?? 0),
            ],
            'companies_with_failures' => $failCompanies,
            'companies_with_pending' => $pendingCompanies,
            'duplicate_invoice_numbers' => $duplicates,
            'note' => 'Fleet aggregates only. Duplicate invoice numbers are same-company repeats in the date range — not a fiscal mutation.',
        ];
    }

    private function serverHealth(): array
    {
        return [
            'server' => $this->platform->server(),
            'application' => $this->platform->application(),
            'database' => $this->platform->database(),
            'queue' => $this->platform->queue(),
            'scheduler' => $this->platform->scheduler(),
            'websocket' => $this->platform->websocket(),
            'performance' => $this->platform->performance(),
            'note' => 'Read-only host/process/DB signals. Missing values are UNKNOWN. No services were restarted.',
        ];
    }

    private function companyActivity(Carbon $from, Carbon $to): array
    {
        $limit = (int) config('live_ops.limits.max_companies_in_report', 200);
        $all = $this->posCompaniesQuery()
            ->limit($limit)
            ->get(['id', 'name', 'status', 'company_status', 'agent_enabled']);

        $active = $all->filter(function ($c) {
            $statusOk = ($c->status ?? 'approved') === 'approved' || $c->status === null;
            $cs = $c->company_status ?? 'active';

            return $statusOk && ($cs === 'active' || $cs === null);
        });

        $billing = $this->billingSummary($from, $to, null);
        $problematic = $this->problematicCompanies($from, $to);
        $agents = $this->agentFleetHealth();

        return [
            'in_scope_count' => $all->count(),
            'active_enabled_count' => $active->count(),
            'agent_enabled_count' => $all->where('agent_enabled', true)->count(),
            'companies_with_billing' => $billing['totals']['company_count'] ?? 0,
            'zero_billing_count' => count($problematic['zero_billing'] ?? []),
            'zero_billing' => $problematic['zero_billing'] ?? [],
            'problematic' => [
                'offline_agents' => $problematic['offline_agents'] ?? [],
                'pra_failures' => $problematic['pra_failures'] ?? [],
                'print_failures' => $problematic['print_failures'] ?? [],
            ],
            'agents' => $agents,
        ];
    }

    private function dailyOps(Carbon $from, Carbon $to): array
    {
        $server = $this->platform->server();
        $application = $this->platform->application();
        $database = $this->platform->database();
        $queue = $this->platform->queue();
        $scheduler = $this->platform->scheduler();
        $websocket = $this->platform->websocket();
        $performance = $this->platform->performance();
        $security = $this->platform->security($from, $to);
        $billing = $this->billingByCompany($from, $to);
        $activity = $this->companyActivity($from, $to);
        $pra = $this->praHealthFleet($from, $to);
        $errors = $this->errorSummaryFleet($from, $to);
        $agents = $activity['agents'];

        $sections = [
            'server_health' => $server,
            'application_health' => $application,
            'database_health' => $database,
            'queue_worker_health' => $queue,
            'scheduler_health' => $scheduler,
            'websocket_agent_health' => [
                'websocket' => $websocket,
                'agents' => $agents,
            ],
            'company_activity' => [
                'in_scope_count' => $activity['in_scope_count'],
                'active_enabled_count' => $activity['active_enabled_count'],
                'agent_enabled_count' => $activity['agent_enabled_count'],
                'companies_with_billing' => $activity['companies_with_billing'],
                'zero_billing_count' => $activity['zero_billing_count'],
            ],
            'billing_by_company' => $billing,
            'zero_billing_companies' => $activity['zero_billing'],
            'problematic_companies' => $activity['problematic'],
            'pra_transaction_health' => $pra,
            'errors' => $errors,
            'performance' => $performance,
            'security_auth' => $security,
        ];

        $overall = $this->composeOverallStatus($sections);

        return $sections + [
            'overall' => $overall,
            'unknown_observability_gaps' => $overall['unknown'],
            'recommended_owner_actions' => $overall['recommended_owner_actions'],
            'remediation_note' => 'Diagnosis never executes fixes. Owner must explicitly approve a low/medium allow-listed remediation.',
        ];
    }

    /**
     * Facts vs inference: CRITICAL/ATTENTION only from measured evidence.
     * UNKNOWN gaps never invent a failure.
     *
     * @param  array<string, mixed>  $sections
     * @return array{status: string, summary: string, reasons: array, unknown: list<string>, recommended_owner_actions: list<string>}
     */
    private function composeOverallStatus(array $sections): array
    {
        $critical = [];
        $attention = [];
        $unknown = [];
        $actions = [];

        $db = $sections['database_health'] ?? [];
        if (($db['connection_ok'] ?? null) === false) {
            $critical[] = 'Database ping failed.';
            $actions[] = 'Check MariaDB on the production host (owner SSH / system panel). Do not use Live Ops Remediate for this.';
        }
        if (($db['threads_availability'] ?? '') === 'unknown') {
            $unknown[] = 'MariaDB thread/max_connection gauges (information_schema not available on this engine).';
        } elseif (is_numeric($db['threads_pct'] ?? null) && (float) $db['threads_pct'] >= 80) {
            $attention[] = 'MariaDB threads_connected is high (≥80% of max_connections).';
        }

        $disk = $sections['server_health']['disk'] ?? [];
        if (($disk['availability'] ?? '') === 'measured' && is_numeric($disk['used_pct'] ?? null)) {
            if ((float) $disk['used_pct'] >= 95) {
                $critical[] = 'Disk used ≥95%.';
                $actions[] = 'Free disk on the production host; Live Ops will not delete files.';
            } elseif ((float) $disk['used_pct'] >= 85) {
                $attention[] = 'Disk used ≥85%.';
            }
        } else {
            $unknown[] = 'Disk usage.';
        }

        $cpu = $sections['server_health']['cpu'] ?? [];
        if (($cpu['availability'] ?? '') !== 'measured') {
            $unknown[] = 'CPU load average.';
        } elseif (isset($cpu['load']['1m'], $cpu['nproc']) && is_numeric($cpu['nproc']) && (int) $cpu['nproc'] > 0) {
            if ((float) $cpu['load']['1m'] >= ((int) $cpu['nproc'] * 4)) {
                $attention[] = '1-minute load is ≥4× nproc (measured; not proof of an outage).';
            }
        }

        $mem = $sections['server_health']['memory'] ?? [];
        if (($mem['availability'] ?? '') !== 'measured') {
            $unknown[] = 'RAM / MemAvailable.';
        } elseif (is_numeric($mem['used_pct'] ?? null) && (float) $mem['used_pct'] >= 90) {
            $attention[] = 'Memory used ≥90%.';
        }

        $procs = $sections['server_health']['processes'] ?? [];
        foreach (['php_fpm' => 'PHP-FPM', 'apache' => 'Apache/httpd', 'mariadb' => 'MariaDB/mysqld'] as $key => $label) {
            $row = $procs[$key] ?? [];
            if (($row['availability'] ?? '') !== 'measured') {
                $unknown[] = $label.' process scan.';
            }
        }

        $queue = $sections['queue_worker_health'] ?? [];
        if (($queue['heartbeat_availability'] ?? '') === 'unknown') {
            $unknown[] = 'Queue worker heartbeat (SystemSetting queue_last_heartbeat).';
        } elseif ($queue['heartbeat_stale'] === true) {
            $attention[] = 'Queue worker heartbeat is stale.';
            $actions[] = 'Confirm taxnest-queue / queue:work is running. Do not restart from this diagnostic.';
        }
        if (($queue['failed_jobs_availability'] ?? '') === 'measured' && (int) ($queue['failed_jobs_count'] ?? 0) > 0) {
            $attention[] = 'failed_jobs table has '.(int) $queue['failed_jobs_count'].' row(s).';
        } elseif (($queue['failed_jobs_availability'] ?? '') !== 'measured') {
            $unknown[] = 'failed_jobs table.';
        }

        $sched = $sections['scheduler_health'] ?? [];
        if (($sched['heartbeat_availability'] ?? '') === 'unknown') {
            $unknown[] = 'Scheduler heartbeat (SystemSetting scheduler_last_heartbeat).';
        } elseif ($sched['heartbeat_stale'] === true) {
            $attention[] = 'Scheduler heartbeat is stale.';
            $actions[] = 'Confirm crontab php artisan schedule:run.';
        }

        $ws = $sections['websocket_agent_health']['websocket'] ?? [];
        if (($ws['availability'] ?? '') !== 'measured') {
            $unknown[] = 'WebSocket gateway /health ('.$this->unknownReason($ws).').';
        } elseif (($ws['ok'] ?? null) === false || (isset($ws['http_status']) && (int) $ws['http_status'] >= 500)) {
            $attention[] = 'WebSocket gateway /health did not report ok.';
        }

        $perf = $sections['performance'] ?? [];
        if (($perf['availability'] ?? '') !== 'measured') {
            $unknown[] = 'Public HTTP timing probes (/up, /pos/login).';
        } else {
            foreach ($perf['probes'] ?? [] as $name => $probe) {
                if (!($probe['reached'] ?? false)) {
                    $unknown[] = "HTTP probe {$name} did not complete.";
                    continue;
                }
                $status = (int) ($probe['status'] ?? 0);
                if ($name === 'public_up' && $status >= 500) {
                    $critical[] = '/up returned HTTP '.$status.'.';
                } elseif ($status >= 500) {
                    $attention[] = "{$name} returned HTTP {$status}.";
                }
                if (is_numeric($probe['ms'] ?? null) && (int) $probe['ms'] >= 3000) {
                    $attention[] = "{$name} TTFB/total ≥3000ms (measured ".$probe['ms'].'ms).';
                }
            }
        }

        $pra = $sections['pra_transaction_health'] ?? [];
        $praFailed = (int) ($pra['counts']['failed'] ?? 0);
        if ($praFailed > 0) {
            $attention[] = "PRA failed invoices in range: {$praFailed}.";
        }
        if (!empty($pra['duplicate_invoice_numbers'])) {
            $attention[] = 'Duplicate PRA invoice numbers detected (same company, date range). Inference: possible retry/race — not proof of fiscal corruption.';
        }

        $agents = $sections['websocket_agent_health']['agents']['counts'] ?? [];
        $longOffline = (int) ($agents['long_offline'] ?? 0);
        if ($longOffline > 0) {
            $attention[] = "Long-offline agents: {$longOffline}.";
        }

        $appLog = $sections['application_health']['laravel_log'] ?? [];
        if (($appLog['availability'] ?? '') !== 'measured') {
            $unknown[] = 'laravel.log tail scan.';
        } elseif ((int) ($appLog['critical_count'] ?? 0) > 0) {
            $attention[] = 'laravel.log tail contains CRITICAL/ALERT/EMERGENCY lines.';
        } elseif ((int) ($appLog['error_count'] ?? 0) > 0) {
            $attention[] = 'laravel.log tail contains ERROR lines.';
        }
        if (!empty($sections['application_health']['log_health_failure'])) {
            $attention[] = 'LogHealth daily probe is in a failure state.';
        }

        $sec = $sections['security_auth'] ?? [];
        if (!empty($sec['demo_login_exposed_in_production'])) {
            $critical[] = 'DEMO_LOGIN_ENABLED is on while APP_ENV is production — demo-login must stay fail-closed.';
            $actions[] = 'Set DEMO_LOGIN_ENABLED=false on production (config only; this diagnostic did not change it).';
        }
        if (($sec['debug'] ?? false) === true && in_array((string) ($sec['app_env'] ?? ''), ['production', 'prod'], true)) {
            $attention[] = 'APP_DEBUG is true in a production-like environment.';
        }
        if (($sec['failed_login_availability'] ?? '') !== 'measured') {
            $unknown[] = 'security_logs failed_login counts.';
        } elseif ((int) ($sec['failed_login_count'] ?? 0) >= 20) {
            $attention[] = 'failed_login count in range is ≥20 (counts only; IPs omitted).';
        }

        $unknown = array_values(array_unique($unknown));
        $status = 'GREEN';
        if ($critical) {
            $status = 'CRITICAL';
        } elseif ($attention) {
            $status = 'ATTENTION';
        }

        $summary = match ($status) {
            'CRITICAL' => 'Measured critical signals: '.implode(' ', $critical),
            'ATTENTION' => 'Measured attention signals: '.implode(' ', $attention),
            default => 'No measured critical/attention signals. UNKNOWN gaps are listed separately and are not failures.',
        };

        if (!$actions) {
            $actions[] = 'No automatic remediation. Re-run DAILY_OPS after any owner change. Company-level drills use COMPANY_DIAGNOSTIC.';
        }

        return [
            'status' => $status,
            'summary' => $summary,
            'reasons' => [
                'critical' => $critical,
                'attention' => $attention,
            ],
            'unknown' => $unknown,
            'recommended_owner_actions' => $actions,
            'legend' => 'GREEN/ATTENTION/CRITICAL use measured facts only. UNKNOWN means the signal could not be collected safely.',
        ];
    }

    private function unknownReason(array $row): string
    {
        return (string) ($row['reason'] ?? 'not measured');
    }

    private function inferRootCause(array $company, array $pra, array $agent, array $printer, array $errors): string
    {
        $online = $company['company']['agent_online'] ?? false;
        $failedPra = (int) ($pra['counts']['failed'] ?? 0);
        $pendingPra = (int) ($pra['counts']['pending'] ?? 0) + (int) ($pra['counts']['offline'] ?? 0);
        $printFailed = (int) ($printer['failed_job_count'] ?? 0);
        $hasPrinter = !empty($printer['assigned_receipt_printer']) || !empty($printer['discovered_printers']);

        if (!$online && ($pendingPra > 0 || $printFailed > 0 || ($company['company']['silent_print_enabled'] ?? false))) {
            return 'agent_offline';
        }
        if ($printFailed > 0 && $online) {
            return 'printer_or_print_pipeline';
        }
        if ($failedPra > 0 && $online) {
            return 'pra_submission_errors';
        }
        if ($pendingPra > 0 && $online) {
            return 'pra_backlog_pending_agent_sync';
        }
        if (($company['company']['silent_print_enabled'] ?? false) && !$hasPrinter) {
            return 'printer_not_configured';
        }
        if (!empty($errors['errors'])) {
            return 'mixed_operational_errors';
        }

        return 'no_obvious_fault_from_available_signals';
    }

    private function recommendFix(string $cause): array
    {
        return match ($cause) {
            'agent_offline' => [
                'risk' => 'medium',
                'suggested_actions' => ['ENQUEUE_AGENT_COMMAND:SAFE_AGENT_RESTART', 'ENQUEUE_AGENT_COMMAND:RESYNC', 'FORCE_AGENT_UPDATE_ADVERTISE'],
                'manual_if_needed' => 'Confirm PC power, Windows service, and network to taxnest.pk on-site if agent stays offline.',
            ],
            'printer_or_print_pipeline' => [
                'risk' => 'medium',
                'suggested_actions' => ['ENQUEUE_AGENT_COMMAND:PRINTER_REFRESH', 'REBIND_ASSIGNED_PRINTER', 'ENQUEUE_TEST_PRINT'],
                'manual_if_needed' => 'Driver/USB/network printer problems require on-site Windows admin.',
            ],
            'pra_submission_errors' => [
                'risk' => 'low',
                'suggested_actions' => ['RETRY_ONE_PRA_INVOICE'],
                'manual_if_needed' => 'Inspect PRA error text; credential/POS-ID issues are high-risk and not auto-remediable here.',
            ],
            'pra_backlog_pending_agent_sync' => [
                'risk' => 'medium',
                'suggested_actions' => ['ENQUEUE_AGENT_COMMAND:RESYNC', 'ENQUEUE_AGENT_COMMAND:STATUS_REFRESH'],
                'manual_if_needed' => null,
            ],
            'printer_not_configured' => [
                'risk' => 'medium',
                'suggested_actions' => ['ENQUEUE_AGENT_COMMAND:PRINTER_REFRESH', 'REBIND_ASSIGNED_PRINTER'],
                'manual_if_needed' => null,
            ],
            default => [
                'risk' => 'none',
                'suggested_actions' => [],
                'manual_if_needed' => 'Review COMPANY_DIAGNOSTIC details; escalate only with explicit owner instruction.',
            ],
        };
    }

    private function countResults(array $payload): int
    {
        if (isset($payload['companies']) && is_array($payload['companies'])) {
            return count($payload['companies']);
        }
        if (isset($payload['agents']) && is_array($payload['agents'])) {
            return count($payload['agents']);
        }
        if (isset($payload['errors']['errors']) && is_array($payload['errors']['errors'])) {
            return count($payload['errors']['errors']);
        }
        if (isset($payload['errors']) && is_array($payload['errors']) && array_is_list($payload['errors'])) {
            return count($payload['errors']);
        }
        if (isset($payload['company_activity']['in_scope_count'])) {
            return (int) $payload['company_activity']['in_scope_count'];
        }
        if (isset($payload['company'])) {
            return 1;
        }

        return 1;
    }

    private function humanSummary(string $operation, array $payload): string
    {
        return match ($operation) {
            'COMPANY_DIAGNOSTIC' => sprintf(
                'Company %s (%s): agent %s; likely cause: %s.',
                $payload['company']['name'] ?? '?',
                $payload['company']['id'] ?? '?',
                !empty($payload['agent']['online']) ? 'online' : 'offline',
                $payload['likely_root_cause'] ?? 'unknown'
            ),
            'BILLING_SUMMARY', 'BILLING_BY_COMPANY' => sprintf(
                'Billing: %d companies, %d invoices, gross %.2f.',
                $payload['totals']['company_count'] ?? 0,
                $payload['totals']['invoice_count'] ?? 0,
                $payload['totals']['gross_total'] ?? 0
            ),
            'PROBLEMATIC_COMPANIES' => sprintf(
                'Problematic: %d offline agents, %d PRA-fail companies, %d zero-billing.',
                count($payload['offline_agents'] ?? []),
                count($payload['pra_failures'] ?? []),
                count($payload['zero_billing'] ?? [])
            ),
            'ERROR_SUMMARY' => sprintf(
                'Errors: %d lines, PRA-fail %d, print-fail %d.',
                count($payload['errors'] ?? []),
                (int) ($payload['pra_failed_count'] ?? 0),
                (int) ($payload['print_failed_count'] ?? 0)
            ),
            'PRA_HEALTH' => sprintf(
                'PRA: submitted %d, failed %d, pending %d.',
                (int) ($payload['counts']['submitted'] ?? 0),
                (int) ($payload['counts']['failed'] ?? 0),
                (int) ($payload['counts']['pending'] ?? 0)
            ),
            'SERVER_HEALTH' => 'Server health snapshot (read-only).',
            'DAILY_OPS' => sprintf(
                'DAILY_OPS %s — %s',
                $payload['overall']['status'] ?? 'UNKNOWN',
                $payload['overall']['summary'] ?? 'completed.'
            ),
            default => $operation.' completed.',
        };
    }
}
