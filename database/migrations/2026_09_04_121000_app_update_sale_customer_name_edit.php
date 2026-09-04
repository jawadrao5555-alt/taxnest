<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TITLE = 'Sale screen se customer ka naam durust karein';

    public function up(): void
    {
        if (!Schema::hasTable('app_updates')
            || DB::table('app_updates')->where('title', self::TITLE)->exists()) {
            return;
        }

        DB::table('app_updates')->insert([
            'title' => self::TITLE,
            'points' => json_encode([
                'Customer search result ke saath edit icon se ghalat ya temporary naam foran durust karein.',
                'Purane imported X, AX ya XYZ naam ko customer dobara banaye baghair update kiya ja sakta hai.',
                'Customer ka phone number mehfooz aur wahi rehta hai; sirf naam change hota hai.',
            ], JSON_UNESCAPED_UNICODE),
            'audience' => 'pos',
            'is_published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('app_updates')) {
            DB::table('app_updates')->where('title', self::TITLE)->delete();
        }
    }
};