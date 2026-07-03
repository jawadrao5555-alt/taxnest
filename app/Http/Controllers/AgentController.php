<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Company;
use App\Models\PosTransaction;
use App\Services\PraIntegrationService;

class AgentController extends Controller
{
    public function heartbeat(Request $request)
    {
        $company = $request->attributes->get('agent_company');

        $company->update([
            'agent_last_seen' => now(),
            'agent_version' => $request->input('version', $company->agent_version),
        ]);

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

    public function pendingInvoices(Request $request)
    {
        $company = $request->attributes->get('agent_company');

        $company->update(['agent_last_seen' => now()]);

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
}
