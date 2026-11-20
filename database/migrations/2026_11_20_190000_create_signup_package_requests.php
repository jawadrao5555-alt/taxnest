<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('signup_package_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->unique();
            $table->unsignedBigInteger('pricing_plan_id');
            $table->string('plan_name');
            $table->string('billing_cycle', 16);
            $table->decimal('quoted_amount', 14, 2);
            $table->json('quote_snapshot');
            $table->string('quote_source', 24);
            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('reviewer_admin_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->unsignedBigInteger('subscription_invoice_id')->nullable();
            $table->timestamps();
            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signup_package_requests');
    }
};
