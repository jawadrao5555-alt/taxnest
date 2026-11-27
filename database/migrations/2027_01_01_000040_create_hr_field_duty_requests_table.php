<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_field_duty_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('hr_organizations')->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained('hr_shift_assignments')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('request_key');
            $table->foreignId('policy_version_id')->nullable()->constrained('hr_policy_versions')->nullOnDelete();
            $table->dateTime('starts_at_utc');
            $table->dateTime('ends_at_utc');
            $table->string('timezone', 64);
            $table->text('assignment_note');
            $table->string('status', 12)->default('requested');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at_utc')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'request_key'], 'hr_field_duty_request_key');
            $table->index(['organization_id', 'shift_id', 'status'], 'hr_field_duty_shift_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_field_duty_requests');
    }
};
