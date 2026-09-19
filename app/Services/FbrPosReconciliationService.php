<?php

namespace App\Services;

use App\Models\Company;
use App\Models\FbrPosCallbackDiagnostic;
use App\Models\FbrPosLog;
use App\Models\FbrPosTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only FBR POS reconciliation view model.
 *
 * This deliberately treats a local IMS Code 100 as local acceptance only.
 * Central verification is shown only when a callback explicitly supplied a
 * central sync/verification field.
 */
class FbrPosReconciliationService
{
    public function diagnose(int $companyId): array
    {
        $company = Company::query()
            ->whereKey($companyId)
            ->where('product_type', 'fbrpos')
            ->first();

        if (!$company) {
            throw new \InvalidArgumentException('FBR POS company not found.');
        }

        $hasDiagnostics = Schema::hasTable('fbr_pos_callback_diagnostics');
        $latestDiagnostic = $hasDiagnostics
            ? FbrPosCallbackDiagnostic::where('company_id', $company->id)
                ->latest('callback_received_at')
                ->first()
            : null;
        $clientEnvironment = $hasDiagnostics
            ? FbrPosCallbackDiagnostic::where('company_id', $company->id)
                ->whereNotNull('client_environment')
                ->latest('callback_received_at')
                ->value('client_environment')
            : null;

        $requestedEnvironment = $this->normaliseEnvironment($company->fbr_pos_environment);
        $direct900901 = FbrPosLog::query()
            ->where('company_id', $company->id)
            ->when(
                Schema::hasColumn('fbr_pos_logs', 'environment'),
                fn ($query) => $query->where(function ($environmentQuery) use ($requestedEnvironment) {
                    $environmentQuery->where('environment', 'production');
                    if ($requestedEnvironment === 'production') {
                        // Pre-migration rows did not retain environment. Keep the
                        // warning, but label its evidence source as a fallback below.
                        $environmentQuery->orWhereNull('environment');
                    }
                })
            )
            ->where(function ($query) {
                $query->where('response_code', '900901')
                    ->orWhere('error_message', 'like', '%900901%');
            })
            ->latest()
            ->first();

        $from = now()->subDays(60);
        $transactions = FbrPosTransaction::query()
            ->where('company_id', $company->id)
            ->where('created_at', '>=', $from)
            ->orderByDesc('created_at')
            ->limit(250)
            ->get([
                'id', 'invoice_number', 'fbr_invoice_number', 'fbr_status',
                'fbr_response_code', 'created_at', 'updated_at',
            ])
            ->map(function (FbrPosTransaction $transaction) use ($hasDiagnostics) {
                $diagnostic = $hasDiagnostics
                    ? FbrPosCallbackDiagnostic::where('transaction_id', $transaction->id)
                        ->latest('callback_received_at')
                        ->first()
                    : null;
                $central = $this->centralVerdict($diagnostic);

                return [
                    'id' => (int) $transaction->id,
                    'invoice_number' => $this->maskLocalInvoice((string) $transaction->invoice_number),
                    'fbr_number' => $this->maskFiscalNumber($transaction->fbr_invoice_number),
                    'status' => (string) ($transaction->fbr_status ?? 'unknown'),
                    'code' => (string) ($transaction->fbr_response_code ?? '—'),
                    'local_acceptance' => $transaction->fbr_status === 'submitted'
                        && trim((string) $transaction->fbr_invoice_number) !== '',
                    'central_verdict' => $central['label'],
                    'central_detail' => $central['detail'],
                    'created_at' => optional($transaction->created_at)?->toIso8601String(),
                    'updated_at' => optional($transaction->updated_at)?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
        $acceptedCount = count(array_filter(
            $transactions,
            fn (array $transaction) => $transaction['local_acceptance']
        ));

        $central = $this->centralVerdict($latestDiagnostic);
        $clientEnvironment = $this->normaliseEnvironment($clientEnvironment);
        $environmentMismatch = $requestedEnvironment !== null
            && $clientEnvironment !== null
            && $requestedEnvironment !== $clientEnvironment;
        $agentOnline = $company->agentOnline();

        $warnings = [];
        if ($environmentMismatch) {
            $warnings[] = 'FBRIMS environment mismatch — do not submit until the local client environment matches the requested environment.';
        }
        if (!$agentOnline && $company->agent_enabled) {
            $warnings[] = 'Desktop Agent is offline — restore heartbeats before relying on fiscal-device synchronization.';
        }
        if ($direct900901) {
            $warnings[] = 'FBR direct credentials rejected — do not use direct fallback until credentials are corrected.';
        }

        $nextActions = [];
        if ($environmentMismatch) {
            $nextActions[] = 'Confirm FBRIMS is set to '.ucfirst($requestedEnvironment).' and re-open the diagnostic after a callback.';
        }
        if (!$agentOnline && $company->agent_enabled) {
            $nextActions[] = 'Restore the Desktop Agent service and confirm a new heartbeat; do not retry historical invoices from this screen.';
        }
        if ($direct900901) {
            $nextActions[] = 'Correct direct Production credentials with the authorized owner; do not use direct fallback.';
        }
        if ($central['label'] === 'Unknown') {
            $nextActions[] = 'Inspect the local FBRIMS central upload/sync queue and its rejected items; Code 100 alone is not central confirmation.';
        }
        if (!$nextActions) {
            $nextActions[] = 'No corrective action is executed here. Continue monitoring the read-only evidence.';
        }

        return [
            'company' => [
                'id' => (int) $company->id,
                'name' => (string) $company->name,
                'requested_environment' => $requestedEnvironment ? ucfirst($requestedEnvironment) : 'Unknown',
                'connection_mode' => ($company->fbr_connection_mode ?? 'cloud') === 'fiscal_device'
                    ? 'Fiscal Device (Agent)' : 'Cloud',
            ],
            'local_acceptance' => [
                'label' => $acceptedCount > 0 ? 'Local FBR IMS accepted' : 'No local acceptance observed',
                'detail' => $acceptedCount > 0
                    ? "{$acceptedCount} transaction(s) have a stored local fiscal-device result. This is not proof of central FBR verification."
                    : 'No submitted transaction with a fiscal number was found in the 60-day window.',
            ],
            'central' => [
                'label' => $central['label'],
                'detail' => $central['detail'],
                'source' => $central['source'],
            ],
            'agent' => [
                'online' => $agentOnline,
                'enabled' => (bool) $company->agent_enabled,
                'last_heartbeat' => optional($company->agent_last_seen)?->toIso8601String(),
                'version' => $company->agent_version ?: null,
            ],
            'environment' => [
                'requested' => $requestedEnvironment ? ucfirst($requestedEnvironment) : 'Unknown',
                'client_reported' => $clientEnvironment ? ucfirst($clientEnvironment) : 'Unknown',
                'mismatch' => $environmentMismatch,
            ],
            'direct_credentials' => [
                'production_900901_seen' => (bool) $direct900901,
                'last_observed_at' => optional($direct900901?->created_at)?->toIso8601String(),
                'environment_evidence' => $direct900901
                    ? (($direct900901->environment ?? null) === 'production'
                        ? 'Stored on direct attempt'
                        : 'Legacy log; Production inferred from current company setting')
                    : null,
                'warning' => $direct900901
                    ? 'FBR direct credentials rejected — do not use direct fallback until credentials are corrected.'
                    : null,
            ],
            'latest_callback' => $this->callbackSummary($latestDiagnostic),
            'transactions' => $transactions,
            'warnings' => $warnings,
            'next_actions' => $nextActions,
            'scope' => [
                'from' => $from->toIso8601String(),
                'to' => now()->toIso8601String(),
                'read_only' => true,
            ],
        ];
    }

    private function centralVerdict(?FbrPosCallbackDiagnostic $diagnostic): array
    {
        if (!$diagnostic || trim((string) $diagnostic->central_sync_status) === '') {
            return [
                'label' => 'Unknown',
                'detail' => 'No genuine central sync or verification field was returned.',
                'source' => 'not stored',
            ];
        }

        $status = strtolower(trim((string) $diagnostic->central_sync_status));
        if ($diagnostic->success
            && !$diagnostic->offline
            && in_array($status, ['confirmed', 'verified'], true)) {
            return [
                'label' => 'Confirmed',
                'detail' => 'Confirmed only because the callback explicitly returned a central status.',
                'source' => 'FBRIMS callback',
            ];
        }
        if (in_array($status, ['rejected', 'failed', 'error', 'denied'], true)) {
            return [
                'label' => 'Rejected',
                'detail' => 'Rejected only because the callback explicitly returned a central status.',
                'source' => 'FBRIMS callback',
            ];
        }

        return [
            'label' => 'Unknown',
            'detail' => 'A central field was returned but did not identify a confirmed or rejected state.',
            'source' => 'FBRIMS callback',
        ];
    }

    private function callbackSummary(?FbrPosCallbackDiagnostic $diagnostic): array
    {
        if (!$diagnostic) {
            return [
                'available' => false,
                'received_at' => null,
                'agent_version' => null,
                'ims_version' => null,
                'client_environment' => 'Unknown',
                'code' => null,
                'invoice_number_field' => null,
                'central_status' => null,
                'error' => null,
            ];
        }

        return [
            'available' => true,
            'received_at' => optional($diagnostic->callback_received_at)?->toIso8601String(),
            'agent_version' => $diagnostic->agent_version ?: null,
            'ims_version' => $diagnostic->ims_version ?: null,
            'client_environment' => $this->normaliseEnvironment($diagnostic->client_environment)
                ? ucfirst($this->normaliseEnvironment($diagnostic->client_environment)) : 'Unknown',
            'code' => $diagnostic->response_code ?: null,
            'invoice_number_field' => $diagnostic->invoice_number_field ?: null,
            'central_status' => $diagnostic->central_sync_status ?: null,
            'error' => $this->safeError($diagnostic->error_message),
        ];
    }

    private function normaliseEnvironment(?string $environment): ?string
    {
        $value = strtolower(trim((string) $environment));

        return in_array($value, ['test', 'sandbox'], true)
            ? 'test'
            : (in_array($value, ['production', 'prod', 'live'], true) ? 'production' : null);
    }

    private function maskFiscalNumber(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '—';
        }

        return strlen($value) <= 6
            ? str_repeat('*', strlen($value))
            : substr($value, 0, 2).'…'.substr($value, -4);
    }

    private function maskLocalInvoice(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '—';
        }

        return strlen($value) <= 6
            ? str_repeat('*', strlen($value))
            : substr($value, 0, 3).'…'.substr($value, -3);
    }

    private function safeError(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $value = preg_replace('/(?:bearer|token|secret|password|authorization|api[_ -]?key)\s*[:=]?\s*\S+/i', '[redacted]', $value);
        $value = preg_replace('~https?://\S+~i', '[redacted-url]', $value);

        return mb_substr(preg_replace('/[\r\n\t]+/', ' ', $value), 0, 240);
    }
}