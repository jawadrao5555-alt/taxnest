<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 1194 — Device-aware KOT printer picking ("kisi bhi counter ka
 * printer, kahin se bhi").
 *
 * The KOT-family printer picks (Kitchen/KOT, Counter KOT Copy, per-station)
 * can now point at a printer that physically lives on ONE counter PC of a
 * multi-counter shop. The company-level picks store their owning device in
 * the pos_printer_settings JSON (kot_printer_device / counter_kot_printer_device
 * — no schema change needed); each kitchen station gets its own column here.
 *
 * NULL = legacy pick (printer name only) → jobs stay unstamped and behave
 * exactly as before. Idempotent + guarded (prod schema-drift convention).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_stations') && !Schema::hasColumn('pos_stations', 'printer_device_uid')) {
            Schema::table('pos_stations', function (Blueprint $table) {
                $table->string('printer_device_uid', 64)->nullable()->after('printer_name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pos_stations') && Schema::hasColumn('pos_stations', 'printer_device_uid')) {
            Schema::table('pos_stations', function (Blueprint $table) {
                $table->dropColumn('printer_device_uid');
            });
        }
    }
};
