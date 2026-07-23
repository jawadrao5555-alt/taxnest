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
            ->whereHas('company', function ($q) {
                $q->where('is_internal_account', false);
            })
            ->with('company')
            ->get();

        foreach ($expiredTrials as $subscription) {
            // Admin-granted overrides (lifetime, or temporary/grace with a
            // future override_until) ride ON the subscription row — deactivating
            // the row would silently kill a still-valid grant. Skip those;
            // reconcileExpiredGrants() below handles them once they lapse.
            if ($subscription->hasActiveOverride()) {
                continue;
            }

            $subscription->update(['active' => false]);

            Notification::create([
                'company_id' => $subscription->company_id,
                'type' => 'trial_expired',
                'title' => 'Free Trial Expired',
                'message' => 'Your free trial has expired. Please subscribe to a plan to continue using TaxNest.',
                'read' => false,
            ]);
        }

        // Lock companies whose admin-granted temporary / grace access has ended.
        \App\Services\SubscriptionAccessService::reconcileExpiredGrants();
    }
}
