<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shows a waiter appending products to an already-sent order before the
 * cashier makes one final PRA POS bill.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tutorial_videos')) {
            return;
        }

        $row = [
            'slug' => 'waiter-add-products',
            'title' => 'Waiter Order mein Mazeed Items — PRA POS',
            'description' => 'Send Order ke baad My Orders se Add Items kholna, naye products bhejna, updated order cashier ko dena aur ek hi PRA bill complete karna.',
            'video_url' => '/videos/tutorials/waiter-add-products.mp4',
            'category' => 'restaurant',
            'sort' => 43,
            'min_role' => 'any',
            'required_feature' => 'restaurant',
            'show_public' => true,
            'duration_seconds' => 170,
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
            DB::table('tutorial_videos')->where('slug', 'waiter-add-products')->delete();
        }
    }
};