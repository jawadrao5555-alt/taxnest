<?php

namespace App\Jobs;

use App\Models\Subscription;
use App\Models\PricingPlan;
use App\Models\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckTrialExpiryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $expiredTrials = Subscription::where('active', true)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<', now())
            ->whereHas('pricingPlan', function ($q) {
                $q->where('is_trial', true);
            })
            ->with('company')
            ->get();

        foreach ($expiredTrials as $subscription) {
            $subscription->update(['active' => false]);

            Notification::create([
                'company_id' => $subscription->company_id,
                'type' => 'trial_expired',
                'title' => 'Free Trial Expired',
                'message' => 'Your 14-day free trial has expired. Please subscribe to a plan to continue using TaxNest.',
                'read' => false,
            ]);
        }
    }
}
