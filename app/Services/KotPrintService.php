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
     * Offline KOT local handoff (Sep 2026).
     *
     * A hold that reached the cloud from the shop PC's Local Core with a
     * kot_document means "the shop PC is printing this kitchen slip itself".
     * The cloud records that as a pos_print_jobs row with status LOCAL_STATUS
     * (never claimable by the agent poll) instead of stamping the lines
     * printed — a stamp is written ONLY when the shop PC's durable
     * print.complete acknowledgement arrives (the paper really came out).
     *
     * While the handoff is FRESH (younger than LOCAL_HANDOFF_TIMEOUT_SECONDS)
     * every cloud KOT enqueue path excludes the handed-off lines, so the
     * counter's auto/full/safety-net KOT cannot print them a second time.
     * Once it is older than that with no acknowledgement AND the shop PC
     * still looks online, expireLocalHandoffs() marks it expired and enqueues
     * the normal cloud KOT for whatever is still unprinted. A dead /
     * unresponsive shop PC is parked as Action Required immediately instead
     * of a silent 5-minute wait — automatic reprint is not proven safe after
     * a local print that may already have come out. The shop PC gives up
     * strictly EARLIER (pra-agent LOCAL_KOT_HANDOFF_MS = 3 min, measured from
     * the same accept instant) and says so with print.fail{terminal} — so
     * there is never a moment where both sides believe they own the slip.
     */
    public const LOCAL_STATUS = 'local';
    public const LOCAL_EXPIRED_STATUS = 'expired';
    public const LOCAL_HANDOFF_TIMEOUT_SECONDS = 300;

    /**
     * Fast dead-agent window for an OPEN kitchen handoff / in-flight claim.
     * The desktop agent heartbeats about every 30s and is treated online for
     * 2 minutes. Three missed beats (90s) is the fastest safe "this PC is
     * gone" signal that does not false-trigger on a single network blip.
     */
    public const HANDOFF_UNRESPONSIVE_SECONDS = 90;

    /**
     * Shop PC died or stopped answering after it took the slip. Paper may
     * already be in the tray — automatic reprint is NOT proven safe.
     */
    public const LOCAL_AGENT_UNRESPONSIVE_ERROR = 'local_agent_unresponsive: the shop PC stopped answering after it took this kitchen slip. Check the printer tray — reprint from the bill/order screen only if nothing came out.';

    /** An authenticated agent restarted during printer transport. The paper outcome is unknown. */
    public const LOCAL_INTERRUPTED_ERROR = 'local_agent_interrupted: the shop PC restarted during kitchen printing. Check the printer tray — reprint from the bill/order screen only if nothing came out.';

    public static function reportInterruptedLocalPrints(Company $company, ?string $deviceUid, mixed $orderIds): int
    {
        // Never mark another PC's work or a legacy handoff with no device
        // binding. A repeated heartbeat is safe after the first conditional flip.
        if (!$deviceUid || !is_array($orderIds) || !self::deviceRoutingReadyForInterrupts()) return 0;
        $ids = array_values(array_unique(array_filter($orderIds,
            fn ($id) => is_string($id) && strlen($id) >= 1 && strlen($id) <= 100 && !str_contains($id, "\0"))));
        $marked = 0;
        foreach (array_slice($ids, 0, 50) as $id) {
            $marked += PosPrintJob::where('company_id', $company->id)
                ->where('device_uid', $deviceUid)->where('type', 'kot')
                ->where('claim_token', self::localHandoffToken($id))
                ->where('status', self::LOCAL_STATUS)
                ->update(['status' => 'failed', 'error' => self::LOCAL_INTERRUPTED_ERROR, 'updated_at' => now()]);
        }
        return $marked;
    }

    private static function deviceRoutingReadyForInterrupts(): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::hasTable('pos_print_jobs')
                && \Illuminate\Support\Facades\Schema::hasColumn('pos_print_jobs', 'device_uid');
        } catch (\Throwable $e) { return false; }
    }

    /** claim_token the Local Core ack (print.* on aggregate kot:<order>) resolves to. */
    public static function localHandoffToken(string $orderAggregate): string
    {
        return 'ac:kot:' . $orderAggregate;
    }

    /**
     * Record that the shop PC owns this order's kitchen slip. Idempotent per
     * order aggregate (hold replays hit the same token). Returns the row or
     * null when the print-job table is not migrated (then nothing suppresses
     * the cloud path — one extra slip beats a kitchen that never got one).
     */
    public static function openLocalHandoff(Company $company, RestaurantOrder $order, string $orderAggregate, array $lineIds, ?string $deviceUid, ?int $userId, $at = null): ?PosPrintJob
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('pos_print_jobs')) return null;
            $token = self::localHandoffToken($orderAggregate);
            $existing = PosPrintJob::where('company_id', $company->id)->where('claim_token', $token)->orderByDesc('id')->first();
            if ($existing) return $existing;
            $attrs = [
                'company_id' => $company->id,
                'type' => 'kot',
                'target_printer' => 'local-core',
                'restaurant_order_id' => $order->id,
                'render_query' => 'local=1',
                'printed_item_ids' => array_values(array_unique(array_map('intval', $lineIds))),
                'status' => self::LOCAL_STATUS,
                'claim_token' => $token,
                'created_by' => $userId,
            ];
            if ($deviceUid && \App\Http\Controllers\AgentController::deviceRoutingReady()) $attrs['device_uid'] = $deviceUid;
            $job = new PosPrintJob($attrs);
            // Handoff clock = cloud RECEIPT time (never the device's clock).
            $job->created_at = $at ?: now();
            $job->updated_at = $job->created_at;
            $job->save();
            return $job;
        } catch (\Throwable $e) {
            \Log::warning('KotPrintService local handoff failed: ' . $e->getMessage(), ['order_id' => $order->id ?? null]);
            return null;
        }
    }

    /**
     * Kitchen line ids the shop PC currently owns for this order (fresh,
     * unacknowledged local handoffs). Empty = the cloud may print anything.
     */
    public static function freshLocalHandoffLineIds(Company $company, RestaurantOrder $order): array
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('pos_print_jobs')) return [];
            return PosPrintJob::where('company_id', $company->id)->where('type', 'kot')
                ->where('restaurant_order_id', $order->id)->where('status', self::LOCAL_STATUS)
                ->where('created_at', '>=', now()->subSeconds(self::LOCAL_HANDOFF_TIMEOUT_SECONDS))
                ->get()->flatMap(fn ($job) => (array) ($job->printed_item_ids ?? []))
                ->map(fn ($id) => (int) $id)->unique()->values()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Kitchen line ids the shop PC has CONFIRMED printing (acknowledged
     * handoffs). Durable ownership state read at RENDER time: a cloud KOT job
     * — even one whose baked printed_item_ids were computed before the ack
     * arrived (recovery job after an expired handoff, a counter safety-net
     * fired in the gap) — must drop these lines, so a late shop-PC ack can
     * never be followed by a second physical slip for the same lines.
     */
    public static function shopPcPrintedLineIds(Company $company, RestaurantOrder $order): array
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('pos_print_jobs')) return [];
            return PosPrintJob::where('company_id', $company->id)->where('type', 'kot')
                ->where('restaurant_order_id', $order->id)->where('status', 'done')
                ->where('claim_token', 'like', 'ac:kot:%')
                ->get()->flatMap(fn ($job) => (array) ($job->printed_item_ids ?? []))
                ->map(fn ($id) => (int) $id)->unique()->values()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * The shop PC's durable print.complete for its handed-off slip. Stamps
     * ONLY still-unstamped lines (batch 1), closes the handoff (a handoff the
     * cloud had already expired is closed too — the paper is in the kitchen),
     * and takes the lines back from any cloud KOT job that is still PENDING
     * (unclaimed): fully covered jobs are voided (status done, never rendered),
     * partially covered ones keep only the lines the shop PC did not print.
     * The status='pending' predicate in every update is the claim fence — a job
     * the agent already flipped to printing is left alone, and its render
     * re-checks shopPcPrintedLineIds() anyway. Call inside a transaction.
     *
     * @return array{printed_line_ids: int[], local_handoff: ?string, voided_job_ids: int[], trimmed_job_ids: int[]}
     */
    public static function acknowledgeLocalHandoff(Company $company, RestaurantOrder $order, string $orderAggregate, $printedAt = null): array
    {
        $printedAt = $printedAt ?: now();
        $handoff = null;
        if (\Illuminate\Support\Facades\Schema::hasTable('pos_print_jobs')) {
            $handoff = PosPrintJob::where('company_id', $company->id)
                ->where('claim_token', self::localHandoffToken($orderAggregate))
                ->orderByDesc('id')->lockForUpdate()->first();
        }
        $candidates = $handoff && !empty($handoff->printed_item_ids)
            ? array_map('intval', (array) $handoff->printed_item_ids)
            : \App\Support\PosKitchenLines::scope($order->items())->pluck('id')->map(fn ($id) => (int) $id)->all();
        $stampIds = \App\Models\RestaurantOrderItem::where('order_id', $order->id)->whereIn('id', $candidates)
            ->whereNull('kot_printed_at')->lockForUpdate()->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        if ($stampIds) {
            \App\Models\RestaurantOrderItem::where('order_id', $order->id)->whereIn('id', $stampIds)
                ->update(['kot_printed_at' => $printedAt, 'kot_batch_no' => 1]);
        }
        $updates = [];
        if (!$order->kot_sent_at) $updates['kot_sent_at'] = $printedAt;
        if (\Illuminate\Support\Facades\Schema::hasColumn('restaurant_orders', 'kot_print_count') && (int) $order->kot_print_count < 1) $updates['kot_print_count'] = 1;
        if ($updates) $order->update($updates);

        $handoffStatus = null;
        if ($handoff) {
            if (in_array($handoff->status, [self::LOCAL_STATUS, self::LOCAL_EXPIRED_STATUS, 'failed'], true)) {
                $handoff->update(['status' => 'done', 'error' => $handoff->status === self::LOCAL_STATUS ? null
                    : 'Shop PC confirmed the slip after the cloud took it back.']);
            }
            $handoffStatus = $handoff->status;
        }

        $voided = [];
        $trimmed = [];
        if ($handoff && $candidates) {
            $pending = PosPrintJob::where('company_id', $company->id)->where('type', 'kot')
                ->where('restaurant_order_id', $order->id)->where('status', 'pending')
                ->where(fn ($q) => $q->whereNull('claim_token')->orWhere('claim_token', 'not like', 'ac:kot:%'))
                ->lockForUpdate()->get();
            foreach ($pending as $job) {
                $baked = array_map('intval', (array) ($job->printed_item_ids ?? []));
                if (!$baked) continue; // full (non-delta) reprint request: not a recovery of these lines
                $rest = array_values(array_diff($baked, $candidates));
                if (count($rest) === count($baked)) continue;
                if ($rest) {
                    if (PosPrintJob::whereKey($job->id)->where('status', 'pending')->update(['printed_item_ids' => $rest])) $trimmed[] = (int) $job->id;
                } elseif (PosPrintJob::whereKey($job->id)->where('status', 'pending')->update([
                    'status' => 'done', 'error' => 'Superseded: shop PC confirmed this kitchen slip.',
                ])) {
                    $voided[] = (int) $job->id;
                }
            }
        }
        return ['printed_line_ids' => $stampIds, 'local_handoff' => $handoffStatus, 'voided_job_ids' => $voided, 'trimmed_job_ids' => $trimmed];
    }

    /**
     * True when reprinting this job cannot create a second physical slip:
     * the agent never fetched print content, so paper cannot have come out.
     * Local handoffs print from the shop PC's own document — they are never
     * proven safe after the PC goes silent.
     */
    public static function reprintProvenSafe(?PosPrintJob $job): bool
    {
        if (!$job) {
            return false;
        }
        $token = (string) ($job->claim_token ?? '');
        if (str_starts_with($token, 'ac:kot:') || ($job->status ?? '') === self::LOCAL_STATUS) {
            return false;
        }
        if (!\App\Http\Controllers\AgentController::contentFetchTrackingReady()) {
            return false;
        }
        $fetched = $job->content_fetched_at ?? null;

        return $fetched === null && in_array((string) $job->status, ['printing', 'pending'], true);
    }

    /**
     * Fastest safe "this shop PC is gone" check for an open handoff / claim.
     * Uses the job's owning device when stamped; otherwise the company heartbeat.
     */
    public static function owningAgentUnresponsive(Company $company, ?string $deviceUid, int $windowSeconds = self::HANDOFF_UNRESPONSIVE_SECONDS): bool
    {
        $cutoff = now()->subSeconds(max(1, $windowSeconds));
        try {
            if ($deviceUid && \App\Http\Controllers\AgentController::deviceRoutingReady()) {
                $device = \App\Models\PosAgentDevice::where('company_id', $company->id)
                    ->where('device_uid', $deviceUid)
                    ->first();
                if ($device) {
                    return (bool) ($device->last_seen_at && $device->last_seen_at->lt($cutoff));
                }
            }
        } catch (\Throwable $e) {
            // fall through to company heartbeat
        }

        return (bool) ($company->agent_last_seen && $company->agent_last_seen->lt($cutoff));
    }

    /**
     * Park a local handoff as Action Required. Never enqueues a cloud KOT —
     * the shop PC may already have printed.
     */
    public static function markLocalHandoffActionRequired(PosPrintJob $job, string $error = self::LOCAL_AGENT_UNRESPONSIVE_ERROR): bool
    {
        return (bool) PosPrintJob::whereKey($job->id)->where('status', self::LOCAL_STATUS)
            ->update(['status' => 'failed', 'error' => $error, 'updated_at' => now()]);
    }

    /**
     * Dead / unresponsive shop PC with an open local handoff: surface Action
     * Required immediately. Instant automatic reprint is not proven safe.
     *
     * @return array{marked:int}
     */
    public static function recoverUnresponsiveHandoffs(Company $company): array
    {
        $out = ['marked' => 0];
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('pos_print_jobs')) {
                return $out;
            }
            $open = PosPrintJob::where('company_id', $company->id)->where('type', 'kot')
                ->where('status', self::LOCAL_STATUS)->orderBy('id')->limit(50)->get();
            foreach ($open as $job) {
                if (!self::owningAgentUnresponsive($company, $job->device_uid)) {
                    continue;
                }
                if (self::markLocalHandoffActionRequired($job)) {
                    $out['marked']++;
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('KotPrintService recoverUnresponsiveHandoffs failed: '.$e->getMessage());
        }

        return $out;
    }

    /**
     * POS / waiter reported Local Core as dead right now. Instant Action
     * Required for this device's open handoffs (or unstamped / already-dead
     * ones). Never touches another online counter's live handoff.
     *
     * @return array{marked:int}
     */
    public static function reportLocalCoreDown(Company $company, ?string $deviceUid): array
    {
        $out = ['marked' => 0];
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('pos_print_jobs')) {
                return $out;
            }
            $open = PosPrintJob::where('company_id', $company->id)->where('type', 'kot')
                ->where('status', self::LOCAL_STATUS)->orderBy('id')->limit(50)->get();
            foreach ($open as $job) {
                $uid = $job->device_uid ? (string) $job->device_uid : null;
                if ($deviceUid && $uid && $uid !== $deviceUid) {
                    continue;
                }
                if (!$deviceUid && $uid && !self::owningAgentUnresponsive($company, $uid)) {
                    continue;
                }
                if (self::markLocalHandoffActionRequired($job)) {
                    $out['marked']++;
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('KotPrintService reportLocalCoreDown failed: '.$e->getMessage());
        }

        return $out;
    }

    /**
     * Cloud claim whose owning agent is dead: requeue only when content was
     * never fetched; otherwise fail closed (Action Required).
     *
     * @return array{failed:int, requeued:int}
     */
    public static function recoverUnresponsiveCloudClaims(Company $company): array
    {
        $out = ['failed' => 0, 'requeued' => 0];
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('pos_print_jobs')) {
                return $out;
            }
            $fetchTracked = \App\Http\Controllers\AgentController::contentFetchTrackingReady();
            $rows = PosPrintJob::where('company_id', $company->id)
                ->where('status', 'printing')
                ->where(fn ($q) => $q->whereNull('claim_token')->orWhere('claim_token', 'not like', 'ac:kot:%'))
                ->orderBy('id')->limit(50)->get();
            foreach ($rows as $job) {
                if (!self::owningAgentUnresponsive($company, $job->device_uid)) {
                    continue;
                }
                // A claim that just succeeded is proof of life for this tick —
                // do not requeue or fail-closed a job the agent is still holding.
                if ($job->updated_at && $job->updated_at->gt(now()->subSeconds(self::HANDOFF_UNRESPONSIVE_SECONDS))) {
                    continue;
                }
                if ($fetchTracked && $job->content_fetched_at) {
                    $flipped = PosPrintJob::whereKey($job->id)->where('status', 'printing')
                        ->whereNotNull('content_fetched_at')
                        ->update([
                            'status' => 'failed',
                            'claim_token' => null,
                            'error' => \App\Http\Controllers\AgentController::UNCONFIRMED_AFTER_FETCH_ERROR,
                            'updated_at' => now(),
                        ]);
                    if ($flipped) {
                        $out['failed']++;
                    }
                    continue;
                }
                $flipped = PosPrintJob::whereKey($job->id)->where('status', 'printing')
                    ->when($fetchTracked, fn ($q) => $q->whereNull('content_fetched_at'))
                    ->update(['status' => 'pending', 'claim_token' => null, 'updated_at' => now()]);
                if ($flipped) {
                    $out['requeued']++;
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('KotPrintService recoverUnresponsiveCloudClaims failed: '.$e->getMessage());
        }

        return $out;
    }

    /**
     * @return array{local_action_required:int, cloud_failed:int, cloud_requeued:int}
     */
    public static function recoverUnresponsivePrints(Company $company): array
    {
        $local = self::recoverUnresponsiveHandoffs($company);
        $cloud = self::recoverUnresponsiveCloudClaims($company);

        return [
            'local_action_required' => (int) ($local['marked'] ?? 0),
            'cloud_failed' => (int) ($cloud['failed'] ?? 0),
            'cloud_requeued' => (int) ($cloud['requeued'] ?? 0),
        ];
    }

    /**
     * Operator-facing Action Required rows (failed-closed unknown outcomes).
     *
     * @return list<array{id:int, restaurant_order_id:?int, error:string, display_state:string}>
     */
    public static function actionRequiredJobs(Company $company, int $limit = 15): array
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('pos_print_jobs')) {
                return [];
            }
            self::recoverUnresponsivePrints($company);

            return PosPrintJob::where('company_id', $company->id)
                ->where('type', 'kot')
                ->where('status', 'failed')
                ->where(function ($q) {
                    $q->where('error', 'like', 'local_agent_unresponsive%')
                        ->orWhere('error', 'like', 'local_agent_interrupted%')
                        ->orWhere('error', 'like', 'unconfirmed_after_print_content_fetched%');
                })
                ->where('created_at', '>=', now()->subHours(12))
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->map(fn (PosPrintJob $job) => [
                    'id' => (int) $job->id,
                    'restaurant_order_id' => $job->restaurant_order_id ? (int) $job->restaurant_order_id : null,
                    'error' => (string) ($job->error ?? ''),
                    'display_state' => \App\Support\KotPrintState::ACTION_REQUIRED,
                    'created_at' => optional($job->created_at)?->toIso8601String(),
                ])->values()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Cloud recovery for a shop PC that never acknowledged its slip. First
     * recovers dead/unresponsive agents (Action Required, no blind reprint).
     * A still-online agent that simply never acked past LOCAL_HANDOFF_TIMEOUT
     * is the last-resort expire → one cloud KOT (late print.complete still
     * voids that job while pending).
     *
     * @return array{expired:int, queued:int, action_required:int, cloud_requeued:int}
     */
    public static function expireLocalHandoffs(Company $company): array
    {
        $out = ['expired' => 0, 'queued' => 0, 'action_required' => 0, 'cloud_requeued' => 0];
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('pos_print_jobs')) return $out;
            $dead = self::recoverUnresponsivePrints($company);
            $out['action_required'] = (int) ($dead['local_action_required'] ?? 0) + (int) ($dead['cloud_failed'] ?? 0);
            $out['cloud_requeued'] = (int) ($dead['cloud_requeued'] ?? 0);
            $stale = PosPrintJob::where('company_id', $company->id)->where('type', 'kot')
                ->where('status', self::LOCAL_STATUS)
                ->where('created_at', '<', now()->subSeconds(self::LOCAL_HANDOFF_TIMEOUT_SECONDS))
                ->orderBy('id')->limit(50)->get();
            foreach ($stale as $job) {
                // Still-online hung drain: last-resort cloud take-back.
                // Dead agents were already parked as Action Required above.
                if (self::owningAgentUnresponsive($company, $job->device_uid)) {
                    if (self::markLocalHandoffActionRequired($job)) {
                        $out['action_required']++;
                    }
                    continue;
                }
                // Conditional flip: whoever flips it owns the cloud enqueue.
                $flipped = PosPrintJob::whereKey($job->id)->where('status', self::LOCAL_STATUS)
                    ->update(['status' => self::LOCAL_EXPIRED_STATUS, 'error' => 'Shop PC never confirmed the kitchen slip; cloud printed it.']);
                if (!$flipped) continue;
                $out['expired']++;
                $order = RestaurantOrder::where('company_id', $company->id)->find($job->restaurant_order_id);
                if (!$order || !in_array($order->status, ['held', 'preparing', 'ready'], true)) continue;
                $queued = self::enqueueForOrder($company, $order, null, true);
                if ($queued['printed'] ?? false) $out['queued'] += count($queued['job_ids'] ?? []);
            }
        } catch (\Throwable $e) {
            \Log::warning('KotPrintService expireLocalHandoffs failed: ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * Watchdog sweep: dead-agent Action Required first, then overdue
     * still-online handoffs. Independent of agent claim polling.
     *
     * @return array{expired:int, queued:int, companies:int, action_required:int, cloud_requeued:int}
     */
    public static function expireLocalHandoffsAll(): array
    {
        $out = ['expired' => 0, 'queued' => 0, 'companies' => 0, 'action_required' => 0, 'cloud_requeued' => 0];
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('pos_print_jobs')) {
                return $out;
            }
            $companyIds = PosPrintJob::query()
                ->where('type', 'kot')
                ->where(function ($q) {
                    $q->where('status', self::LOCAL_STATUS)
                        ->orWhere('status', 'printing');
                })
                ->distinct()
                ->orderBy('company_id')
                ->limit(200)
                ->pluck('company_id');
            foreach ($companyIds as $companyId) {
                $company = Company::find($companyId);
                if (!$company) {
                    continue;
                }
                $one = self::expireLocalHandoffs($company);
                $out['companies']++;
                $out['expired'] += (int) ($one['expired'] ?? 0);
                $out['queued'] += (int) ($one['queued'] ?? 0);
                $out['action_required'] += (int) ($one['action_required'] ?? 0);
                $out['cloud_requeued'] += (int) ($one['cloud_requeued'] ?? 0);
            }
        } catch (\Throwable $e) {
            \Log::warning('KotPrintService expireLocalHandoffsAll failed: '.$e->getMessage());
        }

        return $out;
    }

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
    public static function enqueueForOrder(Company $company, RestaurantOrder $order, ?int $userId, bool $delta = false, bool $queueWhileOffline = false, ?string $dedupeKey = null): array
    {
        try {
            $settings = $company->printerSettings();
            if (!$settings['silent_print_enabled']) {
                return ['printed' => false, 'reason' => 'disabled'];
            }
            if (!$company->agentOnline() && !$queueWhileOffline) {
                return ['printed' => false, 'reason' => 'agent_offline'];
            }

            $order->loadMissing('items');
            // Delivery Charges jaisi non-kitchen lines yahin, SAB HISAAB SE
            // PEHLE nikal do: delta ids, station mapping aur "kuch chhapna hai
            // ya nahi" — sab isi chhanti hui list par. Warna ek aisi row jo
            // kabhi chhapti hi nahi, hamesha unprinted reh kar khali (204)
            // print job banwati rehti.
            \App\Support\PosKitchenLines::pruneOrder($order);
            // Sirf delivery fee wala order (koi dish hi nahi) — bawarchi ke liye
            // is parchi par kuch nahi. Koi job na banao, warna agent har baar
            // ek khali (204) job uthata rehta hai.
            if ($order->items->isEmpty()) {
                return ['printed' => false, 'reason' => 'no_kitchen_items'];
            }
            // Offline KOT local handoff: lines the shop PC is printing itself
            // right now are NOT ours to print. Whatever is left becomes a delta.
            $handoffIds = self::freshLocalHandoffLineIds($company, $order);
            if ($handoffIds) $delta = true;
            $deltaQ = $delta ? '&delta=1' : '';
            // Delta snapshot (Pizza Master edit-path bug, Aug 2026): bake the
            // unprinted row ids into EVERY job of this send — result-time
            // stamping from the first printed job must not empty the later
            // overlapping delta jobs (counter copy). Mirrors apiCreatePrintJob.
            $deltaIds = $delta
                ? $order->items->whereNull('kot_printed_at')->pluck('id')->map(fn ($i) => (int) $i)
                    ->reject(fn ($i) => in_array($i, $handoffIds, true))->values()->all()
                : null;
            if ($delta && empty($deltaIds)) {
                return ['printed' => true, 'job_ids' => [], 'reason' => $handoffIds ? 'local_handoff' : 'nothing_unprinted'];
            }
            $makeJob = function (?string $printer, ?string $renderQuery, ?string $ownerDeviceUid = null) use ($company, $order, $userId, $delta, $deltaIds, $dedupeKey) {
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
                    ->when($dedupeKey, fn ($q) => $q->where('dedupe_key', $dedupeKey . ':' . ($printer ?: 'default')))
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
                if ($dedupeKey && \Illuminate\Support\Facades\Schema::hasColumn('pos_print_jobs', 'dedupe_key')) {
                    $attrs['dedupe_key'] = $dedupeKey . ':' . ($printer ?: 'default');
                }
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
            $baseItems = $delta ? $order->items->whereIn('id', $deltaIds)->values() : $order->items;
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
    public static function enqueueVoid(Company $company, RestaurantOrder $order, array $voidItems, ?int $userId, bool $queueWhileOffline = false, ?string $dedupeKey = null): array
    {
        try {
            if (empty($voidItems)) {
                return ['printed' => true, 'job_ids' => []];
            }
            $settings = $company->printerSettings();
            if (!$settings['silent_print_enabled']) {
                return ['printed' => false, 'reason' => 'disabled'];
            }
            if (!$company->agentOnline() && !$queueWhileOffline) {
                return ['printed' => false, 'reason' => 'agent_offline'];
            }

            $makeVoidJob = function (?string $printer, array $items, ?string $ownerDeviceUid = null) use ($company, $order, $userId, $dedupeKey) {
                $renderQuery = json_encode(array_values($items));

                // Task 951: lock this order while finding or creating the
                // station slip. Two simultaneous waiter taps therefore cannot
                // both observe an empty queue and create duplicate jobs. The
                // exact payload is part of the identity so a later, different
                // cancellation for the same station still reaches the kitchen.
                return DB::transaction(function () use ($company, $order, $userId, $printer, $ownerDeviceUid, $renderQuery, $dedupeKey) {
                    RestaurantOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

                    $inFlight = PosPrintJob::where('company_id', $company->id)
                        ->where('type', 'kot_void')
                        ->where('restaurant_order_id', $order->id)
                        ->where('target_printer', $printer)
                        ->where('render_query', $renderQuery)
                        ->when($dedupeKey, fn ($q) => $q->where('dedupe_key', $dedupeKey . ':' . ($printer ?: 'default'))
                        )
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
                    if ($dedupeKey && \Illuminate\Support\Facades\Schema::hasColumn('pos_print_jobs', 'dedupe_key')) {
                        $attrs['dedupe_key'] = $dedupeKey . ':' . ($printer ?: 'default');
                    }
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
