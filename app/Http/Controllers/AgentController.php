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

        return response()->json([
            'ok' => true,
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'pra_pos_id' => $company->pra_pos_id,
                'pra_environment' => $company->pra_environment,
            ],
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

        $praService = app(PraIntegrationService::class);

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

        return response()->json([
            'count' => count($invoices),
            'invoices' => $invoices,
            'pra_endpoint' => $company->pra_environment === 'production'
                ? 'https://ims.pral.com.pk/ims/production/api/Live/PostData'
                : 'https://ims.pral.com.pk/ims/sandbox/api/Live/PostData',
            'pra_token' => $company->pra_production_token,
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

        if ($request->boolean('success')) {
            DB::table('pos_transactions')->where('id', $txnId)->update([
                'pra_status' => 'submitted',
                'pra_invoice_number' => $request->input('pra_invoice_number'),
                'pra_response' => json_encode($request->input('response')),
                'updated_at' => now(),
            ]);

            Log::info('Agent: PRA submission success', [
                'company_id' => $company->id,
                'transaction_id' => $txnId,
                'pra_invoice' => $request->input('pra_invoice_number'),
            ]);
        } else {
            DB::table('pos_transactions')->where('id', $txnId)->update([
                'pra_status' => 'failed',
                'pra_response' => json_encode([
                    'error' => $request->input('error'),
                    'response' => $request->input('response'),
                ]),
                'updated_at' => now(),
            ]);

            Log::warning('Agent: PRA submission failed', [
                'company_id' => $company->id,
                'transaction_id' => $txnId,
                'error' => $request->input('error'),
            ]);
        }

        $company->update(['agent_last_seen' => now()]);

        return response()->json(['ok' => true]);
    }
}
