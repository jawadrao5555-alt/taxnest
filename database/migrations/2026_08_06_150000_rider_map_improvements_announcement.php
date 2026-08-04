<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "What's New" elaan — Rider Tracking map improvements (Aug 2026):
 * apni city par khulta hai (riders/IP), place search box, English basemap.
 * Idempotent data migration — prod deploys run `migrate --force` (never seed).
 */
return new class extends Migration
{
    private string $title = 'Rider Tracking ka naqsha behtar: apni city, search aur English naam';

    public function up(): void
    {
        if (!Schema::hasTable('app_updates')) {
            return;
        }

        if (!DB::table('app_updates')->where('title', $this->title)->where('audience', 'pos')->exists()) {
            DB::table('app_updates')->insert([
                'title' => $this->title,
                'points' => json_encode([
                    'Map ab hamesha Lahore par nahi khulta — aap ke riders jahan hon wahan, warna aap ke internet ki location se aap ki apni city par khulta hai (jaise Lodhran).',
                    'Map ke upar naya search box — koi bhi jagah likh kar dhoondein, naqsha seedha wahan chala jaye ga.',
                    'Naqshe par shehron aur jaghon ke naam ab English mein nazar aate hain (pehle Urdu script mein aate thay).',
                ], JSON_UNESCAPED_UNICODE),
                'image_path' => null,
                'audience' => 'pos',
                'is_published' => true,
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('app_updates')) {
            return;
        }
        DB::table('app_updates')->where('title', $this->title)->where('audience', 'pos')->delete();
    }
};
