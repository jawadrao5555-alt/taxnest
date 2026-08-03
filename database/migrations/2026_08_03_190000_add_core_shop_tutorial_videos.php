<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Core shop feature tutorial videos (Task 232, 3 Aug 2026): products/Excel,
 * inventory, day-close aur reports ki Urdu tutorial videos ko tutorial
 * library mein add karta hai. Same convention as the library migration:
 * PROD runs `migrate --force` (never db:seed), so rows are seeded here
 * idempotently (slug-guarded — never duplicates, never overwrites later
 * edits from /admin/tutorial-videos).
 *
 * Video files live in public/videos/tutorials/ (committed to the repo) so
 * dev preview aur live dono serve karte hain. show_public intentionally
 * NOT set (column default false) — super admin publishes to the landing
 * page from /admin/tutorial-videos; in-app /pos/tutorials par ye
 * is_published default ke zariye foran nazar aati hain.
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
                'slug' => 'products-excel-import',
                'title' => 'Products & Categories — Excel import/export',
                'description' => 'Naya product add karna (category, qeemat, tax), Excel file se bohat saare products ek saath import karna aur poori list export karna.',
                'video_url' => '/videos/tutorials/products-excel-import.mp4',
                'category' => 'products',
                'sort' => 21,
                'duration_seconds' => 149,
            ],
            [
                'slug' => 'inventory-stock',
                'title' => 'Inventory — stock dekhna, adjust karna aur alerts',
                'description' => 'Stock levels aur value dekhna, naya maal aane par stock adjust karna, movements ki history aur kam-stock alerts ka istemal.',
                'video_url' => '/videos/tutorials/inventory-stock.mp4',
                'category' => 'products',
                'sort' => 22,
                'duration_seconds' => 122,
            ],
            [
                'slug' => 'day-close-report',
                'title' => 'Day Close — opening cash se Z-Report tak',
                'description' => 'Subah opening cash save karna, din bhar ki sales ka khulasa, cash milana (reconciliation) aur din band kar ke Z-Report PDF nikaalna.',
                'video_url' => '/videos/tutorials/day-close-report.mp4',
                'category' => 'reports',
                'sort' => 25,
                'duration_seconds' => 133,
            ],
            [
                'slug' => 'reports-analytics',
                'title' => 'Reports & Analytics — sales, charts aur export',
                'description' => 'Sales reports date range ke saath dekhna, charts aur profit ka andaza, aur CSV/PDF export karna.',
                'video_url' => '/videos/tutorials/reports-analytics.mp4',
                'category' => 'reports',
                'sort' => 26,
                'duration_seconds' => 111,
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
            'products-excel-import',
            'inventory-stock',
            'day-close-report',
            'reports-analytics',
        ])->delete();
    }
};
