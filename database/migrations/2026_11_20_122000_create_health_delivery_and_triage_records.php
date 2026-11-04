<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('health_deliveries')) {
            Schema::create('health_deliveries', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained()->cascadeOnDelete();
                $t->foreignId('health_admission_id')->unique()->constrained()->cascadeOnDelete();
                $t->foreignId('health_operation_id')->nullable()->constrained();
                $t->string('method', 16);
                $t->dateTime('delivered_at');
                $t->text('newborns');
                $t->unsignedBigInteger('recorded_by');
                $t->timestamps();
            });
        }
        if (!Schema::hasTable('health_triage_records')) {
            Schema::create('health_triage_records', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained()->cascadeOnDelete();
                $t->foreignId('health_visit_id')->unique()->constrained()->cascadeOnDelete();
                $t->string('priority', 20);
                $t->text('presenting_problem');
                $t->unsignedBigInteger('recorded_by');
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('health_triage_records');
        Schema::dropIfExists('health_deliveries');
    }
};
