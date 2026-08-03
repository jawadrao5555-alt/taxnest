<?php

namespace Tests\Feature;

use App\Models\TutorialVideo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tutorial library — missing-file guard.
 *
 * Rows are seeded by migration AHEAD of their MP4s landing in the repo
 * (har video task apni file khud commit karta hai). groupedFrom()
 * must therefore hide any published row whose local /videos/... file does
 * not exist yet — warna /tutorials aur /pos/tutorials par toota hua player
 * dikhta hai. File aate hi card khud zaahir ho jata hai (self-healing,
 * koi migration churn nahi).
 *
 * Run with:
 *   APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: \
 *     php artisan test --filter=TutorialVideoMissingFileTest
 */
class TutorialVideoMissingFileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('tutorial_videos', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('video_url');
            $table->string('category')->default('shuruat');
            $table->integer('sort')->default(0);
            $table->boolean('is_published')->default(true);
            $table->integer('duration_seconds')->nullable();
            $table->timestamps();
        });
    }

    private function insertRow(string $slug, string $url, string $category = 'billing', bool $published = true): void
    {
        DB::table('tutorial_videos')->insert([
            'slug' => $slug,
            'title' => "Video $slug",
            'video_url' => $url,
            'category' => $category,
            'sort' => 1,
            'is_published' => $published,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_rows_with_missing_local_files_are_hidden(): void
    {
        // Committed, real file (pilot video ships with the repo).
        $this->insertRow('exists-local', '/videos/tutorials/sale-screen-tutorial.mp4');
        // Seeded ahead of its file — MUST stay hidden until the MP4 lands.
        $this->insertRow('missing-local', '/videos/tutorials/not-committed-yet.mp4');
        // External URL — passes through untouched.
        $this->insertRow('external', 'https://cdn.example.com/v.mp4');
        // Unpublished rows stay hidden regardless of file existence.
        $this->insertRow('unpublished', '/videos/tutorials/sale-screen-tutorial.mp4', 'billing', false);

        $this->assertFileExists(public_path('videos/tutorials/sale-screen-tutorial.mp4'));

        $groups = TutorialVideo::groupedFrom(
            TutorialVideo::published()->orderBy('sort')->orderBy('id')->get()
        );
        $slugs = collect($groups)
            ->flatMap(fn ($g) => $g['videos']->pluck('slug'))
            ->values()
            ->all();

        $this->assertContains('exists-local', $slugs);
        $this->assertContains('external', $slugs);
        $this->assertNotContains('missing-local', $slugs, 'Row without its MP4 must not render a broken card');
        $this->assertNotContains('unpublished', $slugs);
    }

    public function test_task233_module_videos_are_committed_and_displayable(): void
    {
        // The three Task-233 files must exist in the repo…
        foreach ([
            'restaurant-mode-tutorial',
            'delivery-riders-tutorial',
            'deals-tutorial',
        ] as $slug) {
            $this->assertFileExists(
                public_path("videos/tutorials/$slug.mp4"),
                "Task 233 video file [$slug.mp4] must be committed"
            );
            $this->insertRow($slug, "/videos/tutorials/$slug.mp4", 'restaurant');
        }

        // …and therefore all three render.
        $groups = TutorialVideo::groupedFrom(
            TutorialVideo::published()->orderBy('sort')->orderBy('id')->get()
        );
        $slugs = collect($groups)
            ->flatMap(fn ($g) => $g['videos']->pluck('slug'))
            ->values()
            ->all();

        $this->assertCount(3, $slugs);
    }
}
