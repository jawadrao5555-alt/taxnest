<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Task 585: one-time cleanup of LEGACY companies.cnic rows.
 *
 * Task 579/580 made every CNIC WRITE path store plain digits and enforce
 * global uniqueness — but rows saved BEFORE that can still hold dashed /
 * spaced values ("35202-1234567-1"). Login already digit-compares via
 * REPLACE, so normalizing storage is purely a consistency cleanup and can
 * never break an existing login.
 *
 * What this does (idempotent, PROD-safe: hasTable/hasColumn guards, runs
 * under the owner's standard `migrate --force`):
 *   1. Normalize every companies.cnic to its plain-digit form
 *      (soft-deleted rows included — they can be restored later).
 *      Values with NO digits at all become NULL (junk like "-").
 *   2. Report duplicate digit-form CNICs (same CNIC on 2+ live companies).
 *      Owner decision required — we NEVER auto-null a duplicate. The list
 *      is echoed to the migrate output AND written to the Laravel log so
 *      the owner can see it after a cPanel deploy. On-demand re-check:
 *      `php artisan cnic:duplicates`.
 *
 * down(): no-op — the dashed forms are not worth restoring.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('companies') || !Schema::hasColumn('companies', 'cnic')) {
            return;
        }

        // 1. Plain-digit normalization. Legacy separators seen in the wild
        //    are dashes and spaces (write paths only ever allowed [0-9-\s]).
        $normalized = DB::update(
            "UPDATE companies
                SET cnic = NULLIF(REPLACE(REPLACE(cnic, '-', ''), ' ', ''), '')
              WHERE cnic IS NOT NULL
                AND cnic <> REPLACE(REPLACE(cnic, '-', ''), ' ', '')"
        );

        // 2. Duplicate report (live companies only) — echoed + logged, never
        //    auto-fixed. Driver-portable SQL (no GROUP_CONCAT).
        $dupeGroups = DB::table('companies')
            ->selectRaw('cnic, COUNT(*) as n')
            ->whereNotNull('cnic')
            ->whereNull('deleted_at')
            ->groupBy('cnic')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $lines = ["[cnic-cleanup] normalized {$normalized} dashed/spaced CNIC row(s) to plain digits."];
        if ($dupeGroups->isNotEmpty()) {
            $lines[] = '[cnic-cleanup] DUPLICATE CNICs found — owner decision needed (run `php artisan cnic:duplicates` any time):';
            foreach ($dupeGroups as $group) {
                $companies = DB::table('companies')
                    ->whereNull('deleted_at')
                    ->where('cnic', $group->cnic)
                    ->get(['id', 'name', 'product_type'])
                    ->map(fn ($c) => $c->id . ':' . $c->name . ' [' . ($c->product_type ?? '-') . ']')
                    ->implode(' | ');
                $lines[] = "[cnic-cleanup]   CNIC {$group->cnic} on {$group->n} companies: {$companies}";
            }
        } else {
            $lines[] = '[cnic-cleanup] no duplicate CNICs found.';
        }

        foreach ($lines as $line) {
            echo $line . PHP_EOL;
            Log::info($line);
        }
    }

    public function down(): void
    {
        // Intentional no-op: plain-digit storage is the canonical form.
    }
};
