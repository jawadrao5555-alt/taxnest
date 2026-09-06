<?php

use App\Services\PosCategoryRolloutService;
use App\Services\PosFeatureService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Task 1582 — record every module a live shop already uses OUTSIDE its
 * business category as a grandfathered extra, BEFORE the availability
 * predicate can hide it. Idempotent: existing records are never overwritten;
 * re-running only adds what is newly outside.
 *
 * The report is echoed and logged so the owner gets the list of affected shops
 * (also available later via `php artisan pos:category-extras --list`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('companies') || !Schema::hasColumn('companies', 'pos_module_extras')) {
            return;
        }
        PosFeatureService::flushGateCaches();
        $rows = PosCategoryRolloutService::backfill(true);
        foreach ($rows as $row) {
            $line = sprintf(
                'category-extras grandfathered: #%d %s [%s] -> %s',
                $row['id'],
                $row['name'],
                $row['category'],
                implode(', ', array_keys($row['modules']))
            );
            Log::info($line);
            if (PHP_SAPI === 'cli') {
                fwrite(STDOUT, $line . PHP_EOL);
            }
        }
        if (PHP_SAPI === 'cli') {
            fwrite(STDOUT, count($rows) . ' shop(s) carry modules outside their category (grandfathered).' . PHP_EOL);
        }
    }

    public function down(): void
    {
        // Grandfather records are data, not schema; nothing to undo here.
    }
};
