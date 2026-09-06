<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One linked product per catalogue row per company — the database fence
 * behind the catalogue picker's "already added" promise. The controller
 * serialises adds under a company-row lock; this index guarantees that no
 * other write path (imports, future tools) can leave a second linked copy.
 *
 * Pre-existing duplicates are UNLINKED (the oldest product keeps the link),
 * never deleted: a product may already carry sales.
 * NULL links are unconstrained (MySQL/MariaDB/SQLite all allow repeated
 * NULLs in a unique index).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('products') || !Schema::hasColumn('products', 'medicine_catalogue_id')) {
            return;
        }

        $dupes = DB::table('products')
            ->select('company_id', 'medicine_catalogue_id', DB::raw('MIN(id) as keep_id'))
            ->whereNotNull('medicine_catalogue_id')
            ->groupBy('company_id', 'medicine_catalogue_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();
        foreach ($dupes as $d) {
            DB::table('products')
                ->where('company_id', $d->company_id)
                ->where('medicine_catalogue_id', $d->medicine_catalogue_id)
                ->where('id', '<>', $d->keep_id)
                ->update(['medicine_catalogue_id' => null]);
        }

        try {
            Schema::table('products', fn (Blueprint $t) => $t->unique(['company_id', 'medicine_catalogue_id'], 'products_company_mc_unique'));
        } catch (\Throwable $e) {
            // already there (re-run) — nothing to do
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }
        try {
            Schema::table('products', fn (Blueprint $t) => $t->dropUnique('products_company_mc_unique'));
        } catch (\Throwable $e) {
        }
    }
};
