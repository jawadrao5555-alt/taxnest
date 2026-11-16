<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Optional, current-state policy. Client flags and cached sessions grant no authority. */
class PosOnlinePaymentPolicy
{
    public static function requiresApproval(?User $actor, int $companyId): bool
    {
        if (!$actor || (int) $actor->company_id !== $companyId) return true;
        $current = User::where('company_id', $companyId)->find($actor->id);
        if (!$current || !$current->is_active) return true;
        // Confirmation retains existing manager/admin authority; configuring the policy is owner-only.
        if ($current->isPosAdmin()) return false;
        return Schema::hasTable('pos_online_payment_policies') && DB::table('pos_online_payment_policies')
            ->where('company_id', $companyId)->where('user_id', $current->id)->where('requires_approval', true)->exists();
    }

    public static function assertAllowed(?User $actor, int $companyId, ?string $paymentMethod, bool $confirmed = false): void
    {
        if (($confirmed || in_array($paymentMethod, ['qr_payment', 'online'], true)) && self::requiresApproval($actor, $companyId)) {
            abort(403, __('online_payment_policy.denied'));
        }
    }
}
