<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SchoolErpAnnouncements
{
    public function __construct(private readonly SchoolErpWorkspace $workspace) {}

    public function forSchool(int $companyId): Builder
    {
        return DB::table('school_erp_announcements as notice')
            ->leftJoin('branches as campus', function ($join) {
                $join->on('campus.company_id', '=', 'notice.company_id')->on('campus.id', '=', 'notice.campus_id');
            })
            ->where('notice.company_id', $companyId)
            ->orderByDesc('notice.id')
            ->select('notice.*', 'campus.name as campus_name');
    }

    public function forFamily(User $user, SchoolErpFamilyAccess $access): Builder
    {
        $students = $access->students($user);

        return $this->forSchool($user->company_id)
            ->where('notice.published_at', '<=', now())
            ->whereNull('notice.withdrawn_at')
            ->where(fn (Builder $active) => $active->whereNull('notice.expires_on')
                ->orWhere('notice.expires_on', '>=', now()->toDateString()))
            ->whereExists((clone $students)->selectRaw('1'))
            ->where(fn (Builder $audience) => $audience->whereNull('notice.campus_id')
                ->orWhereIn('notice.campus_id', $students->select('student.campus_id')))
            ->select('notice.title', 'notice.body', 'notice.expires_on', 'campus.name as campus_name');
    }

    public function publish(User $owner, array $data): void
    {
        $this->workspace->mutate($owner, true, function (Company $company, User $actor) use ($data) {
            $campus = $this->workspace->authorizedCampus($actor, $data['campus_id'] ?? null);
            $key = ['company_id' => $company->id, 'publication_key' => $data['publication_key']];
            $details = [
                'title' => $data['title'], 'body' => $data['body'], 'campus_id' => $campus, 'expires_on' => $data['expires_on'] ?? null,
            ];
            $prior = DB::table('school_erp_announcements')->where($key)->first();
            if ($prior) {
                if ($prior->title !== $details['title'] || $prior->body !== $details['body']
                    || ($prior->campus_id === null ? null : (int) $prior->campus_id) !== $campus
                    || $prior->expires_on !== $details['expires_on']) {
                    throw ValidationException::withMessages(['title' => __('school_announcement.already_published')]);
                }

                return;
            }

            DB::table('school_erp_announcements')->insert($key + $details + [
                'published_at' => now(), 'published_by' => $actor->id,
            ]);
        });
    }

    public function withdraw(User $owner, int $noticeId): void
    {
        $notice = DB::table('school_erp_announcements')->where('company_id', $owner->company_id)->where('id', $noticeId);
        abort_unless($owner->isCompanyAdmin(), 403);
        abort_unless($notice->exists(), 404);
        $notice->whereNull('withdrawn_at')->update(['withdrawn_at' => now(), 'withdrawn_by' => $owner->id]);
    }
}
