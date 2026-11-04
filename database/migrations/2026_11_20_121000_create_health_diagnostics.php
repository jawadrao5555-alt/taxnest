<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('health_diagnostic_tests')) {
            Schema::create('health_diagnostic_tests', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained()->cascadeOnDelete();
                $t->string('service', 16);
                $t->string('code', 32);
                $t->string('name', 140);
                $t->decimal('price', 12, 2);
                $t->string('unit', 40)->nullable();
                $t->string('reference_interval', 120)->nullable();
                $t->timestamps();
                $t->unique(['company_id', 'service', 'code'], 'health_diagnostic_catalog_code');
            });
        }
        if (!Schema::hasTable('health_diagnostic_orders')) {
            Schema::create('health_diagnostic_orders', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained()->cascadeOnDelete();
                $t->unsignedBigInteger('branch_id')->nullable();
                $t->unsignedBigInteger('health_department_id')->nullable();
                $t->foreignId('health_patient_id')->constrained()->cascadeOnDelete();
                $t->foreignId('test_id')->constrained('health_diagnostic_tests');
                $t->string('service', 16);
                $t->uuid('request_key');
                $t->string('test_name', 140);
                $t->decimal('price', 12, 2);
                $t->string('unit', 40)->nullable();
                $t->string('reference_interval', 120)->nullable();
                $t->string('status', 16)->default('ordered');
                $t->string('accession', 80)->nullable();
                $t->text('result')->nullable();
                foreach (['ordered', 'collected', 'released'] as $event) {
                    $t->unsignedBigInteger($event.'_by')->nullable();
                }
                $t->timestamp('collected_at')->nullable();
                $t->timestamp('released_at')->nullable();
                $t->timestamps();
                $t->unique(['company_id', 'request_key']);
                $t->unique(['company_id', 'service', 'accession'], 'health_diagnostic_accession');
                $t->index(['company_id', 'service', 'branch_id', 'status'], 'health_diagnostic_worklist');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('health_diagnostic_orders');
        Schema::dropIfExists('health_diagnostic_tests');
    }
};
