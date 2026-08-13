<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 579 — What's New elaan: owners can now set their own CNIC (Business
 * Profile) and username (My Profile) and use them to log in. Data migration
 * only (idempotent insert); runs on prod via `migrate --force` at deploy.
 */
return new class extends Migration
{
    private const TITLE = 'CNIC / Username se login — ab khud set karein';

    public function up(): void
    {
        if (! Schema::hasTable('app_updates')) {
            return;
        }
        if (DB::table('app_updates')->where('title', self::TITLE)->exists()) {
            return;
        }

        DB::table('app_updates')->insert([
            'title' => self::TITLE,
            'points' => json_encode([
                'Business Profile par ab malik ka CNIC khud set/update kar sakte hain — usi CNIC se login karein (dash ke saath ya baghair).',
                'My Profile se username set karein aur email ki jagah username se login karein.',
                'Login ke 5 tareeqay: Email, Phone, Username, NTN, CNIC — jo yaad rahe wohi istemal karein.',
            ], JSON_UNESCAPED_UNICODE),
            'audience' => 'all',
            'is_published' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('app_updates')) {
            return;
        }
        DB::table('app_updates')->where('title', self::TITLE)->delete();
    }
};
