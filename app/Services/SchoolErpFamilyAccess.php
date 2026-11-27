<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use App\Support\SchoolErpPanel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class SchoolErpFamilyAccess
{
    public function __construct(private readonly SchoolErpWorkspace $workspace) {}

    public function students(User $user): Builder
    {
        return DB::table('school_erp_family_access as access')
            ->join('school_erp_students as student', function ($join) {
                $join->on('student.company_id', '=', 'access.company_id')->on('student.id', '=', 'access.student_id');
            })
            ->where('access.company_id', $user->company_id)
            ->where('access.user_id', $user->id)
            ->whereNull('access.revoked_at');
    }

    public function createAccount(User $owner, array $data): void
    {
        $this->workspace->mutate($owner, true, function (Company $company, User $actor) use ($data) {
            $student = $this->findStudent($company->id, $data['student_reference']);
            $limit = PlanLimitService::canAddUser($company->id);
            if (!$limit['allowed']) {
                throw ValidationException::withMessages(['email' => SubscriptionAccessService::localizedLockReason($limit['reason'])]);
            }

            $user = User::create([
                'company_id' => $company->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'role' => SchoolErpPanel::FAMILY_ROLE,
                'is_active' => true,
            ]);
            $this->grant($actor, $user->id, $student);
        });
    }

    public function linkStudent(User $owner, int $userId, string $reference): void
    {
        $this->workspace->mutate($owner, true, function (Company $company, User $actor) use ($userId, $reference) {
            abort_unless(User::whereKey($userId)->where('company_id', $company->id)
                ->where('role', SchoolErpPanel::FAMILY_ROLE)->where('is_active', true)->exists(), 404);
            $this->grant($actor, $userId, $this->findStudent($company->id, $reference));
        });
    }

    public function revoke(User $owner, int $grantId): void
    {
        DB::transaction(function () use ($owner, $grantId) {
            $company = Company::whereKey($owner->company_id)->lockForUpdate()->first();
            $actor = User::whereKey($owner->id)->where('company_id', $owner->company_id)->lockForUpdate()->first();
            abort_unless(SchoolErpPanel::ownsCompany($company) && $actor?->is_active && $actor->isCompanyAdmin(), 403);
            $grant = DB::table('school_erp_family_access')->where('company_id', $company->id)->where('id', $grantId);
            abort_unless($grant->exists(), 404);
            $grant->whereNull('revoked_at')->update(['revoked_at' => now(), 'revoked_by' => $actor->id]);
        }, 3);
    }

    private function findStudent(int $companyId, string $reference): int
    {
        $student = DB::table('school_erp_students')->where('company_id', $companyId)
            ->where('student_reference', $reference)->value('id');
        if (!$student) {
            throw ValidationException::withMessages(['student_reference' => __('school_erp.student_unavailable')]);
        }

        return (int) $student;
    }

    private function grant(User $actor, int $userId, int $studentId): void
    {
        $key = ['company_id' => $actor->company_id, 'user_id' => $userId, 'student_id' => $studentId];
        $existing = DB::table('school_erp_family_access')->where($key)->first();
        if ($existing && $existing->revoked_at === null) {
            return;
        }

        DB::table('school_erp_family_access')->updateOrInsert($key, [
            'granted_by' => $actor->id, 'granted_at' => now(), 'revoked_by' => null, 'revoked_at' => null,
        ]);
    }
}
