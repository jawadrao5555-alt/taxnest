<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\HealthHrPolicy;
use App\Models\HealthLeaveType;
use App\Models\HealthShift;
use App\Models\HealthStaffProfile;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Healthcare HR helpers — the small decisions every HR screen and the
 * attendance calculation both need, answered in exactly one place.
 *
 * Deliberately NOT an access-control class (HealthAccessService owns that) and
 * NOT the attendance maths (HealthAttendanceService owns that). This is the
 * boring middle: the organisation's policy row, a person's employment record,
 * their weekly off days and the seeded leave types.
 *
 * Everything here is defensive about schema drift. The owner's production box
 * has a history of migrations marked "Ran" whose columns never landed, so a
 * missing HR table must degrade to "HR is not set up yet" rather than 500 the
 * whole panel.
 */
class HealthHrService
{
    /** Per-request memo: company_id => policy row. */
    private static array $policyCache = [];

    /** Per-request memo: "companyId:userId" => profile row (or null). */
    private static array $profileCache = [];

    /** Tables this module owns. A missing one means HR has not migrated yet. */
    public const TABLES = [
        'health_hr_policies',
        'health_shifts',
        'health_staff_profiles',
        'health_holidays',
        'health_leave_types',
        'health_leave_requests',
        'health_roster_entries',
        'health_attendance_punches',
        'health_attendance_corrections',
        'health_attendance_days',
        'health_attendance_locks',
    ];

    /** Is the HR schema actually present on this box? */
    public static function schemaReady(): bool
    {
        try {
            foreach (self::TABLES as $table) {
                if (!Schema::hasTable($table)) {
                    return false;
                }
            }
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    /**
     * The organisation's attendance policy, created with the documented
     * defaults the first time anybody needs it.
     */
    public static function policy(int|Company|null $company): ?HealthHrPolicy
    {
        $companyId = $company instanceof Company ? (int) $company->id : (int) $company;
        if ($companyId <= 0 || !self::schemaReady()) {
            return null;
        }

        if (array_key_exists($companyId, self::$policyCache)) {
            return self::$policyCache[$companyId];
        }

        $policy = HealthHrPolicy::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->first();

        if (!$policy) {
            $policy = HealthHrPolicy::create(array_merge(
                HealthHrPolicy::DEFAULTS,
                ['company_id' => $companyId]
            ));
        }

        return self::$policyCache[$companyId] = $policy;
    }

    /**
     * The employment record for a member, created empty on first touch.
     *
     * Creating it lazily is what keeps the promise in the plan: HR never makes
     * a second account for somebody who already has a login. The user row IS
     * the person; this row is only their employment paperwork.
     */
    public static function profile(int $companyId, int $userId, bool $create = true): ?HealthStaffProfile
    {
        if ($companyId <= 0 || $userId <= 0 || !self::schemaReady()) {
            return null;
        }

        $key = $companyId . ':' . $userId;
        if (array_key_exists($key, self::$profileCache) && self::$profileCache[$key]) {
            return self::$profileCache[$key];
        }

        $profile = HealthStaffProfile::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('user_id', $userId)
            ->first();

        if (!$profile && $create) {
            $profile = HealthStaffProfile::create([
                'company_id'        => $companyId,
                'user_id'           => $userId,
                'employment_type'   => 'permanent',
                'employment_status' => 'active',
            ]);
        }

        return self::$profileCache[$key] = $profile;
    }

    /**
     * The geofence centre a punch at this branch must be inside.
     *
     * Branch coordinates first (that is the building somebody is standing in),
     * the organisation's single site second. A branch may set its own radius;
     * otherwise the organisation's applies.
     *
     * @return array{lat:float,lng:float,radius:int}|null null = nothing configured
     */
    public static function geofence(int $companyId, ?int $branchId, ?HealthHrPolicy $policy = null): ?array
    {
        $policy = $policy ?: self::policy($companyId);
        $radius = max(1, (int) $policy->geo_radius_m);

        if ($branchId && Schema::hasTable('branches')) {
            try {
                if (Schema::hasColumn('branches', 'latitude') && Schema::hasColumn('branches', 'longitude')) {
                    $branch = Branch::withoutGlobalScopes()
                        ->where('company_id', $companyId)
                        ->where('id', $branchId)
                        ->first();

                    if ($branch && $branch->latitude !== null && $branch->longitude !== null) {
                        return [
                            'lat'    => (float) $branch->latitude,
                            'lng'    => (float) $branch->longitude,
                            'radius' => (int) ($branch->geo_radius_m ?: $radius),
                        ];
                    }
                }
            } catch (\Throwable $e) {
                // Schema drift: fall through to the organisation's own site.
            }
        }

        if ($policy->geo_latitude !== null && $policy->geo_longitude !== null) {
            return [
                'lat'    => (float) $policy->geo_latitude,
                'lng'    => (float) $policy->geo_longitude,
                'radius' => $radius,
            ];
        }

        return null;
    }

    /** Great-circle distance in metres. */
    public static function metresBetween(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Profiles for a whole staff list in one query, keyed by user id.
     *
     * Production runs with strict lazy loading: every screen that lists staff
     * must resolve their employment records here, not per row.
     *
     * @return array<int,HealthStaffProfile>
     */
    public static function profilesFor(int $companyId, array $userIds): array
    {
        if (!$userIds || !self::schemaReady()) {
            return [];
        }

        return HealthStaffProfile::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy(fn (HealthStaffProfile $profile) => (int) $profile->user_id)
            ->all();
    }

    /**
     * ISO weekday numbers this person is off. The employment record overrides
     * the organisation policy; NULL there means "use the policy".
     */
    public static function offDays(?HealthStaffProfile $profile, ?HealthHrPolicy $policy): array
    {
        $own = $profile?->weekly_off_days;

        if (is_array($own) && $own !== []) {
            return array_values(array_filter(
                array_map('intval', $own),
                fn (int $day) => $day >= 1 && $day <= 7
            ));
        }

        return $policy ? $policy->offDays() : [];
    }

    /** Seed the five standard leave types for a company that has none. */
    public static function ensureLeaveTypes(int $companyId): void
    {
        if ($companyId <= 0 || !self::schemaReady()) {
            return;
        }

        $existing = HealthLeaveType::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->exists();

        if ($existing) {
            return;
        }

        foreach (HealthLeaveType::SEED as $seed) {
            HealthLeaveType::create([
                'company_id'        => $companyId,
                // Seeded in the organisation's current language; renameable.
                'name'              => __($seed['name_key']),
                'code'              => $seed['code'],
                'annual_quota_days' => $seed['annual_quota_days'],
                'is_paid'           => $seed['is_paid'],
                'requires_approval' => true,
                'is_active'         => true,
            ]);
        }
    }

    /**
     * Active shift templates for a company, keyed by id.
     *
     * @return array<int,HealthShift>
     */
    public static function shifts(int $companyId, bool $activeOnly = true): array
    {
        if (!self::schemaReady()) {
            return [];
        }

        $query = HealthShift::withoutGlobalScopes()->where('company_id', $companyId);
        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return $query->orderBy('start_time')->get()->keyBy('id')->all();
    }

    /**
     * The people HR rosters: healthcare staff who are still working.
     *
     * A member whose employment status is "left" keeps their history but drops
     * off the roster and the coverage view — exactly like a deactivated login.
     *
     * @return \Illuminate\Support\Collection<int,User>
     */
    public static function rosterableStaff(?Company $company)
    {
        $staff = HealthPlatformService::staff($company);

        if (!self::schemaReady() || $staff->isEmpty()) {
            return $staff;
        }

        $profiles = self::profilesFor((int) ($company->id ?? 0), $staff->pluck('id')->all());

        return $staff->filter(function (User $member) use ($profiles) {
            $profile = $profiles[(int) $member->id] ?? null;

            return $profile === null || $profile->isWorking();
        })->values();
    }

    /**
     * The attendance (duty) date a moment belongs to.
     *
     * A punch before the organisation's day boundary belongs to the PREVIOUS
     * duty date — the same rule the POS business day uses, and for the same
     * reason: a 03:00 punch is the tail of last night's shift, not the start of
     * today. Never compare a raw punch timestamp with whereDate().
     */
    public static function attendanceDate(Carbon $moment, ?HealthHrPolicy $policy): Carbon
    {
        $boundary = self::boundaryMinutes($policy);
        $minutes = $moment->hour * 60 + $moment->minute;

        $date = $moment->copy()->startOfDay();

        return $minutes < $boundary ? $date->subDay() : $date;
    }

    /** The organisation's day boundary as minutes past midnight. */
    public static function boundaryMinutes(?HealthHrPolicy $policy): int
    {
        $raw = $policy?->business_day_start ?: HealthHrPolicy::DEFAULTS['business_day_start'];
        $parts = explode(':', (string) $raw);

        return ((int) ($parts[0] ?? 6)) * 60 + ((int) ($parts[1] ?? 0));
    }

    /** The instant a duty date opens, e.g. 12 Mar 06:00. */
    public static function dayStart(Carbon $date, ?HealthHrPolicy $policy): Carbon
    {
        return $date->copy()->startOfDay()->addMinutes(self::boundaryMinutes($policy));
    }

    /** Tests and long-running workers change state mid-process. */
    public static function forget(): void
    {
        self::$policyCache = [];
        self::$profileCache = [];
    }
}
