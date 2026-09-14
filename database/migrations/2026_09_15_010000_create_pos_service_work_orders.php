<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_service_work_order_series')) {
            Schema::create('pos_service_work_order_series', function (Blueprint $table) {
                $table->unsignedBigInteger('company_id')->primary();
                $table->unsignedBigInteger('next_number')->default(1);
                $table->timestamps();
                $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            });
        }
        if (! Schema::hasTable('pos_service_work_orders')) {
            Schema::create('pos_service_work_orders', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->string('category', 64);
                $table->string('job_number', 32);
                $table->unsignedBigInteger('service_id')->nullable();
                $table->string('customer_name');
                $table->string('customer_phone', 40)->nullable();
                $table->string('title');
                $table->timestamp('scheduled_at')->nullable();
                $table->timestamp('due_at')->nullable();
                $table->string('status', 40);
                $table->decimal('quantity', 12, 3)->default(1);
                $table->decimal('unit_price', 15, 2)->default(0);
                $table->decimal('total_amount', 15, 2)->default(0);
                $table->json('details')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'job_number'], 'pos_service_job_company_number_uq');
                $table->index(['company_id', 'branch_id', 'status'], 'pos_service_job_scope_status_idx');
                $table->index(['company_id', 'scheduled_at'], 'pos_service_job_schedule_idx');
                $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
                $table->foreign('service_id')->references('id')->on('pos_services')->onDelete('set null');
            });
        }
        if (! Schema::hasTable('pos_service_work_order_events')) {
            Schema::create('pos_service_work_order_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('work_order_id');
                $table->string('from_status', 40)->nullable();
                $table->string('to_status', 40);
                $table->text('note')->nullable();
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->timestamp('occurred_at');
                $table->timestamps();
                $table->index(['work_order_id', 'occurred_at'], 'pos_service_job_event_timeline_idx');
                $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
                $table->foreign('work_order_id')->references('id')->on('pos_service_work_orders')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_service_work_order_events');
        Schema::dropIfExists('pos_service_work_orders');
        Schema::dropIfExists('pos_service_work_order_series');
    }
};
