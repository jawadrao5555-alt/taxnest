<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What's New announcement for POS Team Custom Access (Task #111).
 * Data migration (idempotent) because PROD deploys run `migrate --force`,
 * never seeders. Audience 'pos', points array in Roman Urdu per convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('app_updates')) {
            return;
        }
        $title = 'Naya: Team Custom Access — har member ke liye features chunein';
        if (DB::table('app_updates')->where('title', $title)->exists()) {
            return;
        }
        DB::table('app_updates')->insert([
            'title' => $title,
            'points' => json_encode([
                '/pos/team par Cashier ya Manager ki row mein naya "Custom Access" button — tick-boxes se chunein ke us member ko kaunse features milen (Dashboard, Orders, Products, Customers, Reports, Day Close, Inventory, Customize, Team waghera).',
                'Jo features tick hon sirf wohi navigation mein nazar aate hain; baqi pages band. Sale screen hamesha khuli rehti hai — billing kabhi nahi rukti.',
                'Custom Access OFF rahe to sab kuch pehle jaisa — kisi purani dukan par koi asar nahi.',
                'Kitchen, Waiter, Rider aur Delivery roles pehle ki tarah apne mehdood ilaqe mein hi rehte hain.',
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
            DB::table('app_updates')
                ->where('title', 'Naya: Team Custom Access — har member ke liye features chunein')
                ->delete();
        }
    }
};
