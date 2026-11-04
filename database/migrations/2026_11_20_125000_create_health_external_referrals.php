<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('health_external_referrals')) {
            Schema::create('health_external_referrals', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained()->cascadeOnDelete();
                $t->foreignId('health_patient_id')->constrained()->cascadeOnDelete();
                $t->unsignedBigInteger('branch_id')->nullable();
                $t->unsignedBigInteger('health_department_id')->nullable();
                $t->uuid('request_key');
                $t->string('service', 16);
                $t->string('provider', 160);
                $t->text('instructions');
                $t->unsignedBigInteger('ordered_by');
                $t->timestamps();
                $t->unique(['company_id','request_key']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('health_external_referrals');
    }
};
