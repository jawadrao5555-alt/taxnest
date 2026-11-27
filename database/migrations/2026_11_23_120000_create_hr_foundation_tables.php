<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('timezone', 64)->default('Asia/Karachi');
            $table->string('status', 24)->default('active');
            $table->timestamps();
        });
        Schema::create('hr_organization_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('hr_organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 32);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
        });
        Schema::create('hr_departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('hr_organizations')->cascadeOnDelete();
            $table->string('name', 150);
            $table->timestamps();
            $table->unique(['organization_id','name']);
        });
        Schema::create('hr_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('hr_organizations')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('employee_number', 64);
            $table->string('full_name', 200);
            $table->string('father_name', 200)->nullable();
            $table->text('cnic_encrypted')->nullable();
            $table->string('cnic_lookup_hash', 64)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('designation', 150)->nullable();
            $table->date('joined_on')->nullable();
            $table->string('status', 24)->default('active');
            $table->timestamps();
            $table->unique(['organization_id','employee_number']);
            $table->unique(['organization_id','cnic_lookup_hash']);
            $table->index(['organization_id','status']);
        });
        Schema::create('hr_policy_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('hr_organizations')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->date('effective_from');
            $table->json('rules');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id','version']);
            $table->index(['organization_id','effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_policy_versions');
        Schema::dropIfExists('hr_employees');
        Schema::dropIfExists('hr_departments');
        Schema::dropIfExists('hr_organization_memberships');
        Schema::dropIfExists('hr_organizations');
    }
};
