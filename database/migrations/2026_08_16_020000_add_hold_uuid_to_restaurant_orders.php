<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 1001 (16 Aug 2026): hold_uuid idempotency key on restaurant_orders.
 *
 * The standalone Hold (F5) and Send-to-Kitchen buttons had no idempotency key:
 * a lost server response followed by a retry created a duplicate held order
 * (ghost KOT in the kitchen, twin row in Held Orders strip). The pay_uuid
 * pattern from Task 994 is applied here — the client generates one UUID per
 * hold attempt, reuses it on retry; the server returns the original order
 * when the uuid is already known.
 *
 * hold_uuid (VARCHAR 64, nullable, unique): set at order birth from the
 * client's per-attempt UUID. NULL = old orders created before this feature
 * (no dedup possible). Unique index makes the replay lookup an index scan.
 *
 * Idempotent + hasColumn guard (prod schema-drift memory).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('restaurant_orders', 'hold_uuid')) {
                $table->string('hold_uuid', 64)->nullable()->unique();
            }
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_orders', function (Blueprint $table) {
            if (Schema::hasColumn('restaurant_orders', 'hold_uuid')) {
                $table->dropColumn('hold_uuid');
            }
        });
    }
};
