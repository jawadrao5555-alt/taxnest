<?php

namespace Tests\Feature;

use App\Models\TutorialVideo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 253 tutorial videos — shipped-for-real guard.
 *
 * Guards against the "pipeline-only, not shipped" regression that hit these
 * exact videos in task 234: the four tutorials (team/custom access,
 * settings-branding, offline-mode, pra-mode) must
 * (1) have their MP4 files committed under public/videos/tutorials/,
 * (2) be seeded by their migration with matching video_url rows, and
 * (3) get the owner controls applied (offline lockdown + custom-access gate).
 *
 * Run with:
 *   APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: \
 *     php artisan test --filter=Task253TutorialVideosTest
 */
class Task253TutorialVideosTest extends TestCase
{
    private const SLUGS = [
        'team-custom-access',
        'settings-branding',
        'offline-mode',
        'pra-mode',
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
            $table->boolean('controls_applied')->default(false);
            $table->integer('duration_seconds')->nullable();
            $table->timestamps();
        });
    }

    private function runSeedMigration(): void
    {
        $migration = require base_path('database/migrations/2026_08_03_170000_add_task253_tutorial_videos.php');
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
            $this->assertSame(1, (int) $rows[0]->is_published, "{$slug} seeded published (offline lockdown comes from applyOwnerControls)");
            $this->assertSame(0, (int) $rows[0]->show_public, "{$slug} must NOT auto-publish to the landing page");
        }
    }

    public function test_owner_controls_gate_and_offline_lockdown(): void
    {
        $this->runSeedMigration();
        TutorialVideo::applyOwnerControls();

        $offline = TutorialVideo::where('slug', 'offline-mode')->first();
        $this->assertSame(0, (int) $offline->is_published, 'offline video must be force-unpublished');
        $this->assertSame(0, (int) $offline->show_public);

        $team = TutorialVideo::where('slug', 'team-custom-access')->first();
        $this->assertSame('custom_access_enabled', $team->required_feature, 'team video must pick up the custom-access slug gate');

        foreach (['settings-branding', 'pra-mode'] as $slug) {
            $row = TutorialVideo::where('slug', $slug)->first();
            $this->assertNull($row->required_feature, "{$slug} is core — no plan gate");
            $this->assertSame(1, (int) $row->is_published);
        }
    }

    public function test_rows_survive_the_missing_file_guard(): void
    {
        $this->runSeedMigration();
        TutorialVideo::applyOwnerControls();

        $videos = TutorialVideo::published()->orderBy('sort')->get();
        $grouped = TutorialVideo::groupedFrom($videos);

        $visibleSlugs = collect($grouped)
            ->flatMap(fn ($group) => collect($group['videos'])->pluck('slug'))
            ->all();

        foreach (['team-custom-access', 'settings-branding', 'pra-mode'] as $slug) {
            $this->assertContains(
                $slug,
                $visibleSlugs,
                "{$slug} hidden by the missing-file guard — is its MP4 really committed?"
            );
        }
    }
}
