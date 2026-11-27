<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->unique(['company_id', 'id'], 'se_branch_company_key');
        });

        foreach (['students' => 'student', 'enrollments' => 'enrollment'] as $tableName => $entity) {
            Schema::table('school_erp_'.$tableName, function (Blueprint $table) use ($entity) {
                $table->unsignedBigInteger('campus_id')->nullable();
                $table->index(['company_id', 'campus_id'], 'se_'.$entity.'_campus_idx');
                $table->foreign(['company_id', 'campus_id'], 'se_'.$entity.'_campus_fk')
                    ->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
            });
        }

        Schema::create('school_erp_campus_transfers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('from_campus_id')->nullable();
            $table->unsignedBigInteger('to_campus_id');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
            $table->foreign(['company_id', 'student_id'], 'se_transfer_student_fk')
                ->references(['company_id', 'id'])->on('school_erp_students')->restrictOnDelete();
            $table->foreign(['company_id', 'from_campus_id'], 'se_transfer_from_campus_fk')
                ->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['company_id', 'to_campus_id'], 'se_transfer_to_campus_fk')
                ->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_erp_campus_transfers');

        $sqlite = Schema::getConnection()->getDriverName() === 'sqlite';
        foreach (['enrollments' => 'enrollment', 'students' => 'student'] as $tableName => $entity) {
            Schema::table('school_erp_'.$tableName, function (Blueprint $table) use ($entity, $sqlite) {
                $table->dropForeign($sqlite ? ['company_id', 'campus_id'] : 'se_'.$entity.'_campus_fk');
                $table->dropIndex('se_'.$entity.'_campus_idx');
                $table->dropColumn('campus_id');
            });
        }

        // InnoDB may replace the implicit company foreign-key index with the composite key.
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true) && !Schema::hasIndex('branches', ['company_id'])) {
            Schema::table('branches', fn (Blueprint $table) => $table->index('company_id'));
        }

        Schema::table('branches', function (Blueprint $table) {
            $table->dropUnique('se_branch_company_key');
        });
    }
};
