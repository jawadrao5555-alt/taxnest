<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task #111 (owner-approved, 2 Aug 2026): POS Team Custom Access.
 * Optional per-member feature grants (JSON array of feature keys) that
 * overlay the role defaults. NULL = no custom set = role behavior unchanged.
 *
 * hasColumn-guarded per the PROD schema-drift convention — safe to re-run
 * on the owner's cPanel PROD even if a copy was marked "Ran".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'pos_custom_access')) {
            Schema::table('users', function (Blueprint $table) {
                // TEXT (not varchar): ~14 feature keys can exceed 255 chars.
                $table->text('pos_custom_access')->nullable()->after('pos_role');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'pos_custom_access')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('pos_custom_access');
            });
        }
    }
};
