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
     * Task 1166 — per-counter printer routing. Column/table guards for the
     * multi-counter device registry, cached per request (deploy window where
     * code lands before the migration must never 500 an agent call).
     */
    public static function deviceRoutingReady(): bool
    {
        static $ready = null;
        // Tests share one PHP process across many Schema::dropAllTables()
        // rebuilds — a cached answer from another suite's schema would poison
        // every later test. Production is one process per request: cache there.
        if ($ready === null || app()->runningUnitTests()) {
            try {
                $ready = \Illuminate\Support\Facades\Schema::hasTable('pos_agent_devices')
                    && \Illuminate\Support\Facades\Schema::hasColumn('pos_print_jobs', 'device_uid');
            } catch (\Throwable $e) {
                $ready = false;
            }
        }
        return $ready;
    }

    /**
     * Sanitized device UID from an agent request (query or body), or null.
     * Old agents send nothing → null → exact legacy behavior everywhere.
     */
    private function requestDeviceUid(Request $request): ?string
    {
        $uid = trim((string) $request->input('device_uid', $request->query('device_uid', '')));
        if ($uid === '' || strlen($uid) > 64 || !preg_match('/^[A-Za-z0-9._-]+$/', $uid)) {
            return null;
        }
        return $uid;
    }

    /**
     * Upsert this agent install's device row (multi-counter registry).
     * Fire-and-forget telemetry: throttled to one write per 60s per device
     * (heartbeat is every 30s and claim polls are near-continuous), and any
     * failure is swallowed — a registry hiccup must never break printing.
     */
    private function syncAgentDevice($company, Request $request, array $extra = []): void
    {
        $uid = $this->requestDeviceUid($request);
        if (!$uid || !self::deviceRoutingReady()) {
            return;
        }
        $throttleKey = 'agent_device_beat_' . $company->id . '_' . md5($uid);
        if (empty($extra) && !\Illuminate\Support\Facades\Cache::add($throttleKey, 1, 60)) {
            return; // plain last-seen beat already written recently
        }
        try {
            $attrs = ['last_seen_at' => now()] + $extra;
            if ($request->filled('hostname')) {
                $attrs['hostname'] = mb_substr(trim((string) $request->input('hostname')), 0, 120);
            }
            if ($request->filled('version')) {
                $attrs['agent_version'] = mb_substr((string) $request->input('version'), 0, 32);
            }
            // PC Name (v1.9.0): shopkeeper-given friendly label sent by the agent.
            // Only stored when non-blank — a blank/absent pc_name must never
            // wipe a name that the admin set via the Printer Settings page.
            $pcName = trim((string) $request->input('pc_name', ''));
            if ($pcName !== '') {
                $attrs['name'] = mb_substr($pcName, 0, 60);
            }
            \App\Models\PosAgentDevice::updateOrCreate(
                ['company_id' => $company->id, 'device_uid' => $uid],
                $attrs
            );
        } catch (\Throwable $e) {
            // Unique-key race between two first beats, or transient DB issue —
            // the next beat self-heals. Never block the agent call.
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

            // 25 Aug 2026 (shop: "agent roz update maangta hai") — pehle server
            // HAR heartbeat par latest release advertise karta tha aur "purana
            // hai ya nahi" ka faisla poori tarah client par chhorta tha. Yani ek
            // already-updated agent ko bhi update payload milta rehta tha; agar
            // kisi bhi surface ne us payload ki MAUJOODGI ko "update available"
            // samajh liya to shop ko roz prompt dikhta raha, chahe wo latest par
            // hi kyun na ho. Ab faisla server par bhi lagta hai: version pata ho
            // aur latest se kam na ho to kuch bhejte hi nahi. (Version na batane
            // wale purane agents ke liye purana rawaiya barqarar — unka faisla
            // client hi karega.)
            $latest = $m[1] . '.' . $m[2] . '.' . $m[3];
            if ($agentVersion
                && preg_match('/^v?(\d+)\.(\d+)\.(\d+)/', trim($agentVersion), $cv)
                && version_compare("{$cv[1]}.{$cv[2]}.{$cv[3]}", $latest, '>=')) {
                return null;
            }

            $zip = collect($info['assets'] ?? [])
                ->filter(fn($a) => str_ends_with(strtolower($a['name']), '.zip'))
                ->sortByDesc('size')
                ->first();
            if (!$zip) {
                return null;
            }
            $zipUrl = $zip['url'];

            // Transition shim (Aug 2026): releases moved to the public releases-only
            // repo (nestpos-releases) so the main source repo can go private.
            // Agents < 1.7.0 host-pin ONLY the old taxnest repo and silently reject
            // any other zip_url, so for them we rewrite the download URL back to the
            // OLD repo, where every transition release is published with identical assets.
            //
            // Remove this shim once every live agent (companies.agent_version where
            // agent_last_seen is recent) is >= 1.7.0. While the shim is active, the
            // old taxnest repo MUST remain public (or the transition releases republished
            // elsewhere) — agents < 1.7.0 download directly from that URL.
            // Shops to watch: ZFC PIZZA POINT (company 28, v1.6.2) and X-WAY SHOES
            // (company 27, v1.6.1).
            $legacy = true;
            if ($agentVersion && preg_match('/^v?(\d+)\.(\d+)\.(\d+)/', trim($agentVersion), $av)) {
                $legacy = version_compare("{$av[1]}.{$av[2]}.{$av[3]}", '1.7.0', '<');
            }
            $newPrefix = 'https://github.com/jawadrao5555-alt/nestpos-releases/releases/download/';
            $oldPrefix = 'https://github.com/jawadrao5555-alt/taxnest/releases/download/';
            if ($legacy && str_starts_with($zipUrl, $newPrefix)) {
                $zipUrl = $oldPrefix . substr($zipUrl, strlen($newPrefix));
            }

            return [
                'version' => $m[1] . '.' . $m[2] . '.' . $m[3],
                'tag' => $tag,
                'zip_url' => $zipUrl,
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

        // Self-update telemetry (Task 1062): agents v1.8.0+ report the LAST
        // update attempt (target version, failure stage, error) so a shop
        // stuck on an old version is visible in saas-admin instead of silent.
        // Column-guarded (deploy-before-migration safe) AND only written when
        // the agent actually sent the fields — old agents omitting them must
        // never wipe stored values.
        static $updateTelemetryCols = null;
        if ($updateTelemetryCols === null) {
            $updateTelemetryCols = \Illuminate\Support\Facades\Schema::hasColumn('companies', 'agent_update_target')
                && \Illuminate\Support\Facades\Schema::hasColumn('companies', 'agent_update_stage')
                && \Illuminate\Support\Facades\Schema::hasColumn('companies', 'agent_update_error')
                && \Illuminate\Support\Facades\Schema::hasColumn('companies', 'agent_update_at');
        }
        if ($updateTelemetryCols && $request->filled('update_target')) {
            $update['agent_update_target'] = mb_substr((string) $request->input('update_target'), 0, 32);
            $update['agent_update_stage'] = $request->filled('update_stage')
                ? mb_substr((string) $request->input('update_stage'), 0, 40) : null;
            $update['agent_update_error'] = $request->filled('update_error')
                ? mb_substr((string) $request->input('update_error'), 0, 800) : null;
            $updAt = null;
            if ($request->filled('update_attempted_at')) {
                try {
                    $updAt = \Carbon\Carbon::parse($request->input('update_attempted_at'));
                    if ($updAt->gt(now())) {
                        $updAt = now(); // wrong PC clock guard
                    }
                } catch (\Throwable $e) {
                    $updAt = null;
                }
            }
            $update['agent_update_at'] = $updAt ?: now();
        }

        // Task 1209: clear stale update telemetry once the agent has actually
        // reached (or passed) the previously stored target version. After a
        // successful self-update the NEW agent never re-sends the old attempt's
        // telemetry (only-sent-after-an-attempt rule), so a past failure row
        // (e.g. the v1.9.0 EPERM temp-dir trap) would otherwise sit in
        // saas-admin forever looking like the shop is still stuck.
        if ($updateTelemetryCols
            && !$request->filled('update_target')
            && $company->agent_update_target
            && preg_match('/^v?(\d+)\.(\d+)\.(\d+)/', trim((string) $request->input('version')), $cv)
            && preg_match('/^v?(\d+)\.(\d+)\.(\d+)/', trim((string) $company->agent_update_target), $tv)
            && version_compare("{$cv[1]}.{$cv[2]}.{$cv[3]}", "{$tv[1]}.{$tv[2]}.{$tv[3]}", '>=')) {
            $update['agent_update_target'] = null;
            $update['agent_update_stage'] = null;
            $update['agent_update_error'] = null;
            $update['agent_update_at'] = null;
        }

        $this->telemetryUpdate($company, $update);

        // Task 1166: multi-counter registry — agents v1.9.0+ identify their
        // counter PC with a persistent device_uid + hostname on every beat.
        $this->syncAgentDevice($company, $request);

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
            // Task 1475: a whitespace-only number is no number — self-healing on it
            // would promote the row to 'submitted' with nothing to print a QR from.
            ->whereRaw("TRIM(pra_invoice_number) <> ''")
            ->whereIn('pra_status', ['offline', 'pending', 'failed'])
            ->update([
                'pra_status' => 'submitted',
                'updated_at' => now(),
            ]);

        // Phase 1b — auto-promote any stale 'offline' rows back to 'pending' so the agent re-picks them up.
        $repromoted = DB::table('pos_transactions')
            ->where('company_id', $company->id)
            ->where('pra_status', 'offline')
            // Same rule as the self-heal above: whitespace counts as "no number", so
            // such a row is still eligible to be re-queued instead of sitting stuck.
            ->where(function ($q) {
                $q->whereNull('pra_invoice_number')->orWhereRaw("TRIM(pra_invoice_number) = ''");
            })
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
                // Live runs with strict lazy-loading: touching $txn->items without an
                // eager load throws, killing payload generation on EVERY poll (bills
                // stuck 'pending' forever). Mirror the FBR loop's loadMissing pattern.
                $txn->loadMissing(['items', 'company']);

                // Do not hand a cooked-return resale to the desktop agent in
                // the same poll as its credit note. The next poll will include
                // it after the dependency has a real PRA number.
                if (\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'pra_dependency_transaction_id')
                    && $txn->pra_dependency_transaction_id) {
                    $dependency = PosTransaction::withoutGlobalScope('hide_archived')
                        ->where('company_id', $company->id)
                        ->find($txn->pra_dependency_transaction_id);
                    if (!$dependency || $dependency->pra_status !== 'submitted' || !$dependency->pra_invoice_number) {
                        continue;
                    }
                }

                // Task 760 (owner, 15 Aug 2026): exempt items are zero-rated —
                // generatePayload now includes them at TaxRate 0 / TaxCharged 0,
                // so all-exempt bills go to the agent like any other bill (the
                // old exempt_internal skip is gone; historical exempt_internal
                // rows never match the pending-status query above anyway).
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
                // Surface the reason in the F11 Failed Bills modal (Task 624 line)
                // instead of leaving the bill silently stuck in 'pending'.
                try {
                    if (\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'pra_error_message')) {
                        $txn->pra_error_message = 'Bill ka PRA payload nahi ban saka — ' . mb_substr($e->getMessage(), 0, 200);
                        $txn->save();
                    }
                } catch (\Throwable $saveErr) {
                    Log::warning('Agent: could not persist payload-generation error', [
                        'transaction_id' => $txn->id,
                        'error' => $saveErr->getMessage(),
                    ]);
                }
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

        // Task 1475: whitespace is NOT a fiscal number. !empty('   ') is true, so a
        // blank number from the agent would have stamped pra_status='submitted' with
        // an unusable number — and this is a query-builder write, so the model's
        // saving() backstop never sees it. Normalise once, here.
        $praInvoiceNumber = trim((string) $request->input('pra_invoice_number', ''));
        $treatAsSuccess = $request->boolean('success') || ($praInvoiceNumber !== '' && preg_match('/^\d{6}[A-Z]{4,6}\d{4,}$/', $praInvoiceNumber));

        if ($treatAsSuccess && $praInvoiceNumber !== '') {
            $response = $request->input('response');
            $code = is_array($response) ? ($response['Code'] ?? $response['response_code'] ?? $response['code'] ?? '100') : '100';

            $successUpdate = [
                'pra_status' => 'submitted',
                'pra_invoice_number' => $praInvoiceNumber,
                'pra_response_code' => substr((string) $code, 0, 250),
                'updated_at' => now(),
            ];
            // Task 624: clear stale failure reason once the bill goes through.
            if (\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'pra_error_message')) {
                $successUpdate['pra_error_message'] = null;
            }
            DB::table('pos_transactions')->where('id', $txnId)->update($successUpdate);

            Log::info('Agent: PRA submission success', [
                'company_id' => $company->id,
                'transaction_id' => $txnId,
                'pra_invoice' => $praInvoiceNumber,
                'full_response' => $response,
            ]);
        } else {
            $errMsg = (string) $request->input('error', 'PRA submission failed');

            // Task 1475: the agent claimed success but sent no usable fiscal number.
            // Route it through the ordinary failure handling with a reason the cashier
            // can act on, rather than recording a submission that never happened.
            if ($request->boolean('success') && $praInvoiceNumber === '') {
                $errMsg = 'Agent ne success bheja magar PRA fiscal invoice number nahi diya — bill report nahi hua. Retry karein.';
            }

            // IMS-contact-optional (owner rule Jul 2026): transport-level failures
            // (IMS Fiscal Device service down, no internet, timeout) are NOT PRA
            // rejections — keep the bill QUEUED as 'offline' so it auto-syncs the
            // moment the service/net is back. Pattern rescue covers OLD installed
            // agents that don't send the `offline` flag yet.
            $transportError = $request->boolean('offline')
                || $this->isTransportError($errMsg);
            $newStatus = $transportError ? 'offline' : 'failed';

            $failUpdate = [
                'pra_status' => $newStatus,
                'pra_response_code' => substr($errMsg, 0, 250),
                'updated_at' => now(),
            ];
            // Task 624: store a short cashier-readable reason for the F11 modal.
            if (\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'pra_error_message')) {
                $failUpdate['pra_error_message'] = \App\Services\PraIntegrationService::shortTransportError($errMsg);
            }
            DB::table('pos_transactions')->where('id', $txnId)->update($failUpdate);

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

            $txn->update(array_merge([
                'fbr_status' => 'submitted',
                'fbr_invoice_number' => $fbrInvoiceNumber,
                'fbr_response_code' => substr((string) $code, 0, 250),
                'fbr_response' => is_array($response) ? $response : null,
                'fbr_submission_hash' => null,
            ], \App\Services\FbrService::fbrErrorPatch(null)));

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

            // Task 627: asal wajah bill par save (F11 modal) — transport errors get the
            // short Roman-Urdu reason, real FBR rejections keep the raw message.
            $txn->update(array_merge([
                'fbr_status' => $newStatus,
                'fbr_response_code' => substr($errMsg, 0, 250),
                'fbr_submission_hash' => null,
            ], \App\Services\FbrService::fbrErrorPatch(
                $transportError ? \App\Services\FbrService::shortFbrTransportError($errMsg) : $errMsg
            )));

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
    /**
     * Task 1075: Detect a Windows text-only/generic driver from the printer queue
     * name or display name.  Conservative patterns only — known thermal model names
     * (POS-80, XP-80, TM-T88, RP-80, etc.) are deliberately NOT flagged because
     * those are valid thermal queues.  Only flag strings that unmistakably identify
     * a plain-text driver (no ESC/POS, no graphics, no bold).
     */
    private static function detectTextOnlyPrinter(string $name, string $displayName): bool
    {
        foreach ([$name, $displayName] as $candidate) {
            $lower = strtolower($candidate);
            // "Generic / Text Only", "Generic Text Only", "Generic Text"
            if (str_contains($lower, 'generic text') || str_contains($lower, 'generic/text') || str_contains($lower, 'generic / text')) {
                return true;
            }
            // "Text Only", "Text-Only", "TextOnly"
            if (str_contains($lower, 'text only') || str_contains($lower, 'text-only') || str_contains($lower, 'textonly')) {
                return true;
            }
            // "Raw Text" (some legacy PostScript / text-passthrough drivers)
            if (str_contains($lower, 'raw text')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Task 1187 — Agent setup printer picker.
     *
     * Called when the shopkeeper explicitly picks a printer from the agent setup
     * form and saves. Two effects:
     *  1. Stores the choice on this device's row (pos_agent_devices.receipt_printer)
     *     so the Printer Settings page reflects it without extra admin work.
     *  2. When explicit=true (real thermal printer, not PDF/XPS/OneNote):
     *     enables company-level silent receipt printing the same way the
     *     one-click enable prompt does — prompt_dismissed_at is stamped so the
     *     shop is never nagged afterwards.
     *
     * Precedence rules (owner voice note):
     *  - explicit=true  → device printer + activate company silent print
     *  - explicit=false → device printer only (restart carrying saved choice,
     *                     dropdown was never changed — precedence preserved)
     *  - blank printer  → no-op (never wipe an existing choice on either side)
     *  - admin edit from Printer Settings survives agent restarts because an
     *    unchanged dropdown sends explicit=false (no activation, no wipe).
     *  - a deliberately-OFF shop is only re-enabled by a fresh explicit pick,
     *    never by an unchanged re-save.
     */
    public function setDevicePrinter(Request $request)
    {
        $company = $request->attributes->get('agent_company');

        $validated = $request->validate([
            'receipt_printer' => 'nullable|string|max:255',
            'explicit'        => 'nullable|boolean',
        ]);

        $printer  = trim((string) ($validated['receipt_printer'] ?? ''));
        $explicit = (bool) ($validated['explicit'] ?? false);

        // Blank printer = never wipe; return current state and exit.
        if ($printer === '') {
            return response()->json([
                'ok'                    => true,
                'silent_print_enabled'  => $company->printerSettings()['silent_print_enabled'],
            ]);
        }

        // 1. Update per-device receipt_printer so Printer Settings page reflects
        //    the agent-side pick without any admin action.
        // Remember the device's previously-saved printer BEFORE the upsert —
        // an unchanged re-save (same printer posted again, e.g. the setup form
        // reopened and Saved without touching the dropdown) must never count
        // as a fresh explicit pick for a deliberately-OFF shop (see step 2).
        $priorDevicePrinter = null;
        if (self::deviceRoutingReady()) {
            $uid = $this->requestDeviceUid($request);
            if ($uid) {
                try {
                    $priorDevicePrinter = \App\Models\PosAgentDevice::where('company_id', $company->id)
                        ->where('device_uid', $uid)
                        ->value('receipt_printer');
                } catch (\Throwable $e) {
                    // Registry hiccup — fall through with null (legacy behavior).
                }
                try {
                    \App\Models\PosAgentDevice::updateOrCreate(
                        ['company_id' => $company->id, 'device_uid' => $uid],
                        [
                            'receipt_printer' => $printer,
                            'last_seen_at'    => now(),
                            // Keep hostname/name in sync (heartbeat may not have
                            // run yet if the shopkeeper saves on first launch).
                            ...(trim((string) $request->input('hostname', '')) !== ''
                                ? ['hostname' => mb_substr(trim((string) $request->input('hostname')), 0, 120)]
                                : []),
                        ]
                    );
                } catch (\Throwable $e) {
                    // Race on first beat — next save self-heals; never block.
                }
            }
        }

        // 2. Explicit real-printer pick → enable company-level silent printing.
        //    "Silent print OFF by default" shops get printing in one step from
        //    the agent setup form — no separate panel visit required.
        $silentNowEnabled = $company->printerSettings()['silent_print_enabled'];

        // Unchanged re-save guard: the shop is silent-OFF and this device posted
        // the exact printer it already had saved — that is a Save-button reflex,
        // not a fresh deliberate pick. Never reactivate an OFF shop from it.
        // (A genuinely new/changed real-printer pick still activates below.)
        if ($explicit && !$silentNowEnabled
            && $priorDevicePrinter !== null && trim((string) $priorDevicePrinter) === $printer) {
            $explicit = false;
            Log::info('Agent: unchanged printer re-save ignored (shop silent-OFF)', [
                'company_id'      => $company->id,
                'receipt_printer' => $printer,
            ]);
        }

        if ($explicit) {
            $settings = $company->printerSettings();
            $settings['silent_print_enabled'] = true;
            // Stamp dismiss so the one-click banner never re-appears (deliberate
            // human choice from the agent setup form = same semantic as the banner).
            $settings['prompt_dismissed_at'] = now()->toIso8601String();
            // Single-counter shops: set the company-level receipt_printer too so
            // bills route correctly even before the admin visits Printer Settings.
            // Multi-counter shops already have per-device routing; company-level
            // stays as-is if already set by the admin (most-recent-deliberate-
            // choice rule: the admin panel wins if they set it explicitly there).
            if (empty($settings['receipt_printer'])) {
                $settings['receipt_printer'] = $printer;
            }
            // telemetryUpdate: does NOT bump companies.updated_at (sale-screen boot
            // fingerprint must not flap on every agent save — same guard as heartbeat).
            $this->telemetryUpdate($company, ['pos_printer_settings' => $settings]);
            $silentNowEnabled = true;

            Log::info('Agent: silent print activated from setup form', [
                'company_id'      => $company->id,
                'receipt_printer' => $printer,
            ]);
        }

        return response()->json([
            'ok'                   => true,
            'silent_print_enabled' => $silentNowEnabled,
        ]);
    }

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
            // Task 1075: flag text-only/generic drivers so the Printer Settings
            // page can warn before garbled receipts happen. Conservative patterns
            // only — known thermal model names (POS-80, XP-80, TM-T88…) are never
            // flagged even though they can look similar.
            'isTextOnly' => self::detectTextOnlyPrinter($p['name'], $p['displayName'] ?? $p['name']),
        ])->values()->all();
        $settings['printers_reported_at'] = now()->toIso8601String();

        $this->telemetryUpdate($company, [
            'pos_printer_settings' => $settings,
            'agent_last_seen' => now(),
        ]);

        // Task 1166: also store THIS counter's own printer list on its device
        // row, so the Printer Settings page can offer a per-device dropdown.
        // Company-wide list above stays exactly as before (legacy fallback —
        // last reporter wins, same as today).
        $this->syncAgentDevice($company, $request, [
            'printers' => $settings['available_printers'],
            'printers_reported_at' => now(),
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

        // Task 1166 — per-counter routing. Claim visibility rules:
        //   agent WITH device_uid    → its own stamped jobs + unstamped (legacy) jobs
        //   agent WITHOUT device_uid → unstamped jobs ONLY (a legacy agent must
        //                              never race another counter's stamped bill)
        // Column-guarded: pre-migration prod behaves exactly as before.
        $deviceUid = self::deviceRoutingReady() ? $this->requestDeviceUid($request) : null;
        $deviceAware = self::deviceRoutingReady();
        if ($deviceAware) {
            // Poll beats keep the device row's last_seen fresh (throttled) so
            // the Printer Settings page and enqueue-time routing see it online.
            $this->syncAgentDevice($company, $request);
        }
        $deviceScope = function ($q) use ($deviceAware, $deviceUid) {
            if (!$deviceAware) {
                return; // column not migrated yet — legacy behavior
            }
            if ($deviceUid) {
                $q->where(function ($w) use ($deviceUid) {
                    $w->whereNull('device_uid')->orWhere('device_uid', $deviceUid);
                });
            } else {
                $q->whereNull('device_uid');
            }
        };

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
            ->where($deviceScope)
            ->exists();
        $hasPending = $pendingExists();

        // Activity gate (Aug 2026 — "server bohat slow" incident). A held poll
        // occupies one PHP worker for up to $wait seconds. The shared cPanel
        // host runs a TINY lsphp pool (observed: 4), so agents that long-poll
        // around the clock were sleeping on most of it while a counter's own
        // page request queued behind them — invisible to the slow-request log,
        // because the wait happens BEFORE PHP boots.
        //
        // Holding is therefore allowed only while a company is actually
        // printing: the marker is (re)armed whenever a poll sees a real job,
        // and expires after a quiet spell. An idle/closed shop short-polls
        // instead, which costs ~40ms of worker time per poll instead of 8s.
        // Cost to print latency is at most one short-poll interval on the
        // FIRST job after a quiet spell — that job is picked up by the very
        // next short-poll anyway, and the rush that follows prints instantly.
        $activeKey = 'print_recent_activity_' . $company->id;
        $activeMinutes = max(1, (int) config('print.active_window_minutes', 20));
        if ($hasPending) {
            \Illuminate\Support\Facades\Cache::put($activeKey, 1, now()->addMinutes($activeMinutes));
        }
        if ($wait > 0 && !$hasPending && !\Illuminate\Support\Facades\Cache::has($activeKey)) {
            $wait = 0; // quiet shop — never tie up a worker
        }
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
            ->where($deviceScope)
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
        $v = config('print.longpoll_max_holds', 1);
        return max(1, is_numeric($v) ? (int) $v : 1);
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
        // 500ms, not 250ms: the inner re-check is a DB round trip, so a held
        // 8s poll used to cost ~32 queries. Printing does not feel any
        // different at half the cadence, and it halves the polling load the
        // shared MySQL sees during a rush.
        usleep(500000);
    }

    /**
     * Print-job table maintenance — stale-claim requeue + old-row purge.
     * Called from claimPrintJobs, throttled to once per 30s per company.
     */
    private function printJobsHousekeeping($company): void
    {
        // Task 1166 — stranded stamped-job rescue: a job stamped for a counter
        // whose agent stopped claiming (PC shut down right after enqueue, agent
        // downgraded mid-flight) must NEVER sit pending forever. Rescue ONLY
        // when the assigned device itself has gone OFFLINE (row gone or
        // last_seen past the 2-min online window — an actively polling agent
        // beats last_seen at least every 60s): a busy-but-alive counter working
        // through a print backlog must never have its queued bills released to
        // another counter just because they aged past a timer. For a genuinely
        // dead counter, wait 90s of no claim, then unstamp so any company agent
        // picks the job up, retargeting bill/proof to the company default
        // receipt printer when one is set (the per-device printer may not
        // exist on the rescuing PC). Enqueue-time routing only stamps ONLINE
        // devices, so this is rare.
        if (self::deviceRoutingReady()) {
            $deviceOfflineBefore = now()->subSeconds(120);
            $stranded = DB::table('pos_print_jobs as j')
                ->leftJoin('pos_agent_devices as d', function ($join) use ($company) {
                    $join->on('d.device_uid', '=', 'j.device_uid')
                        ->where('d.company_id', '=', $company->id);
                })
                ->where('j.company_id', $company->id)
                ->where('j.status', 'pending')
                ->whereNotNull('j.device_uid')
                ->where('j.created_at', '<', now()->subSeconds(90))
                ->where(function ($q) use ($deviceOfflineBefore) {
                    $q->whereNull('d.id')                                  // device row vanished
                        ->orWhereNull('d.last_seen_at')                    // never seen
                        ->orWhere('d.last_seen_at', '<', $deviceOfflineBefore); // offline
                })
                ->get(['j.id', 'j.type', 'j.target_printer', 'j.device_uid']);
            if ($stranded->isNotEmpty()) {
                $defaultReceipt = $company->printerSettings()['receipt_printer'] ?? null;
                // Task 1194 — KOT-family jobs are stamped for the counter that
                // OWNS the chosen printer, so blind-unstamping is wrong: an
                // agent whose PC doesn't have that printer would claim the job
                // and fail (and with several such agents it would bounce).
                // Retarget only when another ONLINE counter reports the SAME
                // printer name (LAN/shared printer) — re-stamp to that counter.
                // Nobody else has it → park as failed so it surfaces on the
                // recent-failed strip instead of sitting stranded forever.
                $onlineDevices = collect();
                try {
                    $onlineDevices = \App\Models\PosAgentDevice::where('company_id', $company->id)
                        ->where('last_seen_at', '>=', $deviceOfflineBefore)
                        ->get();
                } catch (\Throwable $e) { /* registry hiccup → treat as none online */ }
                foreach ($stranded as $row) {
                    // Task 1285: fbr_kot (FBR store slips) joins the KOT family —
                    // same printer-owning-counter semantics as PRA KOTs.
                    if (in_array($row->type, ['kot', 'kot_void', 'fbr_kot'], true)) {
                        $carrier = $onlineDevices->first(function ($d) use ($row) {
                            return $d->device_uid !== $row->device_uid
                                && collect($d->printers ?? [])->pluck('name')->contains($row->target_printer);
                        });
                        if ($carrier) {
                            DB::table('pos_print_jobs')->where('id', $row->id)
                                ->update(['device_uid' => $carrier->device_uid, 'updated_at' => now()]);
                        } else {
                            DB::table('pos_print_jobs')->where('id', $row->id)->update([
                                'status' => 'failed',
                                'error' => 'Counter owning printer "' . $row->target_printer . '" is offline and no other online counter reports this printer.',
                                'updated_at' => now(),
                            ]);
                        }
                        continue;
                    }
                    // A test slip PRINTS THE QUEUE IT WAS SENT TO — moving one
                    // to another printer would hand the shop a slip that lies
                    // about which queue produced the paper, which is the exact
                    // confusion the test print exists to end. Stranded test =
                    // failed; the shop presses Test again on a live counter.
                    if ($row->type === 'test') {
                        DB::table('pos_print_jobs')->where('id', $row->id)->update([
                            'status' => 'failed',
                            'error' => 'Counter went offline before the test slip printed — run Test Print again from the counter that is switched on.',
                            'updated_at' => now(),
                        ]);
                        continue;
                    }
                    $upd = ['device_uid' => null, 'updated_at' => now()];
                    // Task 1285: fbr_bill retargets to the company default receipt
                    // printer exactly like PRA bills — the per-device printer may
                    // not exist on the rescuing PC.
                    if ($defaultReceipt && in_array($row->type, ['bill', 'proof', 'fbr_bill'], true)) {
                        $upd['target_printer'] = $defaultReceipt;
                    }
                    DB::table('pos_print_jobs')->where('id', $row->id)->update($upd);
                }
                Log::info('PRINT_ROUTING stranded stamped jobs rescued to company scope', [
                    'company_id' => $company->id, 'count' => $stranded->count(),
                ]);
            }
        }

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

        // Test slip: prints the QUEUE'S OWN NAME. A shop whose Windows carries
        // several queues for one physical printer ("XP-80C" vs "XP-80C (copy 2)")
        // cannot otherwise tell which one is still wired to the device — the
        // dead queue swallows jobs and still reports success.
        if ($job->type === 'test') {
            $this->setPrintLocale(null, $job, $company);
            $requestedBy = null;
            try {
                $requestedBy = $job->created_by ? (\App\Models\User::find($job->created_by)?->name) : null;
            } catch (\Throwable $e) { /* name is decoration — never block the slip */ }
            return response(view('pos.receipts.test-slip', [
                'company' => $company,
                'printerName' => $job->target_printer,
                'printedAt' => now()->format('d/m/Y h:i A'),
                'requestedBy' => $requestedBy,
            ])->render())->header('Content-Type', 'text/html; charset=UTF-8');
        }

        if ($job->type === 'bill') {
            $transaction = \App\Models\PosTransaction::withoutGlobalScope('hide_archived')
                ->where('company_id', $company->id)
                ->with(['items', 'payments', 'creator', 'terminal', 'rider'])
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
            // Task 778: KOT-split rows (qty-aware carry) must merge back into
            // one customer line per dish — same rule as the iframe proof route.
            $order->setRelation('items', \App\Services\PosBillLineConsolidator::consolidate($order->items));
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
            // Delta snapshot (Pizza Master edit-path bug, Aug 2026): delta jobs
            // now BAKE their unprinted row ids at enqueue (printed_item_ids).
            // Without it, the FIRST job's result-time stamping emptied every
            // LATER overlapping delta job in the same kitchen-send — the counter
            // KOT copy rendered after the kitchen ticket printed found zero
            // whereNull rows → 204 → no slip at the counter. Baked ids keep all
            // copies of one send identical; legacy/blank jobs fall back to the
            // old whereNull resolution.
            $baked = ($delta && is_array($job->printed_item_ids) && count($job->printed_item_ids))
                ? array_map('intval', $job->printed_item_ids)
                : null;
            $unprinted = $baked !== null
                ? $order->items->whereIn('id', $baked)->values()
                : $order->items->whereNull('kot_printed_at');
            // Task 753 MISSED-DELTA RECOVERY: render_query batch=last — mirror
            // of the kitchenTicket route. LAST printed batch's rows (+ any rows
            // still unprinted) as one clean delta-style ticket. Result-time
            // stamping below stays whereNull-guarded, so already-printed rows
            // are never renumbered; only genuinely-unprinted rows get stamped.
            $batchLast = ($q['batch'] ?? null) === 'last';
            if ($batchLast) {
                $delta = true;
                $maxBatch = (int) $order->items->max('kot_batch_no');
                $ticketItems = $order->items
                    ->filter(fn ($i) => $i->kot_printed_at === null || ($maxBatch > 0 && (int) $i->kot_batch_no === $maxBatch))
                    ->values();
                if ($ticketItems->isEmpty()) {
                    $ticketItems = $order->items;
                }
                $newItemIds = collect();
            } elseif ($delta && $fullMode && $unprinted->isNotEmpty()) {
                $ticketItems = $order->items;
                $newItemIds = $unprinted->pluck('id');
            } else {
                $ticketItems = $delta ? $unprinted->values() : $order->items;
                $newItemIds = $fullMode ? $unprinted->pluck('id') : collect();
            }

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

        // Task 794 — VOID / CANCEL slip: dishes removed from a running order
        // after their KOT fired. Void items ride in render_query as JSON
        // (kot_void jobs never carry a station query — the split already
        // happened at enqueue, one job per station). Same kitchen-ticket view
        // in void mode as the iframe route (RestaurantPosController::voidTicket).
        if ($job->type === 'kot_void') {
            $order = \App\Models\RestaurantOrder::where('company_id', $company->id)
                ->with(['table', 'creator'])
                ->find($job->restaurant_order_id);
            if (!$order) {
                return response('', 204); // order gone — nothing to void-print
            }
            $this->setPrintLocale($order->creator?->language, $job, $company);
            $voidItems = collect();
            if ($job->render_query) {
                $decoded = json_decode($job->render_query, true);
                if (is_array($decoded)) {
                    $voidItems = collect($decoded);
                }
            }
            if ($voidItems->isEmpty()) {
                return response('', 204); // no payload — never print a blank slip
            }
            return response(view('pos.restaurant.kitchen-ticket', [
                'order'        => $order,
                'company'      => $company,
                'void'         => true,
                'voidItems'    => $voidItems,
                'ticketItems'  => collect(),
                'grouped'      => collect(),
                'stationLabel' => null,
                'delta'        => false,
                'kotBatchNo'   => null,
                'newItemIds'   => collect(),
            ])->render())->header('Content-Type', 'text/html; charset=UTF-8');
        }

        // ═══ Task 1263 — FBR POS silent printing (same agent, fiscal_device shops) ═══
        // FBR bill receipt: ONE template handles both paper widths (branches on
        // company print_paper_size internally). No fbrPlanGate here — the agent
        // route carries no web session; the job was already gated at enqueue.
        if ($job->type === 'fbr_bill') {
            $transaction = \App\Models\FbrPosTransaction::where('company_id', $company->id)
                ->with(['items', 'creator'])
                ->find($job->transaction_id);
            if (!$transaction) {
                return response()->json(['error' => 'Transaction not found'], 404);
            }
            $this->setPrintLocale($transaction->creator?->language, $job, $company);
            return response(view('fbr-pos.receipt', compact('transaction', 'company'))->render())
                ->header('Content-Type', 'text/html; charset=UTF-8');
        }

        // FBR KOT: restaurant_order_id carries an FbrPosHeldSale id (pre-pay
        // Send-to-Kitchen ticket — FBR holds are JSON carts, no RestaurantOrder
        // rows); transaction_id = post-pay reprint. Mirrors kotTicket/kotReprint.
        if ($job->type === 'fbr_kot') {
            // Task 1403: the Store Slip switch is re-asked at RENDER time, not
            // trusted from queue time. The agent carries no session, so the
            // controller's auth-based gate would pass here — a slip queued a
            // second before the owner switched the feature off (or before a
            // downgrade) must still not print. 204 = nothing to print, the
            // agent marks the job done instead of retrying forever.
            if (!\App\Services\PosFeatureService::fbrStoreSlipOn($company)) {
                return response('', 204);
            }
            $notesOn = \App\Services\PosFeatureService::fbrStoreNotesOn($company);
            $this->setPrintLocale(null, $job, $company);
            if ($job->restaurant_order_id) {
                $held = \App\Models\FbrPosHeldSale::where('company_id', $company->id)
                    ->find($job->restaurant_order_id);
                if (!$held) {
                    return response('', 204); // hold recalled/billed — nothing to print
                }
                $cartData  = $held->cart_data ?? [];
                $items     = is_array($cartData['items'] ?? null) ? $cartData['items'] : [];
                if (!$notesOn) {
                    $items = array_map(function ($it) {
                        if (is_array($it)) { unset($it['special_notes']); }
                        return $it;
                    }, $items);
                }
                $tokenNo   = isset($held->token_no)   ? (int)  $held->token_no   : (isset($cartData['token_no'])   ? (int)  $cartData['token_no']   : null);
                $orderCode = isset($held->order_code) ? (string) $held->order_code : (isset($cartData['order_code']) ? (string) $cartData['order_code'] : null);
                $customerName = $held->customer_name ?? ($cartData['customer_name'] ?? null);
                $kitchenNotes = $cartData['kitchen_notes'] ?? null;
                $now = now();
                $autoPrint = false; // agent prints natively — no window.print() script needed
                return response(view('fbr-pos.kitchen-ticket', compact(
                    'company', 'held', 'items', 'tokenNo', 'orderCode',
                    'customerName', 'kitchenNotes', 'now', 'autoPrint'
                ))->render())->header('Content-Type', 'text/html; charset=UTF-8');
            }
            $transaction = \App\Models\FbrPosTransaction::where('company_id', $company->id)
                ->with(['items', 'creator'])
                ->find($job->transaction_id);
            if (!$transaction) {
                return response()->json(['error' => 'Transaction not found'], 404);
            }
            $this->setPrintLocale($transaction->creator?->language, $job, $company);
            $tokenNo   = \Illuminate\Support\Facades\Schema::hasColumn('fbr_pos_transactions', 'token_no')
                ? ($transaction->token_no ? (int) $transaction->token_no : null)
                : null;
            $orderCode = \Illuminate\Support\Facades\Schema::hasColumn('fbr_pos_transactions', 'order_code')
                ? ($transaction->order_code ?: null)
                : null;
            // Task 1403: the note used to be hardcoded null here, so a silently
            // reprinted slip lost every note the cashier typed (including a deal
            // note, which store() parks on the combo's first component row).
            $hasNotes = $notesOn
                && \Illuminate\Support\Facades\Schema::hasColumn('fbr_pos_transaction_items', 'special_notes');
            $items = $transaction->items->map(function ($it) use ($hasNotes) {
                return [
                    'item_name'     => $it->item_name,
                    'quantity'      => (float) $it->quantity,
                    'special_notes' => $hasNotes ? ($it->special_notes ?: null) : null,
                ];
            })->all();
            $customerName = $transaction->customer_name;
            $kitchenNotes = null;
            $now = $transaction->created_at ?? now();
            $held = null; // not a held sale — template branches on this
            $autoPrint = false;
            return response(view('fbr-pos.kitchen-ticket', compact(
                'company', 'held', 'items', 'tokenNo', 'orderCode',
                'customerName', 'kitchenNotes', 'now', 'autoPrint'
            ))->render())->header('Content-Type', 'text/html; charset=UTF-8');
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

        // Task 1166: result reports also carry the device identity (throttled
        // last-seen beat — keeps the counter visibly online while printing).
        $this->syncAgentDevice($company, $request);

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

    /**
     * POST /api/agent/caller-events — LAN Mode ring replay.
     *
     * Internet down: the Caller ID phone posts its rings to the agent's LAN
     * server, which shows them on the counter PC. This endpoint is where those
     * rings land once the line is back, purely for HISTORY — the bell list,
     * customer matching and call-back marks.
     *
     * Two rules make it safe to call repeatedly:
     *  1. offline_uuid is the ring's identity, so a retried batch stores nothing
     *     new (the agent only clears its buffer once we acknowledge).
     *  2. A replayed ring is stamped with the time it ACTUALLY rang, not now.
     *     The sale-screen poll only surfaces rings from the last two minutes, so
     *     a reconnect can never make an hour-old call pop up on the counter.
     */
    public function callerEvents(Request $request)
    {
        $company = $request->attributes->get('agent_company');

        $validated = $request->validate([
            'events' => 'required|array|max:200',
            'events.*.uuid' => 'required|string|max:64',
            'events.*.number' => 'nullable|string|max:40',
            'events.*.phone' => 'nullable|string|max:40',
            'events.*.name' => 'nullable|string|max:120',
            'events.*.source' => 'nullable|string|in:sim,whatsapp',
            'events.*.at' => 'nullable|numeric',
        ]);

        // The agent must always learn which uuids it may drop, otherwise its
        // buffer grows forever on a shop whose Caller ID is switched off.
        $accepted = collect($validated['events'])->pluck('uuid')
            ->filter()->map(fn ($u) => (string) $u)->unique()->values()->all();

        $ready = \Illuminate\Support\Facades\Schema::hasTable('pos_caller_events')
            && \Illuminate\Support\Facades\Schema::hasColumn('pos_caller_events', 'offline_uuid');
        if (!$ready || !\App\Services\PosFeatureService::callerIdLive($company)) {
            return response()->json([
                'ok' => true,
                'accepted' => $accepted,
                'stored' => 0,
                'reason' => $ready ? 'disabled' : 'unsupported',
            ]);
        }

        $hasCleared = \Illuminate\Support\Facades\Schema::hasColumn('pos_caller_events', 'cleared_at');
        $stored = 0;

        foreach ($validated['events'] as $event) {
            $uuid = trim((string) ($event['uuid'] ?? ''));
            if ($uuid === '') {
                continue;
            }

            $phone = \App\Services\PkPhone::normalize($event['phone'] ?? $event['number'] ?? null);
            $name = trim((string) ($event['name'] ?? ''));
            $name = $name !== '' ? mb_substr($name, 0, 120) : null;
            if (!$phone && !$name) {
                continue; // nothing to show a cashier
            }

            // Ring time: epoch ms from the phone → app TZ (the rider-app 5h
            // trap). A wrong device clock must not post-date the row, and a
            // week-old ring is history nobody needs.
            $ringAt = now();
            if (!empty($event['at'])) {
                try {
                    $candidate = \Carbon\Carbon::createFromTimestampMs((float) $event['at'])
                        ->setTimezone(config('app.timezone'));
                    if ($candidate->lt(now()) && $candidate->gt(now()->subDays(7))) {
                        $ringAt = $candidate;
                    }
                } catch (\Throwable $e) {
                    // keep now()
                }
            }

            // Same ring, second delivery: the unique index would refuse it, but
            // checking first keeps the response clean for the agent.
            $exists = DB::table('pos_caller_events')
                ->where('company_id', $company->id)
                ->where('offline_uuid', $uuid)
                ->exists();
            if ($exists) {
                continue;
            }

            // The phone may have reached BOTH lanes during a flaky patch —
            // collapse a cloud ring and its LAN twin the same way repeated
            // rings collapse at ingest.
            $twin = DB::table('pos_caller_events')
                ->where('company_id', $company->id)
                ->whereBetween('ring_at', [$ringAt->copy()->subSeconds(20), $ringAt->copy()->addSeconds(20)])
                ->when($phone, fn ($q) => $q->where('phone', $phone))
                ->when(!$phone, fn ($q) => $q->whereNull('phone')->where('caller_name', $name))
                ->exists();
            if ($twin) {
                continue;
            }

            try {
                DB::table('pos_caller_events')->insert(array_merge([
                    'company_id' => $company->id,
                    'offline_uuid' => $uuid,
                    'phone' => $phone,
                    'caller_name' => $name,
                    'source' => ($event['source'] ?? 'sim') === 'whatsapp' ? 'whatsapp' : 'sim',
                    'ring_at' => $ringAt,
                    // Deliberately the ring time, NOT now(): keeps old calls out
                    // of the fresh-popup window on the counter.
                    'created_at' => $ringAt,
                ], $hasCleared ? ['cleared_at' => null] : []));
                $stored++;
            } catch (\Throwable $e) {
                // Unique index race with a parallel batch = already stored.
                Log::debug('caller replay skipped: ' . $e->getMessage());
            }
        }

        return response()->json(['ok' => true, 'accepted' => $accepted, 'stored' => $stored]);
    }
}
