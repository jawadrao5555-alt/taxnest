<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('company_id_snapshot')->nullable()->index();
            $table->string('company_name_snapshot')->nullable();
            $table->foreignId('assigned_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('opened_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('resolved_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('reopened_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('source_box', 16);
            $table->unsignedBigInteger('source_uid');
            $table->string('source_message_id', 512)->nullable();
            $table->string('subject');
            $table->string('status', 24)->default('open');
            $table->string('resolution_build', 100)->nullable();
            $table->dateTime('opened_at');
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('reopened_at')->nullable();
            $table->timestamps();

            $table->unique(['source_box', 'source_uid'], 'support_cases_source_unique');
            $table->index(['company_id', 'status'], 'support_cases_company_status');
            $table->index(['assigned_admin_id', 'status'], 'support_cases_owner_status');
        });

        Schema::create('support_case_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_case_id')->constrained('support_cases')->cascadeOnDelete();
            $table->foreignId('actor_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('event_type', 24);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->string('resolution_build', 100)->nullable();
            $table->text('note')->nullable();
            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->index(['support_case_id', 'occurred_at'], 'support_case_events_timeline');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_case_events');
        Schema::dropIfExists('support_cases');
    }
};
