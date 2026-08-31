<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PosPrintJob;
use App\Models\PosStation;
use App\Models\RestaurantOrder;
use Illuminate\Support\Facades\DB;

/**
 * Server-side KOT enqueue for SILENT printing — used by the waiter app
 * (ZFC issue #10, 28 Jul 2026: waiter-punched orders stamped kot_sent_at
 * but never created a print job, so the kitchen ticket never printed).
 *
 * Mirrors PosController::apiCreatePrintJob's KOT branch (no-station single
 * job + station-split), WITHOUT the station-pinned KDS path (that one is a
 * device-initiated flow and stays where it is). Best-effort by design:
 * returns ['printed' => false, 'reason' => ...] instead of throwing so an
 * order save is never lost because of printing.
 */
class KotPrintService
{
    /**
     * Task 1194 — enqueue-time owning-device stamp for KOT-family jobs
     * (kot / counter copy / station / kot_void). A pick made on the union
     * printer picker remembers which counter PC owns the printer; stamping
     * the job with that device_uid means ONLY that counter's agent claims it
     * (no Windows printer sharing needed).
     *
     * Returns the uid ONLY when the routing schema is migrated, the device
     * row exists for THIS company, and its agent is ONLINE — mirrors the
     * bill/proof rule: a job stamped for an offline counter would just
     * strand. Anything short of that → null = unstamped legacy job,
     * claimable by any agent (pre-1194 behavior, popup fallback preserved).
     */
    public static function deviceStampFor(int $companyId, ?string $deviceUid): ?string
    {
        if (!$deviceUid || !\App\Http\Controllers\AgentController::deviceRoutingReady()) {
            return null;
        }
        try {
            $device = \App\Models\PosAgentDevice::where('company_id', $companyId)
                ->where('device_uid', $deviceUid)
                ->first();
            return ($device && $device->isOnline()) ? $device->device_uid : null;
        } catch (\Throwable $e) {
            return null; // routing must never break the print fallback chain
        }
    }

    /**
     * Task 1194 — owning device of a STATION job's effective printer: the
     * station's own pick when it has one, else the company KOT pick's owner.
     * Must mirror the printer fallback (`printer_name ?: kot_printer`) —
     * the stamp always belongs to whichever printer actually got the job.
     */
    public static function stationDeviceUid(?PosStation $station, array $settings): ?string
    {
        return ($station && ($station->printer_name ?? null))
            ? ($station->printer_device_uid ?? null)
            : ($settings['kot_printer_device'] ?? null);
    }

    /**
     * Task 1356 — "kitchen ne ye lines dekhi hi nahi" ka WAHID sach.
     *
     * Line-level `restaurant_order_items.kot_printed_at` is the ONLY trustworthy
     * signal. `restaurant_orders.kot_sent_at` must NEVER be used for this: hold
     * stamps it on EVERY held order (RestaurantPosController::holdOrder) even
     * when no ticket was rendered or enqueued, so a straight-to-pay dine-in cart
     * looks "sent" while the kitchen got nothing. Stamps are written at real
     * print time only (kitchenTicket ?auto_print=1 render + agent result), which
     * is exactly the "kitchen saw it" moment we need.
     *
     * Re-queries instead of using a loaded relation: pay paths hold the order in
     * memory from BEFORE the lock/commit, and a concurrent KOT print may have
     * stamped rows in between.
     */
    public static function unseenLineCount(?RestaurantOrder $order): int
    {
        if (!$order || !$order->id) {
            return 0;
        }
        try {
            // Non-kitchen lines (Delivery Charges) is ginti se BAHAR. Woh kabhi
            // chhapti hi nahi, is liye kabhi kot_printed_at stamp nahi khati —
            // ginti mein rehti to har delivery bill hamesha "kitchen ne dekha
            // hi nahi" kehta aur safety-net KOT baar baar chalta. Wahid qaida:
            // App\Support\PosKitchenLines.
            $q = \App\Models\RestaurantOrderItem::where('order_id', $order->id)
                ->whereNull('kot_printed_at');

            return (int) \App\Support\PosKitchenLines::scope($q)->count();
        } catch (\Throwable $e) {
            return 0; // never let the signal break a committed bill
        }
    }

    /**
     * TRUE when a just-finalised bill still owes the kitchen a ticket, i.e. the
     * safety net should fire. Deliberately conservative — every gate below must
     * pass or the sale screen prints nothing new:
     *   • shop actually uses kitchen tickets (restaurant mode + KOT feature) so
     *     plain retail can NEVER get a surprise slip;
     *   • the shop-level off-switch (default ON, missing column = ON);
     *   • at least one line the kitchen has never seen (empty slip impossible).
     * The KDS-owns-printing case is decided client-side (kdsHandlesKot) — those
     * shops surface the order on the KDS board instead of printing.
     */
    public static function pendingForFinal(?Company $company, ?RestaurantOrder $order): bool
    {
        if (!$company || !$order) {
            return false;
        }
        if (!(bool) ($company->restaurant_mode ?? false)) {
            return false;
        }
        if (($company->kot_on_final_if_unsent ?? true) === false) {
            return false;
        }
        try {
            $features = \App\Services\PosFeatureService::forCompany($company);
            if (!($features->kot ?? false)) {
                return false;
            }
        } catch (\Throwable $e) {
            return false;
        }

        return self::unseenLineCount($order) > 0;
    }

    /**
     * Task 1379 — "is this send a REPRINT?" — the WAHID rule behind every
     * kitchen-ticket permission gate (render, silent enqueue, resend). Kept
     * here (not in the controllers) so the render path and the print-job path
     * can never drift apart and open a bypass.
     *
     * Deliberately conservative — a blocked staffer must be stopped, but a
     * genuine FIRST fire must NEVER be blocked (a lost KOT is worse than a
     * duplicate one):
     *   • batch=last ("Akhri Add-on" rescue) → reprint by definition.
     *   • delta=1 → renders ONLY rows the kitchen never saw → first fire.
     *   • full ticket → reprint only when the kitchen has already seen EVERY
     *     line (zero unprinted rows). A full ticket that still carries new
     *     lines (KDS adoption, waiter appends) stays open.
     * Line-level kot_printed_at is the only trustworthy signal — see
     * unseenLineCount() for why orders.kot_sent_at must not be used.
     */
    public static function isReprintRender(?RestaurantOrder $order, bool $delta, bool $batchLast = false): bool
    {
        if ($batchLast) {
            return true;
        }
        if (!$order || !$order->id || $delta) {
            return false;
        }
        try {
            // Sirf kitchen wali lines (PosKitchenLines) — warna ek Delivery
            // Charges row hamesha unseen reh kar har full ticket ko "pehla
            // fire" bana deti aur reprint gate kabhi band na hota.
            $row = \App\Support\PosKitchenLines::scope(
                \App\Models\RestaurantOrderItem::where('order_id', $order->id)
            )
                ->selectRaw('COUNT(*) AS total, SUM(CASE WHEN kot_printed_at IS NULL THEN 1 ELSE 0 END) AS unseen')
                ->first();
            $total = (int) ($row->total ?? 0);
            $unseen = (int) ($row->unseen ?? 0);

            return $total > 0 && $unseen === 0;
        } catch (\Throwable $e) {
            return false; // signal failure must never block a first fire
        }
    }

    /**
     * Task 1379 — transaction (order-less delivery bill) KOTs. renderTransactionKot
     * stamps kot_sent_at on the FIRST render, so an already-stamped bill means
     * the kitchen has the slip and this is the reprint.
     */
    public static function isTransactionReprint($transaction): bool
    {
        return $transaction ? (bool) ($transaction->kot_sent_at ?? null) : false;
    }

    /**
     * Task 1368 — the DELIVERY lane of "Payment First, Then KOT" ("bill final ho
     * to kitchen ki parchi sach mein jaye").
     *
     * The F10 provisional row carries the flag the sale screen acts on when a
     * delivery bill is made final. That flag used to be `empty($txn->kot_sent_at)`
     * alone, which is only half the truth: most delivery provisionals DO have a
     * restaurant order behind them (the sale screen saves them through the
     * internal hold → payOrder pass-through), and hold stamps that order's
     * kot_sent_at whether or not a ticket was ever printed. So the same lie Task
     * 1356 removed from dine-in/counter finals was still driving this lane — a
     * bill whose ticket had already fired got a SECOND full slip at final, and
     * "kitchen ne dekh liya" was answered by a stamp that never meant that.
     *
     * The rule, in order:
     *   • the shop toggle + delivery order type decide whether this lane runs at
     *     all — unchanged, so a shop's configured behaviour stays exactly as is;
     *   • a TRANSACTION-level stamp still means the order-less shim ticket has
     *     already been rendered (isTransactionReprint) → nothing owed;
     *   • with a linked order, LINE stamps decide (unseenLineCount, the same
     *     single truth pendingForFinal uses): unseen lines → ticket owed, and it
     *     is printed as a DELTA off that order, never a full reprint; every line
     *     already seen → nothing owed, no second slip;
     *   • an order carrying no lines at all is no signal — fall back to the
     *     transaction ticket rather than leave a real bill uncooked.
     *
     * NOTE the shop switch here is delivery_kot_after_payment, NOT
     * kot_on_final_if_unsent: this lane is the toggle's own promised behaviour
     * (hold the ticket until payment), not the straight-to-pay safety net, so
     * pendingForFinal's extra gates must not silence it.
     *
     * @return array{pending: bool, order_id: int|null} order_id != null =>
     *         print the DELTA kitchen ticket of that restaurant order;
     *         null with pending => order-less bill, print the transaction ticket.
     */
    public static function deliveryPromoteKot(?Company $company, $transaction, ?RestaurantOrder $order): array
    {
        $none = ['pending' => false, 'order_id' => null];

        if (!$company || !$transaction) {
            return $none;
        }
        if (!(bool) ($company->delivery_kot_after_payment ?? false)) {
            return $none;
        }
        if (($transaction->order_type ?? null) !== 'delivery') {
            return $none;
        }
        if (self::isTransactionReprint($transaction)) {
            return $none;
        }
        if (!$order || !$order->id) {
            return ['pending' => true, 'order_id' => null];
        }
        if (self::unseenLineCount($order) > 0) {
            return ['pending' => true, 'order_id' => (int) $order->id];
        }

        // Zero unseen lines = either the kitchen has genuinely seen every line
        // (isReprintRender true → no second slip, the bug this rule exists for)
        // or the order has no lines to judge → transaction ticket stays the
        // fallback.
        return self::isReprintRender($order, false) ? $none : ['pending' => true, 'order_id' => null];
    }

    /**
     * @return array{printed: bool, reason?: string, job_ids?: array<int>}
     */
    public static function enqueueForOrder(Company $company, RestaurantOrder $order, ?int $userId, bool $delta = false): array
    {
        try {
            $settings = $company->printerSettings();
            if (!$settings['silent_print_enabled']) {
                return ['printed' => false, 'reason' => 'disabled'];
            }
            if (!$company->agentOnline()) {
                return ['printed' => false, 'reason' => 'agent_offline'];
            }

            $order->loadMissing('items');
            // Delivery Charges jaisi non-kitchen lines yahin, SAB HISAAB SE
            // PEHLE nikal do: delta ids, station mapping aur "kuch chhapna hai
            // ya nahi" — sab isi chhanti hui list par. Warna ek aisi row jo
            // kabhi chhapti hi nahi, hamesha unprinted reh kar khali (204)
            // print job banwati rehti.
            \App\Support\PosKitchenLines::pruneOrder($order);
            $deltaQ = $delta ? '&delta=1' : '';
            // Delta snapshot (Pizza Master edit-path bug, Aug 2026): bake the
            // unprinted row ids into EVERY job of this send — result-time
            // stamping from the first printed job must not empty the later
            // overlapping delta jobs (counter copy). Mirrors apiCreatePrintJob.
            $deltaIds = $delta
                ? $order->items->whereNull('kot_printed_at')->pluck('id')->map(fn ($i) => (int) $i)->values()->all()
                : null;
            if ($delta && empty($deltaIds)) {
                return ['printed' => true, 'job_ids' => []];
            }
            $makeJob = function (?string $printer, ?string $renderQuery, ?string $ownerDeviceUid = null) use ($company, $order, $userId, $delta, $deltaIds) {
                // Task 753: in-flight dedupe + merge — mirrors apiCreatePrintJob's
                // rule so the hold-time server enqueue, the KDS auto-print fire and
                // the cashier fallback all collapse into ONE physical slip. A
                // pending delta job absorbs newly-unprinted ids (rapid second
                // append); an already-printing job keeps its rendered set.
                $inFlight = PosPrintJob::where('company_id', $company->id)
                    ->where('type', 'kot')
                    ->where('restaurant_order_id', $order->id)
                    ->where('target_printer', $printer)
                    ->where(fn ($q) => $renderQuery === null ? $q->whereNull('render_query') : $q->where('render_query', $renderQuery))
                    ->whereIn('status', ['pending', 'printing'])
                    ->where('created_at', '>=', now()->subMinutes(2))
                    ->orderByDesc('id')->first();
                if ($inFlight) {
                    if ($delta && $deltaIds && $inFlight->status === 'pending') {
                        $merged = collect($inFlight->printed_item_ids ?? [])->map(fn ($i) => (int) $i)
                            ->merge($deltaIds)->unique()->values()->all();
                        if ($merged !== ($inFlight->printed_item_ids ?? [])) {
                            $inFlight->update(['printed_item_ids' => $merged]);
                        }
                    }
                    return $inFlight;
                }
                $attrs = [
                    'company_id' => $company->id,
                    'type' => 'kot',
                    'target_printer' => $printer,
                    'restaurant_order_id' => $order->id,
                    'render_query' => $renderQuery,
                    'printed_item_ids' => ($delta && $deltaIds) ? $deltaIds : null,
                    'status' => 'pending',
                    'created_by' => $userId,
                ];
                // Task 1194: key only added when a stamp resolves — pre-migration
                // prod (no device_uid column) never sees it in the INSERT.
                if ($stamp = self::deviceStampFor($company->id, $ownerDeviceUid)) {
                    $attrs['device_uid'] = $stamp;
                }
                return PosPrintJob::create($attrs);
            };

            $stations = PosStation::activeFor($company->id);

            // Counter KOT Copy (owner request 30 Jul 2026): DINE-IN orders only —
            // one FULL copy of the KOT on the counter printer, in addition to the
            // normal kitchen job(s). Best-effort, never blocks the kitchen print.
            $counterCopy = function () use ($settings, $order, $makeJob, $delta) {
                try {
                    if (!($settings['counter_kot_enabled'] ?? false)) return;
                    $printer = $settings['counter_kot_printer'] ?? null;
                    if (!$printer || ($order->order_type ?? null) !== 'dine_in') return;
                    $makeJob($printer, $delta ? 'delta=1' : null, $settings['counter_kot_printer_device'] ?? null);
                } catch (\Throwable $e) { /* copy is optional */ }
            };

            // Zero stations => single full/delta KOT on the company KOT printer.
            if ($stations->isEmpty()) {
                if (!$settings['kot_printer']) {
                    return ['printed' => false, 'reason' => 'no_printer'];
                }
                $job = $makeJob($settings['kot_printer'], $delta ? 'delta=1' : null, $settings['kot_printer_device'] ?? null);
                $counterCopy();
                return ['printed' => true, 'job_ids' => [$job->id]];
            }

            // Stations configured: SPLIT — one job per station that has items.
            $baseItems = $delta ? $order->items->whereNull('kot_printed_at')->values() : $order->items;
            $itemMap = PosStation::mapItems($company->id, $stations, $baseItems);
            $sids = collect($itemMap)->values()->unique()->sort()->values();
            if ($sids->isEmpty()) {
                return ['printed' => true, 'job_ids' => []];
            }

            $plan = [];
            foreach ($sids as $sid) {
                $station = $sid === PosStation::DEFAULT_ID ? null : $stations->firstWhere('id', $sid);
                $printer = ($station->printer_name ?? null) ?: $settings['kot_printer'];
                if (!$printer) {
                    return ['printed' => false, 'reason' => 'no_printer'];
                }
                $plan[] = [$printer, 'station=' . $sid . $deltaQ, self::stationDeviceUid($station, $settings)];
            }
            $jobIds = [];
            foreach ($plan as [$printer, $rq, $ownerUid]) {
                $jobIds[] = $makeJob($printer, $rq, $ownerUid)->id;
            }
            $counterCopy();
            return ['printed' => true, 'job_ids' => $jobIds];
        } catch (\Throwable $e) {
            \Log::warning('KotPrintService enqueue failed: ' . $e->getMessage(), ['order_id' => $order->id ?? null]);
            return ['printed' => false, 'reason' => 'error'];
        }
    }

    /**
     * Task 794 — VOID / CANCEL slip enqueue: dishes removed from a running
     * order AFTER their KOT already printed. The kitchen must STOP making the
     * removed qty. Void items ride in render_query as JSON (kot_void jobs are
     * never station-query-parsed, so the field is free for payload use):
     *   [{item_type, item_id, item_name, notes, qty}, ...]
     *
     * Station routing: each void line is mapped through the SAME resolver as
     * normal KOTs (PosStation::mapItems on item_type/item_id/name) so the void
     * reaches the counter that got the original dish; one job per station that
     * had a removed item. Zero stations => single job on the company KOT
     * printer. Counter copy honored for dine-in like normal KOTs.
     * Best-effort by design — never throws.
     *
     * @param array<int, array{item_type: string, item_id: mixed, item_name: string, notes: string, qty: float}> $voidItems
     * @return array{printed: bool, reason?: string, job_ids?: array<int>}
     */
    public static function enqueueVoid(Company $company, RestaurantOrder $order, array $voidItems, ?int $userId): array
    {
        try {
            if (empty($voidItems)) {
                return ['printed' => true, 'job_ids' => []];
            }
            $settings = $company->printerSettings();
            if (!$settings['silent_print_enabled']) {
                return ['printed' => false, 'reason' => 'disabled'];
            }
            if (!$company->agentOnline()) {
                return ['printed' => false, 'reason' => 'agent_offline'];
            }

            $makeVoidJob = function (?string $printer, array $items, ?string $ownerDeviceUid = null) use ($company, $order, $userId) {
                $renderQuery = json_encode(array_values($items));

                // Task 951: lock this order while finding or creating the
                // station slip. Two simultaneous waiter taps therefore cannot
                // both observe an empty queue and create duplicate jobs. The
                // exact payload is part of the identity so a later, different
                // cancellation for the same station still reaches the kitchen.
                return DB::transaction(function () use ($company, $order, $userId, $printer, $ownerDeviceUid, $renderQuery) {
                    RestaurantOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

                    $inFlight = PosPrintJob::where('company_id', $company->id)
                        ->where('type', 'kot_void')
                        ->where('restaurant_order_id', $order->id)
                        ->where('target_printer', $printer)
                        ->where('render_query', $renderQuery)
                        ->whereIn('status', ['pending', 'printing'])
                        ->where('created_at', '>=', now()->subMinutes(2))
                        ->orderByDesc('id')
                        ->first();
                    if ($inFlight) {
                        return $inFlight;
                    }

                    $attrs = [
                        'company_id'          => $company->id,
                        'type'                => 'kot_void',
                        'target_printer'      => $printer,
                        'restaurant_order_id' => $order->id,
                        'render_query'        => $renderQuery,
                        'status'              => 'pending',
                        'created_by'          => $userId,
                    ];
                    // Task 1194: void slips route to the owning counter too — key
                    // only added when a stamp resolves (pre-migration prod safe).
                    if ($stamp = self::deviceStampFor($company->id, $ownerDeviceUid)) {
                        $attrs['device_uid'] = $stamp;
                    }
                    return PosPrintJob::create($attrs);
                });
            };

            // Counter copy (dine-in only, same policy as normal KOT copies) always
            // carries the FULL void list — the counter oversees every station.
            $counterCopy = function () use ($settings, $order, $makeVoidJob, $voidItems) {
                try {
                    if (!($settings['counter_kot_enabled'] ?? false)) return;
                    $printer = $settings['counter_kot_printer'] ?? null;
                    if (!$printer || ($order->order_type ?? null) !== 'dine_in') return;
                    $makeVoidJob($printer, $voidItems, $settings['counter_kot_printer_device'] ?? null);
                } catch (\Throwable $e) { /* copy is optional */ }
            };

            $stations = PosStation::activeFor($company->id);

            if ($stations->isEmpty()) {
                if (!$settings['kot_printer']) {
                    return ['printed' => false, 'reason' => 'no_printer'];
                }
                $job = $makeVoidJob($settings['kot_printer'], $voidItems, $settings['kot_printer_device'] ?? null);
                $counterCopy();
                return ['printed' => true, 'job_ids' => [$job->id]];
            }

            // Stations configured: route each void line to the station that got the
            // original dish. mapItems keys on the item's type/id/name — shim rows
            // (unsaved models) work because only attributes are read.
            $shimRows = collect($voidItems)->map(function ($vi, $idx) {
                $row = new \App\Models\RestaurantOrderItem([
                    'item_type' => $vi['item_type'] ?? 'product',
                    'item_id'   => $vi['item_id'] ?? null,
                    'item_name' => $vi['item_name'] ?? '',
                ]);
                $row->id = $idx; // stable local key for the split below
                return $row;
            })->values();
            $itemMap = PosStation::mapItems($company->id, $stations, $shimRows);

            $byStation = [];
            foreach ($shimRows as $row) {
                $sid = $itemMap[$row->id] ?? PosStation::DEFAULT_ID;
                $byStation[$sid][] = $voidItems[$row->id];
            }

            $jobIds = [];
            foreach ($byStation as $sid => $items) {
                $station = $sid === PosStation::DEFAULT_ID ? null : $stations->firstWhere('id', $sid);
                $printer = ($station->printer_name ?? null) ?: $settings['kot_printer'];
                if (!$printer) {
                    return ['printed' => false, 'reason' => 'no_printer'];
                }
                $jobIds[] = $makeVoidJob($printer, $items, self::stationDeviceUid($station, $settings))->id;
            }
            $counterCopy();
            return ['printed' => true, 'job_ids' => $jobIds];
        } catch (\Throwable $e) {
            \Log::warning('KotPrintService void enqueue failed: ' . $e->getMessage(), ['order_id' => $order->id ?? null]);
            return ['printed' => false, 'reason' => 'error'];
        }
    }
}
