<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('saas_local_bill_retention', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->primary();
            $table->boolean('retain')->default(true);
            $table->timestamps();
        });
        Schema::create('saas_local_bill_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('reference');
            $table->date('business_date')->nullable();
            $table->decimal('total_amount', 16, 2);
            $table->longText('encrypted_payload');
            $table->string('payload_hash', 64);
            $table->timestamps();
            // Bill numbers can repeat after cleanup; the source identity cannot.
            $table->unique(['company_id', 'source_id'], 'saas_local_bill_source_unique');
            $table->index(['company_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_local_bill_records');
        Schema::dropIfExists('saas_local_bill_retention');
    }
};
