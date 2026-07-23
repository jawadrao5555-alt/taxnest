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
     * Self-update advertisement for v1.3.0+ agents, piggybacked on the
     * heartbeat response. Reuses the cached GitHub latest-release info so
     * agents never hit api.github.com directly (shared-ISP rate limits).
     * Only tags that look like an AGENT semver (major <= 99) are advertised —
     * date-style tags like v2026.2.0 are ignored so a mis-tagged release can
     * never trigger a downgrade/update loop.
     */
    private function agentUpdateInfo(): ?array
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

        $company->update([
            'agent_last_seen' => now(),
            'agent_version' => $request->input('version', $company->agent_version),
        ]);

        // ===== FBR POS Fiscal Device company =====
        if ($company->agentServesFbr()) {
            return $this->fbrHeartbeat($company);
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
            'agent_update' => $this->agentUpdateInfo(),
        ]);
    }

    /** FBR POS equivalent of the PRA self-heal sweep, operating on fbr_pos_transactions. */
    private function fbrHeartbeat(Company $company)
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
            'agent_update' => $this->agentUpdateInfo(),
        ]);
    }

    public function pendingInvoices(Request $request)
    {
        $company = $request->attributes->get('agent_company');

        $company->update(['agent_last_seen' => now()]);

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

        $company->update(['agent_last_seen' => now()]);

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

        $company->update(['agent_last_seen' => now()]);

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

        $company->update([
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
        DB::table('pos_print_jobs')
            ->where('company_id', $company->id)
            ->whereIn('status', ['done', 'failed'])
            ->where('updated_at', '<', now()->subDays(7))
            ->delete();

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

        return response()->json(['ok' => true, 'jobs' => $jobs, 'count' => $jobs->count()]);
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
            $printerSize = $company->receipt_printer_size ?? '80mm';
            $receiptView = $printerSize === '58mm' ? 'pos.receipts.receipt_58mm' : 'pos.receipts.receipt_80mm';
            return response(view($receiptView, compact('transaction', 'company'))->render())
                ->header('Content-Type', 'text/html; charset=UTF-8');
        }

        if ($job->type === 'kot') {
            $order = \App\Models\RestaurantOrder::where('company_id', $company->id)
                ->with(['items', 'table', 'creator'])
                ->find($job->restaurant_order_id);
            if (!$order) {
                return response()->json(['error' => 'Order not found'], 404);
            }
            parse_str($job->render_query ?? '', $q);
            $delta = ($q['delta'] ?? null) == '1';
            $ticketItems = $delta ? $order->items->whereNull('kot_printed_at')->values() : $order->items;

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

            // DON'T stamp kot_printed_at here — the physical print can still
            // fail after render (printer off, driver error). We record which
            // items this ticket carries and stamp them only when the agent
            // reports success in printJobResult. A failed job re-renders the
            // SAME items (still NULL), so retries print identical content.
            $job->update(['printed_item_ids' => $ticketItems->pluck('id')->values()->all()]);

            return response(view('pos.restaurant.kitchen-ticket', compact('order', 'company', 'ticketItems', 'delta', 'kotBatchNo', 'grouped', 'stationLabel'))->render())
                ->header('Content-Type', 'text/html; charset=UTF-8');
        }

        return response()->json(['error' => 'Unknown job type'], 422);
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
        // delta cycle (or a retry) naturally re-prints them.
        if ($validated['success'] && $job->type === 'kot' && !empty($job->printed_item_ids)) {
            \App\Models\RestaurantOrderItem::whereIn('id', $job->printed_item_ids)
                ->where('order_id', $job->restaurant_order_id)
                ->whereNull('kot_printed_at')
                ->update(['kot_printed_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }
}
