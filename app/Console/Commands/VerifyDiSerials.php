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
    protected $signature = 'di:serials {--check : Report only, change nothing}';

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
