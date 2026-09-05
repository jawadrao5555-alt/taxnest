<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nest ERPS — carry the healthcare product line onto the umbrella discriminator.
 *
 * The panel used to be sold as a one-off product called "Healthcare ERP" and
 * stored product_type = 'health'. It is now the FIRST VERTICAL of Nest ERPS, a
 * single product line that further ERPs join by registering a vertical.
 *
 * What this does, idempotently and per column (live schemas drift — a column
 * marked "Ran" is not a column that exists):
 *   1. adds `erps_vertical` to companies and pricing_plans,
 *   2. rewrites the stored 'health' discriminator to 'erps' on companies,
 *      pricing_plans and registered_credentials,
 *   3. records 'health' as the vertical on exactly those rows.
 *
 * Nothing else moves: the panel prefix (/health), the auth guard (health), the
 * per-vertical health_* columns and every healthcare table stay untouched, so
 * live sessions, saved links and data keep working. Reads also stay tolerant of
 * the old value (NestErps::PRODUCT_TYPES), so a deploy-before-migrate window
 * cannot make a live organisation look like a Digital Invoice company.
 */
return new class extends Migration
{
    /** Stored value the line used before the umbrella existed. */
    private const LEGACY_TYPE = 'health';

    private const NEW_TYPE = \App\Support\NestErps::PRODUCT_TYPE;

    private const VERTICAL_COLUMN = \App\Support\NestErps::VERTICAL_COLUMN;

    private const HEALTH_VERTICAL = \App\Support\NestErps::HEALTH;

    public function up(): void
    {
        // ── 1. Vertical column ────────────────────────────────────────────
        // Which ERP inside the line a company / package belongs to. NULL on
        // every non-Nest-ERPS row, and never read for one.
        foreach (['companies', 'pricing_plans'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            if (Schema::hasColumn($table, self::VERTICAL_COLUMN)) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $column = $blueprint->string(self::VERTICAL_COLUMN, 20)->nullable();
                if (Schema::hasColumn($table, 'product_type')) {
                    $column->after('product_type');
                }
            });
        }

        // ── 2 + 3. Discriminator and vertical, together ───────────────────
        foreach (['companies', 'pricing_plans'] as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'product_type')) {
                continue;
            }

            $update = ['product_type' => self::NEW_TYPE];
            if (Schema::hasColumn($table, self::VERTICAL_COLUMN)) {
                $update[self::VERTICAL_COLUMN] = self::HEALTH_VERTICAL;
            }

            DB::table($table)->where('product_type', self::LEGACY_TYPE)->update($update);

            // Re-run safety: a row already carrying the new type but no
            // vertical (e.g. created between deploy and migrate) still needs
            // one, and healthcare is the only vertical that exists.
            if (Schema::hasColumn($table, self::VERTICAL_COLUMN)) {
                DB::table($table)
                    ->where('product_type', self::NEW_TYPE)
                    ->where(function ($q) {
                        $q->whereNull(self::VERTICAL_COLUMN)->orWhere(self::VERTICAL_COLUMN, '');
                    })
                    ->update([self::VERTICAL_COLUMN => self::HEALTH_VERTICAL]);
            }
        }

        // The anti-free-trial-abuse ledger only labels a credential with the
        // product it was first used on; keeping the label current means the
        // admin sees "Nest ERPS", not a retired product name.
        if (Schema::hasTable('registered_credentials') && Schema::hasColumn('registered_credentials', 'product_type')) {
            DB::table('registered_credentials')
                ->where('product_type', self::LEGACY_TYPE)
                ->update(['product_type' => self::NEW_TYPE]);
        }
    }

    public function down(): void
    {
        foreach (['companies', 'pricing_plans'] as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'product_type')) {
                continue;
            }

            $query = DB::table($table)->where('product_type', self::NEW_TYPE);
            if (Schema::hasColumn($table, self::VERTICAL_COLUMN)) {
                // Only the healthcare vertical existed under the old spelling —
                // a later vertical must NOT be rolled back onto it.
                $query->where(self::VERTICAL_COLUMN, self::HEALTH_VERTICAL);
            }
            $query->update(['product_type' => self::LEGACY_TYPE]);
        }

        if (Schema::hasTable('registered_credentials') && Schema::hasColumn('registered_credentials', 'product_type')) {
            DB::table('registered_credentials')
                ->where('product_type', self::NEW_TYPE)
                ->update(['product_type' => self::LEGACY_TYPE]);
        }

        foreach (['companies', 'pricing_plans'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, self::VERTICAL_COLUMN)) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn(self::VERTICAL_COLUMN);
                });
            }
        }
    }
};
