<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Activate POS APK v1.1.0 rollout (push notifications):
 *  1. Update pos_app_latest_version SystemSetting → "1.1.0" so old installs
 *     (UA TaxNestPOSApp/<1.1.0) see the "New App Available" banner.
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
                    'value' => '1.1.0',
                    'updated_at' => now(),
                ]);
        }

        // 2. What's New elaan for v1.1.0.
        if (!Schema::hasTable('app_updates')) {
            return;
        }
        $title = 'TaxNest POS App v1.1.0 — ab notification turant phone par';
        if (DB::table('app_updates')->where('title', $title)->exists()) {
            return;
        }
        DB::table('app_updates')->insert([
            'title' => $title,
            'points' => json_encode([
                'Android app update ho gayi — naya order, order tayyar aur day close ki notification ab turant phone par aa jati hai, chahe app band ho.',
                'Update lene ke liye taxnest.com.pk/downloads par ja kar "Download APK" dabayein aur purani app ke upar hi install kar lein — login aur data waisa hi rahega.',
                'Pehli dafa app kholte waqt "Notifications allow karein" ka message aaye to Allow dabayein, warna notification nahi aayegi.',
                'Notification par tap karte hi app khul jati hai; logout karne par us phone par notification khud band ho jati hain.',
            ], JSON_UNESCAPED_UNICODE),
            'audience' => 'pos',
            'is_published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Revert version to 1.0.3 and remove the elaan.
        if (Schema::hasTable('system_settings')) {
            DB::table('system_settings')
                ->where('key', 'pos_app_latest_version')
                ->update(['value' => '1.0.3', 'updated_at' => now()]);
        }
        if (Schema::hasTable('app_updates')) {
            DB::table('app_updates')
                ->where('title', 'TaxNest POS App v1.1.0 — ab notification turant phone par')
                ->delete();
        }
    }
};
