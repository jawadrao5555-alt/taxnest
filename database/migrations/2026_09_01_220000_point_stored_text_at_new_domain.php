<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The site now answers on taxnest.pk. Some user-facing text was stored in the
 * database rather than in blades — What's New bullet points and tutorial
 * descriptions — so a code change alone leaves shops reading the old address.
 *
 * Email addresses are deliberately left alone: there is no mailbox on the new
 * domain yet, so support@taxnest.com.pk must keep working. The nested REPLACE
 * parks any '@taxnest.com.pk' behind a placeholder, rewrites the bare hostname,
 * then puts the address back exactly as it was.
 *
 * Idempotent: running it twice finds nothing left to change.
 */
return new class extends Migration
{
    /** table => column */
    private const TARGETS = [
        'app_updates'     => 'points',
        'tutorial_videos' => 'description',
    ];

    private const PLACEHOLDER = '__TAXNEST_MAIL_KEEP__';

    public function up(): void
    {
        foreach (self::TARGETS as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            DB::table($table)
                ->where($column, 'like', '%taxnest.com.pk%')
                ->update([
                    $column => DB::raw(sprintf(
                        "REPLACE(REPLACE(REPLACE(`%s`, '@taxnest.com.pk', '%s'), 'taxnest.com.pk', 'taxnest.pk'), '%s', '@taxnest.com.pk')",
                        $column,
                        self::PLACEHOLDER,
                        self::PLACEHOLDER
                    )),
                ]);
        }
    }

    public function down(): void
    {
        // Reversing would also rewrite addresses that legitimately point at the
        // old domain, so this migration is intentionally one-way.
    }
};
