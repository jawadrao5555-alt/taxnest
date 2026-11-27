<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('hr_organizations')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('request_key');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('category', 12);
            $table->boolean('emergency')->default(false);
            $table->text('employee_note')->nullable();
            $table->string('status', 12)->default('requested');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at_utc')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('policy_version_id')->nullable()->constrained('hr_policy_versions')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'request_key'], 'hr_leave_request_key');
            $table->index(['organization_id', 'employee_id', 'status', 'starts_on', 'ends_on'], 'hr_leave_employee_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_leave_requests');
    }
};
