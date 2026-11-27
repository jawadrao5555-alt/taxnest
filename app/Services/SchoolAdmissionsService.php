<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SchoolAdmissionsService
{
    /**
     * The caller supplies the school from its authorized company context.
     * Repeating the same admission preserves the original enrollment.
     */
    public function enroll(int $companyId, int $studentId, int $termId, string $admittedOn): int
    {
        Validator::make(['admitted_on' => $admittedOn], [
            'admitted_on' => ['required', 'date_format:Y-m-d'],
        ])->validate();

        return DB::transaction(function () use ($companyId, $studentId, $termId, $admittedOn) {
            $student = DB::table('school_erp_students')->where('company_id', $companyId)
                ->where('id', $studentId)->lockForUpdate()->first();
            if (!$student) {
                throw ValidationException::withMessages(['student_id' => 'Choose a student from this school.']);
            }

            $term = DB::table('school_erp_terms')->where('company_id', $companyId)
                ->where('id', $termId)->lockForUpdate()->first();
            if (!$term) {
                throw ValidationException::withMessages(['term_id' => 'Choose a term from this school.']);
            }

            $identity = ['company_id' => $companyId, 'student_id' => $studentId, 'term_id' => $termId];
            $existing = DB::table('school_erp_enrollments')->where($identity)->first();
            if ($existing) {
                if ($existing->admitted_on !== $admittedOn) {
                    throw ValidationException::withMessages(['admitted_on' => 'This student is already enrolled in the term with a different admission date.']);
                }

                return (int) $existing->id;
            }

            if ($admittedOn < $term->starts_on || $admittedOn > $term->ends_on) {
                throw ValidationException::withMessages(['admitted_on' => 'The admission date must fall within the selected term.']);
            }

            return DB::table('school_erp_enrollments')->insertGetId($identity + [
                'admitted_on' => $admittedOn,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 3);
    }
}
