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

    protected $description = 'Verify and heal per-company DI invoice serial counters (never modifies invoices)';

    public function handle(): int
    {
        if (!Schema::hasColumn('companies', 'next_invoice_number')) {
            $this->error('companies.next_invoice_number column is MISSING — run: php artisan migrate --force');
            return self::FAILURE;
        }

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

        return self::SUCCESS;
    }
}
