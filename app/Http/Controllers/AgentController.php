<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Company;
use App\Models\PosTransaction;
use App\Models\FbrPosTransaction;
use App\Services\PraIntegrationService;
use App\Services\FbrService;

class AgentController extends Controller
{
    /**
     * Agent TELEMETRY writes (last-seen beats, printer inventory) must NEVER
     * bump companies.updated_at: the sale screen's boot fingerprint hashes
     * that timestamp, so a beating agent made every cached sale screen look
     * "stale" → endless reload loop on agent-running shops (ZFC, 30 Jul 2026
     * "NestPOS bar bar load ho raha hai").
     */
    private function telemetryUpdate($company, array $attrs): void
    {
        $company->timestamps = false;
        try {
            $company->update($attrs);
        } finally {
            $company->timestamps = true;
        }
    }

    /**
     * Self-update advertisement for v1.3.0+ agents, piggybacked on the
     * heartbeat response. Reuses the cached GitHub latest-release info so
     * agents never hit api.github.com directly (shared-ISP rate limits).
     * Only tags that look like an AGENT semver (major <= 99) are advertised —
     * date-style tags like v2026.2.0 are ignored so a mis-tagged release can
     * never trigger a downgrade/update loop.
     */
    private function agentUpdateInfo(?string $agentVersion = null): ?array
    {
        try {
            $info = AgentManagementController::latestReleaseInfo();
            $tag = $info['tag'] ?? null;
            if (!$tag || !preg_match('/^v?(\d{1,2})\.(\d+)\.(\d+)$/', $tag, $m)) {
                return null;
            }
            $zip = collect($info['assets'] ?? [])
                ->filter(fn($a) => str_ends_with(strtolower($a['name']), '.zip'))
                ->sortByDesc('size')
                ->first();
            if (!$zip) {
                return null;
            }
            return [
                'version' => $m[1] . '.' . $m[2] . '.' . $m[3],
                'tag' => $tag,
                'zip_url' => $zip['url'],
                'zip_size' => $zip['size'] ?? 0,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function heartbeat(Request $request)
    {
        $company = $request->attributes->get('agent_company');

        $update = [
            'agent_last_seen' => now(),
            'agent_version' => $request->input('version', $company->agent_version),
        ];

        // NestPOS Desktop Offline Mode telemetry (Jul 2026): agents v1.5.3+
        // report the toggle + snapshot freshness. Column-guarded so a deploy
        // window where code lands before the migration can never 500 a beat.
        static $telemetryCols = null;
        if ($telemetryCols === null) {
            $telemetryCols = \Illuminate\Support\Facades\Schema::hasColumn('companies', 'agent_offline_mode')
                && \Illuminate\Support\Facades\Schema::hasColumn('companies', 'agent_snapshot_at');
        }
        if ($telemetryCols && $request->has('offline_mode')) {
            $update['agent_offline_mode'] = (bool) $request->input('offline_mode');
            $snapAt = null;
            if ($request->filled('snapshot_saved_at')) {
                try {
                    $snapAt = \Carbon\Carbon::parse($request->input('snapshot_saved_at'));
                    // A wrong PC clock must never post-date the snapshot.
                    if ($snapAt->gt(now())) {
                        $snapAt = now();
                    }
                } catch (\Throwable $e) {
                    $snapAt = null;
                }
            }
            $update['agent_snapshot_at'] = $snapAt;
        }

        $this->telemetryUpdate($company, $update);

        // ===== FBR POS Fiscal Device company =====
        if ($company->agentServesFbr()) {
            return $this->fbrHeartbeat($company, $request->input('version'));
        }

        // ===== PRA POS company (default) =====
        // Phase 5a — self-heal: rows that already have a fiscal # but never got their status flipped.
        $healed = DB::table('pos_transactions')
            ->where('company_id', $company->id)
            ->whereNotNull('pra_invoice_number')
            ->where('pra_invoice_number', '!=', '')
            ->whereIn('pra_status', ['offline', 'pending', 'failed'])
            ->update([
                'pra_status' => 'submitted',
                'updated_at' => now(),
            ]);

        // Phase 1b — auto-promote any stale 'offline' rows back to 'pending' so the agent re-picks them up.
        $repromoted = DB::table('pos_transactions')
            ->where('company_id', $company->id)
            ->where('pra_status', 'offline')
            ->whereNull('pra_invoice_number')
            ->update([
                'pra_status' => 'pending',
                'updated_at' => now(),
            ]);

        // Phase 5b — provide a snapshot of stuck rows so the agent can confirm none are forgotten.
        $stuckIds = DB::table('pos_transactions')
            ->where('company_id', $company->id)
            ->whereIn('pra_status', ['pending', 'failed', 'offline'])
            ->whereNull('pra_invoice_number')
            ->orderByDesc('id')
            ->limit(50)
            ->pluck('id');

        if ($healed > 0 || $repromoted > 0) {
            Log::info('Agent heartbeat: self-heal sweep', [
                'company_id' => $company->id,
                'healed_count' => $healed,
                'repromoted_count' => $repromoted,
                'stuck_count' => $stuckIds->count(),
            ]);
        }

        return response()->json([
            'ok' => true,
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'pra_pos_id' => $company->pra_pos_id,
                'pra_environment' => $company->pra_environment,
            ],
            'healed' => $healed,
            'repromoted' => $repromoted,
            'stuck_transaction_ids' => $stuckIds,
            'server_time' => now()->toIso8601String(),
            'agent_update' => $this->agentUpdateInfo($request->input('version')),
        ]);
    }

    /** FBR POS equivalent of the PRA self-heal sweep, operating on fbr_pos_transactions. */
    private function fbrHeartbeat(Company $company, ?string $agentVersion = null)
    {
        // Self-heal: rows with a fiscal invoice # but a stale status.
        $healed = DB::table('fbr_pos_transactions')
            ->where('company_id', $company->id)
            ->whereNotNull('fbr_invoice_number')
            ->where('fbr_invoice_number', '!=', '')
            ->whereIn('fbr_status', ['offline', 'pending', 'failed'])
            ->update([
                'fbr_status' => 'submitted',
                'updated_at' => now(),
            ]);

        // Re-promote stale 'offline' finals back to 'pending' (never touches 'local' provisionals).
        $repromoted = DB::table('fbr_pos_transactions')
            ->where('company_id', $company->id)
            ->where('fbr_status', 'offline')
            ->whereNull('fbr_invoice_number')
            ->update([
                'fbr_status' => 'pending',
                'updated_at' => now(),
            ]);

        $stuckIds = DB::table('fbr_pos_transactions')
            ->where('company_id', $company->id)
            ->whereIn('fbr_status', ['pending', 'failed', 'offline'])
            ->whereNull('fbr_invoice_number')
            ->where(function ($q) {
                $q->whereNull('invoice_mode')->orWhere('invoice_mode', '!=', 'local');
            })
            ->orderByDesc('id')
            ->limit(50)
            ->pluck('id');

        if ($healed > 0 || $repromoted > 0) {
            Log::info('Agent heartbeat (FBR): self-heal sweep', [
                'company_id' => $company->id,
                'healed_count' => $healed,
                'repromoted_count' => $repromoted,
                'stuck_count' => $stuckIds->count(),
            ]);
        }

        return response()->json([
            'ok' => true,
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'pra_pos_id' => $company->fbr_pos_id,
                'pra_environment' => $company->fbr_pos_environment,
            ],
            'healed' => $healed,
            'repromoted' => $repromoted,
            'stuck_transaction_ids' => $stuckIds,
            'server_time' => now()->toIso8601String(),
            'agent_update' => $this->agentUpdateInfo($agentVersion),
        ]);
    }

    public function pendingInvoices(Request $request)
    {
        $company = $request->attributes->get('agent_company');

        $this->telemetryUpdate($company, ['agent_last_seen' => now()]);

        // ===== FBR POS Fiscal Device company =====
        if ($company->agentServesFbr()) {
            return $this->fbrPendingInvoices($company);
        }

        // ===== PRA POS company (default) =====
        // Direct Production mode: the SERVER submits to PRA — hand the agent nothing,
        // or we'd race the server into a double submission. The agent stays connected
        // purely for silent printing (and heartbeat/self-update).
        if (!$company->agentHandlesPra()) {
            return response()->json([
                'count' => 0,
                'invoices' => [],
                'pra_endpoint' => null,
                'pra_mode' => 'direct_server',
                'pra_token' => null,
                'pra_pos_id' => $company->pra_pos_id,
            ]);
        }

        $pending = PosTransaction::where('company_id', $company->id)
            ->whereIn('pra_status', ['offline', 'pending', 'failed'])
            ->whereNull('pra_invoice_number')
            ->orderBy('id', 'asc')
            ->limit(20)
            ->get();

        $praService = new PraIntegrationService($company);

        $invoices = [];
        foreach ($pending as $txn) {
            try {
                // All-exempt bills are never reported to PRA (mirrors sendInvoice) —
                // without this, the agent would receive an empty-Items payload.
                if ($txn->items->isNotEmpty() && $txn->items->every(fn ($item) => (bool) $item->is_tax_exempt)) {
                    $txn->pra_status = 'exempt_internal';
                    $txn->save();
                    Log::info("Agent: PRA submission skipped for transaction #{$txn->id} — all items tax-exempt. Internal only.");
                    continue;
                }

                $payload = $praService->generatePayload($txn);
                $invoices[] = [
                    'transaction_id' => $txn->id,
                    'invoice_number' => $txn->invoice_number,
                    'payload' => $payload,
                    'created_at' => $txn->created_at?->toIso8601String(),
                ];
            } catch (\Throwable $e) {
                Log::warning('Agent: payload generation failed', [
                    'transaction_id' => $txn->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // PRA Fiscal Device mode (new POS IDs): PRAL retired the cloud Live/PostData API (Code 112).
        // The agent must POST each invoice to PRAL's local IMS Fiscal Device service running
        // on the SAME shop PC as the agent (installed via PRA registration).
        $fiscalDevice = ($company->pra_connection_mode ?? 'cloud') === 'fiscal_device';

        return response()->json([
            'count' => count($invoices),
            'invoices' => $invoices,
            'pra_endpoint' => $fiscalDevice
                ? 'http://localhost:8524/api/IMSFiscal/GetInvoiceNumberByModel'
                : ($company->pra_environment === 'production'
                    ? 'https://ims.pral.com.pk/ims/production/api/Live/PostData'
                    : 'https://ims.pral.com.pk/ims/sandbox/api/Live/PostData'),
            'pra_mode' => $fiscalDevice ? 'fiscal_device' : 'cloud',
            'pra_token' => $company->pra_production_token,
            'pra_pos_id' => $company->pra_pos_id,
        ]);
    }

    /**
     * FBR POS pending invoices for the Desktop Sync Agent. Same response shape as the PRA
     * path so the agent needs zero changes: it POSTs each `payload` to `pra_endpoint`
     * (the local FBR IMS component on localhost:8524) and reports back via /submit-result.
     */
    private function fbrPendingInvoices(Company $company)
    {
        $pending = FbrPosTransaction::where('company_id', $company->id)
            ->whereIn('fbr_status', ['offline', 'pending', 'failed'])
            ->whereNull('fbr_invoice_number')
            ->where(function ($q) {
                // Never hand the agent a deliberate 'local' provisional bill.
                $q->whereNull('invoice_mode')->orWhere('invoice_mode', '!=', 'local');
            })
            ->orderBy('id', 'asc')
            ->limit(20)
            ->get();

        $fbrService = new FbrService();

        $invoices = [];
        foreach ($pending as $txn) {
            try {
                $txn->loadMissing(['items', 'company']);
                $payload = $fbrService->buildFbrPosPayload($txn);
                $invoices[] = [
                    'transaction_id' => $txn->id,
                    'invoice_number' => $txn->invoice_number,
                    'payload' => $payload,
                    'created_at' => $txn->created_at?->toIso8601String(),
                ];
            } catch (\Throwable $e) {
                Log::warning('Agent: FBR payload generation failed', [
                    'transaction_id' => $txn->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // FBR retired cloud bulk PostData (Code 112) → the agent posts to the LOCAL FBR IMS
        // fiscal component. The local service is authenticated by its own on-PC installation,
        // so no bearer token is needed (sent blank).
        return response()->json([
            'count' => count($invoices),
            'invoices' => $invoices,
            'pra_endpoint' => 'http://localhost:8524/api/IMSFiscal/GetInvoiceNumberByModel',
            'pra_mode' => 'fiscal_device',
            'pra_token' => '',
            'pra_pos_id' => $company->fbr_pos_id,
        ]);
    }

    public function submitResult(Request $request)
    {
        $company = $request->attributes->get('agent_company');

        $request->validate([
            'transaction_id' => 'required|integer',
            'success' => 'required|boolean',
            'pra_invoice_number' => 'nullable|string',
            'response' => 'nullable|array',
            'error' => 'nullable|string',
            // Agent >= Jul 2026: true when the failure was transport-level (IMS service
            // down / no internet / timeout) — the bill stays QUEUED, never 'failed'.
            'offline' => 'nullable|boolean',
        ]);

        // ===== FBR POS Fiscal Device company =====
        if ($company->agentServesFbr()) {
            return $this->fbrSubmitResult($request, $company);
        }

        // ===== PRA POS company (default) =====
        $txnId = $request->input('transaction_id');

        $txn = DB::table('pos_transactions')
            ->where('id', $txnId)
            ->where('company_id', $company->id)
            ->first();

        if (!$txn) {
            return response()->json(['error' => 'Transaction not found'], 404);
        }

        $praInvoiceNumber = $request->input('pra_invoice_number');
        $treatAsSuccess = $request->boolean('success') || (!empty($praInvoiceNumber) && preg_match('/^\d{6}[A-Z]{4,6}\d{4,}$/', $praInvoiceNumber));

        if ($treatAsSuccess && !empty($praInvoiceNumber)) {
            $response = $request->input('response');
            $code = is_array($response) ? ($response['Code'] ?? $response['response_code'] ?? $response['code'] ?? '100') : '100';

            DB::table('pos_transactions')->where('id', $txnId)->update([
                'pra_status' => 'submitted',
                'pra_invoice_number' => $praInvoiceNumber,
                'pra_response_code' => substr((string) $code, 0, 250),
                'updated_at' => now(),
            ]);

            Log::info('Agent: PRA submission success', [
                'company_id' => $company->id,
                'transaction_id' => $txnId,
                'pra_invoice' => $request->input('pra_invoice_number'),
                'full_response' => $response,
            ]);
        } else {
            $errMsg = (string) $request->input('error', 'PRA submission failed');

            // IMS-contact-optional (owner rule Jul 2026): transport-level failures
            // (IMS Fiscal Device service down, no internet, timeout) are NOT PRA
            // rejections — keep the bill QUEUED as 'offline' so it auto-syncs the
            // moment the service/net is back. Pattern rescue covers OLD installed
            // agents that don't send the `offline` flag yet.
            $transportError = $request->boolean('offline')
                || $this->isTransportError($errMsg);
            $newStatus = $transportError ? 'offline' : 'failed';

            DB::table('pos_transactions')->where('id', $txnId)->update([
                'pra_status' => $newStatus,
                'pra_response_code' => substr($errMsg, 0, 250),
                'updated_at' => now(),
            ]);

            Log::log($transportError ? 'info' : 'warning', 'Agent: PRA submission ' . ($transportError ? 'deferred (offline/IMS unreachable — queued)' : 'failed'), [
                'company_id' => $company->id,
                'transaction_id' => $txnId,
                'error' => $errMsg,
                'response' => $request->input('response'),
            ]);
        }

        $this->telemetryUpdate($company, ['agent_last_seen' => now()]);

        return response()->json(['ok' => true]);
    }

    /**
     * True when an agent-reported error is a TRANSPORT failure (IMS Fiscal Device
     * service not running, no internet, DNS/timeout) rather than a regulator
     * rejection. Covers old installed agents that predate the `offline` flag —
     * their messages contain the raw axios/network error codes.
     */
    private function isTransportError(string $errMsg): bool
    {
        return (bool) preg_match(
            '/ECONNREFUSED|ENOTFOUND|ETIMEDOUT|ECONNABORTED|EHOSTUNREACH|ENETUNREACH|ECONNRESET|socket hang up|Network Error|timeout of \d+ms|localhost:8524 unreachable|NOT running on this PC/i',
            $errMsg
        );
    }

    /**
     * FBR POS submit-result callback. Writes to fbr_pos_transactions. Success requires the
     * agent to report success=true WITH a non-empty invoice number — the PRA-specific
     * fiscal-number regex is deliberately NOT applied (FBR IMS invoice formats differ).
     */
    private function fbrSubmitResult(Request $request, Company $company)
    {
        $txnId = $request->input('transaction_id');

        $txn = FbrPosTransaction::where('id', $txnId)
            ->where('company_id', $company->id)
            ->first();

        if (!$txn) {
            return response()->json(['error' => 'Transaction not found'], 404);
        }

        $fbrInvoiceNumber = $request->input('pra_invoice_number');
        $treatAsSuccess = $request->boolean('success') && !empty($fbrInvoiceNumber);

        if ($treatAsSuccess) {
            $response = $request->input('response');
            $code = is_array($response) ? ($response['Code'] ?? $response['response_code'] ?? $response['code'] ?? '100') : '100';

            $txn->update([
                'fbr_status' => 'submitted',
                'fbr_invoice_number' => $fbrInvoiceNumber,
                'fbr_response_code' => substr((string) $code, 0, 250),
                'fbr_response' => is_array($response) ? $response : null,
                'fbr_submission_hash' => null,
            ]);

            Log::info('Agent: FBR submission success', [
                'company_id' => $company->id,
                'transaction_id' => $txnId,
                'fbr_invoice' => $fbrInvoiceNumber,
            ]);
        } else {
            $errMsg = (string) $request->input('error', 'FBR submission failed');

            // Same IMS-contact-optional rule as PRA: transport failures stay QUEUED
            // ('offline') and auto-retry; only real FBR rejections become 'failed'.
            $transportError = $request->boolean('offline')
                || $this->isTransportError($errMsg);
            $newStatus = $transportError ? 'offline' : 'failed';

            $txn->update([
                'fbr_status' => $newStatus,
                'fbr_response_code' => substr($errMsg, 0, 250),
                'fbr_submission_hash' => null,
            ]);

            Log::log($transportError ? 'info' : 'warning', 'Agent: FBR submission ' . ($transportError ? 'deferred (offline/IMS unreachable — queued)' : 'failed'), [
                'company_id' => $company->id,
                'transaction_id' => $txnId,
                'error' => $errMsg,
                'response' => $request->input('response'),
            ]);
        }

        $this->telemetryUpdate($company, ['agent_last_seen' => now()]);

        return response()->json(['ok' => true]);
    }

    // =====================================================
    // SILENT PRINTER ROUTING (Desktop Sync Agent print jobs)
    // =====================================================

    /**
     * Agent reports the shop PC's installed printers (on start + every 5 min).
     * Stored inside companies.pos_printer_settings so the Printer Settings page
     * can offer real dropdowns.
     */
    public function reportPrinters(Request $request)
    {
        $company = $request->attributes->get('agent_company');

        $validated = $request->validate([
            'printers' => 'required|array|max:50',
            'printers.*.name' => 'required|string|max:255',
            'printers.*.displayName' => 'nullable|string|max:255',
            'printers.*.isDefault' => 'nullable|boolean',
        ]);

        $settings = $company->printerSettings();
        $settings['available_printers'] = collect($validated['printers'])->map(fn ($p) => [
            'name' => $p['name'],
            'displayName' => $p['displayName'] ?? $p['name'],
            'isDefault' => (bool) ($p['isDefault'] ?? false),
        ])->values()->all();
        $settings['printers_reported_at'] = now()->toIso8601String();

        $this->telemetryUpdate($company, [
            'pos_printer_settings' => $settings,
            'agent_last_seen' => now(),
        ]);

        return response()->json(['ok' => true, 'count' => count($settings['available_printers'])]);
    }

    /**
     * Atomically claim up to 10 pending print jobs for this company.
     * Two-step token claim (UPDATE ... LIMIT, then SELECT by token) — race-safe
     * without lockForUpdate, matching the fail-safe patterns used elsewhere.
     */
    public function claimPrintJobs(Request $request)
    {
        $company = $request->attributes->get('agent_company');

        // Housekeeping (stale requeue + purge) is throttled to once per 30s per
        // company — with long-polling agents this endpoint runs far more often
        // and the maintenance queries must not run on every pass.
        if (\Illuminate\Support\Facades\Cache::add('print_jobs_housekeeping_' . $company->id, 1, 30)) {
            $this->printJobsHousekeeping($company);
        }

        // Long-poll (agent v1.6.2+, ZFC "instant print" request Aug 2026):
        // ?wait=N holds this request up to N seconds (capped) checking for
        // pending jobs every 250ms, so a job enqueued mid-hold is claimed near
        // instantly instead of waiting out a fixed poll interval. Older agents
        // send no wait param and behave exactly as before. `held` in the
        // response tells the new agent whether the server actually waited —
        // if not (old server / instant answer), the agent adds its own delay
        // so it never tight-loops.
        $wait = min(max((int) $request->query('wait', 0), 0), max(0, (int) config('print.longpoll_max_wait', 8)));
        // PHP's built-in dev server (artisan serve) handles few/one request(s)
        // at a time — a held long-poll would block the whole dev site. Keep
        // holds very short there; production runs PHP-FPM.
        if ($wait > 0 && PHP_SAPI === 'cli-server') {
            $wait = min($wait, 2);
        }
        $held = false;
        $pendingExists = fn () => DB::table('pos_print_jobs')
            ->where('company_id', $company->id)
            ->where('status', 'pending')
            ->exists();
        $hasPending = $pendingExists();
        if ($wait > 0 && !$hasPending) {
            // Bounded admission: each held long-poll occupies one PHP worker
            // (sleeping) for up to $wait seconds. Cap concurrent holds so a
            // fleet of agents can never crowd out normal POS traffic — over
            // the cap we answer instantly (held:false) and the agent falls
            // back to its own 1.5s short-poll delay. Slot acquisition is
            // ATOMIC: MySQL GET_LOCK per slot (auto-released if the
            // connection/worker dies — leak-proof), Cache::add slot keys on
            // other drivers (tests).
            $slot = $this->acquireLongPollSlot();
            if ($slot === null) {
                // Trip visibility (throttled): if this fires often, the cap /
                // hold strategy needs revisiting before more shops enable it.
                if (\Illuminate\Support\Facades\Cache::add('print_jobs_longpoll_cap_log', 1, 60)) {
                    \Log::info('PRINT_LONGPOLL cap reached — answering short-poll', [
                        'max' => $this->longPollMaxHolds(), 'company_id' => $company->id,
                    ]);
                }
            } else {
                try {
                    $held = true;
                    $deadline = microtime(true) + $wait;
                    while (microtime(true) < $deadline) {
                        $this->longPollPause();
                        if ($hasPending = $pendingExists()) {
                            break;
                        }
                    }
                } finally {
                    $this->releaseLongPollSlot($slot);
                }
            }
        }

        if (!$hasPending) {
            return response()->json(['ok' => true, 'jobs' => [], 'count' => 0, 'held' => $held]);
        }

        $token = (string) \Illuminate\Support\Str::uuid();
        DB::table('pos_print_jobs')
            ->where('company_id', $company->id)
            ->where('status', 'pending')
            ->orderBy('id')
            ->limit(10)
            ->update([
                'status' => 'printing',
                'claim_token' => $token,
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);

        $jobs = DB::table('pos_print_jobs')
            ->where('company_id', $company->id)
            ->where('claim_token', $token)
            ->orderBy('id')
            ->get(['id', 'type', 'target_printer', 'transaction_id', 'restaurant_order_id', 'render_query']);

        return response()->json(['ok' => true, 'jobs' => $jobs, 'count' => $jobs->count(), 'held' => $held]);
    }

    /**
     * Max concurrent held long-polls across ALL agents — deployment-tunable
     * via config/print.php (PRINT_LONGPOLL_MAX_HOLDS in .env; read through
     * config() so it stays correct under config:cache). Default is a very
     * conservative 3: the shared cPanel host's FPM pool size is not
     * introspectable, so the cap must sit safely below even small pools and
     * always leave workers free for normal POS traffic. Agents refused a
     * hold fall back to a 1.5s short-poll.
     */
    protected function longPollMaxHolds(): int
    {
        $v = config('print.longpoll_max_holds', 3);
        return max(1, is_numeric($v) ? (int) $v : 3);
    }

    /**
     * Atomically acquire one of the longPollMaxHolds() hold slots.
     * Returns an opaque slot handle, or null when all slots are taken.
     *
     * MySQL: GET_LOCK(name, 0) per slot — atomic across workers, and the
     * server auto-releases the lock if the holding connection dies, so a
     * killed request can never leak a slot.
     * Other drivers (sqlite tests): Cache::add per slot key — atomic within
     * the store; the 15s TTL self-heals any leak.
     */
    protected function acquireLongPollSlot(): ?string
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            for ($i = 0; $i < $this->longPollMaxHolds(); $i++) {
                $name = 'taxnest_print_longpoll_' . $i;
                try {
                    $row = DB::selectOne('SELECT GET_LOCK(?, 0) AS l', [$name]);
                    if ((int) ($row->l ?? 0) === 1) {
                        return $name;
                    }
                } catch (\Throwable $e) {
                    return null; // lock machinery unavailable → be conservative, short-poll
                }
            }
            return null;
        }
        for ($i = 0; $i < $this->longPollMaxHolds(); $i++) {
            $key = 'print_jobs_longpoll_slot_' . $i;
            if (\Illuminate\Support\Facades\Cache::add($key, 1, 15)) {
                return $key;
            }
        }
        return null;
    }

    protected function releaseLongPollSlot(string $slot): void
    {
        if (str_starts_with($slot, 'taxnest_print_longpoll_')) {
            try { DB::select('SELECT RELEASE_LOCK(?)', [$slot]); } catch (\Throwable $e) {}
            return;
        }
        \Illuminate\Support\Facades\Cache::forget($slot);
    }

    /**
     * One 250ms tick of the hold loop — separated so integration tests can
     * override it (e.g. enqueue a job mid-hold to prove early wake-up).
     */
    protected function longPollPause(): void
    {
        usleep(250000);
    }

    /**
     * Print-job table maintenance — stale-claim requeue + old-row purge.
     * Called from claimPrintJobs, throttled to once per 30s per company.
     */
    private function printJobsHousekeeping($company): void
    {
        // Stale-claim requeue: a job stuck 'printing' >2 min means the agent
        // died mid-print. Retry up to 3 attempts, then park as failed.
        DB::table('pos_print_jobs')
            ->where('company_id', $company->id)
            ->where('status', 'printing')
            ->where('updated_at', '<', now()->subMinutes(2))
            ->where('attempts', '<', 3)
            ->update(['status' => 'pending', 'claim_token' => null, 'updated_at' => now()]);
        DB::table('pos_print_jobs')
            ->where('company_id', $company->id)
            ->where('status', 'printing')
            ->where('updated_at', '<', now()->subMinutes(2))
            ->where('attempts', '>=', 3)
            ->update(['status' => 'failed', 'error' => 'Print attempt timed out repeatedly (agent lost the job mid-print).', 'updated_at' => now()]);

        // Housekeeping: finished jobs older than 7 days are useless — prune so
        // the table never grows unbounded (failed jobs stay visible on the
        // Printer Settings page until they age out too).
        // Delete in small LIMIT batches and swallow deadlocks (SQLSTATE 40001):
        // this purge races with the agent's own claim/update queries on the same
        // table, and a lost round is harmless — the next poll retries. Logging
        // it as ERROR just drowned production logs with noise.
        try {
            do {
                $deleted = DB::table('pos_print_jobs')
                    ->where('company_id', $company->id)
                    ->whereIn('status', ['done', 'failed'])
                    ->where('updated_at', '<', now()->subDays(7))
                    ->limit(100)
                    ->delete();
            } while ($deleted === 100);
        } catch (\Illuminate\Database\QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '40001' || str_contains($e->getMessage(), 'Deadlock')) {
                \Log::warning('pos_print_jobs purge skipped this round (deadlock with agent queries)', [
                    'company_id' => $company->id,
                ]);
            } else {
                throw $e;
            }
        }
    }

    /**
     * Render a claimed job's printable HTML for the agent's hidden window.
     * Reuses the exact same blade templates as the popup flow. Never accepts
     * arbitrary URLs — only job-id lookups scoped to the agent key's company.
     */
    public function printJobContent(Request $request, $id)
    {
        $company = $request->attributes->get('agent_company');

        $job = \App\Models\PosPrintJob::where('company_id', $company->id)->find($id);
        if (!$job) {
            return response()->json(['error' => 'Job not found'], 404);
        }

        // Views + nested render logic may read the container binding.
        app()->instance('currentCompanyId', $company->id);

        if ($job->type === 'bill') {
            $transaction = \App\Models\PosTransaction::withoutGlobalScope('hide_archived')
                ->where('company_id', $company->id)
                ->with(['items', 'payments', 'creator', 'terminal'])
                ->find($job->transaction_id);
            if (!$transaction) {
                return response()->json(['error' => 'Transaction not found'], 404);
            }
            // Receipt language (Task #61, 31 Jul 2026): agent requests carry no
            // web session, so SetPosLocale never runs here — resolve the locale
            // from the BILL's creator (per-user override), falling back to the
            // print-job presser, then the company default. Reprints by another
            // user must match the language the bill was made in. Owner decision
            // 1 Aug 2026: KOT + proof-bill now follow the language too.
            $this->setPrintLocale($transaction->creator?->language, $job, $company);
            $printerSize = $company->receipt_printer_size ?? '80mm';
            $receiptView = $printerSize === '58mm' ? 'pos.receipts.receipt_58mm' : 'pos.receipts.receipt_80mm';
            return response(view($receiptView, compact('transaction', 'company'))->render())
                ->header('Content-Type', 'text/html; charset=UTF-8');
        }

        // Proof bill (ZFC 28 Jul 2026): pre-bill via silent path — no OS dialog.
        if ($job->type === 'proof') {
            $order = \App\Models\RestaurantOrder::where('company_id', $company->id)
                ->with(['items', 'table', 'creator'])
                ->find($job->restaurant_order_id);
            if (!$order) {
                return response()->json(['error' => 'Order not found'], 404);
            }
            // Owner decision 1 Aug 2026: proof-bill follows chosen language.
            $this->setPrintLocale($order->creator?->language, $job, $company);
            return response(view('pos.restaurant.proof-bill', ['order' => $order, 'company' => $company])->render())
                ->header('Content-Type', 'text/html; charset=UTF-8');
        }

        if ($job->type === 'kot') {
            // Owner decision 1 Aug 2026: KOT follows chosen language. For the
            // order-less path (below) the presser/company default decides.
            $this->setPrintLocale(null, $job, $company);
            // Order-less delivery bills: KOT rendered from the transaction itself.
            if (!$job->restaurant_order_id && $job->transaction_id) {
                $html = \App\Http\Controllers\RestaurantPosController::renderTransactionKot($company->id, (int) $job->transaction_id);
                if ($html === null) {
                    return response('', 204); // nothing to print — agent marks done
                }
                return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
            }
            $order = \App\Models\RestaurantOrder::where('company_id', $company->id)
                ->with(['items', 'table', 'creator'])
                ->find($job->restaurant_order_id);
            if (!$order) {
                return response()->json(['error' => 'Order not found'], 404);
            }
            // Refine with the order creator's language now that we have it.
            $this->setPrintLocale($order->creator?->language, $job, $company);
            parse_str($job->render_query ?? '', $q);
            $delta = ($q['delta'] ?? null) == '1';
            // KOT Full Mode (ZFC feedback, Jul 2026): mirror of the kitchenTicket
            // route — delta request + new rows => print the WHOLE order (new rows
            // flagged NEW); delta with nothing new stays empty => 204 (no blank,
            // no duplicate). Explicit full prints unchanged.
            $fullMode = (bool) ($company->pos_kot_full_mode ?? false);
            $unprinted = $order->items->whereNull('kot_printed_at');
            if ($delta && $fullMode && $unprinted->isNotEmpty()) {
                $ticketItems = $order->items;
            } else {
                $ticketItems = $delta ? $unprinted->values() : $order->items;
            }
            $newItemIds = $fullMode ? $unprinted->pluck('id') : collect();

            // Counter/Station routing (Jul 2026): render_query may pin this job to
            // one station (station=ID, 0 = main Kitchen). Same shared resolver as
            // the kitchenTicket route — grouping/filtering never diverges.
            $prep = \App\Models\PosStation::prepareTicket($company->id, $ticketItems, $q['station'] ?? null);
            $ticketItems = $prep['items'];
            $grouped = $prep['grouped'];
            $stationLabel = $prep['stationLabel'];

            // Nothing left to print (another job already covered these items, or
            // this station has no rows) — 204 tells the agent to mark the job
            // done WITHOUT printing a blank.
            if ($ticketItems->isEmpty()) {
                return response('', 204);
            }

            $kotBatchNo = $ticketItems->max('kot_batch_no');
            // Full-update ticket carries UNPRINTED rows that will be stamped with
            // the NEXT batch at result time — show that number (matches the
            // browser path, which stamps at render).
            if ($ticketItems->whereNull('kot_printed_at')->isNotEmpty()) {
                $kotBatchNo = ((int) $order->items->max('kot_batch_no')) + 1;
            }

            // DON'T stamp kot_printed_at here — the physical print can still
            // fail after render (printer off, driver error). We record which
            // items this ticket carries and stamp them only when the agent
            // reports success in printJobResult. A failed job re-renders the
            // SAME items (still NULL), so retries print identical content.
            $job->update(['printed_item_ids' => $ticketItems->pluck('id')->values()->all()]);

            return response(view('pos.restaurant.kitchen-ticket', compact('order', 'company', 'ticketItems', 'delta', 'kotBatchNo', 'grouped', 'stationLabel', 'newItemIds'))->render())
                ->header('Content-Type', 'text/html; charset=UTF-8');
        }

        return response()->json(['error' => 'Unknown job type'], 422);
    }

    /**
     * Silent-print locale (Task #61 + #68): agent requests carry no web session,
     * so SetPosLocale never runs — resolve from the document creator's language,
     * then the print-job presser, then the company default. Never blocks a print.
     */
    private function setPrintLocale(?string $creatorLang, $job, $company): void
    {
        try {
            $presser = $job->created_by ? \App\Models\User::find($job->created_by) : null;
            $lang = $creatorLang
                ?? $presser?->language
                ?? $company->default_language
                ?? \App\Support\PosLocale::DEFAULT;
            app()->setLocale(\App\Support\PosLocale::normalize($lang));
        } catch (\Throwable $e) { /* never block a print over locale */ }
    }

    /**
     * Agent reports the outcome of a claimed print job.
     */
    public function printJobResult(Request $request, $id)
    {
        $company = $request->attributes->get('agent_company');

        $validated = $request->validate([
            'success' => 'required|boolean',
            'error' => 'nullable|string|max:2000',
        ]);

        $job = \App\Models\PosPrintJob::where('company_id', $company->id)->find($id);
        if (!$job) {
            return response()->json(['error' => 'Job not found'], 404);
        }

        $job->update([
            'status' => $validated['success'] ? 'done' : 'failed',
            'error' => $validated['success'] ? null : ($validated['error'] ?? 'Print failed'),
            'claim_token' => null,
        ]);

        // KOT actually reached paper — NOW stamp the rendered items so delta
        // tickets stay correct. Failed prints leave items NULL, so the KDS
        // delta cycle (or a retry) naturally re-prints them. Stamp kot_batch_no
        // in the SAME update (was missing — agent-printed rows showed batch NULL
        // and reprint/"KOT #" headers lost their numbering).
        if ($validated['success'] && $job->type === 'kot' && !empty($job->printed_item_ids)) {
            $nextBatch = ((int) \App\Models\RestaurantOrderItem::where('order_id', $job->restaurant_order_id)->max('kot_batch_no')) + 1;
            \App\Models\RestaurantOrderItem::whereIn('id', $job->printed_item_ids)
                ->where('order_id', $job->restaurant_order_id)
                ->whereNull('kot_printed_at')
                ->update(['kot_printed_at' => now(), 'kot_batch_no' => $nextBatch]);
        }

        return response()->json(['ok' => true]);
    }
}
