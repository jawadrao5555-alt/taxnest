<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('hr_organizations')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->date('work_date');
            $table->dateTime('starts_at_utc');
            $table->dateTime('ends_at_utc');
            $table->string('timezone', 64);
            $table->unsignedSmallInteger('grace_minutes');
            $table->unsignedSmallInteger('absent_after_minutes');
            $table->foreignId('policy_version_id')->nullable()->constrained('hr_policy_versions')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'employee_id', 'work_date'], 'hr_shifts_employee_day');
            $table->index(['organization_id', 'work_date'], 'hr_shifts_workspace_day');
        });

        Schema::create('hr_attendance_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('hr_organizations')->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained('hr_shift_assignments')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('request_key');
            $table->string('action', 12);
            $table->dateTime('occurred_at_utc');
            $table->unique(['organization_id', 'request_key'], 'hr_punch_request');
            $table->unique(['organization_id', 'shift_id', 'action'], 'hr_punch_shift_action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_attendance_punches');
        Schema::dropIfExists('hr_shift_assignments');
    }
};
