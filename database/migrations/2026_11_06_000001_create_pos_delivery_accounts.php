<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_transactions', function (Blueprint $table) {
            $table->decimal('delivery_credit_amount', 14, 2)->default(0);
        });

        Schema::create('pos_delivery_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable();
            // Financial history outlives the source bill and rider profile.
            $table->unsignedBigInteger('transaction_id');
            $table->unsignedBigInteger('rider_id');
            $table->string('rider_name');
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->date('business_date');
            $table->decimal('received_amount', 14, 2);
            $table->decimal('credit_amount', 14, 2);
            $table->decimal('outstanding_amount', 14, 2);
            $table->timestamps();
            $table->unique(['company_id', 'transaction_id']);
            $table->index(['company_id', 'branch_id', 'id'], 'pos_delivery_accounts_company_branch_idx');
        });

        Schema::create('pos_delivery_account_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('pos_delivery_accounts')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->uuid('request_id')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->unsignedBigInteger('return_transaction_id')->nullable();
            $table->decimal('amount', 14, 2);
            $table->date('business_date');
            $table->timestamp('created_at');
            $table->unique(['account_id', 'request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_delivery_account_events');
        Schema::dropIfExists('pos_delivery_accounts');
        Schema::table('pos_transactions', fn (Blueprint $table) => $table->dropColumn('delivery_credit_amount'));
    }
};
