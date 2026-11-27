<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_erp_students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('student_reference', 64);
            $table->string('display_name', 160);
            $table->timestamps();

            $table->unique(['company_id', 'student_reference'], 'se_student_reference_unique');
            $table->unique(['company_id', 'id'], 'se_student_company_key');
        });

        Schema::create('school_erp_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name', 120);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();

            $table->unique(['company_id', 'code'], 'se_term_code_unique');
            $table->unique(['company_id', 'id'], 'se_term_company_key');
        });

        Schema::create('school_erp_enrollments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('term_id');
            $table->date('admitted_on');
            $table->timestamps();

            $table->unique(['company_id', 'student_id', 'term_id'], 'se_enrollment_unique');
            $table->foreign(['company_id', 'student_id'], 'se_enrollment_student_fk')
                ->references(['company_id', 'id'])->on('school_erp_students')->restrictOnDelete();
            $table->foreign(['company_id', 'term_id'], 'se_enrollment_term_fk')
                ->references(['company_id', 'id'])->on('school_erp_terms')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_erp_enrollments');
        Schema::dropIfExists('school_erp_terms');
        Schema::dropIfExists('school_erp_students');
    }
};
