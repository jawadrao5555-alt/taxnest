<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_erp_classes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('campus_id');
            $table->unsignedBigInteger('term_id');
            $table->string('code', 32);
            $table->string('name', 120);
            $table->timestamps();
            $table->unique(['company_id', 'campus_id', 'term_id', 'code'], 'se_class_code_key');
            $table->unique(['company_id', 'id', 'term_id'], 'se_class_term_key');
            $table->unique(['company_id', 'id'], 'se_class_company_key');
            $table->foreign(['company_id', 'campus_id'], 'se_class_campus_fk')
                ->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['company_id', 'term_id'], 'se_class_term_fk')
                ->references(['company_id', 'id'])->on('school_erp_terms')->restrictOnDelete();
        });

        Schema::create('school_erp_class_students', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('class_id');
            $table->unsignedBigInteger('term_id');
            $table->unsignedBigInteger('student_id');
            $table->timestamp('created_at');
            $table->unique(['company_id', 'class_id', 'student_id'], 'se_roster_student_key');
            $table->foreign(['company_id', 'class_id', 'term_id'], 'se_roster_class_term_fk')
                ->references(['company_id', 'id', 'term_id'])->on('school_erp_classes')->restrictOnDelete();
            $table->foreign(['company_id', 'student_id', 'term_id'], 'se_roster_enrollment_fk')
                ->references(['company_id', 'student_id', 'term_id'])->on('school_erp_enrollments')->restrictOnDelete();
        });

        Schema::create('school_erp_assessments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('class_id');
            $table->string('code', 32);
            $table->string('title', 120);
            $table->unsignedInteger('max_hundredths');
            $table->date('assessed_on');
            $table->timestamps();
            $table->unique(['company_id', 'class_id', 'code'], 'se_assessment_code_key');
            $table->unique(['company_id', 'class_id', 'id'], 'se_assessment_class_key');
            $table->foreign(['company_id', 'class_id'], 'se_assessment_class_fk')
                ->references(['company_id', 'id'])->on('school_erp_classes')->restrictOnDelete();
        });

        Schema::create('school_erp_grades', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('class_id');
            $table->unsignedBigInteger('assessment_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedInteger('score_hundredths');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'assessment_id', 'student_id'], 'se_grade_student_key');
            $table->foreign(['company_id', 'class_id', 'assessment_id'], 'se_grade_assessment_fk')
                ->references(['company_id', 'class_id', 'id'])->on('school_erp_assessments')->restrictOnDelete();
            $table->foreign(['company_id', 'class_id', 'student_id'], 'se_grade_roster_fk')
                ->references(['company_id', 'class_id', 'student_id'])->on('school_erp_class_students')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_erp_grades');
        Schema::dropIfExists('school_erp_assessments');
        Schema::dropIfExists('school_erp_class_students');
        Schema::dropIfExists('school_erp_classes');
    }
};
