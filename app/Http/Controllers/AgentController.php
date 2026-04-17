<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Company;

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

        $pending = DB::table('pos_transactions')
            ->where('company_id', $company->id)
            ->whereIn('pra_status', ['offline', 'pending', 'failed'])
            ->whereNull('pra_invoice_number')
            ->orderBy('id', 'asc')
            ->limit(20)
            ->get([
                'id',
                'invoice_number',
                'pra_payload',
                'pra_status',
                'created_at',
            ]);

        $invoices = $pending->map(function ($t) {
            return [
                'transaction_id' => $t->id,
                'invoice_number' => $t->invoice_number,
                'payload' => is_string($t->pra_payload) ? json_decode($t->pra_payload, true) : $t->pra_payload,
                'created_at' => $t->created_at,
            ];
        });

        return response()->json([
            'count' => $invoices->count(),
            'invoices' => $invoices,
            'pra_endpoint' => $company->pra_environment === 'production'
                ? 'https://ims.pral.com.pk/ims/production/api/Live/PostData'
                : 'https://gw.fbr.gov.pk/imsp/v1/api/Live/PostData',
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
                'pra_submitted_at' => now(),
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
