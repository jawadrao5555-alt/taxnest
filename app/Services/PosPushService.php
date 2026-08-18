<?php

namespace App\Services;

use App\Models\PosAppDevice;
use App\Models\RestaurantOrder;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * POS shell-app instant push (Task #1142) — waiter/cashier/owner phone
 * notifications via the shared FcmSender. Mirrors the rider push contract:
 *
 *  - FIRE-AND-FORGET: every queue*() defers the network work to
 *    app()->terminating() — after the response is flushed — and traps all
 *    throwables. Push can NEVER block or fail order submit / KDS status /
 *    day-close. Push is additive: poll/refresh keeps working without it.
 *  - Data-only messages; the shell's PushService builds a local notification
 *    from title/body (generic payload → future notification types need no
 *    APK update).
 *  - Dead tokens (UNREGISTERED/404/INVALID_ARGUMENT) delete their
 *    pos_app_devices row so they are not retried forever.
 *  - Missing credential / missing table (prod deploy-before-migrate) → no-op.
 *
 * Notification set (owner-approved, Aug 2026):
 *  - new waiter order  → assigned cashier, or ALL cashier-capable users
 *                        (pos_cashier/pos_manager/pos_admin/company_admin)
 *  - KDS marks READY   → the order's creating waiter (pos_waiter only —
 *                        admins punching from the tablet are at the counter
 *                        already)
 *  - PRA day-close     → owner/admin/manager with a short totals summary
 * Confined roles (archive_viewer/local_viewer) are never targeted.
 */
class PosPushService
{
    /** Safety cap — a company will realistically have a handful of devices. */
    private const MAX_DEVICES_PER_SEND = 50;

    // ─── Public API (all fire-and-forget) ───────────────────────────────────

    /** Waiter punched a new order → notify the cashier(s). */
    public static function queueWaiterOrderPush(?int $orderId): void
    {
        if (!$orderId || !self::ready()) {
            return;
        }
        app()->terminating(function () use ($orderId) {
            try {
                self::sendWaiterOrder($orderId);
            } catch (\Throwable $e) {
                Log::warning('PosPushService: new-order push failed (order saved normally)', [
                    'order_id' => $orderId, 'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /** Kitchen marked the order READY → notify the creating waiter. */
    public static function queueOrderReadyPush(?int $orderId): void
    {
        if (!$orderId || !self::ready()) {
            return;
        }
        app()->terminating(function () use ($orderId) {
            try {
                self::sendOrderReady($orderId);
            } catch (\Throwable $e) {
                Log::warning('PosPushService: order-ready push failed (status saved normally)', [
                    'order_id' => $orderId, 'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * PRA day-close persisted → notify owner/admin/manager with a summary.
     * $summary keys: date, total, cash, invoices (already computed by the
     * close — never re-derived here).
     */
    public static function queueDayClosePush(?int $companyId, array $summary): void
    {
        if (!$companyId || !self::ready()) {
            return;
        }
        app()->terminating(function () use ($companyId, $summary) {
            try {
                self::sendDayClose($companyId, $summary);
            } catch (\Throwable $e) {
                Log::warning('PosPushService: day-close push failed (close completed normally)', [
                    'company_id' => $companyId, 'error' => $e->getMessage(),
                ]);
            }
        });
    }

    // ─── Blocking senders (only ever run inside terminating callbacks) ──────

    private static function sendWaiterOrder(int $orderId): void
    {
        // Re-load AFTER commit: if the punch transaction rolled back or the
        // order was already cancelled/claimed, there is nothing to alert.
        $order = RestaurantOrder::with('table')->find($orderId);
        if (!$order || $order->status !== 'held') {
            return;
        }

        $targets = User::where('company_id', $order->company_id)
            ->where('is_active', true)
            ->when($order->assigned_cashier_id, function ($q) use ($order) {
                $q->where('id', $order->assigned_cashier_id);
            }, function ($q) {
                // Unassigned → every cashier-capable user (same capability set
                // the waiter "send to" picker uses).
                $q->where(function ($w) {
                    $w->whereIn('pos_role', ['pos_admin', 'pos_manager', 'pos_cashier'])
                        ->orWhere('role', 'company_admin');
                });
            })
            // Never self-notify the person who punched the order.
            ->where('id', '!=', (int) $order->created_by)
            ->pluck('id')->all();

        $where = self::orderWhere($order);
        $amount = number_format((float) $order->total_amount);
        self::sendToUsers($targets, (int) $order->company_id, [
            'type' => 'pos_event',
            'event' => 'new_order',
            'nid' => 'order-' . $order->id,
            'title' => 'Naya Order — ' . $where,
            'body' => 'Naya order aaya — ' . $where . ' · ' . $order->order_number . ' · Rs ' . $amount,
        ]);
    }

    private static function sendOrderReady(int $orderId): void
    {
        $order = RestaurantOrder::with('table')->find($orderId);
        if (!$order || !$order->created_by || $order->kitchen_status !== 'ready') {
            return;
        }
        $creator = User::find($order->created_by);
        // Waiters only — a cashier/admin who punched from the counter is
        // looking at the KDS/held panel already.
        if (!$creator || !$creator->is_active || ($creator->pos_role ?? null) !== 'pos_waiter') {
            return;
        }

        $where = self::orderWhere($order);
        self::sendToUsers([$creator->id], (int) $order->company_id, [
            'type' => 'pos_event',
            'event' => 'order_ready',
            'nid' => 'ready-' . $order->id,
            'title' => 'Order Tayyar — ' . $where,
            'body' => 'Order tayyar hai — ' . $where . ' · ' . $order->order_number,
        ]);
    }

    private static function sendDayClose(int $companyId, array $summary): void
    {
        $targets = User::where('company_id', $companyId)
            ->where('is_active', true)
            ->where(function ($w) {
                $w->whereIn('pos_role', ['pos_admin', 'pos_manager'])
                    ->orWhere('role', 'company_admin');
            })
            ->pluck('id')->all();

        $date = (string) ($summary['date'] ?? '');
        try {
            $date = \Carbon\Carbon::parse($date)->format('d M Y');
        } catch (\Throwable $e) {
            // keep raw date string
        }
        $body = 'Total sale Rs ' . number_format((float) ($summary['total'] ?? 0))
            . ' · Cash Rs ' . number_format((float) ($summary['cash'] ?? 0))
            . ' · ' . (int) ($summary['invoices'] ?? 0) . ' bills';
        self::sendToUsers($targets, $companyId, [
            'type' => 'pos_event',
            'event' => 'day_close',
            'nid' => 'dayclose-' . $companyId . '-' . ($summary['date'] ?? ''),
            'title' => 'Day Close — ' . $date,
            'body' => $body,
        ]);
    }

    // ─── Shared plumbing ────────────────────────────────────────────────────

    /** Configured + table exists (prod deploy-before-migrate safe). */
    private static function ready(): bool
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

    private static function sendToUsers(array $userIds, int $companyId, array $data): void
    {
        if (empty($userIds)) {
            return;
        }
        $devices = PosAppDevice::where('company_id', $companyId)
            ->whereIn('user_id', $userIds)
            ->orderByDesc('last_seen_at')
            ->limit(self::MAX_DEVICES_PER_SEND)
            ->get();
        foreach ($devices as $device) {
            $res = FcmSender::send($device->fcm_token, $data);
            if ($res['result'] === 'skipped') {
                return; // credential/OAuth problem — retrying per-device is pointless
            }
            if ($res['result'] === 'dead') {
                // Uninstalled / data-cleared / rotated-away token — stop retrying.
                $device->delete();
            } elseif ($res['result'] === 'error') {
                Log::warning('PosPushService: FCM send non-2xx', [
                    'device_id' => $device->id,
                    'status' => $res['status'],
                    'body' => $res['body'],
                ]);
            }
        }
    }

    /** Human place label — "Table 5" / "Takeaway" / "Delivery" / "Counter". */
    private static function orderWhere(RestaurantOrder $order): string
    {
        if ($order->order_type === 'dine_in') {
            $tno = optional($order->table)->table_number;
            return $tno !== null && $tno !== '' ? ('Table ' . $tno) : 'Dine-in';
        }
        if ($order->order_type === 'takeaway') {
            return 'Takeaway';
        }
        if ($order->order_type === 'delivery') {
            return 'Delivery';
        }
        return 'Counter';
    }
}
