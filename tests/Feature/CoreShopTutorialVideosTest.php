<?php

namespace Tests\Feature;

use App\Models\TutorialVideo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Core shop tutorial videos (Task 232) — shipped-for-real guard.
 *
 * Guards against the "pipeline-only, not shipped" regression: the four core
 * shop tutorials (products/Excel, inventory, day-close, reports) must
 * (1) have their MP4 files committed under public/videos/tutorials/,
 * (2) be seeded by their migration with matching video_url rows, and
 * (3) survive the library's missing-file guard (groupedFrom hides rows whose
 *     file is absent — so a deleted/renamed MP4 fails this test).
 *
 * Run with:
 *   APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: \
 *     php artisan test --filter=CoreShopTutorialVideosTest
 */
class CoreShopTutorialVideosTest extends TestCase
{
    private const SLUGS = [
        'products-excel-import',
        'inventory-stock',
        'day-close-report',
        'reports-analytics',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        // Real table shape (create + controls migrations combined).
        Schema::create('tutorial_videos', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('product', 30)->default('nestpos');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('video_url');
            $table->string('category')->default('shuruat');
            $table->string('required_feature', 50)->nullable();
            $table->integer('sort')->default(0);
            $table->boolean('is_published')->default(true);
            $table->boolean('show_public')->default(false);
            $table->integer('duration_seconds')->nullable();
            $table->timestamps();
        });
    }

    private function runSeedMigration(): void
    {
        $migration = require base_path('database/migrations/2026_08_03_190000_add_core_shop_tutorial_videos.php');
        $migration->up();
    }

    public function test_mp4_files_are_committed_for_all_four_tutorials(): void
    {
        foreach (self::SLUGS as $slug) {
            $path = public_path("videos/tutorials/{$slug}.mp4");
            $this->assertFileExists($path, "Missing shipped video file for {$slug}");
            $this->assertGreaterThan(
                1_000_000,
                filesize($path),
                "Video file for {$slug} is suspiciously small — broken/placeholder render?"
            );
        }
    }

    public function test_migration_seeds_all_four_rows_idempotently(): void
    {
        $this->runSeedMigration();
        $this->runSeedMigration(); // second run must not duplicate

        foreach (self::SLUGS as $slug) {
            $rows = DB::table('tutorial_videos')->where('slug', $slug)->get();
            $this->assertCount(1, $rows, "Expected exactly one row for {$slug}");
            $this->assertSame("/videos/tutorials/{$slug}.mp4", $rows[0]->video_url);
            $this->assertSame(1, (int) $rows[0]->is_published, "{$slug} must be visible in /pos/tutorials");
            $this->assertSame(0, (int) $rows[0]->show_public, "{$slug} must NOT auto-publish to the landing page");
        }
    }

    public function test_rows_survive_the_missing_file_guard(): void
    {
        $this->runSeedMigration();

        $videos = TutorialVideo::published()->orderBy('sort')->get();
        $grouped = TutorialVideo::groupedFrom($videos);

        $visibleSlugs = collect($grouped)
            ->flatMap(fn ($group) => collect($group['videos'])->pluck('slug'))
            ->all();

        foreach (self::SLUGS as $slug) {
            $this->assertContains(
                $slug,
                $visibleSlugs,
                "{$slug} hidden by the missing-file guard — is its MP4 really committed?"
            );
        }
    }
}
