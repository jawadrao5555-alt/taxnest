<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_payroll_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('hr_organizations')->cascadeOnDelete();
            $table->date('month');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at_utc')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'month']);
        });

        Schema::create('hr_payroll_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('hr_payroll_periods')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->foreignId('employee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('employee_number', 64);
            $table->string('employee_name', 255);
            $table->unsignedBigInteger('basic_minor');
            $table->unsignedBigInteger('allowances_minor');
            $table->unsignedBigInteger('overtime_minor');
            $table->unsignedBigInteger('deductions_minor');
            $table->unsignedBigInteger('bonus_minor');
            $table->unsignedBigInteger('net_minor');
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['period_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_payroll_lines');
        Schema::dropIfExists('hr_payroll_periods');
    }
};
