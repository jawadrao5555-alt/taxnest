<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 585: on-demand duplicate-CNIC report.
 *
 * Read-only. Compares companies by DIGIT-form CNIC (dash/space stripped via
 * REPLACE, same comparison the login lookup uses) so dashed-stored legacy
 * rows collide with plain-stored ones. Duplicates are NEVER auto-fixed —
 * the owner decides which company keeps the CNIC (edit the other via
 * saas-admin / Business Profile).
 */
class CnicDuplicatesReport extends Command
{
    protected $signature = 'cnic:duplicates {--with-deleted : include soft-deleted companies}';

    protected $description = 'List companies that share the same CNIC (digit-form compare)';

    public function handle(): int
    {
        if (!Schema::hasTable('companies') || !Schema::hasColumn('companies', 'cnic')) {
            $this->warn('companies.cnic column not found — nothing to check.');

            return self::SUCCESS;
        }

        $withDeleted = (bool) $this->option('with-deleted');

        $groups = DB::table('companies')
            ->selectRaw("REPLACE(REPLACE(cnic, '-', ''), ' ', '') as digits, COUNT(*) as n")
            ->whereNotNull('cnic')
            ->where('cnic', '!=', '')
            ->when(!$withDeleted, fn ($q) => $q->whereNull('deleted_at'))
            ->groupByRaw("REPLACE(REPLACE(cnic, '-', ''), ' ', '')")
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($groups->isEmpty()) {
            $this->info('No duplicate CNICs — every company CNIC is unique.');

            return self::SUCCESS;
        }

        $this->warn($groups->count() . ' duplicate CNIC group(s) found — owner decision needed:');
        foreach ($groups as $group) {
            $companies = DB::table('companies')
                ->whereRaw("REPLACE(REPLACE(cnic, '-', ''), ' ', '') = ?", [$group->digits])
                ->when(!$withDeleted, fn ($q) => $q->whereNull('deleted_at'))
                ->get(['id', 'name', 'product_type', 'deleted_at'])
                ->map(fn ($c) => $c->id . ':' . $c->name . ' [' . ($c->product_type ?? '-') . ']' . ($c->deleted_at ? ' (deleted)' : ''))
                ->implode(' | ');
            $this->line("  CNIC {$group->digits} → {$group->n} companies: {$companies}");
        }
        $this->line("Fix by editing the wrong company's CNIC (saas-admin company edit or the owner's Business Profile page). Never auto-nulled.");

        return self::SUCCESS;
    }
}
