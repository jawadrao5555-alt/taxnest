<?php

use App\Services\CompanyProductId;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('company_product_id_counters')) {
            Schema::create('company_product_id_counters', function (Blueprint $table) {
                $table->string('series', 3)->primary();
                $table->unsignedBigInteger('next_serial');
            });
        }

        DB::table('company_product_id_counters')->insertOrIgnore(array_map(
            fn (string $series) => ['series' => $series, 'next_serial' => 1],
            CompanyProductId::SERIES,
        ));

        if (!Schema::hasTable('company_product_ids')) {
            Schema::create('company_product_ids', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('series', 3);
                $table->unsignedBigInteger('serial');
                $table->string('code', 24)->unique();
                $table->unique(['series', 'serial']);
                $table->unique(['company_id', 'series']);
            });
        }

        if (!Schema::hasColumn('companies', 'product_company_id')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->string('product_company_id', 24)->nullable();
            });
        }

        // Deployment enters maintenance before the first historical backfill.
        CompanyProductId::backfill(DB::connection());
    }

    public function down(): void
    {
        // Product codes are durable aliases. Keep them available across an
        // application rollback so rerunning this migration never recycles them.
    }
};
