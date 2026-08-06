<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Activate APK v1.0.3 rollout (Task #307):
 *  1. Update pos_app_latest_version SystemSetting → "1.0.3" so old installs
 *     (UA TaxNestPOSApp/<1.0.3) see the "New App Available" banner.
 *  2. Insert a What's New AppUpdate elaan (bell/popup) for POS users.
 * Idempotent: safe to run on prod via `migrate --force`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Bump the APK version setting (always update — deliberate rollout).
        if (Schema::hasTable('system_settings')) {
            DB::table('system_settings')
                ->where('key', 'pos_app_latest_version')
                ->update([
                    'value' => '1.0.3',
                    'updated_at' => now(),
                ]);
        }

        // 2. What's New elaan for v1.0.3.
        if (!Schema::hasTable('app_updates')) {
            return;
        }
        $title = 'TaxNest POS App v1.0.3 — Android par download karein';
        if (DB::table('app_updates')->where('title', $title)->exists()) {
            return;
        }
        DB::table('app_updates')->insert([
            'title' => $title,
            'points' => json_encode([
                'TaxNest POS ka Android app update ho gaya — v1.0.3 mein tutorial videos fullscreen mein dekh saktay hain, seedha app ke andar.',
                'Apne Android phone par /download page se "Download APK" dabayein, install karein, aur apne normal login se sign in ho jayein.',
                'Har team member apna role dekhe ga — owner, cashier, waiter, kitchen, rider — wahi screen jo browser mein milti hai, seedha mobile par.',
                'App khud automatically web changes pakad leta hai — siwaye shell fix ke update ki zaroorat nahi hoti.',
            ], JSON_UNESCAPED_UNICODE),
            'audience' => 'pos',
            'is_published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Revert version to 1.0.2 and remove the elaan.
        if (Schema::hasTable('system_settings')) {
            DB::table('system_settings')
                ->where('key', 'pos_app_latest_version')
                ->update(['value' => '1.0.2', 'updated_at' => now()]);
        }
        if (Schema::hasTable('app_updates')) {
            DB::table('app_updates')
                ->where('title', 'TaxNest POS App v1.0.3 — Android par download karein')
                ->delete();
        }
    }
};
