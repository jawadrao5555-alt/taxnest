<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\PosTransaction;
use App\Services\PraIntegrationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncPosOfflineInvoicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $transactions = PosTransaction::whereIn('pra_status', ['offline', 'pending'])
            ->where('status', 'completed')
            ->whereNull('pra_invoice_number')
            ->with('company')
            ->orderBy('created_at', 'asc')
            ->limit(50)
            ->get();

        foreach ($transactions as $transaction) {
            $company = $transaction->company;

            // Per-cashier toggle (owner rule Jul 2026): offline bills were queued by a
            // user whose own switch was ON — sync them if reporting is active for ANY
            // account of the company (company flag OR any user's personal toggle).
            if (!$company || !$company->praReportingActive()) {
                continue;
            }

            // Agent-first: when the shop's desktop agent handles PRA submission AND is
            // alive, leave submission to it — avoids a concurrent double-submit window
            // between this job's direct cloud POST and the agent (both read
            // pra_invoice_number non-atomically). Stale/absent agent → job still rescues
            // the bills. Direct Production shops (agent connected only for silent
            // printing) are ALWAYS rescued here — the agent no longer submits for them.
            if ($company->agentHandlesPra()
                && $company->agent_last_seen
                && $company->agent_last_seen->gt(now()->subMinutes(10))) {
                continue;
            }

            if ($transaction->pra_invoice_number) {
                continue;
            }

            try {
                $praService = new PraIntegrationService($company);
                $result = $praService->sendInvoice($transaction);

                if ($result['success']) {
                    Log::info('POS Auto-Sync: Invoice synced to PRA', [
                        'transaction_id' => $transaction->id,
                        'invoice_number' => $transaction->invoice_number,
                        'pra_invoice_number' => $result['pra_invoice_number'] ?? null,
                    ]);
                } else {
                    Log::warning('POS Auto-Sync: PRA submission failed', [
                        'transaction_id' => $transaction->id,
                        'invoice_number' => $transaction->invoice_number,
                        'message' => $result['message'] ?? 'Unknown error',
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('POS Auto-Sync: Exception during sync', [
                    'transaction_id' => $transaction->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
