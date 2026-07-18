<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery Riders feature (PRA POS restaurant module).
 *
 * - pos_riders: per-company rider records (optional login via user_id).
 * - pos_rider_settlements: cash-settlement events (rider hands over cash).
 * - pos_transactions: rider assignment + delivery lifecycle + khata stamps.
 * - pos_day_close_reports: per-day rider snapshot JSON for historic replays.
 *
 * Idempotent (hasTable/hasColumn guards) — prod schema drift safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pos_riders')) {
            Schema::create('pos_riders', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->string('name', 120);
                $table->string('phone', 30)->nullable();
                $table->string('cnic', 20)->nullable();
                $table->string('vehicle_no', 30)->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('pos_rider_settlements')) {
            Schema::create('pos_rider_settlements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('rider_id')->index();
                $table->unsignedBigInteger('settled_by')->nullable();
                $table->decimal('total_amount', 12, 2)->default(0);
                $table->unsignedInteger('bill_count')->default(0);
                $table->string('notes', 500)->nullable();
                $table->timestamps();
            });
        }

        Schema::table('pos_transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_transactions', 'rider_id')) {
                $table->unsignedBigInteger('rider_id')->nullable()->index()->after('customer_phone');
            }
            if (!Schema::hasColumn('pos_transactions', 'order_type')) {
                $table->string('order_type', 20)->nullable()->after('rider_id');
            }
            if (!Schema::hasColumn('pos_transactions', 'delivery_status')) {
                $table->string('delivery_status', 20)->nullable()->after('order_type');
            }
            if (!Schema::hasColumn('pos_transactions', 'rider_settlement_id')) {
                $table->unsignedBigInteger('rider_settlement_id')->nullable()->index()->after('delivery_status');
            }
            if (!Schema::hasColumn('pos_transactions', 'rider_settled_at')) {
                $table->timestamp('rider_settled_at')->nullable()->after('rider_settlement_id');
            }
        });

        Schema::table('pos_day_close_reports', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_day_close_reports', 'rider_summary')) {
                $table->text('rider_summary')->nullable()->after('local_summary');
            }
        });
    }

    public function down(): void
    {
        // Additive only — no destructive down (prod safety).
    }
};
