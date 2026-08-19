<?php

namespace App\Services;

use App\Models\PosAppDevice;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * FBR POS shell-app push alerts (Task 1275) — mirrors PosPushService plumbing
 * (shared FcmSender + pos_app_devices; users are strictly per-panel so the
 * same table serves both shells).
 *
 * Unlike the PRA service, both senders here run SYNCHRONOUSLY: they are only
 * ever called from scheduled artisan commands (fbrpos:fail-queue-alerts /
 * fbrpos:dayclose-reminders), never from a web request — no terminating()
 * wrapper needed. The commands own throttling/dedupe; this class only targets
 * and sends.
 *
 * Targeting: admin/manager only (pos_admin/pos_manager/company_admin,
 * is_active) — cashiers can't fix the fail queue or close the day.
 */
class FbrPosPushService
{
    /** Safety cap — a company will realistically have a handful of devices. */
    private const MAX_DEVICES_PER_SEND = 50;

    /** Configured + table exists (prod deploy-before-migrate safe). */
    public static function ready(): bool
    {
        if (!FcmSender::isConfigured()) {
            return false;
        }
        try {
            return Schema::hasTable('pos_app_devices');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * "N bills FBR ko report nahi huay" — unreported bills piling up in the
     * fail queue. $count already computed by the command (page predicate).
     * Returns the number of devices attempted (0 = no targets/devices).
     */
    public static function sendFailQueueAlert(int $companyId, int $count): int
    {
        return self::sendToAdmins($companyId, [
            'type' => 'pos_event',
            'event' => 'fbr_fail_queue',
            'nid' => 'fbr-failq-' . $companyId . '-' . now()->format('Ymd'),
            'title' => 'FBR Fail Queue — ' . $count . ' bills',
            'body' => $count . ' bills FBR ko report nahi huay — Fail Queue khol kar Retry All karein.',
        ]);
    }

    /** Day-close reminder — today's sales exist but no close yet. */
    public static function sendDayCloseReminder(int $companyId, string $date): int
    {
        return self::sendToAdmins($companyId, [
            'type' => 'pos_event',
            'event' => 'fbr_dayclose_reminder',
            'nid' => 'fbr-dcrem-' . $companyId . '-' . $date,
            'title' => 'Day Close reh gaya hai',
            'body' => 'Aaj ka din abhi close nahi hua — Day Close karna na bhoolein.',
        ]);
    }

    // ─── Shared plumbing (same contract as PosPushService) ──────────────────

    private static function sendToAdmins(int $companyId, array $data): int
    {
        $targets = User::where('company_id', $companyId)
            ->where('is_active', true)
            ->where(function ($w) {
                $w->whereIn('pos_role', ['pos_admin', 'pos_manager'])
                    ->orWhere('role', 'company_admin');
            })
            ->pluck('id')->all();

        return self::sendToUsers($targets, $companyId, $data);
    }

    private static function sendToUsers(array $userIds, int $companyId, array $data): int
    {
        if (empty($userIds)) {
            return 0;
        }
        $devices = PosAppDevice::where('company_id', $companyId)
            ->whereIn('user_id', $userIds)
            ->orderByDesc('last_seen_at')
            ->limit(self::MAX_DEVICES_PER_SEND)
            ->get();
        $attempted = 0;
        foreach ($devices as $device) {
            $res = FcmSender::send($device->fcm_token, $data);
            if ($res['result'] === 'skipped') {
                return $attempted; // credential/OAuth problem — retrying per-device is pointless
            }
            $attempted++;
            if ($res['result'] === 'dead') {
                // Uninstalled / data-cleared / rotated-away token — stop retrying.
                $device->delete();
            } elseif ($res['result'] === 'error') {
                Log::warning('FbrPosPushService: FCM send non-2xx', [
                    'device_id' => $device->id,
                    'status' => $res['status'],
                    'body' => $res['body'],
                ]);
            }
        }

        return $attempted;
    }
}
