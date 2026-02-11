<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use App\Services\SecurityLogService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class CheckTokenExpiryJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $companies = Company::whereNotNull('token_expiry_date')
            ->where('token_expiry_date', '<=', now()->addHours(48))
            ->where('token_expiry_date', '>=', now())
            ->get();

        foreach ($companies as $company) {
            $hoursLeft = now()->diffInHours($company->token_expiry_date, false);

            $admins = User::where('company_id', $company->id)
                ->where('role', 'company_admin')
                ->get();

            foreach ($admins as $admin) {
                Notification::create([
                    'company_id' => $company->id,
                    'user_id' => $admin->id,
                    'type' => 'token_expiry_warning',
                    'title' => 'FBR Token Expiring Soon',
                    'message' => "Your FBR token will expire in approximately {$hoursLeft} hours. Please renew it to avoid submission failures.",
                    'read' => false,
                    'metadata' => json_encode([
                        'token_expiry_date' => $company->token_expiry_date->toDateString(),
                        'hours_remaining' => $hoursLeft,
                    ]),
                ]);
            }

            SecurityLogService::log('token_expiry_warning', null, [
                'company_id' => $company->id,
                'company_name' => $company->name,
                'token_expiry_date' => $company->token_expiry_date->toDateString(),
                'hours_remaining' => $hoursLeft,
            ]);

            Log::info("Token expiry warning sent for company #{$company->id} ({$company->name}), expires in {$hoursLeft} hours.");
        }

        $expiredCompanies = Company::whereNotNull('token_expiry_date')
            ->where('token_expiry_date', '<', now())
            ->get();

        foreach ($expiredCompanies as $company) {
            $company->update(['fbr_connection_status' => 'red']);
        }
    }
}
