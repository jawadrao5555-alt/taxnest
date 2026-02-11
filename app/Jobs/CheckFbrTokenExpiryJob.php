<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CheckFbrTokenExpiryJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $companies = Company::whereNotNull('token_expires_at')->get();

        foreach ($companies as $company) {
            $expiresAt = Carbon::parse($company->token_expires_at);
            $hoursUntilExpiry = now()->diffInHours($expiresAt, false);

            if ($hoursUntilExpiry <= 48 && $hoursUntilExpiry > 0) {
                $existing = Notification::where('company_id', $company->id)
                    ->where('type', 'fbr_token_expiry')
                    ->where('created_at', '>=', now()->subDay())
                    ->first();

                if (!$existing) {
                    Notification::create([
                        'company_id' => $company->id,
                        'type' => 'fbr_token_expiry',
                        'title' => 'FBR Token Expiring Soon',
                        'message' => "Your FBR token will expire in {$hoursUntilExpiry} hours. Please renew it to avoid submission failures.",
                        'metadata' => [
                            'expires_at' => $expiresAt->toIso8601String(),
                            'hours_remaining' => $hoursUntilExpiry,
                        ],
                    ]);

                    Log::warning("FBR token expiring for company #{$company->id} in {$hoursUntilExpiry} hours");
                }
            }

            if ($hoursUntilExpiry <= 0) {
                $existing = Notification::where('company_id', $company->id)
                    ->where('type', 'fbr_token_expired')
                    ->where('created_at', '>=', now()->subDay())
                    ->first();

                if (!$existing) {
                    Notification::create([
                        'company_id' => $company->id,
                        'type' => 'fbr_token_expired',
                        'title' => 'FBR Token Expired',
                        'message' => "Your FBR token has expired. Invoice submissions will fail until you renew it.",
                        'metadata' => [
                            'expired_at' => $expiresAt->toIso8601String(),
                        ],
                    ]);
                }
            }
        }
    }
}
