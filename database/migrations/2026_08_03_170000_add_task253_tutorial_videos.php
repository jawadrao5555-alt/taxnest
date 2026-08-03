<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 253 tutorial videos (3 Aug 2026): team accounts (custom access),
 * settings/branding, offline mode aur PRA mode ki Urdu tutorial videos ko
 * tutorial library mein add karta hai. Same convention as
 * 2026_08_03_150000_add_module_tutorial_videos.php: PROD runs
 * `migrate --force` (never db:seed), so rows are seeded here idempotently
 * (slug-guarded — never duplicates, never overwrites later edits).
 *
 * Gating is intentionally NOT set here: TutorialVideo::applyOwnerControls()
 * self-heals new rows — "custom-access" in the team video slug applies the
 * custom_access_enabled gate, and the "offline" slug gets force-unpublished
 * (owner's offline lockdown) until the owner enables it from the admin panel.
 *
 * Video files live in public/videos/tutorials/ (committed to the repo) so
 * dev preview aur live dono serve karte hain.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tutorial_videos')) {
            return; // library migration hasn't run yet; it runs first by date
        }

        $now = now();
        $rows = [
            [
                'slug' => 'team-custom-access',
                'title' => 'Team Accounts — cashier banana, permissions aur hazri',
                'description' => 'Naya cashier account banana, role chunna, Custom Access se har member ke features tay karna aur Staff Hazri ki report dekhna.',
                'video_url' => '/videos/tutorials/team-custom-access.mp4',
                'category' => 'settings',
                'sort' => 70,
                'duration_seconds' => 143,
            ],
            [
                'slug' => 'settings-branding',
                'title' => 'Settings aur Branding — theme, zuban, logo aur receipt',
                'description' => 'POS ko apni dukan ke mutabiq sajana — theme ka rang, default language, receipt par apna logo aur receipt/printer settings.',
                'video_url' => '/videos/tutorials/settings-branding.mp4',
                'category' => 'settings',
                'sort' => 80,
                'duration_seconds' => 147,
            ],
            [
                'slug' => 'offline-mode',
                'title' => 'Offline Mode — internet band, kaam band nahi',
                'description' => 'Baghair internet ke bill banana, device par mehfooz receipts aur net wapis aane par khud-ba-khud sync — poora offline flow.',
                'video_url' => '/videos/tutorials/offline-mode.mp4',
                'category' => 'billing',
                'sort' => 90,
                'duration_seconds' => 145,
            ],
            [
                'slug' => 'pra-mode',
                'title' => 'PRA Mode — reporting on karna aur invoice numbers',
                'description' => 'PRA integration settings (environment, POS ID, token), sale screen par PRA reporting ka switch, local bill ka farq aur cashier-level control.',
                'video_url' => '/videos/tutorials/pra-mode.mp4',
                'category' => 'settings',
                'sort' => 100,
                'duration_seconds' => 202,
            ],
        ];

        foreach ($rows as $row) {
            $exists = DB::table('tutorial_videos')->where('slug', $row['slug'])->exists();
            if (!$exists) {
                DB::table('tutorial_videos')->insert($row + ['created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        DB::table('tutorial_videos')->whereIn('slug', [
            'team-custom-access',
            'settings-branding',
            'offline-mode',
            'pra-mode',
        ])->delete();
    }
};
