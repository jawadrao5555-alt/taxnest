<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('health_cashier_requests')) {
            Schema::create('health_cashier_requests', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained()->cascadeOnDelete();
                $t->foreignId('health_patient_id')->constrained()->cascadeOnDelete();
                $t->uuid('request_key');
                $t->char('request_hash', 64);
                $t->foreignId('health_payment_id')->constrained()->cascadeOnDelete();
                $t->decimal('credited', 14, 2)->default(0);
                $t->unique(['company_id', 'health_patient_id', 'request_key'], 'health_cashier_request_key');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('health_cashier_requests');
    }
};
