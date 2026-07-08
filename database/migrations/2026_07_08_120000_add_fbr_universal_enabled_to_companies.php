<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FBR POS Universal sale screen — per-company opt-in toggle.
     * Default OFF (0): every company keeps the classic create screen until
     * the owner/admin flips the toggle. Idempotent (hasColumn-guarded) so it
     * self-heals on PROD where migration state can drift.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('companies', 'fbr_universal_enabled')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->boolean('fbr_universal_enabled')->default(false)->after('fbr_reporting_enabled');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'fbr_universal_enabled')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('fbr_universal_enabled');
            });
        }
    }
};
