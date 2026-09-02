<?php

namespace Tests\Feature;

use App\Models\TutorialVideo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StockCheckTutorialVideoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();
        Schema::create('tutorial_videos', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('product', 30)->default('nestpos');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('video_url');
            $table->string('category')->default('shuruat');
            $table->string('required_feature', 50)->nullable();
            $table->string('min_role', 20)->default('any');
            $table->integer('sort')->default(0);
            $table->boolean('is_published')->default(true);
            $table->boolean('show_public')->default(false);
            $table->integer('duration_seconds')->nullable();
            $table->timestamps();
        });
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_10_07_000000_add_stock_check_tutorial_video.php');
    }

    public function test_migration_registers_the_published_public_admin_stock_check_video_idempotently(): void
    {
        $migration = $this->migration();
        $migration->up();
        $migration->up();

        $rows = DB::table('tutorial_videos')->where('slug', 'stock-check')->get();
        $this->assertCount(1, $rows);
        $video = $rows->first();
        $this->assertSame('nestpos', $video->product);
        $this->assertSame('products', $video->category);
        $this->assertSame('admin', $video->min_role);
        $this->assertSame('/videos/tutorials/stock-check.mp4', $video->video_url);
        $this->assertSame(1, (int) $video->is_published);
        $this->assertSame(1, (int) $video->show_public);
        $this->assertSame(270, (int) $video->duration_seconds);
    }

    public function test_existing_admin_edited_row_is_not_overwritten(): void
    {
        DB::table('tutorial_videos')->insert([
            'slug' => 'stock-check',
            'title' => 'Admin ka apna title',
            'video_url' => 'https://videos.example.test/edited.mp4',
            'category' => 'settings',
            'min_role' => 'any',
            'is_published' => false,
            'show_public' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->migration()->up();

        $video = DB::table('tutorial_videos')->where('slug', 'stock-check')->first();
        $this->assertSame('Admin ka apna title', $video->title);
        $this->assertSame('https://videos.example.test/edited.mp4', $video->video_url);
        $this->assertSame(0, (int) $video->is_published);
    }

    public function test_shipped_stock_check_file_survives_the_local_file_guard(): void
    {
        $path = public_path('videos/tutorials/stock-check.mp4');
        $this->assertFileExists($path);
        $this->assertGreaterThan(1_000_000, filesize($path));

        $this->migration()->up();
        $groups = TutorialVideo::groupedFrom(TutorialVideo::published()->get());
        $visible = collect($groups)->flatMap(fn (array $group) => $group['videos']->pluck('slug'));

        $this->assertContains('stock-check', $visible->all());
    }
}