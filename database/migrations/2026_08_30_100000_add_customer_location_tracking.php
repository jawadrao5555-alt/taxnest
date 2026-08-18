<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task #1105 (Aug 2026) — Customer live tracking link & delivery ETA.
 *
 * pos_transactions:
 *  - customer_lat / customer_lng: per-bill customer delivery pin
 *    (pasted Google Maps link or picked on the mini map).
 *  - track_token: long random public token for the customer-facing
 *    "aapka rider yahan hai" live map page (/track/{token}). NULL until
 *    the shop presses "Track link". Read-time invalidation: the public
 *    endpoints refuse once delivery_status is delivered/returned.
 *
 * pos_customers:
 *  - geo_lat / geo_lng: remembered location per customer (keyed by phone
 *    at save time) so the next bill's locate modal starts pre-pinned.
 *
 * Idempotent (per-column hasColumn guards) — prod schema drift safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_transactions')) {
            if (!Schema::hasColumn('pos_transactions', 'customer_lat')) {
                Schema::table('pos_transactions', function (Blueprint $table) {
                    $table->decimal('customer_lat', 10, 7)->nullable()
                        ->comment('Customer delivery pin (Task 1105)');
                });
            }
            if (!Schema::hasColumn('pos_transactions', 'customer_lng')) {
                Schema::table('pos_transactions', function (Blueprint $table) {
                    $table->decimal('customer_lng', 10, 7)->nullable();
                });
            }
            if (!Schema::hasColumn('pos_transactions', 'track_token')) {
                Schema::table('pos_transactions', function (Blueprint $table) {
                    $table->string('track_token', 64)->nullable()->index()
                        ->comment('Public live-tracking token; dead once delivered/returned');
                });
            }
        }

        if (Schema::hasTable('pos_customers')) {
            if (!Schema::hasColumn('pos_customers', 'geo_lat')) {
                Schema::table('pos_customers', function (Blueprint $table) {
                    $table->decimal('geo_lat', 10, 7)->nullable()
                        ->comment('Remembered delivery pin (Task 1105)');
                });
            }
            if (!Schema::hasColumn('pos_customers', 'geo_lng')) {
                Schema::table('pos_customers', function (Blueprint $table) {
                    $table->decimal('geo_lng', 10, 7)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        // Additive only — no destructive down (prod safety).
    }
};
