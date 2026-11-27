<?php

namespace App\Services;

use App\Models\HrPolicyVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class HrPolicySettings
{
    public function __construct(private HrWorkspaceAccess $access)
    {
    }

    public function effectiveOn(int $organizationId, string $date): ?HrPolicyVersion
    {
        return HrPolicyVersion::query()->where('organization_id', $organizationId)
            ->whereDate('effective_from', '<=', $date)->orderByDesc('effective_from')->orderByDesc('version')->first();
    }

    public function publish(int $organizationId, int $actorId, array $attributes): HrPolicyVersion
    {
        $this->access->membership($organizationId, $actorId, 'policy.write');
        $data = validator($attributes, [
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'grace_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'absent_after_minutes' => ['required', 'integer', 'gte:grace_minutes', 'max:1440'],
            'annual_leave_days' => ['required', 'integer', 'min:0', 'max:366'],
            'manual_field_duty' => ['sometimes', 'boolean'],
        ])->validate();

        return DB::transaction(function () use ($organizationId, $actorId, $data) {
            $membership = $this->access->membership($organizationId, $actorId, 'policy.write', lock: true);
            $organization = $membership->organization;
            $effectiveFrom = $data['effective_from'];
            if ($effectiveFrom < CarbonImmutable::now($organization->timezone)->toDateString()) {
                throw ValidationException::withMessages(['effective_from' => 'Choose today or a future policy effective date.']);
            }
            $latest = $organization->policyVersions()->orderByDesc('version')->first();
            $existing = $this->effectiveOn($organizationId, $effectiveFrom);
            $rules = array_replace($existing?->rules ?? [], [
                'grace_minutes' => (int) $data['grace_minutes'],
                'absent_after_minutes' => (int) $data['absent_after_minutes'],
                'annual_leave_days' => (int) $data['annual_leave_days'],
            ]);
            if (array_key_exists('manual_field_duty', $data)) {
                $rules['manual_field_duty'] = (bool) $data['manual_field_duty'];
            }
            if ($existing && $existing->effective_from->toDateString() === $effectiveFrom && $existing->rules === $rules) {
                return $existing;
            }

            return $organization->policyVersions()->create([
                'version' => (int) ($latest?->version ?? 0) + 1,
                'effective_from' => $effectiveFrom, 'rules' => $rules, 'created_by' => $actorId,
            ]);
        }, 3);
    }
}
