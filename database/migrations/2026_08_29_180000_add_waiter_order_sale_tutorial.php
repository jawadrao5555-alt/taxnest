<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dedicated waiter-to-cashier tutorial (Task 1536).
 *
 * The waiter and cashier are both part of this workflow, so it is visible to
 * every POS role. The restaurant gate keeps it out of non-restaurant shops.
 * The row is slug-idempotent so production deploys can safely run migrate
 * --force and preserve owner edits made in the admin panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tutorial_videos')) {
            return;
        }

        $row = [
            'slug' => 'waiter-order-sale',
            'title' => 'Waiter se Order — Counter par Sale Complete',
            'description' => 'Waiter tablet par login, table aur items select karna, order counter ko bhejna, phir cashier ka incoming order khol kar payment lena — poora real flow.',
            'video_url' => '/videos/tutorials/waiter-order-sale.mp4',
            'category' => 'restaurant',
            'sort' => 42,
            'min_role' => 'any',
            'required_feature' => 'restaurant',
            'show_public' => true,
            'duration_seconds' => 140,
        ];

        $existing = DB::table('tutorial_videos')->where('slug', $row['slug'])->first();
        if ($existing) {
            DB::table('tutorial_videos')->where('id', $existing->id)->update([
                'video_url' => $row['video_url'],
                'duration_seconds' => $row['duration_seconds'],
                'updated_at' => now(),
            ]);
            return;
        }

        DB::table('tutorial_videos')->insert($row + [
            'is_published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('tutorial_videos')) {
            DB::table('tutorial_videos')->where('slug', 'waiter-order-sale')->delete();
        }
    }
};