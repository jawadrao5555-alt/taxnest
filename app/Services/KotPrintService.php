<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PosPrintJob;
use App\Models\PosStation;
use App\Models\RestaurantOrder;

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
                $attrs = [
                    'company_id'          => $company->id,
                    'type'                => 'kot_void',
                    'target_printer'      => $printer,
                    'restaurant_order_id' => $order->id,
                    'render_query'        => json_encode(array_values($items)),
                    'status'              => 'pending',
                    'created_by'          => $userId,
                ];
                // Task 1194: void slips route to the owning counter too — key
                // only added when a stamp resolves (pre-migration prod safe).
                if ($stamp = self::deviceStampFor($company->id, $ownerDeviceUid)) {
                    $attrs['device_uid'] = $stamp;
                }
                return PosPrintJob::create($attrs);
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
