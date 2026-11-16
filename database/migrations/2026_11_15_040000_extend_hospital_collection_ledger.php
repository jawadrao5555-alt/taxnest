<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hospital_services')) {
            Schema::create('hospital_services', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('company_id'); $t->string('code', 20); $t->string('label', 80); $t->string('service_head', 20);
                $t->unsignedBigInteger('rate_paisa'); $t->boolean('active')->default(true); $t->timestamps(); $t->unique(['company_id', 'code']);
            });
        }
        if (!Schema::hasColumn('hospital_invoices', 'patient_name')) {
            Schema::table('hospital_invoices', fn (Blueprint $t) => $t->string('patient_name', 100)->nullable());
        }
        if (!Schema::hasColumn('hospital_invoices', 'service_lines')) {
            Schema::table('hospital_invoices', fn (Blueprint $t) => $t->json('service_lines')->nullable());
        }
        if (!Schema::hasColumn('hospital_payments', 'payment_reference')) {
            Schema::table('hospital_payments', fn (Blueprint $t) => $t->string('payment_reference', 100)->nullable());
        }
    }

    public function down(): void
    {
        // Forward-only: catalogue, service-line and payment-reference evidence
        // remains available after a code rollback. No historical data is dropped.
    }
};
