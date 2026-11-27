<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

final class SchoolErpGradebook
{
    public function __construct(private readonly SchoolErpWorkspace $workspace) {}

    public function addClass(User $user, array $data): void
    {
        $this->workspace->mutate($user, true, function (Company $company, User $actor) use ($data) {
            $campus = $this->workspace->authorizedCampus($actor, $data['campus_id']);
            $this->term($company, $data['term_id']);
            $identity = ['company_id' => $company->id, 'term_id' => $data['term_id'], 'campus_id' => $campus];
            if (DB::table('school_erp_classes')->where($identity)->whereRaw('LOWER(code) = ?', [mb_strtolower($data['code'])])->exists()) {
                throw ValidationException::withMessages(['code' => __('school_erp.class_exists')]);
            }

            DB::table('school_erp_classes')->insert($identity + [
                'code' => $data['code'], 'name' => $data['name'], 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function addStudent(User $user, int $classId, int $studentId): void
    {
        $this->workspace->mutate($user, false, function (Company $company, User $actor) use ($classId, $studentId) {
            $class = $this->writableClass($actor, $classId);
            $this->enrollment($company, $class, $studentId);

            $identity = ['company_id' => $company->id, 'class_id' => $class->id, 'student_id' => $studentId];
            if (!DB::table('school_erp_class_students')->where($identity)->exists()) {
                DB::table('school_erp_class_students')->insert($identity + ['term_id' => $class->term_id, 'created_at' => now()]);
            }
        });
    }

    public function addAssessment(User $user, int $classId, array $data): void
    {
        $this->workspace->mutate($user, false, function (Company $company, User $actor) use ($classId, $data) {
            $class = $this->writableClass($actor, $classId);
            $term = $this->term($company, $class->term_id);
            if ($data['assessed_on'] < $term->starts_on || $data['assessed_on'] > $term->ends_on) {
                throw ValidationException::withMessages(['assessed_on' => __('school_erp.assessment_date_invalid')]);
            }

            $identity = ['company_id' => $company->id, 'class_id' => $class->id];
            if (DB::table('school_erp_assessments')->where($identity)->whereRaw('LOWER(code) = ?', [mb_strtolower($data['code'])])->exists()) {
                throw ValidationException::withMessages(['code' => __('school_erp.assessment_exists')]);
            }

            DB::table('school_erp_assessments')->insert($identity + [
                'code' => $data['code'], 'title' => $data['title'], 'assessed_on' => $data['assessed_on'],
                'max_hundredths' => self::hundredths($data['max_points']), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function recordGrade(User $user, int $classId, array $data): void
    {
        $this->workspace->mutate($user, false, function (Company $company, User $actor) use ($classId, $data) {
            $class = $this->writableClass($actor, $classId);
            $identity = ['company_id' => $company->id, 'class_id' => $class->id];
            $assessment = DB::table('school_erp_assessments')->where($identity)->where('id', $data['assessment_id'])->first();
            if (!$assessment || $assessment->assessed_on > now()->toDateString()) {
                throw ValidationException::withMessages(['assessment_id' => __('school_erp.assessment_unavailable')]);
            }

            $enrollment = $this->enrollment($company, $class, $data['student_id']);
            $student = ['student_id' => $data['student_id']];
            $onRoster = DB::table('school_erp_class_students')->where($identity + $student)->exists();
            $lastTransfer = DB::table('school_erp_campus_transfers')->where('company_id', $company->id)->where($student)->max('created_at');
            if (!$onRoster || $enrollment->admitted_on > $assessment->assessed_on
                || ($lastTransfer && substr($lastTransfer, 0, 10) > $assessment->assessed_on)) {
                throw ValidationException::withMessages(['student_id' => __('school_erp.grade_student_unavailable')]);
            }

            $score = self::hundredths($data['score']);
            if ($score > (int) $assessment->max_hundredths) {
                throw ValidationException::withMessages(['score' => __('school_erp.grade_above_maximum')]);
            }

            $key = $identity + $student + ['assessment_id' => $assessment->id];
            $grade = DB::table('school_erp_grades')->where($key)->lockForUpdate()->first();
            $values = ['score_hundredths' => $score, 'recorded_by' => $actor->id, 'updated_at' => now()];
            if (!$grade) {
                DB::table('school_erp_grades')->insert($key + $values + ['created_at' => now()]);
            } elseif ((int) $grade->score_hundredths !== $score) {
                DB::table('school_erp_grades')->where('id', $grade->id)->update($values);
            }
        });
    }

    private function writableClass(User $actor, int $classId): stdClass
    {
        $class = $this->workspace->scopedRecords($actor, 'school_erp_classes')->where('id', $classId)->first();
        abort_unless($class, 404);
        $this->workspace->authorizedCampus($actor, $class->campus_id);

        return $class;
    }

    private function term(Company $company, int $termId): stdClass
    {
        $term = DB::table('school_erp_terms')->where('company_id', $company->id)->where('id', $termId)->first();
        if (!$term) {
            throw ValidationException::withMessages(['term_id' => __('school_erp.term_unavailable')]);
        }

        return $term;
    }

    private function enrollment(Company $company, stdClass $class, int $studentId): stdClass
    {
        $enrollment = DB::table('school_erp_enrollments as enrollment')
            ->join('school_erp_students as student', 'student.id', '=', 'enrollment.student_id')
            ->where('enrollment.company_id', $company->id)
            ->where('enrollment.term_id', $class->term_id)
            ->where('enrollment.student_id', $studentId)
            ->where('student.campus_id', $class->campus_id)
            ->first(['enrollment.admitted_on']);
        if (!$enrollment) {
            throw ValidationException::withMessages(['student_id' => __('school_erp.grade_student_unavailable')]);
        }

        return $enrollment;
    }

    private static function hundredths(string $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }
}
