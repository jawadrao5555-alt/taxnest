<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('companies', 'pos_staff_discount_enabled')) {
            Schema::table('companies', fn (Blueprint $t) => $t->boolean('pos_staff_discount_enabled')->default(false));
        }
        if (!Schema::hasTable('pos_staff_discount_requests')) {
            Schema::create('pos_staff_discount_requests', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('branch_id')->nullable();
                $t->uuid('request_uuid'); $t->unsignedBigInteger('requester_id'); $t->unsignedBigInteger('employee_id');
                $t->string('kind', 10); $t->string('reason', 500); $t->char('cart_hash', 64);
                $t->text('order_snapshot'); $t->decimal('eligible_subtotal', 14, 2);
                $t->string('proposed_type', 12); $t->decimal('proposed_value', 14, 2);
                $t->string('approved_type', 12)->nullable(); $t->decimal('approved_value', 14, 2)->nullable();
                $t->string('status', 16)->default('pending'); $t->unsignedInteger('revision')->default(1);
                $t->unsignedBigInteger('order_id')->nullable(); $t->unsignedBigInteger('reviewer_id')->nullable();
                $t->dateTime('reviewed_at')->nullable(); $t->unsignedBigInteger('transaction_id')->nullable();
                $t->timestamps(); $t->unique(['company_id', 'request_uuid'], 'pos_staff_request_uuid');
                $t->index(['company_id', 'order_id', 'status'], 'pos_staff_order_status');
            });
        }
        if (!Schema::hasTable('pos_staff_discount_events')) {
            Schema::create('pos_staff_discount_events', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('request_id');
                $t->unsignedBigInteger('actor_id'); $t->string('action', 16); $t->unsignedInteger('revision');
                $t->text('snapshot'); $t->dateTime('created_at');
                $t->index(['company_id', 'request_id'], 'pos_staff_event_request');
            });
        }
    }
    public function down(): void
    {
        Schema::dropIfExists('pos_staff_discount_events'); Schema::dropIfExists('pos_staff_discount_requests');
        if (Schema::hasColumn('companies', 'pos_staff_discount_enabled')) Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('pos_staff_discount_enabled'));
    }
};
