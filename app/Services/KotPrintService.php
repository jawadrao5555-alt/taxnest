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
            $makeJob = function (?string $printer, ?string $renderQuery) use ($company, $order, $userId, $delta, $deltaIds) {
                return PosPrintJob::create([
                    'company_id' => $company->id,
                    'type' => 'kot',
                    'target_printer' => $printer,
                    'restaurant_order_id' => $order->id,
                    'render_query' => $renderQuery,
                    'printed_item_ids' => ($delta && $deltaIds) ? $deltaIds : null,
                    'status' => 'pending',
                    'created_by' => $userId,
                ]);
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
                    $makeJob($printer, $delta ? 'delta=1' : null);
                } catch (\Throwable $e) { /* copy is optional */ }
            };

            // Zero stations => single full/delta KOT on the company KOT printer.
            if ($stations->isEmpty()) {
                if (!$settings['kot_printer']) {
                    return ['printed' => false, 'reason' => 'no_printer'];
                }
                $job = $makeJob($settings['kot_printer'], $delta ? 'delta=1' : null);
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
                $plan[] = [$printer, 'station=' . $sid . $deltaQ];
            }
            $jobIds = [];
            foreach ($plan as [$printer, $rq]) {
                $jobIds[] = $makeJob($printer, $rq)->id;
            }
            $counterCopy();
            return ['printed' => true, 'job_ids' => $jobIds];
        } catch (\Throwable $e) {
            \Log::warning('KotPrintService enqueue failed: ' . $e->getMessage(), ['order_id' => $order->id ?? null]);
            return ['printed' => false, 'reason' => 'error'];
        }
    }
}
