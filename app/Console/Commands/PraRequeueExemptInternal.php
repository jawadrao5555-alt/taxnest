<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time admin tool: re-queue historical pra_status='exempt_internal' bills
 * as 'pending' so the Desktop Agent can submit them to PRA at TaxRate 0.
 *
 * Background (Task 760 / Task 780, Aug 2026):
 *   Before Task 760, all-exempt (bottle) bills were stamped 'exempt_internal'
 *   and never submitted to PRA. After Task 760 exempt items are zero-rated at
 *   PRA — they CAN now be submitted with TaxRate 0 / TaxCharged 0 and will
 *   verify in the Sahulat app with "Total Tax Charged: 0.00".
 *
 *   The 6 historical ZFC (company 28) bills were already stamped
 *   'exempt_internal' before the fix landed, so they are permanently blocked by
 *   the sendInvoice guard. This command is the ONLY escape hatch — it is a
 *   targeted, opt-in, owner-only operation (never called by the app itself).
 *
 * Usage:
 *   php artisan pra:requeue-exempt-internal            # list all exempt_internal bills
 *   php artisan pra:requeue-exempt-internal --company=28 --ids=101,102,103 --confirm
 */
class PraRequeueExemptInternal extends Command
{
    protected $signature = 'pra:requeue-exempt-internal
                            {--company= : Company ID to inspect (optional; shows all companies if omitted)}
                            {--ids=     : Comma-separated pos_transaction IDs to re-queue (optional; re-queues ALL matching rows if omitted)}
                            {--confirm  : Actually apply the update; without this flag the command is a dry-run}';

    protected $description = 'Re-queue historical exempt_internal PRA bills as pending so the Desktop Agent can submit them (dry-run unless --confirm)';

    public function handle(): int
    {
        $companyId = $this->option('company') ? (int) $this->option('company') : null;
        $idsRaw    = $this->option('ids');
        $confirm   = $this->option('confirm');

        // ── Build base query ────────────────────────────────────────────────
        $query = DB::table('pos_transactions')
            ->join('companies', 'companies.id', '=', 'pos_transactions.company_id')
            ->where('pos_transactions.pra_status', 'exempt_internal')
            ->whereNull('pos_transactions.pra_invoice_number')
            ->select(
                'pos_transactions.id',
                'pos_transactions.company_id',
                'companies.name as company_name',
                'pos_transactions.invoice_number',
                'pos_transactions.total_amount',
                'pos_transactions.created_at',
                'pos_transactions.pra_status',
            )
            ->orderBy('pos_transactions.company_id')
            ->orderBy('pos_transactions.id');

        if ($companyId) {
            $query->where('pos_transactions.company_id', $companyId);
        }

        $ids = [];
        if ($idsRaw) {
            $ids = array_filter(array_map('intval', explode(',', $idsRaw)));
            if (!empty($ids)) {
                $query->whereIn('pos_transactions.id', $ids);
            }
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            $this->info('No exempt_internal bills found matching the given criteria.');
            return 0;
        }

        // ── Display ─────────────────────────────────────────────────────────
        $this->newLine();
        $this->line('<fg=yellow>Historical exempt_internal bills (never submitted to PRA):</>');
        $this->newLine();

        $headers = ['ID', 'Company', 'Invoice #', 'Total (Rs)', 'Created At', 'Status'];
        $tableRows = $rows->map(fn($r) => [
            $r->id,
            "[{$r->company_id}] {$r->company_name}",
            $r->invoice_number ?: '—',
            number_format((float) $r->total_amount, 2),
            $r->created_at,
            $r->pra_status,
        ])->toArray();

        $this->table($headers, $tableRows);

        $count = $rows->count();
        $this->newLine();

        if (!$confirm) {
            $this->warn("DRY RUN — {$count} bill(s) would be set to pra_status='pending'.");
            $this->line('Re-run with <fg=green>--confirm</> to actually apply the change.');
            $this->newLine();
            $this->line('Example:');
            $idList = $rows->pluck('id')->implode(',');
            $co = $companyId ?? $rows->first()->company_id;
            $this->line("  php artisan pra:requeue-exempt-internal --company={$co} --ids={$idList} --confirm");
            return 0;
        }

        // ── Confirm ─────────────────────────────────────────────────────────
        if (!$this->confirm("Re-queue {$count} bill(s) as 'pending'? The Desktop Agent will submit them at TaxRate 0 (zero-rated, no tax charged). This cannot be undone automatically.")) {
            $this->line('Aborted — no changes made.');
            return 0;
        }

        // ── Apply ────────────────────────────────────────────────────────────
        $updateIds = $rows->pluck('id')->toArray();

        $updated = DB::table('pos_transactions')
            ->whereIn('id', $updateIds)
            ->where('pra_status', 'exempt_internal')   // safety: never touch any other status
            ->whereNull('pra_invoice_number')           // safety: never overwrite a submitted row
            ->update([
                'pra_status' => 'pending',
                'updated_at' => now(),
            ]);

        $this->newLine();
        $this->info("Done — {$updated} bill(s) set to pra_status='pending'.");
        $this->line('The Desktop Agent will pick them up on its next poll and submit to PRA at TaxRate 0.');
        $this->line('Once submitted, the Sahulat app will show each bill with "Total Tax Charged: 0.00".');
        $this->newLine();

        return 0;
    }
}
