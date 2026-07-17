<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owner request (Jul 2026): POS admins must be able to VIEW team-account
// passwords on /pos/team, not just reset them. Hashes are irreversible, so
// from now on storeCashier/updateCashier also keep an encrypted copy
// (Crypt::encryptString) that only the Team page decrypts for non-cashier
// viewers. TEXT, not varchar(255) — encrypted payloads overflow varchar even
// for tiny plaintext (see memory: encrypted-columns-need-text).
// Idempotent per-column guard — safe to re-run on prod (schema-drift lesson).
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'pos_team_password_enc')) {
            Schema::table('users', function (Blueprint $table) {
                // No ->after() anchor — prod has known column drift; position is irrelevant.
                $table->text('pos_team_password_enc')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'pos_team_password_enc')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('pos_team_password_enc');
            });
        }
    }
};
