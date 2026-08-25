<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * kot_last_addon_enabled split ka follow-up.
 *
 * Naya column default TRUE rakha gaya tha taake chalti hui dukanon ka rawaiya
 * na badle. Magar jis shop ne pehle JAAN BOOJH KAR purana kot_reprint_enabled
 * band kiya tha, us ke liye kitchen ke DONO parche band the — aur naye column
 * ne "Last Add-on" us ki marzi ke baghair dobara khol diya. Yeh access ka izafa
 * hai jo kisi ne maanga nahi tha.
 *
 * Is liye purani shops ki marzi wapas: jahan Re-send band tha, wahan Last
 * Add-on bhi band. Nayi shops par default TRUE waisa hi rehta hai.
 *
 * hasColumn guard: owner ke cPanel PROD par columns "Ran" mark ho kar ghayab
 * mile hain, is liye migration idempotent rakhi hai.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('companies')
            || !Schema::hasColumn('companies', 'kot_last_addon_enabled')
            || !Schema::hasColumn('companies', 'kot_reprint_enabled')) {
            return;
        }

        DB::table('companies')
            ->where('kot_reprint_enabled', false)
            ->update(['kot_last_addon_enabled' => false]);
    }

    public function down(): void
    {
        // Shop ki apni marzi bahal karne wali one-way migration. Ise ulatna
        // access dobara kholna hoga, is liye down jaan boojh kar khali hai.
    }
};
