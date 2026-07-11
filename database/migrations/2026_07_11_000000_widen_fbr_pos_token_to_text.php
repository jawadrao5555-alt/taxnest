<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen companies.fbr_pos_token from VARCHAR(255) to TEXT.
 *
 * The FBR POS token is stored encrypted via Crypt::encryptString() in
 * FbrPosController@fbrSettings. A raw 36-char UUID token encrypts to ~256 chars,
 * which overflows the original VARCHAR(255) column and 500s the FBR POS settings
 * save with:  SQLSTATE[22001] Data too long for column 'fbr_pos_token'.
 * The DI FBR token columns (fbr_sandbox_token / fbr_production_token) are already
 * TEXT for this exact reason; this brings fbr_pos_token in line.
 *
 * Idempotent: MODIFY ... TEXT is safe to re-run, so `php artisan migrate --force`
 * heals production without harming a schema that is already TEXT.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('companies', 'fbr_pos_token')) {
            DB::statement('ALTER TABLE `companies` MODIFY `fbr_pos_token` TEXT NULL');
        }
    }

    public function down(): void
    {
        // No-op: narrowing back to VARCHAR(255) would truncate encrypted tokens.
    }
};
