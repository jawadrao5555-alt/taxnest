<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NestPOS tutorial library — 11 nayi videos (owner request, Aug 2026):
 * 6 Al-Noor retail (dashboard, hold/recall, shortcuts, language, suggestion
 * box, reports+tax) aur 5 Lahore Darbar restaurant (KDS, tables/shift,
 * recipes/ingredients, QR menu, rider live tracking).
 *
 * Same convention as the other tutorial migrations: PROD runs
 * `migrate --force` (never db:seed), so rows are seeded here idempotently
 * (slug-guarded — never duplicates, never overwrites later admin edits).
 * Video files: public/videos/tutorials/<slug>.mp4 (committed to the repo).
 */
return new class extends Migration
{
    private array $rows = [
        [
            'slug' => 'dashboard-tour',
            'title' => 'Dashboard ki Sair — Aik Nazar Mein Pura Karobar',
            'description' => 'Dashboard par aaj ki sale, munafa, opening cash aur recent bills — pura karobar aik screen par kaise dekhein.',
            'category' => 'shuruat', 'sort' => 5, 'min_role' => 'admin',
            'required_feature' => null, 'show_public' => true, 'duration_seconds' => 129,
        ],
        [
            'slug' => 'language-badalna',
            'title' => 'Apni Zaban Chunein — English, Roman Urdu, ya Urdu',
            'description' => 'NestPOS teen zabanon mein chalta hai — apne liye English, Roman Urdu ya Urdu kaise select karein.',
            'category' => 'shuruat', 'sort' => 6, 'min_role' => 'any',
            'required_feature' => null, 'show_public' => true, 'duration_seconds' => 101,
        ],
        [
            'slug' => 'hold-recall',
            'title' => 'Bill Hold aur Recall — Rukay Huay Bill Sambhalen',
            'description' => 'Customer saman lene wapas gaya? Bill hold karein, agla customer nimtayen, phir recall kar ke wahin se aagay chalein.',
            'category' => 'billing', 'sort' => 15, 'min_role' => 'cashier',
            'required_feature' => null, 'show_public' => true, 'duration_seconds' => 108,
        ],
        [
            'slug' => 'keyboard-shortcuts',
            'title' => 'Keyboard Shortcuts — Mouse ke Baghair Tez Kaam',
            'description' => 'F-keys aur letter shortcuts se billing double raftaar — cash, card, hold, recall sab keyboard se.',
            'category' => 'billing', 'sort' => 16, 'min_role' => 'cashier',
            'required_feature' => null, 'show_public' => true, 'duration_seconds' => 105,
        ],
        [
            'slug' => 'reports-tax-guide',
            'title' => 'Reports aur Tax Report — Karobar ki Poori Tasveer',
            'description' => 'Sales, munafa aur tax reports kaise parhein aur PDF/Excel mein kaise nikaalein.',
            'category' => 'reports', 'sort' => 27, 'min_role' => 'admin',
            'required_feature' => null, 'show_public' => true, 'duration_seconds' => 123,
        ],
        [
            'slug' => 'suggestion-box',
            'title' => 'Feature Suggestion Box — Apni Raye Seedha Hum Tak',
            'description' => 'Koi feature chahiye? Suggestion box se apni demand seedha NestPOS team tak pohnchayen.',
            'category' => 'settings', 'sort' => 33, 'min_role' => 'admin',
            'required_feature' => null, 'show_public' => true, 'duration_seconds' => 93,
        ],
        [
            'slug' => 'kds-kitchen',
            'title' => 'Kitchen Display (KDS) — Kitchen ki Apni Screen',
            'description' => 'Order seedha kitchen screen par — Start Preparing se Ready for Pickup tak pura flow.',
            'category' => 'restaurant', 'sort' => 41, 'min_role' => 'cashier',
            'required_feature' => 'restaurant', 'show_public' => false, 'duration_seconds' => 121,
        ],
        [
            'slug' => 'tables-shift',
            'title' => 'Tables aur Table Shift — Dine-in ka Intezam',
            'description' => 'Tables board, table par order aur bharay table se khaali table par shift — dine-in ka pura intezam.',
            'category' => 'restaurant', 'sort' => 42, 'min_role' => 'cashier',
            'required_feature' => 'restaurant', 'show_public' => false, 'duration_seconds' => 122,
        ],
        [
            'slug' => 'recipes-ingredients',
            'title' => 'Ingredients aur Recipes — Kitchen ka Stock Khud-ba-Khud',
            'description' => 'Ingredients add karein, recipes banayen — har sale par kitchen ka stock khud-ba-khud kam hota jaye.',
            'category' => 'restaurant', 'sort' => 43, 'min_role' => 'admin',
            'required_feature' => 'restaurant', 'show_public' => false, 'duration_seconds' => 113,
        ],
        [
            'slug' => 'qr-menu',
            'title' => 'QR Menu — Gahak Khud Menu Dekhe, Apne Mobile Par',
            'description' => 'Public profile on karein, QR code print karein — gahak apne mobile par menu khud dekhe.',
            'category' => 'restaurant', 'sort' => 44, 'min_role' => 'admin',
            'required_feature' => 'restaurant', 'show_public' => false, 'duration_seconds' => 116,
        ],
        [
            'slug' => 'rider-live-tracking',
            'title' => 'Rider Live Tracking — Har Rider Naqshay Par',
            'description' => 'Delivery riders ko naqshay par live dekhein — rider app se location har waqt update.',
            'category' => 'riders', 'sort' => 51, 'min_role' => 'admin',
            'required_feature' => 'riders_enabled', 'show_public' => false, 'duration_seconds' => 109,
        ],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('tutorial_videos')) {
            return;
        }

        $now = now();
        foreach ($this->rows as $row) {
            if (DB::table('tutorial_videos')->where('slug', $row['slug'])->exists()) {
                continue; // never duplicate, never overwrite later admin edits
            }

            DB::table('tutorial_videos')->insert([
                'slug' => $row['slug'],
                'product' => 'nestpos',
                'title' => $row['title'],
                'description' => $row['description'],
                'video_url' => '/videos/tutorials/' . $row['slug'] . '.mp4',
                'category' => $row['category'],
                'required_feature' => $row['required_feature'],
                'min_role' => $row['min_role'],
                'sort' => $row['sort'],
                'is_published' => true,
                'show_public' => $row['show_public'],
                'duration_seconds' => $row['duration_seconds'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tutorial_videos')) {
            DB::table('tutorial_videos')
                ->whereIn('slug', array_column($this->rows, 'slug'))
                ->delete();
        }
    }
};
