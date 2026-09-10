<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('live_ops_autonomous_requests')) {
            return;
        }

        Schema::create('live_ops_autonomous_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_id', 64)->unique();
            $table->string('requester', 120)->nullable();
            $table->string('intent', 40)->nullable();
            $table->string('owner_text', 500);
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('company_name', 255)->nullable();
            $table->string('operation', 64)->nullable();
            $table->string('status', 32)->default('INVESTIGATING');
            $table->unsignedTinyInteger('iteration')->default(0);
            $table->string('diagnostic_report_id', 64)->nullable()->index();
            $table->string('diagnostic_run_id', 32)->nullable();
            $table->string('root_cause', 120)->nullable();
            $table->string('risk_class', 32)->nullable();
            $table->string('fix_commit', 64)->nullable();
            $table->string('pull_request', 32)->nullable();
            $table->json('tests_result')->nullable();
            $table->string('deployment_sha', 64)->nullable();
            $table->json('deployment_result')->nullable();
            $table->json('live_verification')->nullable();
            $table->json('owner_report')->nullable();
            $table->text('error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_ops_autonomous_requests');
    }
};
