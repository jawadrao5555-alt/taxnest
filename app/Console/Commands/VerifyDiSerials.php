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

        // Each series is described the way its issuing service reads it: the
        // coarse LIKE prefilters, then the EXACT grammar that decides whether a
        // row reserves a number. The short PRA series accepts both the current
        // "P036"/"L012" spelling and the dashed one issued before the dash was
        // dropped; stray text ("P-ABC", "LOCAL-2026-00007") reserves nothing.
        $this->reportPosSerials(
            'PRA POS',
            'pos_transactions',
            $companies,
            ['P', 3, ['P%', 'POS-%'], ['/^P-?(\d+)$/', '/^POS-\d{4}-(\d+)$/']],
            ['L', 3, ['L%'], ['/^L-?(\d+)$/']]
        );

        $year = now()->format('Y');
        $this->reportPosSerials(
            'FBR POS',
            'fbr_pos_transactions',
            $companies,
            ['FPOS-' . $year . '-', 5, ['FPOS-' . $year . '-%'], ['/^FPOS-\d{4}-(\d+)$/']],
            ['FLOCAL-' . $year . '-', 5, ['FLOCAL-' . $year . '-%'], ['/^FLOCAL-\d{4}-(\d+)$/']]
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
                if (InvoiceNumberingService::sequenceOf((string) $draft->invoice_number) !== null) {
                    continue; // already a per-company serial (short D036 or legacy …DI00036)
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
     * POS serials (PRA + FBR) are read back from the company's OWN bills, so
     * this section is a pure READ-ONLY cross-check of what the sale paths will
     * issue next. It deliberately re-derives the number instead of asking the
     * series services — that is the point of a verifier.
     *
     * One honest caveat: the PRA series also keep a durable counter, so after a
     * shop cleared or archived bills the SERVICE can legitimately stand higher
     * than what the surviving rows show. The derived value is a floor.
     *
     * @param array{0:string,1:int,2:array<int,string>,3:array<int,string>} $final
     * @param array{0:string,1:int,2:array<int,string>,3:array<int,string>} $local
     */
    private function reportPosSerials(string $label, string $table, $companies, array $final, array $local): void
    {
        if (!Schema::hasTable($table)) {
            $this->warn("{$label}: table {$table} missing — skipped.");
            return;
        }

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
                $this->nextDerived($table, $company->id, $final),
                $this->nextDerived($table, $company->id, $local),
            ];
        }

        $this->line('');
        $this->info("=== {$label} — per-company (self-deriving, read-only) ===");
        if (empty($rows)) {
            $this->line('No bills yet — every company will start at ' . $final[0] . str_pad('1', $final[1], '0', STR_PAD_LEFT));
            return;
        }
        $this->table(['ID', 'Company', 'Bills', 'Next Final #', 'Next Local #'], $rows);
    }

    /**
     * Highest number the company's own bills already occupy, plus one.
     *
     * The scan reads EVERY matching row (never just the newest one — a shop
     * that carries "P-999" and a later "P001" would otherwise be reported at
     * P002 while the sale path correctly issues P1000) and applies the same
     * exact grammar the issuing service applies, so anything that merely starts
     * with the letter reserves nothing.
     *
     * @param array{0:string,1:int,2:array<int,string>,3:array<int,string>} $spec
     */
    private function nextDerived(string $table, int $companyId, array $spec): string
    {
        [$prefix, $pad, $likes, $patterns] = $spec;

        $highest = 0;
        DB::table($table)
            ->where('company_id', $companyId)
            ->where(function ($q) use ($likes) {
                foreach ($likes as $i => $like) {
                    $i === 0
                        ? $q->where('invoice_number', 'like', $like)
                        : $q->orWhere('invoice_number', 'like', $like);
                }
            })
            ->select(['id', 'invoice_number'])
            ->orderBy('id')
            ->chunkById(1000, function ($rows) use (&$highest, $patterns) {
                foreach ($rows as $row) {
                    foreach ($patterns as $pattern) {
                        if (preg_match($pattern, (string) $row->invoice_number, $m) === 1) {
                            $highest = max($highest, (int) $m[1]);
                            break;
                        }
                    }
                }
            });

        return $prefix . str_pad((string) ($highest + 1), $pad, '0', STR_PAD_LEFT);
    }
}
