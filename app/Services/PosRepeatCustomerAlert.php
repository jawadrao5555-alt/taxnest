<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 1161: "Purane customer khamosh hain" — repeat-customer inactivity alert.
 *
 * A REGULAR (repeat) customer who suddenly stops ordering is a churn signal
 * the owner wants surfaced: no order for ~2 weeks → staff can call and ask if
 * something went wrong. This service is the SINGLE definition of "repeat +
 * khamosh" so the dashboard card, the customers-page chip and the history-page
 * chip can never drift apart.
 *
 * Definitions (all thresholds live HERE — tune in one place):
 *   - repeat customer   = MIN_ORDERS+ completed orders, all-time
 *   - khamosh (quiet)   = last completed order older than INACTIVE_DAYS days
 *   - stale drop-off    = last order older than STALE_DAYS days → NOT listed.
 *     Without this the card would list every long-gone customer forever —
 *     the alert is about *recent* churn ("used to order, NOW silent"), and
 *     a customer silent for months is old news, not an actionable alert.
 *
 * Order sources (mirrors the customer-history matching conventions):
 *   - pos_transactions: matched by customer_id OR (customer_id NULL +
 *     customer_phone = customer's phone) — same rule as
 *     PosController::customerTransactions. Returns/credit notes are NOT
 *     orders (transaction_type='return' excluded, schema-guarded).
 *     Queried via DB::table so ARCHIVED local bills still count (day-close
 *     'save' policy keeps them as real purchases — matches the history page
 *     under the default spend-persist setting).
 *   - pos_customer_spend_snapshots: deleted local bills' spend snapshots
 *     (day-close 'delete' policy) — without these a delete-policy shop's
 *     regulars would look like they never ordered.
 *   - restaurant_orders: completed dine-in/waiter orders, but ONLY rows with
 *     pos_transaction_id NULL — a settled order's linked bill is already
 *     counted from pos_transactions (counting both = double).
 *
 * On-the-fly + short per-company cache (CACHE_TTL) — NO scheduled job, live
 * cPanel has no reliable cron. Company-scoped everywhere; deactivated
 * customers (is_active=false) are skipped — staff shouldn't call them.
 * hasTable/hasColumn guards keep pre-migration PROD schemas alive (drift policy).
 */
class PosRepeatCustomerAlert
{
    /** Repeat customer = at least this many completed orders (all-time). */
    public const MIN_ORDERS = 3;

    /** Khamosh window: last completed order older than this many days. */
    public const INACTIVE_DAYS = 12;

    /** Older than this = long-gone (not *recent* churn) — drops off the alert. */
    public const STALE_DAYS = 60;

    /** Dashboard card renders at most this many rows (full list stays cached). */
    public const CARD_LIMIT = 10;

    /** Per-company cache TTL, seconds. */
    public const CACHE_TTL = 600;

    /**
     * Cached list of khamosh repeat customers for a company, most orders first.
     *
     * @return Collection<int, array{id:int,name:string,phone:?string,orders:int,last_order_at:string,days:int}>
     */
    public static function listFor(int $companyId): Collection
    {
        return Cache::remember(
            "pos_inactive_regulars:{$companyId}",
            self::CACHE_TTL,
            fn () => self::compute($companyId)
        );
    }

    /**
     * customer_id => row map for the customers list / history page chips —
     * same cached list as the dashboard card, so definitions never drift.
     *
     * @return array<int, array{id:int,name:string,phone:?string,orders:int,last_order_at:string,days:int}>
     */
    public static function mapFor(int $companyId): array
    {
        return self::listFor($companyId)->keyBy('id')->all();
    }

    private static function compute(int $companyId): Collection
    {
        if (!Schema::hasTable('pos_customers') || !Schema::hasTable('pos_transactions')) {
            return collect();
        }

        // cid => ['orders' => int, 'last' => 'Y-m-d H:i:s']
        $agg = [];
        $merge = function ($rows) use (&$agg) {
            foreach ($rows as $r) {
                $cid = (int) $r->cid;
                if ($cid <= 0) {
                    continue;
                }
                if (!isset($agg[$cid])) {
                    $agg[$cid] = ['orders' => 0, 'last' => null];
                }
                $agg[$cid]['orders'] += (int) $r->c;
                $last = (string) $r->last;
                if ($last !== '' && ($agg[$cid]['last'] === null || $last > $agg[$cid]['last'])) {
                    $agg[$cid]['last'] = $last;
                }
            }
        };

        $typeReady = Schema::hasColumn('pos_transactions', 'transaction_type');
        $notReturn = function ($q) use ($typeReady) {
            if ($typeReady) {
                $q->where(function ($w) {
                    $w->whereNull('transaction_type')->orWhere('transaction_type', '!=', 'return');
                });
            }
        };

        // (a) Bills linked by customer_id.
        $merge(DB::table('pos_transactions')
            ->where('company_id', $companyId)
            ->where('status', 'completed')
            ->whereNotNull('customer_id')
            ->tap($notReturn)
            ->groupBy('customer_id')
            ->selectRaw('customer_id as cid, COUNT(*) as c, MAX(created_at) as last')
            ->get());

        // (b) Walk-in bills carrying only a phone — matched to the customer by
        //     phone, exactly like the history page (customer_id NULL rows only,
        //     so a linked bill is never counted twice).
        $merge(DB::table('pos_transactions as t')
            ->join('pos_customers as pc', function ($j) use ($companyId) {
                $j->on('pc.phone', '=', 't.customer_phone')
                    ->where('pc.company_id', '=', $companyId);
            })
            ->where('t.company_id', $companyId)
            ->where('t.status', 'completed')
            ->whereNull('t.customer_id')
            ->whereNotNull('t.customer_phone')
            ->where('t.customer_phone', '!=', '')
            ->tap(function ($q) use ($typeReady) {
                if ($typeReady) {
                    $q->where(function ($w) {
                        $w->whereNull('t.transaction_type')->orWhere('t.transaction_type', '!=', 'return');
                    });
                }
            })
            ->groupBy('pc.id')
            ->selectRaw('pc.id as cid, COUNT(*) as c, MAX(t.created_at) as last')
            ->get());

        // (c) Spend snapshots of DELETED local bills (day-close 'delete' policy).
        if (Schema::hasTable('pos_customer_spend_snapshots')) {
            $merge(DB::table('pos_customer_spend_snapshots')
                ->where('company_id', $companyId)
                ->whereNotNull('customer_id')
                ->groupBy('customer_id')
                ->selectRaw('customer_id as cid, COUNT(*) as c, MAX(COALESCE(sold_at, created_at)) as last')
                ->get());
            $merge(DB::table('pos_customer_spend_snapshots as s')
                ->join('pos_customers as pc', function ($j) use ($companyId) {
                    $j->on('pc.phone', '=', 's.customer_phone')
                        ->where('pc.company_id', '=', $companyId);
                })
                ->where('s.company_id', $companyId)
                ->whereNull('s.customer_id')
                ->whereNotNull('s.customer_phone')
                ->where('s.customer_phone', '!=', '')
                ->groupBy('pc.id')
                ->selectRaw('pc.id as cid, COUNT(*) as c, MAX(COALESCE(s.sold_at, s.created_at)) as last')
                ->get());
        }

        // (d) Completed restaurant orders that never got a linked bill row —
        //     linked ones are already counted from pos_transactions.
        if (Schema::hasTable('restaurant_orders')) {
            $merge(DB::table('restaurant_orders')
                ->where('company_id', $companyId)
                ->where('status', 'completed')
                ->whereNotNull('customer_id')
                ->whereNull('pos_transaction_id')
                ->groupBy('customer_id')
                ->selectRaw('customer_id as cid, COUNT(*) as c, MAX(created_at) as last')
                ->get());
        }

        $now = now();
        $quietBefore = $now->copy()->subDays(self::INACTIVE_DAYS);
        $staleBefore = $now->copy()->subDays(self::STALE_DAYS);

        $hits = [];
        foreach ($agg as $cid => $a) {
            if ($a['orders'] < self::MIN_ORDERS || $a['last'] === null) {
                continue;
            }
            $last = Carbon::parse($a['last'], config('app.timezone'));
            if ($last >= $quietBefore || $last < $staleBefore) {
                continue;
            }
            $hits[$cid] = ['orders' => $a['orders'], 'last' => $last];
        }

        if (empty($hits)) {
            return collect();
        }

        // Only live customers get an alert row (deactivated = don't call).
        $customers = DB::table('pos_customers')
            ->where('company_id', $companyId)
            ->where('is_active', 1)
            ->whereIn('id', array_keys($hits))
            ->get(['id', 'name', 'phone']);

        return $customers->map(function ($c) use ($hits, $now) {
            $h = $hits[(int) $c->id];
            return [
                'id' => (int) $c->id,
                'name' => (string) ($c->name ?? ''),
                'phone' => $c->phone !== null && trim((string) $c->phone) !== '' ? (string) $c->phone : null,
                'orders' => (int) $h['orders'],
                'last_order_at' => $h['last']->toDateTimeString(),
                // Carbon 3: diffInDays is SIGNED — past date vs now is positive here.
                'days' => (int) floor($h['last']->diffInDays($now, true)),
            ];
        })
            ->sortByDesc('orders') // most valuable regulars first
            ->values();
    }
}
