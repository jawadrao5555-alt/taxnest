<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

/** Human receipt confirmation is an authorized action, never a trusted checkbox. */
final class PosOnlinePaymentVerification
{
    public static function allowed(?User $actor, int $companyId): bool
    {
        return $actor && (int) $actor->company_id === $companyId && $actor->isPosAdmin();
    }

    public static function requireAuthorized(?User $actor, int $companyId): void
    {
        if (self::allowed($actor, $companyId)) {
            return;
        }

        Log::warning('POS online payment confirmation denied', [
            'company_id' => $companyId,
            'actor_id' => $actor?->id,
        ]);
        throw new HttpResponseException(response()->json([
            'success' => false,
            'code' => 'online_payment_manager_required',
            'message' => __('pos.online_payment_manager_required'),
        ], 403));
    }
}
