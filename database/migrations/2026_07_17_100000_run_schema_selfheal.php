<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * SELF-HEAL WRAPPER (idempotent, add-only).
 *
 * Runs `php artisan schema:selfheal` as part of the normal deploy
 * (`php artisan migrate --force`), so a production DB whose earlier
 * migrations were recorded as "Ran" without applying gets every missing
 * column/table added from database/schema-manifest.json in one pass —
 * instead of admin pages 500-ing one at a time.
 *
 * The command is add-only and exits 0 even on partial failure, so this
 * migration can never abort a deploy. If drift reappears later, the owner
 * can re-run `php artisan schema:selfheal` directly at any time.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            Artisan::call('schema:selfheal');
            Log::info('schema:selfheal via migration: ' . trim(Artisan::output()));
        } catch (\Throwable $e) {
            // Never block the migrate run — selfheal can be re-run by hand.
            Log::error('schema:selfheal migration wrapper failed: ' . $e->getMessage());
        }
    }

    public function down(): void
    {
        // No-op: add-only self-heal; nothing to roll back.
    }
};
