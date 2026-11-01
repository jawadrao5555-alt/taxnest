<?php

namespace App\Services;

use App\Models\{AdminAuditLog, Company};
use Illuminate\Support\Facades\DB;

/** Existing company-control states, with atomic evidence; never changes billing. */
final class CompanyAccountStateService
{
    public const ACTIONS = ['Company suspended', 'Company activated'];

    public static function token(Company $company): string
    {
        $event = AdminAuditLog::where('target_type', 'Company')->where('target_id', $company->id)
            ->whereIn('action', self::ACTIONS)->max('id');

        return hash('sha256', json_encode([
            $company->id, self::snapshot($company), $company->updated_at?->toIso8601String(), $event,
        ], JSON_THROW_ON_ERROR));
    }

    private static function snapshot(Company $company): array
    {
        return [
            'status' => $company->status,
            'company_status' => $company->company_status,
            'suspended_at' => $company->suspended_at?->toIso8601String(),
        ];
    }

    public static function change(int $companyId, bool $activate, int $adminId, array $input): array
    {
        return DB::transaction(function () use ($companyId, $activate, $adminId, $input) {
            $company = Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
            $status = $activate ? 'approved' : 'suspended';
            $control = $activate ? 'active' : 'suspended';
            $already = $company->status === $status && $company->company_status === $control
                && ($activate ? $company->suspended_at === null : $company->suspended_at !== null);
            // Retries preserve the first actor/reason/time and never add duplicate events.
            if ($already) {
                return ['changed' => false, 'conflict' => false, 'message' => 'Company is already '.$control.'. No records were changed.'];
            }
            if (!empty($input['state_token']) && !hash_equals(self::token($company), $input['state_token'])) {
                return ['changed' => false, 'conflict' => true, 'message' => 'Company state changed after this form was opened. Reload the company page and review it before trying again.'];
            }

            $before = self::snapshot($company);
            $company->update(['status' => $status, 'company_status' => $control, 'suspended_at' => $activate ? null : now()]);
            AdminAuditLog::log($adminId, $activate ? 'Company activated' : 'Company suspended', 'Company', $companyId, [
                'name' => $company->name,
                'reason' => filled($input['reason'] ?? null) ? trim($input['reason']) : null,
                'restoration_condition' => !$activate && filled($input['restoration_condition'] ?? null) ? trim($input['restoration_condition']) : null,
                'scope' => 'Company-wide company-control state; subscription terms and grants unchanged',
                'before' => $before,
                'after' => self::snapshot($company),
            ]);

            return ['changed' => true, 'conflict' => false, 'message' => $activate
                ? 'Company activated. Subscription dates, expiry and grants were not changed; the normal subscription rules still apply.'
                : 'Company suspended. The reason and restoration condition were recorded; subscription terms were not changed.'];
        }, 3);
    }
}
