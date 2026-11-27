<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_erp_attendance', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('term_id');
            $table->unsignedBigInteger('campus_id');
            $table->date('attendance_on');
            $table->string('status', 10);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'student_id', 'attendance_on'], 'se_attendance_student_day');
            $table->index(['company_id', 'campus_id', 'attendance_on'], 'se_attendance_campus_day');
            $table->foreign(['company_id', 'student_id', 'term_id'], 'se_attendance_enrollment_fk')
                ->references(['company_id', 'student_id', 'term_id'])->on('school_erp_enrollments')->restrictOnDelete();
            $table->foreign(['company_id', 'campus_id'], 'se_attendance_campus_fk')
                ->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_erp_attendance');
    }
};
