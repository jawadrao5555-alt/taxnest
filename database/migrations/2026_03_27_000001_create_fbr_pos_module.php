<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fbr_pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number')->index();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_ntn')->nullable();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_value', 10, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->string('payment_method')->default('cash');
            $table->string('status')->default('completed');
            $table->string('fbr_invoice_number')->nullable()->index();
            $table->string('fbr_status')->default('pending');
            $table->string('fbr_response_code')->nullable();
            $table->json('fbr_response')->nullable();
            $table->string('fbr_submission_hash')->nullable()->unique();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('share_token', 64)->nullable()->unique();
            $table->timestamp('share_token_created_at')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('fbr_pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('item_name');
            $table->string('hs_code')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->boolean('is_tax_exempt')->default(false);
            $table->timestamps();

            $table->foreign('transaction_id')->references('id')->on('fbr_pos_transactions')->onDelete('cascade');
        });

        Schema::create('fbr_pos_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('response_code')->nullable();
            $table->string('status')->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('transaction_id')->references('id')->on('fbr_pos_transactions')->onDelete('set null');
        });

        if (!Schema::hasColumn('companies', 'fbr_pos_enabled')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->boolean('fbr_pos_enabled')->default(false)->after('pra_proxy_url');
                $table->string('fbr_pos_id')->nullable()->after('fbr_pos_enabled');
                $table->string('fbr_pos_token')->nullable()->after('fbr_pos_id');
                $table->string('fbr_pos_environment')->default('sandbox')->after('fbr_pos_token');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fbr_pos_logs');
        Schema::dropIfExists('fbr_pos_transaction_items');
        Schema::dropIfExists('fbr_pos_transactions');

        if (Schema::hasColumn('companies', 'fbr_pos_enabled')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn(['fbr_pos_enabled', 'fbr_pos_id', 'fbr_pos_token', 'fbr_pos_environment']);
            });
        }
    }
};
