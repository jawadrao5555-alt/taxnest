<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\InvoiceNumberingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verify (and safely heal) per-company DI invoice serial counters.
 *
 * READ-ONLY for invoices: this command NEVER touches the invoices table.
 * It only reports each company's counter and — unless --check is given —
 * raises any counter that is behind (invoice count + 1), exactly like the
 * init migration. Running it repeatedly is harmless (idempotent).
 */
class VerifyDiSerials extends Command
{
    protected $signature = 'di:serials
        {--check : Report only, change nothing}
        {--renumber-drafts : Renumber DRAFT DI invoices (never FBR-submitted) to the new per-company serial format}';

    protected $description = 'Verify and heal per-company serial counters for DI, PRA POS and FBR POS (never modifies invoices/bills)';

    public function handle(): int
    {
        if (!Schema::hasColumn('companies', 'next_invoice_number')) {
            $this->error('companies.next_invoice_number column is MISSING — run: php artisan migrate --force');
            return self::FAILURE;
        }

        $this->line('');
        $this->info('=== DIGITAL INVOICE (DI) — per-company counters ===');

        $checkOnly = (bool) $this->option('check');
        $rows = [];
        $fixed = 0;

        $companies = Company::withTrashed()->orderBy('id')->get();

        foreach ($companies as $company) {
            $invoiceCount = DB::table('invoices')->where('company_id', $company->id)->count();
            $expectedMin = $invoiceCount + 1;
            $current = max(1, (int) ($company->next_invoice_number ?? 1));

            $status = 'OK';
            if ($current < $expectedMin) {
                if ($checkOnly) {
                    $status = "BEHIND (should be >= {$expectedMin})";
                } else {
                    $company->next_invoice_number = $expectedMin;
                    $company->save();
                    $current = $expectedMin;
                    $status = "FIXED ({$expectedMin})";
                    $fixed++;
                }
            }

            $rows[] = [
                $company->id,
                mb_strimwidth($company->name ?? '', 0, 30, '…'),
                $invoiceCount,
                $current,
                InvoiceNumberingService::peekNextNumber($company->id),
                $status,
            ];
        }

        $this->table(
            ['ID', 'Company', 'Invoices', 'Next Seq', 'Next Serial', 'Status'],
            $rows
        );

        $hasUnique = false;
        if (DB::connection()->getDriverName() === 'mysql') {
            foreach (DB::select("SHOW INDEX FROM invoices WHERE Non_unique = 0") as $idx) {
                if ($idx->Key_name === 'invoices_company_invoice_unique') {
                    $hasUnique = true;
                    break;
                }
            }
            $this->line('Safety index (company_id, invoice_number): ' . ($hasUnique ? 'PRESENT' : 'missing (service-level check still protects new numbers)'));
        }

        if ($checkOnly) {
            $this->info('Check-only mode: nothing was changed.');
        } elseif ($fixed > 0) {
            $this->info("Fixed {$fixed} counter(s). Invoices data untouched.");
        } else {
            $this->info('All counters already correct. Nothing changed. Invoices data untouched.');
        }

        if ($this->option('renumber-drafts')) {
            $this->renumberDrafts($companies, $checkOnly);
        }

        $this->reportPosSerials(
            'PRA POS',
            'pos_transactions',
            $companies,
            ['POS-' . now()->format('Y') . '-', 5],
            ['L-', 3]
        );

        $this->reportPosSerials(
            'FBR POS',
            'fbr_pos_transactions',
            $companies,
            ['FPOS-' . now()->format('Y') . '-', 5],
            ['FLOCAL-' . now()->format('Y') . '-', 5]
        );

        $this->line('');
        $this->info('POS serials are self-deriving (next = company\'s own last bill + 1, always company-scoped) — nothing to fix, table above is verification only.');

        return self::SUCCESS;
    }

    /**
     * Renumber DRAFT invoices that were created with the old universal/legacy
     * formats onto the new per-company serial format.
     *
     * SAFETY GATES — an invoice is renumbered ONLY if ALL of these hold:
     *   - status = 'draft'                       (never locked/finalized)
     *   - fbr_invoice_number IS NULL             (FBR never accepted it)
     *   - submitted_at IS NULL                   (never marked submitted)
     *   - is_fbr_processing = 0                  (not in the FBR queue)
     *   - number is not already new-format (ends in DI + exactly 5 digits;
     *     legacy timestamp format ends in DI + 13 digits, so no overlap)
     *
     * FBR-submitted / locked invoices are NEVER touched: their numbers are on
     * FBR's official record and must stay identical forever.
     *
     * With --check the section only PREVIEWS what would change.
     */
    private function renumberDrafts($companies, bool $checkOnly): void
    {
        $this->line('');
        $this->info('=== DI DRAFT RENUMBERING (old universal serials → new per-company serials) ===');

        $totalChanged = 0;

        foreach ($companies as $company) {
            $drafts = DB::table('invoices')
                ->where('company_id', $company->id)
                ->where('status', 'draft')
                ->whereNull('fbr_invoice_number')
                ->whereNull('submitted_at')
                ->whereNull('integrity_hash')
                ->where(function ($q) {
                    $q->whereNull('is_fbr_processing')->orWhere('is_fbr_processing', 0);
                })
                ->orderBy('id')
                ->get(['id', 'invoice_number']);

            foreach ($drafts as $draft) {
                if (preg_match('/DI\d{5}$/', (string) $draft->invoice_number)) {
                    continue; // already in the new per-company format
                }

                if ($checkOnly) {
                    $this->line("  [preview] C{$company->id} #{$draft->id}: {$draft->invoice_number} → (next per-company serial)");
                    $totalChanged++;
                    continue;
                }

                $newNumber = InvoiceNumberingService::generateNextNumber($company->id);

                // Re-assert every safety gate in the UPDATE itself: if the
                // invoice was locked / queued for FBR between our SELECT and
                // now, affected rows = 0 and we skip it (the unused serial is
                // harmlessly absorbed by the service's uniqueness loop).
                $updated = DB::table('invoices')
                    ->where('id', $draft->id)
                    ->where('status', 'draft')
                    ->whereNull('fbr_invoice_number')
                    ->whereNull('submitted_at')
                    ->whereNull('integrity_hash')
                    ->where(function ($q) {
                        $q->whereNull('is_fbr_processing')->orWhere('is_fbr_processing', 0);
                    })
                    ->update([
                        'invoice_number' => $newNumber,
                        'internal_invoice_number' => $newNumber,
                        'updated_at' => now(),
                    ]);

                if ($updated === 0) {
                    $this->warn("  SKIPPED C{$company->id} #{$draft->id}: state changed while running (locked/submitted) — left untouched.");
                    continue;
                }

                $this->line("  C{$company->id} #{$draft->id}: {$draft->invoice_number} → {$newNumber}");
                $totalChanged++;
            }
        }

        if ($totalChanged === 0) {
            $this->info('No old-format drafts found — nothing to renumber.');
        } elseif ($checkOnly) {
            $this->info("{$totalChanged} draft(s) WOULD be renumbered. Run without --check (keep --renumber-drafts) to apply.");
        } else {
            $this->info("Renumbered {$totalChanged} draft(s). Locked/FBR-submitted invoices untouched.");
        }
    }

    /**
     * POS serials (PRA + FBR) are derived at sale time from the company's OWN
     * last bill number — there is no counter column, so this section is a
     * pure READ-ONLY verification: it shows what each company's next bill
     * number will be.
     */
    private function reportPosSerials(string $label, string $table, $companies, array $final, array $local): void
    {
        if (!Schema::hasTable($table)) {
            $this->warn("{$label}: table {$table} missing — skipped.");
            return;
        }

        [$finalPrefix, $finalPad] = $final;
        [$localPrefix, $localPad] = $local;

        $rows = [];
        foreach ($companies as $company) {
            $bills = DB::table($table)->where('company_id', $company->id)->count();
            if ($bills === 0) {
                continue;
            }

            $rows[] = [
                $company->id,
                mb_strimwidth($company->name ?? '', 0, 30, '…'),
                $bills,
                $this->nextDerived($table, $company->id, $finalPrefix, $finalPad),
                $this->nextDerived($table, $company->id, $localPrefix, $localPad, $localPrefix === 'L-'),
            ];
        }

        $this->line('');
        $this->info("=== {$label} — per-company (self-deriving, read-only) ===");
        if (empty($rows)) {
            $this->line('No bills yet — every company will start at ' . $finalPrefix . str_pad('1', $finalPad, '0', STR_PAD_LEFT));
            return;
        }
        $this->table(['ID', 'Company', 'Bills', 'Next Final #', 'Next Local #'], $rows);
    }

    private function nextDerived(string $table, int $companyId, string $prefix, int $pad, bool $excludeLegacyLocal = false): string
    {
        $q = DB::table($table)
            ->where('company_id', $companyId)
            ->where('invoice_number', 'like', $prefix . '%');

        if ($excludeLegacyLocal) {
            $q->where('invoice_number', 'not like', 'LOCAL-%');
        }

        $last = $q->orderByDesc('id')->value('invoice_number');

        $next = 1;
        if ($last && preg_match('/(\d+)$/', $last, $m)) {
            $next = ((int) $m[1]) + 1;
        }

        return $prefix . str_pad((string) $next, $pad, '0', STR_PAD_LEFT);
    }
}
