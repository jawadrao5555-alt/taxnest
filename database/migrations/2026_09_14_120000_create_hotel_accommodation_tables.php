<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hotel / Guest House V1 — rooms, stays, folio.
 *
 * Stay numbers (HS001) are operational, never fiscal. Fiscal invoices stay on
 * pos_transactions using PosFinalSeries / PosLocalSeries.
 *
 * Additive only: no company feature_flags are rewritten here. New hotel
 * signups get rooms=true from PosFeatureService::registrationAttributes;
 * existing hotels keep their stored kitchen / module choices.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hotel_stay_series_counters')) {
            Schema::create('hotel_stay_series_counters', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->unique();
                $table->unsignedBigInteger('last_number')->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('hotel_rooms')) {
            Schema::create('hotel_rooms', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('room_number', 32);
                $table->string('room_type', 80)->default('Standard');
                $table->unsignedSmallInteger('capacity')->default(2);
                $table->decimal('rate_amount', 14, 2)->default(0);
                $table->string('rate_unit', 8)->default('NGT');
                $table->string('charging_rule', 24)->default('nightly');
                $table->string('service_state', 24)->default('in_service'); // in_service | out_of_service
                $table->string('housekeeping', 24)->default('clean'); // clean | dirty | inspected
                $table->text('notes')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['company_id', 'branch_id', 'room_number'], 'hotel_rooms_company_branch_number_uq');
            });
        }

        if (!Schema::hasTable('hotel_stays')) {
            Schema::create('hotel_stays', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('stay_number', 32);
                $table->string('status', 24)->default('reserved'); // reserved|checked_in|checked_out|cancelled|no_show
                $table->unsignedBigInteger('room_id')->nullable()->index();
                $table->unsignedBigInteger('guest_customer_id')->nullable()->index();
                $table->unsignedBigInteger('payer_customer_id')->nullable()->index();
                $table->string('guest_name', 160);
                $table->string('guest_phone', 40)->nullable();
                $table->string('guest_cnic', 20)->nullable();
                $table->date('check_in_date');
                $table->date('check_out_date');
                $table->timestamp('actual_check_in_at')->nullable();
                $table->timestamp('actual_check_out_at')->nullable();
                $table->unsignedSmallInteger('adult_count')->default(1);
                $table->unsignedSmallInteger('child_count')->default(0);
                $table->unsignedInteger('nights')->default(1);
                $table->decimal('rate_amount', 14, 2)->default(0);
                $table->string('rate_unit', 8)->default('NGT');
                $table->string('charging_rule', 24)->default('nightly');
                $table->text('notes')->nullable();
                $table->string('cancel_reason', 255)->nullable();
                $table->string('idempotency_key', 64)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'stay_number'], 'hotel_stays_company_number_uq');
                $table->unique(['company_id', 'idempotency_key'], 'hotel_stays_company_idem_uq');
                $table->index(['company_id', 'status', 'check_in_date', 'check_out_date'], 'hotel_stays_overlap_lookup');
            });
        }

        if (!Schema::hasTable('hotel_stay_assignments')) {
            Schema::create('hotel_stay_assignments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('stay_id')->index();
                $table->unsignedBigInteger('room_id')->index();
                $table->date('from_date');
                $table->date('to_date');
                $table->decimal('rate_amount', 14, 2)->default(0);
                $table->string('rate_unit', 8)->default('NGT');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('hotel_stay_guests')) {
            Schema::create('hotel_stay_guests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('stay_id')->index();
                $table->unsignedBigInteger('customer_id')->nullable()->index();
                $table->boolean('is_primary')->default(false);
                $table->string('name', 160);
                $table->string('phone', 40)->nullable();
                $table->string('cnic', 20)->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('hotel_folio_entries')) {
            Schema::create('hotel_folio_entries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('stay_id')->index();
                $table->string('entry_type', 24); // charge|payment|deposit|refund|deposit_refund|adjustment
                $table->string('category', 24)->default('room'); // room|food|laundry|extra|other
                $table->string('description', 255);
                $table->decimal('quantity', 12, 3)->default(1);
                $table->string('uom', 8)->default('NOS');
                $table->decimal('unit_amount', 14, 2)->default(0);
                $table->decimal('amount', 14, 2)->default(0);
                $table->unsignedBigInteger('pos_transaction_id')->nullable()->index();
                $table->unsignedBigInteger('product_id')->nullable();
                $table->string('payment_method', 32)->nullable();
                $table->boolean('is_deposit')->default(false);
                $table->unsignedBigInteger('reverses_entry_id')->nullable();
                $table->string('idempotency_key', 64)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'idempotency_key'], 'hotel_folio_company_idem_uq');
                $table->index(['stay_id', 'entry_type'], 'hotel_folio_stay_type_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_folio_entries');
        Schema::dropIfExists('hotel_stay_guests');
        Schema::dropIfExists('hotel_stay_assignments');
        Schema::dropIfExists('hotel_stays');
        Schema::dropIfExists('hotel_rooms');
        Schema::dropIfExists('hotel_stay_series_counters');
    }
};
