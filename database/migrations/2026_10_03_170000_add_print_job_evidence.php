<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pos_print_jobs', function (Blueprint $t) {
            $t->string('result_outcome', 32)->nullable();
            $t->string('no_document_reason', 60)->nullable();
            $t->timestamp('result_received_at')->nullable();
        });
        Schema::create('pos_print_evidence', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('job_id')->nullable(); $t->unsignedInteger('attempt')->nullable();
            $t->string('event', 40); $t->json('context'); $t->timestamp('received_at');
            $t->index(['company_id', 'job_id', 'attempt'], 'print_evidence_attempt');
            $t->index('received_at');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('pos_print_evidence');
        Schema::table('pos_print_jobs', fn (Blueprint $t) => $t->dropColumn(['result_outcome', 'no_document_reason', 'result_received_at']));
    }
};
