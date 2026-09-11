<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('owner_deployment_approval_requests', function (Blueprint $table) {
            $table->uuid('request_id')->primary();
            $table->unsignedInteger('pull_request_number');
            $table->char('head_sha', 40);
            $table->string('repository', 255);
            $table->string('status', 32)->index();
            $table->foreignId('requested_admin_id')->constrained('admin_users')->restrictOnDelete();
            $table->foreignId('approved_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->uuid('dispatch_lease_id')->nullable()->unique();
            $table->timestamp('dispatch_lease_expires_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->char('provenance_receipt_hash', 64)->nullable()->unique();
            $table->timestamp('provenance_receipt_used_at')->nullable();
            $table->char('merge_sha', 40)->nullable()->index();
            $table->char('owner_workflow_sha', 40)->nullable();
            $table->unsignedBigInteger('owner_workflow_run_id')->nullable();
            $table->unsignedInteger('owner_workflow_run_attempt')->nullable();
            $table->char('handoff_nonce_hash', 64)->nullable();
            $table->unsignedBigInteger('deployment_run_id')->nullable()->unique();
            $table->unsignedInteger('deployment_run_attempt')->nullable();
            $table->char('deployment_workflow_sha', 40)->nullable();
            $table->string('workflow_run_url', 500)->nullable();
            $table->string('deploy_result', 32)->nullable();
            $table->char('deployed_sha', 40)->nullable();
            $table->text('failure_summary')->nullable();
            $table->timestamps();
            $table->index(['repository', 'pull_request_number', 'head_sha'], 'odpr_repo_pr_sha_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_deployment_approval_requests');
    }
};