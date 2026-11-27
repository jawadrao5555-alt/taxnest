<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_erp_family_access', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('student_id');
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at');
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();

            $table->unique(['company_id', 'user_id', 'student_id'], 'se_family_student_key');
            $table->foreign(['company_id', 'student_id'], 'se_family_student_fk')
                ->references(['company_id', 'id'])->on('school_erp_students')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_erp_family_access');
    }
};
