<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('company_status')->default('active');
            $table->date('token_expiry_date')->nullable();
            $table->timestamp('last_successful_submission')->nullable();
            $table->string('fbr_connection_status')->nullable()->default('unknown');
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->text('address')->nullable();
            $table->boolean('is_head_office')->default(false);
            $table->timestamps();
        });

        Schema::create('customer_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->string('customer_name');
            $table->string('customer_ntn');
            $table->foreignId('invoice_id')->nullable()->constrained()->onDelete('set null');
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->decimal('balance_after', 15, 2)->default(0);
            $table->string('type');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->constrained()->onDelete('set null');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('sha256_hash');
            $table->timestamp('created_at')->nullable();
        });

        DB::statement("UPDATE companies SET company_status = 'suspended' WHERE suspended_at IS NOT NULL");
        DB::statement("UPDATE companies SET company_status = 'active' WHERE suspended_at IS NULL");
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });

        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('customer_ledgers');
        Schema::dropIfExists('branches');

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['company_status', 'token_expiry_date', 'last_successful_submission', 'fbr_connection_status']);
        });
    }
};
