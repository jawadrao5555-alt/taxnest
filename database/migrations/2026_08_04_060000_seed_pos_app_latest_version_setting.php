<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seed the latest released Android POS APK version (SystemSetting) so old app
 * installs (UA "TaxNestPOSApp/<ver>") start seeing the "new app available"
 * banner immediately after deploy. Prod runs `migrate --force`, never seeders
 * (pricing-reprice convention). Idempotent: only inserts when the key is absent
 * so an admin-edited value is never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('system_settings')) {
            return;
        }
        $exists = DB::table('system_settings')->where('key', 'pos_app_latest_version')->exists();
        if (!$exists) {
            DB::table('system_settings')->insert([
                'key' => 'pos_app_latest_version',
                'value' => '1.0.1',
                'description' => 'Latest released Android POS APK versionName (update banner threshold)',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Keep the setting — harmless, admin-managed.
    }
};
