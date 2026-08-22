<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer delivery memory for PRA POS rider tracking.
 *
 * No foreign keys: POS tables are shared with older installs and every lookup
 * is company-scoped in code. Historical bill address/location snapshots remain
 * untouched; the new place id is only a private pointer for future deliveries.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pos_customer_places')) {
            Schema::create('pos_customer_places', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->string('customer_phone', 40)->nullable();
                $table->string('place_type', 20)->default('other');
                $table->string('label', 80)->nullable();
                $table->text('address')->nullable();
                $table->decimal('lat', 10, 7);
                $table->decimal('lng', 10, 7);
                $table->unsignedSmallInteger('accuracy_m')->nullable();
                $table->boolean('is_verified')->default(false);
                $table->timestamp('verified_at')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->unsignedInteger('usage_count')->default(0);
                $table->string('created_from', 20)->default('rider');
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->unsignedBigInteger('deleted_by')->nullable();
                $table->unsignedBigInteger('merged_into_id')->nullable();
                $table->unsignedBigInteger('merged_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['company_id', 'customer_id', 'deleted_at'], 'pcp_company_customer_active');
                $table->index(['company_id', 'customer_phone', 'deleted_at'], 'pcp_company_phone_active');
                $table->index(['company_id', 'last_used_at'], 'pcp_company_last_used');
            });
        }

        if (!Schema::hasTable('pos_delivery_completions')) {
            Schema::create('pos_delivery_completions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('transaction_id');
                $table->unsignedBigInteger('rider_id');
                $table->unsignedBigInteger('customer_place_id')->nullable();
                $table->uuid('client_event_id')->nullable();
                $table->string('assignment_revision', 100)->nullable();
                $table->string('place_type', 20)->default('other');
                $table->string('place_label', 80)->nullable();
                $table->decimal('destination_lat', 10, 7)->nullable();
                $table->decimal('destination_lng', 10, 7)->nullable();
                $table->decimal('completed_lat', 10, 7)->nullable();
                $table->decimal('completed_lng', 10, 7)->nullable();
                $table->unsignedSmallInteger('accuracy_m')->nullable();
                $table->timestamp('captured_at')->nullable();
                $table->unsignedInteger('distance_m')->nullable();
                $table->boolean('proximity_verified')->default(false);
                $table->string('evidence_source', 20)->default('legacy');
                $table->timestamps();

                $table->unique(['company_id', 'transaction_id'], 'pdc_company_transaction_unique');
                $table->unique(['rider_id', 'client_event_id'], 'pdc_rider_event_unique');
                $table->index(['company_id', 'customer_place_id', 'captured_at'], 'pdc_company_place_time');
            });
        }

        if (Schema::hasTable('pos_transactions')
            && !Schema::hasColumn('pos_transactions', 'customer_place_id')) {
            Schema::table('pos_transactions', function (Blueprint $table) {
                $table->unsignedBigInteger('customer_place_id')->nullable()
                    ->comment('Private saved delivery place; historical bill snapshots stay unchanged');
                $table->index(['company_id', 'customer_place_id'], 'pt_company_customer_place');
            });
        }
    }

    public function down(): void
    {
        // Additive-only migration: preserving delivery evidence is safer than a
        // destructive rollback on the owner-managed production database.
    }
};