<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task (owner, 25 Aug 2026) — "Re-send KOT / Last Add-on KOT ka enable-disable
 * option chahiye."
 *
 * Ek switch (companies.kot_reprint_enabled) pehle se tha, magar wo DONO buttons
 * ko ek saath band karta tha. Shop ki asal takleef alag hai: poora order dobara
 * kitchen ko bhejna (Re-send) khatarnak hai — 4-5 kitchen wale hain, kisi ko
 * pata nahi chalta ke yeh parcha naya hai ya pehle wala, aur cheez DOBARA pak
 * jati hai. "Aakhri Add-on" ka parcha is se bilkul mukhtalif hai: wo sirf naye
 * add-on items dikhata hai aur rozana ka jaiz kaam hai.
 *
 * Is liye ab donon alag switch hain. Purana kot_reprint_enabled ab sirf Re-send
 * ko chalata hai; yeh naya column Last Add-on ko.
 *
 * DEFAULT TRUE — aaj jin shops par dono buttons chal rahe hain unka rawaiya
 * bilkul na badle. Sirf wo shop farq dekhegi jo khud ja kar switch band kare.
 *
 * hasColumn guard: owner ke cPanel PROD par pehle bhi columns "Ran" mark ho kar
 * ghayab mile hain, is liye migration idempotent rakhi hai.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('companies') && !Schema::hasColumn('companies', 'kot_last_addon_enabled')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->boolean('kot_last_addon_enabled')->default(true)->after('kot_reprint_enabled');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'kot_last_addon_enabled')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('kot_last_addon_enabled');
            });
        }
    }
};
