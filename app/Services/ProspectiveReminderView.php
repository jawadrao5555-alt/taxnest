<?php
namespace App\Services;

use App\Models\Company;
use App\Models\Subscription;
use Carbon\Carbon;

/** Read-only reminder evidence. No dispatch, entitlement mutation or new recipients. */
final class ProspectiveReminderView
{
    public static function forCompany(Company $company): ?array
    {
        if (!ProspectiveSubscriptionPolicy::available() || $company->is_internal_account) return null;
        $sub = Subscription::where('company_id', $company->id)->where('active', true)->latest('id')->first();
        if (!$sub || !$sub->hasLifecycleSnapshot() || $sub->hasActiveOverride()) return null;
        $view = ProspectiveSubscriptionPolicy::display($sub);
        if (!$view) return null;
        $phase = $view['phase'];
        $confirmed = !empty($view['paid_starts_at']);
        $until = match ($phase) {
            'trial' => $view['trial_ends_at'],
            'paid' => $view['paid_ends_at'],
            'grace' => $view['grace_ends_at'],
            default => null,
        };
        if (!$until) return null;
        $end = Carbon::parse($until)->setTimezone(config('app.timezone'));
        $days = max(0, (int) now()->startOfDay()->diffInDays($end->copy()->startOfDay()));
        if ($phase !== 'grace' && !($phase === 'trial' && $confirmed) && $days > 2) return null;
        $kind = $phase === 'trial' && $confirmed ? 'confirmed' : $phase;
        return $view + ['kind'=>$kind, 'until'=>$until, 'days_left'=>$days,
            'text'=>__('prospective_reminder.'.$kind, ['until'=>$end->format('d M Y H:i T'), 'days'=>$days]),
            'key'=>'prospective_'.$kind.'_'.$sub->id.'_'.now()->toDateString(),
            'show_cta'=>$kind !== 'confirmed'];
    }
}
