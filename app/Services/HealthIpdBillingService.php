<?php

namespace App\Services;

use App\Models\HealthAdmission;
use App\Models\HealthAdmissionCharge;
use App\Models\HealthAdmissionEvent;
use App\Models\HealthAdmissionPayment;
use App\Models\HealthBed;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The stay's ledger: what it cost, what has been paid, what is still owed.
 *
 * Three rules the whole module leans on:
 *
 *  1. A charge is NEVER deleted. It is reversed, and both rows stay. A ledger
 *     you can quietly edit is a ledger nobody can defend at the counter.
 *  2. The recurring room/nursing charge is idempotent by construction. Its
 *     dedupe key is (admission, date, bed, kind), so the daily run can be run
 *     twice, re-run after a crash, or raced by two servers, and the patient is
 *     still charged once for that bed on that day.
 *  3. Advances are not negative charges. "What the patient owes" and "what the
 *     patient has paid" are separate totals and are only ever subtracted in
 *     summary(), in one place.
 */
class HealthIpdBillingService
{
    /**
     * Post the bed-day and nursing-day for every day the patient has been in
     * their current bed and has not yet been charged for.
     *
     * Called on admit, on transfer, on discharge, and by the daily command.
     * Catching up several days at once is normal: a hospital that lost its cron
     * over a long weekend must not lose three days of bed charges.
     *
     * @return int how many charge rows were actually written
     */
    public static function postDailyCharges(HealthAdmission $admission, ?User $actor = null, ?string $upToDate = null): int
    {
        if (!$admission->admitted_at || !$admission->health_bed_id) {
            return 0;
        }

        if (!in_array($admission->status, HealthAdmission::OPEN_STATUSES, true)) {
            return 0;
        }

        // Which bed on which day — NOT the bed the patient happens to be in
        // now. A stay transferred to ICU on day four must keep its first three
        // days at the general-ward rate; re-reading the current bed would post
        // three ICU days on top of the general days already charged.
        $timeline = $admission->bedTimeline();
        if (!$timeline) {
            return 0;
        }

        $beds = HealthBed::withoutGlobalScopes()
            ->with(['ward', 'room'])
            ->whereIn('id', array_values(array_unique(array_column($timeline, 'bed_id'))))
            ->get()
            ->keyBy('id');

        if ($beds->isEmpty()) {
            return 0;
        }

        $start = $admission->admitted_at->copy()->startOfDay();
        $end = Carbon::parse($upToDate ?: now())->startOfDay();
        if ($end->lt($start)) {
            return 0;
        }

        // Never bill past the day the patient left, whatever the caller asked
        // for: a discharged stay that keeps growing is the single worst bug a
        // billing module can have.
        if ($admission->discharged_at) {
            $discharge = $admission->discharged_at->copy()->startOfDay();
            if ($end->gt($discharge)) {
                $end = $discharge;
            }
        }

        $written = 0;
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $date = $cursor->toDateString();
            $bedId = $admission->bedOnDate($date, $timeline);
            $bed = $bedId ? $beds->get($bedId) : null;

            if ($bed) {
                $roomRate = $bed->resolvedDailyRate();
                $nursingRate = $bed->resolvedNursingRate();

                if ($roomRate > 0) {
                    $written += self::postRecurring($admission, $bed, $date, HealthAdmissionCharge::CAT_ROOM, $roomRate, $actor) ? 1 : 0;
                }
                if ($nursingRate > 0) {
                    $written += self::postRecurring($admission, $bed, $date, HealthAdmissionCharge::CAT_NURSING, $nursingRate, $actor) ? 1 : 0;
                }
            }

            $cursor->addDay();
        }

        if ($written > 0 || !$admission->charges_posted_through) {
            $admission->forceFill(['charges_posted_through' => $end->toDateString()])->save();
        }

        return $written;
    }

    /**
     * One recurring line. Returns false when the day was already charged.
     *
     * The unique index on (company_id, dedupe_key) is the real guard; the
     * pre-check is only there to keep the common path off the exception
     * handler. Both are needed — the pre-check alone loses the race.
     */
    private static function postRecurring(
        HealthAdmission $admission,
        HealthBed $bed,
        string $date,
        string $category,
        float $rate,
        ?User $actor
    ): bool {
        // The bed is deliberately NOT part of the key: a patient occupies one
        // bed-day per calendar day even if they were moved at noon, and the
        // unique index on (company_id, dedupe_key) is what actually enforces
        // that. On the day of a move the day stays with whichever bed was
        // charged first — the ward can post a manual difference if it wants
        // the newer, dearer room billed for that day too.
        $key = sprintf('%s:%d:%s', $category, $admission->id, $date);

        $exists = HealthAdmissionCharge::withoutGlobalScopes()
            ->where('company_id', $admission->company_id)
            ->where('dedupe_key', $key)
            ->exists();

        if ($exists) {
            return false;
        }

        $description = $category === HealthAdmissionCharge::CAT_ROOM
            ? __('health.charge_room_day', ['bed' => $bed->code])
            : __('health.charge_nursing_day', ['bed' => $bed->code]);

        try {
            HealthAdmissionCharge::create([
                'company_id' => $admission->company_id,
                'branch_id' => $admission->branch_id,
                'health_admission_id' => $admission->id,
                'health_patient_id' => $admission->health_patient_id,
                'charge_date' => $date,
                'category' => $category,
                'description' => $description,
                'reference' => $bed->code,
                'source_type' => 'bed',
                'source_id' => $bed->id,
                'unit_price' => $rate,
                'quantity' => 1,
                'gross_amount' => $rate,
                'concession_amount' => 0,
                'net_amount' => $rate,
                'is_recurring' => true,
                'dedupe_key' => $key,
                'status' => HealthAdmissionCharge::STATUS_POSTED,
                'created_by' => $actor?->id,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Lost the race to another worker. The day is charged — which is
            // the outcome we wanted — so this is a success, not a failure.
            return false;
        }

        return true;
    }

    /**
     * A one-off charge somebody posted by hand or another module produced.
     *
     * `dedupe_key` is optional and is what a calling module (an operation
     * completing, a prescription dispensing) passes so its own retry cannot
     * double-charge.
     */
    public static function postCharge(HealthAdmission $admission, array $data, ?User $actor): ?HealthAdmissionCharge
    {
        return DB::transaction(function () use ($admission, $data, $actor) {
            $category = in_array($data['category'] ?? '', HealthAdmissionCharge::CATEGORIES, true)
                ? $data['category']
                : HealthAdmissionCharge::CAT_MISC;

            $quantity = max(0, round((float) ($data['quantity'] ?? 1), 2));
            $unitPrice = max(0, round((float) ($data['unit_price'] ?? 0), 2));
            $gross = round($quantity * $unitPrice, 2);
            $concession = min($gross, max(0, round((float) ($data['concession_amount'] ?? 0), 2)));

            $key = $data['dedupe_key'] ?? null;
            if ($key) {
                $existing = HealthAdmissionCharge::withoutGlobalScopes()
                    ->where('company_id', $admission->company_id)
                    ->where('dedupe_key', $key)
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }

            $charge = HealthAdmissionCharge::create([
                'company_id' => $admission->company_id,
                'branch_id' => $admission->branch_id,
                'health_admission_id' => $admission->id,
                'health_patient_id' => $admission->health_patient_id,
                'charge_date' => $data['charge_date'] ?? now()->toDateString(),
                'category' => $category,
                'description' => mb_substr((string) ($data['description'] ?? ''), 0, 300),
                'reference' => isset($data['reference']) ? mb_substr((string) $data['reference'], 0, 120) : null,
                'source_type' => $data['source_type'] ?? 'manual',
                'source_id' => $data['source_id'] ?? null,
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'gross_amount' => $gross,
                'concession_amount' => $concession,
                'concession_reason' => $concession > 0 ? ($data['concession_reason'] ?? null) : null,
                'concession_approved_by' => $concession > 0 ? $actor?->id : null,
                'net_amount' => round($gross - $concession, 2),
                'is_recurring' => false,
                'dedupe_key' => $key,
                'status' => HealthAdmissionCharge::STATUS_POSTED,
                'created_by' => $actor?->id,
            ]);

            HealthIpdService::event($admission, HealthAdmissionEvent::CHARGE_POSTED, $actor, [
                'note' => $charge->description,
                'meta' => ['charge_id' => $charge->id, 'net' => (float) $charge->net_amount, 'category' => $category],
            ]);

            return $charge;
        });
    }

    /**
     * Reverse a charge. Both rows survive; the reversal carries the reason.
     *
     * Idempotent: reversing an already-reversed charge is a no-op rather than
     * an error, because the caller's intent is already satisfied.
     */
    public static function reverseCharge(HealthAdmissionCharge $charge, ?User $actor, ?string $reason): HealthAdmissionCharge
    {
        if ($charge->status === HealthAdmissionCharge::STATUS_REVERSED) {
            return $charge;
        }

        return DB::transaction(function () use ($charge, $actor, $reason) {
            $charge->status = HealthAdmissionCharge::STATUS_REVERSED;
            $charge->reversed_at = now();
            $charge->reversed_by = $actor?->id;
            $charge->reversal_reason = $reason;
            $charge->save();

            $admission = HealthAdmission::withoutGlobalScopes()->find($charge->health_admission_id);
            if ($admission) {
                HealthIpdService::event($admission, HealthAdmissionEvent::CHARGE_REVERSED, $actor, [
                    'note' => $reason ?: $charge->description,
                    'meta' => ['charge_id' => $charge->id, 'net' => (float) $charge->net_amount],
                ]);
            }

            return $charge;
        });
    }

    /** Money in (or back out). */
    public static function recordPayment(HealthAdmission $admission, array $data, ?User $actor): HealthAdmissionPayment
    {
        return DB::transaction(function () use ($admission, $data, $actor) {
            $kind = in_array($data['kind'] ?? '', HealthAdmissionPayment::KINDS, true)
                ? $data['kind']
                : HealthAdmissionPayment::KIND_ADVANCE;

            $payment = HealthAdmissionPayment::create([
                'company_id' => $admission->company_id,
                'branch_id' => $admission->branch_id,
                'health_admission_id' => $admission->id,
                'kind' => $kind,
                'amount' => max(0, round((float) ($data['amount'] ?? 0), 2)),
                'method' => in_array($data['method'] ?? '', HealthAdmissionPayment::METHODS, true)
                    ? $data['method']
                    : 'cash',
                'reference' => isset($data['reference']) ? mb_substr((string) $data['reference'], 0, 120) : null,
                'note' => isset($data['note']) ? mb_substr((string) $data['note'], 0, 300) : null,
                'received_at' => now(),
                'received_by' => $actor?->id,
            ]);

            HealthIpdService::event($admission, HealthAdmissionEvent::PAYMENT, $actor, [
                'note' => $payment->reference,
                'meta' => ['kind' => $kind, 'amount' => (float) $payment->amount, 'method' => $payment->method],
            ]);

            return $payment;
        });
    }

    /**
     * The one place the stay's financial position is computed.
     *
     * gross      list price of everything still posted
     * concession line-level concessions + the clearance concession
     * net        what the stay is worth after concessions
     * advances   money taken, less money refunded
     * outstanding what is still owed (never negative — an over-payment is a
     *            REFUND due, reported separately, because "outstanding: -5000"
     *            reads as a credit to nobody)
     */
    public static function summary(HealthAdmission $admission): array
    {
        $rows = HealthAdmissionCharge::withoutGlobalScopes()
            ->where('company_id', $admission->company_id)
            ->where('health_admission_id', $admission->id)
            ->where('status', HealthAdmissionCharge::STATUS_POSTED)
            ->selectRaw('category, SUM(gross_amount) as gross, SUM(concession_amount) as concession, SUM(net_amount) as net, COUNT(*) as lines')
            ->groupBy('category')
            ->get();

        $gross = round((float) $rows->sum('gross'), 2);
        $lineConcession = round((float) $rows->sum('concession'), 2);
        $lineNet = round((float) $rows->sum('net'), 2);

        $stayConcession = round((float) $admission->concession_amount, 2);
        $net = round(max(0, $lineNet - $stayConcession), 2);

        $payments = HealthAdmissionPayment::withoutGlobalScopes()
            ->where('company_id', $admission->company_id)
            ->where('health_admission_id', $admission->id)
            ->selectRaw('kind, SUM(amount) as total')
            ->groupBy('kind')
            ->pluck('total', 'kind');

        $advances = round((float) ($payments[HealthAdmissionPayment::KIND_ADVANCE] ?? 0), 2);
        $refunds = round((float) ($payments[HealthAdmissionPayment::KIND_REFUND] ?? 0), 2);
        $paid = round($advances - $refunds, 2);

        $balance = round($net - $paid, 2);

        return [
            'gross' => $gross,
            'line_concession' => $lineConcession,
            'stay_concession' => $stayConcession,
            'concession' => round($lineConcession + $stayConcession, 2),
            'net' => $net,
            'advances' => $advances,
            'refunds' => $refunds,
            'paid' => $paid,
            'outstanding' => $balance > 0 ? $balance : 0.0,
            'refund_due' => $balance < 0 ? abs($balance) : 0.0,
            'deposit_required' => round((float) $admission->deposit_required, 2),
            'deposit_short' => round(max(0, (float) $admission->deposit_required - $paid), 2),
            'by_category' => $rows->mapWithKeys(fn ($row) => [$row->category => [
                'gross' => round((float) $row->gross, 2),
                'concession' => round((float) $row->concession, 2),
                'net' => round((float) $row->net, 2),
                'lines' => (int) $row->lines,
            ]])->all(),
        ];
    }

    /**
     * What still stands between this stay and the door.
     *
     * Returned as a list of reasons rather than a boolean so the discharge
     * screen can show WHICH one — "cannot discharge" with no reason is the
     * complaint that reaches the owner.
     */
    public static function clearanceBlockers(HealthAdmission $admission): array
    {
        $blockers = [];
        $summary = self::summary($admission);

        if ($summary['outstanding'] > 0.009) {
            $blockers[] = [
                'key' => 'outstanding',
                'message' => __('health.clear_block_outstanding', ['amount' => number_format($summary['outstanding'], 2)]),
            ];
        }

        $openOps = $admission->operations()
            ->whereIn('status', \App\Models\HealthOperation::BLOCKING_STATUSES)
            ->count();

        if ($openOps > 0) {
            $blockers[] = [
                'key' => 'operations',
                'message' => __('health.clear_block_operations', ['count' => $openOps]),
            ];
        }

        if (!$admission->cleared_at) {
            $blockers[] = [
                'key' => 'clearance',
                'message' => __('health.clear_block_not_cleared'),
            ];
        }

        return $blockers;
    }
}
