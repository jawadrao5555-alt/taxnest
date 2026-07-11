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

            DB::table('pos_transactions')->where('id', $txnId)->update([
                'pra_status' => 'failed',
                'pra_response_code' => substr($errMsg, 0, 250),
                'updated_at' => now(),
            ]);

            Log::warning('Agent: PRA submission failed', [
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

            $txn->update([
                'fbr_status' => 'failed',
                'fbr_response_code' => substr($errMsg, 0, 250),
                'fbr_submission_hash' => null,
            ]);

            Log::warning('Agent: FBR submission failed', [
                'company_id' => $company->id,
                'transaction_id' => $txnId,
                'error' => $errMsg,
                'response' => $request->input('response'),
            ]);
        }

        $company->update(['agent_last_seen' => now()]);

        return response()->json(['ok' => true]);
    }
}
