<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use App\Support\SchoolErpPanel;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class SchoolErpWorkspace
{
    public const ATTENDANCE_STATUSES = ['present', 'absent', 'late', 'excused'];

    public function mayWrite(Company $company): bool
    {
        if ($company->status !== 'approved' || $company->company_status === 'pending') {
            return false;
        }

        $subscription = Subscription::where('company_id', $company->id)->where('active', true)->latest('id')->first();
        $plan = $subscription?->entitlementPlan();
        if ($plan && !PlanCompanyCompatibility::matches($company, $plan)) {
            return false;
        }

        return SubscriptionAccessService::hasAccess($company, false, $subscription)['allowed'];
    }

    public function campuses(User $user, bool $forWrite = false): Collection
    {
        $query = DB::table('branches')
            ->where('company_id', $user->company_id)
            ->where('is_active', true)
            ->orderBy('name');

        if (!$user->isCompanyAdmin()) {
            $memberships = DB::table('branch_user')->select('branch_id')->where('user_id', $user->id);
            if ($forWrite) {
                $memberships->where('access_level', 'full');
            }
            $query->whereIn('id', $memberships);
        }

        return $query->get(['id', 'name']);
    }

    public function scopedRecords(User $user, string $table, ?int $campusId = null): Builder
    {
        $query = DB::table($table)->where($table.'.company_id', $user->company_id);
        if (!$user->isCompanyAdmin()) {
            $query->whereIn($table.'.campus_id', $this->campuses($user)->pluck('id'));
        }
        if ($campusId !== null) {
            $query->where($table.'.campus_id', $campusId);
        }

        return $query;
    }

    public function addCampus(User $user, string $name): void
    {
        $this->mutate($user, true, function (Company $company) use ($name) {
            if (DB::table('branches')->where('company_id', $company->id)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
                throw ValidationException::withMessages(['campus_name' => __('school_erp.campus_exists')]);
            }
            $limit = PlanLimitService::canAddBranch($company->id);
            if (!$limit['allowed']) {
                throw ValidationException::withMessages(['campus_name' => SubscriptionAccessService::localizedLockReason($limit['reason'])]);
            }
            Branch::create(['company_id' => $company->id, 'name' => $name, 'is_active' => true]);
        });
    }

    public function addStudent(User $user, array $data): void
    {
        $this->mutate($user, false, function (Company $company, User $actor) use ($data) {
            $campusId = $this->authorizedCampus($actor, $data['campus_id'] ?? null);
            $duplicate = DB::table('school_erp_students')->where('company_id', $company->id)
                ->whereRaw('LOWER(student_reference) = ?', [mb_strtolower($data['student_reference'])])->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['student_reference' => __('school_erp.reference_exists')]);
            }
            DB::table('school_erp_students')->insert([
                'company_id' => $company->id,
                'campus_id' => $campusId,
                'student_reference' => $data['student_reference'],
                'display_name' => $data['display_name'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function addTerm(User $user, array $data): void
    {
        $this->mutate($user, true, function (Company $company) use ($data) {
            if (DB::table('school_erp_terms')->where('company_id', $company->id)
                ->whereRaw('LOWER(code) = ?', [mb_strtolower($data['code'])])->exists()) {
                throw ValidationException::withMessages(['code' => __('school_erp.term_exists')]);
            }
            DB::table('school_erp_terms')->insert($data + [
                'company_id' => $company->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function admit(User $user, array $data): void
    {
        $this->mutate($user, false, function (Company $company, User $actor) use ($data) {
            $student = $this->scopedRecords($actor, 'school_erp_students')->where('id', $data['student_id'])->lockForUpdate()->first();
            if (!$student) {
                throw ValidationException::withMessages(['student_id' => __('school_erp.student_unavailable')]);
            }
            $this->authorizedCampus($actor, $student->campus_id);
            app(SchoolAdmissionsService::class)->enroll($company->id, $student->id, $data['term_id'], $data['admitted_on']);
        });
    }

    public function transfer(User $user, int $studentId, int $campusId): void
    {
        $this->mutate($user, true, function (Company $company, User $actor) use ($studentId, $campusId) {
            $student = DB::table('school_erp_students')->where('company_id', $company->id)->where('id', $studentId)->lockForUpdate()->first();
            abort_unless($student, 404);
            $target = $this->authorizedCampus($actor, $campusId);
            if ($student->campus_id !== null && (int) $student->campus_id === $target) {
                return;
            }

            DB::table('school_erp_students')->where('company_id', $company->id)->where('id', $studentId)
                ->update(['campus_id' => $target, 'updated_at' => now()]);
            DB::table('school_erp_campus_transfers')->insert([
                'company_id' => $company->id,
                'student_id' => $studentId,
                'from_campus_id' => $student->campus_id,
                'to_campus_id' => $target,
                'changed_by' => $actor->id,
                'created_at' => now(),
            ]);
        });
    }

    public function addStaff(User $user, array $data): void
    {
        $this->mutate($user, true, function (Company $company, User $actor) use ($data) {
            $campusId = $this->authorizedCampus($actor, $data['staff_campus_id']);
            $limit = PlanLimitService::canAddUser($company->id);
            if (!$limit['allowed']) {
                throw ValidationException::withMessages(['staff_email' => SubscriptionAccessService::localizedLockReason($limit['reason'])]);
            }
            $staff = User::create([
                'company_id' => $company->id,
                'name' => $data['staff_name'],
                'email' => $data['staff_email'],
                'password' => Hash::make($data['password']),
                'role' => $data['staff_access'] === 'read_only' ? 'viewer' : 'employee',
                'is_active' => true,
            ]);
            DB::table('branch_user')->insert([
                'branch_id' => $campusId, 'user_id' => $staff->id, 'access_level' => $data['staff_access'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function recordAttendance(User $user, array $data): void
    {
        $this->mutate($user, false, function (Company $company, User $actor) use ($data) {
            $student = $this->scopedRecords($actor, 'school_erp_students')
                ->where('id', $data['student_id'])->lockForUpdate()->first();
            if (!$student || $student->campus_id === null) {
                throw ValidationException::withMessages(['student_id' => __('school_erp.student_unavailable')]);
            }

            $campus = $this->authorizedCampus($actor, $student->campus_id);
            $enrollment = DB::table('school_erp_enrollments as enrollment')
                ->join('school_erp_terms as term', 'term.id', '=', 'enrollment.term_id')
                ->where('enrollment.company_id', $company->id)
                ->where('enrollment.student_id', $student->id)
                ->where('term.id', $data['term_id'])
                ->where('enrollment.admitted_on', '<=', $data['attendance_on'])
                ->where('term.starts_on', '<=', $data['attendance_on'])
                ->where('term.ends_on', '>=', $data['attendance_on'])
                ->exists();
            $lastTransfer = DB::table('school_erp_campus_transfers')
                ->where('company_id', $company->id)->where('student_id', $student->id)
                ->max('created_at');
            if (!$enrollment || ($lastTransfer && $data['attendance_on'] < substr($lastTransfer, 0, 10))) {
                throw ValidationException::withMessages(['attendance_on' => __('school_erp.attendance_outside_enrollment')]);
            }

            $identity = ['company_id' => $company->id, 'student_id' => $student->id, 'attendance_on' => $data['attendance_on']];
            $record = DB::table('school_erp_attendance')->where($identity)->lockForUpdate()->first();
            if ($record) {
                if ((int) $record->campus_id !== $campus || (int) $record->term_id !== (int) $data['term_id']) {
                    throw ValidationException::withMessages(['attendance_on' => __('school_erp.attendance_already_recorded')]);
                }
                if ($record->status !== $data['status']) {
                    DB::table('school_erp_attendance')->where($identity)
                        ->update(['status' => $data['status'], 'recorded_by' => $actor->id, 'updated_at' => now()]);
                }

                return;
            }

            DB::table('school_erp_attendance')->insert($identity + [
                'term_id' => $data['term_id'], 'campus_id' => $campus, 'status' => $data['status'],
                'recorded_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function authorizedCampus(User $user, mixed $campusId): ?int
    {
        if ($user->isCompanyAdmin() && $campusId === null) {
            return null;
        }
        if ($campusId !== null && $this->campuses($user, true)->contains(fn ($campus) => (int) $campus->id === (int) $campusId)) {
            return (int) $campusId;
        }

        throw ValidationException::withMessages(['campus_id' => __('school_erp.campus_unavailable')]);
    }

    public function mutate(User $actor, bool $ownerOnly, Closure $operation): void
    {
        DB::transaction(function () use ($actor, $ownerOnly, $operation) {
            $company = Company::whereKey($actor->company_id)->lockForUpdate()->first();
            $user = User::where('company_id', $actor->company_id)->whereKey($actor->id)->lockForUpdate()->first();
            abort_unless($user && SchoolErpPanel::maySignIn($user) && SchoolErpPanel::ownsCompany($company), 403);
            abort_unless($user->isCompanyAdmin() || (!$ownerOnly && $user->role === 'employee'), 403);
            abort_unless($this->mayWrite($company), 403, __('school_erp.read_only'));

            $operation($company, $user);
        }, 3);
    }
}
