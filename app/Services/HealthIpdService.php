<?php

namespace App\Services;

use App\Models\HealthAdmission;
use App\Models\HealthAdmissionEvent;
use App\Models\HealthBed;
use App\Models\HealthPatient;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The inpatient lifecycle — the one place a stay moves.
 *
 *   request → admit (a bed is claimed) → transfer* → discharge request →
 *   clearance → discharge (the bed is released)
 *
 * Every move happens inside a transaction that touches BOTH the admission and
 * the bed, and every move writes a timeline row. That is the whole point of
 * this class: a bed that says "available" while a patient is in it, or a
 * discharge nobody can attribute, are the two ways a ward loses trust in the
 * system, and both come from moving one of the two rows without the other.
 *
 * Bed claims are made with a conditional UPDATE (`where status = available`)
 * rather than read-then-write. Two receptionists assigning the last free bed at
 * the same moment is not a hypothetical in a busy hospital; the loser of that
 * race gets a refusal, not a shared bed.
 */
class HealthIpdService
{
    /**
     * Open an admission REQUEST. No bed is taken yet.
     *
     * A request is a real row rather than a form the ward holds onto, because
     * "who has been waiting for a bed since this morning" is a question the
     * admissions desk has to be able to answer.
     */
    public static function request(array $data, ?User $actor): HealthAdmission
    {
        return DB::transaction(function () use ($data, $actor) {
            $companyId = (int) $data['company_id'];

            $admission = HealthAdmission::create([
                'company_id' => $companyId,
                'branch_id' => $data['branch_id'] ?? null,
                'health_department_id' => $data['health_department_id'] ?? null,
                'health_patient_id' => (int) $data['health_patient_id'],
                'health_doctor_id' => $data['health_doctor_id'] ?? null,
                'health_visit_id' => $data['health_visit_id'] ?? null,
                'admission_no' => HealthNumberService::admissionNumber($companyId),
                'status' => HealthAdmission::STATUS_REQUESTED,
                'admission_type' => in_array($data['admission_type'] ?? '', HealthAdmission::TYPES, true)
                    ? $data['admission_type']
                    : 'planned',
                'reason' => $data['reason'] ?? null,
                'provisional_diagnosis' => $data['provisional_diagnosis'] ?? null,
                'estimated_days' => $data['estimated_days'] ?? null,
                'estimated_cost' => $data['estimated_cost'] ?? null,
                'deposit_required' => $data['deposit_required'] ?? 0,
                'attendant_name' => $data['attendant_name'] ?? null,
                'attendant_phone' => $data['attendant_phone'] ?? null,
                'attendant_relation' => $data['attendant_relation'] ?? null,
                'payer_type' => in_array($data['payer_type'] ?? '', HealthAdmission::PAYER_TYPES, true)
                    ? $data['payer_type']
                    : 'self',
                'payer_name' => $data['payer_name'] ?? null,
                'payer_reference' => $data['payer_reference'] ?? null,
                'requested_at' => now(),
                'requested_by' => $actor?->id,
                'care_status' => 'stable',
            ]);

            self::event($admission, HealthAdmissionEvent::REQUESTED, $actor, [
                'to_status' => HealthAdmission::STATUS_REQUESTED,
                'note' => $data['reason'] ?? null,
            ]);

            return $admission;
        });
    }

    /**
     * Put the patient in a bed.
     *
     * Refuses rather than improvises when the bed is not free or the ward's
     * gender policy does not accept this patient. An admission that quietly
     * lands somewhere else is worse than one that fails loudly at the desk,
     * where the person can pick a different bed.
     *
     * Idempotent for the SAME bed: a double-clicked admit button returns the
     * already-admitted stay instead of writing a second timeline entry.
     *
     * @throws \RuntimeException with a translated message
     */
    public static function admit(HealthAdmission $admission, int $bedId, ?User $actor, array $options = []): HealthAdmission
    {
        return DB::transaction(function () use ($admission, $bedId, $actor, $options) {
            /** @var HealthAdmission $stay */
            $stay = HealthAdmission::withoutGlobalScopes()
                ->where('id', $admission->id)
                ->lockForUpdate()
                ->first();

            if (!$stay) {
                throw new \RuntimeException(__('health.adm_not_found'));
            }

            if ($stay->status === HealthAdmission::STATUS_ADMITTED && (int) $stay->health_bed_id === $bedId) {
                return $stay;   // already done — the second click
            }

            if (!in_array($stay->status, [HealthAdmission::STATUS_REQUESTED], true)) {
                throw new \RuntimeException(__('health.adm_not_admittable'));
            }

            $bed = self::claimBed($stay, $bedId, $actor);

            $stay->status = HealthAdmission::STATUS_ADMITTED;
            $stay->health_bed_id = $bed->id;
            $stay->health_ward_id = $bed->health_ward_id;
            $stay->admitted_at = $options['admitted_at'] ?? now();
            $stay->admitted_by = $actor?->id;
            if (!$stay->branch_id && $bed->branch_id) {
                $stay->branch_id = $bed->branch_id;
            }
            $stay->save();

            self::event($stay, HealthAdmissionEvent::ADMITTED, $actor, [
                'from_status' => HealthAdmission::STATUS_REQUESTED,
                'to_status' => HealthAdmission::STATUS_ADMITTED,
                'to_bed_id' => $bed->id,
                'note' => $bed->code,
            ]);

            // The first bed-day is charged the moment the patient occupies the
            // bed, not overnight by the daily run: a day-care stay that both
            // starts and ends before midnight would otherwise be billed nothing
            // for the bed it actually used.
            HealthIpdBillingService::postDailyCharges($stay, $actor);

            return $stay->refresh();
        });
    }

    /**
     * Move an admitted patient to another bed.
     *
     * The new bed is claimed BEFORE the old one is released, so a failed
     * transfer leaves the patient exactly where they were rather than in
     * nowhere. Same-bed transfers are a no-op.
     */
    public static function transfer(HealthAdmission $admission, int $bedId, ?User $actor, ?string $note = null): HealthAdmission
    {
        return DB::transaction(function () use ($admission, $bedId, $actor, $note) {
            /** @var HealthAdmission $stay */
            $stay = HealthAdmission::withoutGlobalScopes()
                ->where('id', $admission->id)
                ->lockForUpdate()
                ->first();

            if (!$stay || !$stay->isOpen()) {
                throw new \RuntimeException(__('health.adm_not_open'));
            }

            $fromBedId = $stay->health_bed_id ? (int) $stay->health_bed_id : null;
            if ($fromBedId === $bedId) {
                return $stay;
            }

            $bed = self::claimBed($stay, $bedId, $actor);

            if ($fromBedId) {
                self::releaseBed($fromBedId, $stay->id, HealthBed::STATUS_CLEANING, $actor);
            }

            $stay->health_bed_id = $bed->id;
            $stay->health_ward_id = $bed->health_ward_id;
            $stay->save();

            self::event($stay, HealthAdmissionEvent::TRANSFERRED, $actor, [
                'from_bed_id' => $fromBedId,
                'to_bed_id' => $bed->id,
                'note' => $note,
            ]);

            // The new bed's rate applies from the day of the move onward, and
            // only from then: billing walks the stay's own bed timeline, so
            // the days already spent in the old bed keep the old rate and are
            // never re-charged. The day of the move itself is one bed-day, at
            // whichever bed was charged for it first.
            HealthIpdBillingService::postDailyCharges($stay->refresh(), $actor);

            return $stay->refresh();
        });
    }

    /** The ward round: today's condition and note. */
    public static function recordCare(HealthAdmission $admission, ?User $actor, string $careStatus, ?string $note): HealthAdmission
    {
        return DB::transaction(function () use ($admission, $actor, $careStatus, $note) {
            $status = in_array($careStatus, HealthAdmission::CARE_STATUSES, true) ? $careStatus : 'stable';

            $admission->care_status = $status;
            $admission->care_note = $note;
            $admission->care_updated_at = now();
            $admission->save();

            self::event($admission, HealthAdmissionEvent::CARE_NOTE, $actor, [
                'note' => $note,
                'meta' => ['care_status' => $status],
            ]);

            return $admission;
        });
    }

    /**
     * The ward says the patient is ready to go. The bed is NOT released yet —
     * they are still in it until accounts have cleared the bill and somebody
     * signs the discharge.
     */
    public static function requestDischarge(HealthAdmission $admission, ?User $actor, array $data = []): HealthAdmission
    {
        return DB::transaction(function () use ($admission, $actor, $data) {
            /** @var HealthAdmission $stay */
            $stay = HealthAdmission::withoutGlobalScopes()
                ->where('id', $admission->id)
                ->lockForUpdate()
                ->first();

            if (!$stay || $stay->status !== HealthAdmission::STATUS_ADMITTED) {
                throw new \RuntimeException(__('health.adm_not_open'));
            }

            $stay->status = HealthAdmission::STATUS_DISCHARGE_REQUESTED;
            $stay->discharge_requested_at = now();
            $stay->discharge_requested_by = $actor?->id;
            $stay->discharge_type = in_array($data['discharge_type'] ?? '', HealthAdmission::DISCHARGE_TYPES, true)
                ? $data['discharge_type']
                : 'routine';
            $stay->final_diagnosis = $data['final_diagnosis'] ?? $stay->final_diagnosis;
            $stay->discharge_summary = $data['discharge_summary'] ?? $stay->discharge_summary;
            $stay->discharge_advice = $data['discharge_advice'] ?? $stay->discharge_advice;
            $stay->follow_up_date = $data['follow_up_date'] ?? $stay->follow_up_date;
            $stay->save();

            self::event($stay, HealthAdmissionEvent::DISCHARGE_REQUESTED, $actor, [
                'from_status' => HealthAdmission::STATUS_ADMITTED,
                'to_status' => HealthAdmission::STATUS_DISCHARGE_REQUESTED,
                'note' => $stay->discharge_type,
            ]);

            return $stay;
        });
    }

    /**
     * Financial clearance — the accounts signature, separate from the clinical
     * one. Records the concession the hospital agreed to and who approved it.
     *
     * Deliberately does NOT release the bed: clearing the bill is not the same
     * event as the patient walking out, and a hospital that treats it as one
     * ends up with beds freed for patients who are still in them.
     */
    public static function clear(HealthAdmission $admission, ?User $actor, float $concession = 0, ?string $reason = null): HealthAdmission
    {
        return DB::transaction(function () use ($admission, $actor, $concession, $reason) {
            $concession = max(0, round($concession, 2));

            $admission->concession_amount = $concession;
            $admission->concession_reason = $concession > 0 ? $reason : null;
            $admission->concession_approved_by = $concession > 0 ? $actor?->id : null;
            $admission->cleared_at = now();
            $admission->cleared_by = $actor?->id;
            $admission->save();

            self::event($admission, HealthAdmissionEvent::CLEARED, $actor, [
                'note' => $reason,
                'meta' => ['concession' => $concession],
            ]);

            return $admission;
        });
    }

    /**
     * The patient leaves. The bed goes to CLEANING, not straight to available:
     * "bed free" and "bed ready" are different moments, and a board that skips
     * the first one hands a dirty bed to the next admission.
     *
     * Refuses while money is outstanding UNLESS the caller holds the override
     * (accounts may release against a written undertaking, and a death or an
     * LAMA cannot wait for a payment). The refusal is the default because a
     * silently-released unpaid stay is money nobody ever chases.
     */
    public static function discharge(HealthAdmission $admission, ?User $actor, array $options = []): HealthAdmission
    {
        return DB::transaction(function () use ($admission, $actor, $options) {
            /** @var HealthAdmission $stay */
            $stay = HealthAdmission::withoutGlobalScopes()
                ->where('id', $admission->id)
                ->lockForUpdate()
                ->first();

            if (!$stay || !$stay->isOpen()) {
                throw new \RuntimeException(__('health.adm_not_open'));
            }

            // Bring the bed-days up to date before the bill is settled, or the
            // last night in the ward is free.
            HealthIpdBillingService::postDailyCharges($stay, $actor);
            $summary = HealthIpdBillingService::summary($stay->refresh());

            $force = (bool) ($options['force'] ?? false);
            if ($summary['outstanding'] > 0.009 && !$force) {
                throw new \RuntimeException(__('health.adm_outstanding_blocks', [
                    'amount' => number_format($summary['outstanding'], 2),
                ]));
            }

            $bedId = $stay->health_bed_id ? (int) $stay->health_bed_id : null;

            $stay->status = HealthAdmission::STATUS_DISCHARGED;
            $stay->discharge_type = in_array($options['discharge_type'] ?? '', HealthAdmission::DISCHARGE_TYPES, true)
                ? $options['discharge_type']
                : ($stay->discharge_type ?: 'routine');
            $stay->final_diagnosis = $options['final_diagnosis'] ?? $stay->final_diagnosis;
            $stay->discharge_summary = $options['discharge_summary'] ?? $stay->discharge_summary;
            $stay->discharge_advice = $options['discharge_advice'] ?? $stay->discharge_advice;
            $stay->follow_up_date = $options['follow_up_date'] ?? $stay->follow_up_date;
            $stay->discharged_at = now();
            $stay->discharged_by = $actor?->id;
            $stay->health_bed_id = null;
            if (!$stay->cleared_at) {
                $stay->cleared_at = now();
                $stay->cleared_by = $actor?->id;
            }
            $stay->save();

            if ($bedId) {
                self::releaseBed($bedId, $stay->id, HealthBed::STATUS_CLEANING, $actor);
            }

            // Nothing is being held for a patient who has gone home.
            self::releaseReservationsFor((int) $stay->company_id, (int) $stay->id);

            self::event($stay, HealthAdmissionEvent::DISCHARGED, $actor, [
                'from_status' => HealthAdmission::STATUS_DISCHARGE_REQUESTED,
                'to_status' => HealthAdmission::STATUS_DISCHARGED,
                'from_bed_id' => $bedId,
                'note' => $stay->discharge_type,
                'meta' => [
                    'outstanding' => $summary['outstanding'],
                    'forced' => $force,
                ],
            ]);

            return $stay;
        });
    }

    /** A request that never became a stay, or an admission entered in error. */
    public static function cancel(HealthAdmission $admission, ?User $actor, ?string $reason): HealthAdmission
    {
        return DB::transaction(function () use ($admission, $actor, $reason) {
            /** @var HealthAdmission $stay */
            $stay = HealthAdmission::withoutGlobalScopes()
                ->where('id', $admission->id)
                ->lockForUpdate()
                ->first();

            if (!$stay || in_array($stay->status, [HealthAdmission::STATUS_DISCHARGED, HealthAdmission::STATUS_CANCELLED], true)) {
                throw new \RuntimeException(__('health.adm_not_open'));
            }

            $bedId = $stay->health_bed_id ? (int) $stay->health_bed_id : null;
            $from = $stay->status;

            $stay->status = HealthAdmission::STATUS_CANCELLED;
            $stay->cancel_reason = $reason;
            $stay->cancelled_at = now();
            $stay->cancelled_by = $actor?->id;
            $stay->health_bed_id = null;
            $stay->save();

            if ($bedId) {
                self::releaseBed($bedId, $stay->id, HealthBed::STATUS_CLEANING, $actor);
            }

            // A request that was cancelled before anyone was admitted still had
            // a bed held for it. Nothing else will ever let that go.
            self::releaseReservationsFor((int) $stay->company_id, (int) $stay->id);

            self::event($stay, HealthAdmissionEvent::CANCELLED, $actor, [
                'from_status' => $from,
                'to_status' => HealthAdmission::STATUS_CANCELLED,
                'from_bed_id' => $bedId,
                'note' => $reason,
            ]);

            return $stay;
        });
    }

    /**
     * Hold a bed for a stay that is still only a request.
     *
     * A reservation is a soft claim: the bed stops being offered but nobody is
     * charged for it, because nobody is in it.
     */
    public static function reserveBed(HealthAdmission $admission, int $bedId, ?User $actor): HealthBed
    {
        return DB::transaction(function () use ($admission, $bedId, $actor) {
            // Changing your mind about which bed to hold must not leave the
            // first one held: one stay, one reservation.
            self::releaseReservationsFor((int) $admission->company_id, (int) $admission->id, $bedId);

            $claimed = HealthBed::withoutGlobalScopes()
                ->where('id', $bedId)
                ->where('company_id', $admission->company_id)
                ->where('is_active', true)
                ->where(function ($q) use ($admission) {
                    // Re-holding the bed you already hold is not a clash.
                    $q->where('status', HealthBed::STATUS_AVAILABLE)
                        ->orWhere(function ($inner) use ($admission) {
                            $inner->where('status', HealthBed::STATUS_RESERVED)
                                ->where('reserved_for_admission_id', $admission->id);
                        });
                })
                ->update([
                    'status' => HealthBed::STATUS_RESERVED,
                    'reserved_for_admission_id' => $admission->id,
                    'status_changed_at' => now(),
                    'updated_at' => now(),
                ]);

            if (!$claimed) {
                throw new \RuntimeException(__('health.bed_not_available'));
            }

            $bed = HealthBed::withoutGlobalScopes()->find($bedId);

            self::event($admission, HealthAdmissionEvent::BED_ASSIGNED, $actor, [
                'to_bed_id' => $bedId,
                'note' => $bed?->code,
                'meta' => ['reserved' => true],
            ]);

            return $bed;
        });
    }

    /**
     * Let go of every bed still being held for this stay.
     *
     * A reservation lives on the BED, not on the admission, so nothing else
     * knows to clean it up. Miss this and a cancelled request leaves a bed
     * held for a patient who is never coming — invisible on the board and
     * unassignable until somebody notices and clears it by hand.
     */
    public static function releaseReservationsFor(int $companyId, int $admissionId, ?int $exceptBedId = null): int
    {
        $query = HealthBed::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('reserved_for_admission_id', $admissionId)
            ->where('status', HealthBed::STATUS_RESERVED);

        if ($exceptBedId) {
            $query->where('id', '!=', $exceptBedId);
        }

        return $query->update([
            'status' => HealthBed::STATUS_AVAILABLE,
            'reserved_for_admission_id' => null,
            'status_changed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Drop a reservation without admitting anybody. */
    public static function releaseReservation(int $bedId, int $companyId): void
    {
        HealthBed::withoutGlobalScopes()
            ->where('id', $bedId)
            ->where('company_id', $companyId)
            ->where('status', HealthBed::STATUS_RESERVED)
            ->update([
                'status' => HealthBed::STATUS_AVAILABLE,
                'reserved_for_admission_id' => null,
                'status_changed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Housekeeping / maintenance status change from the bed board.
     *
     * An OCCUPIED bed is refused outright: the only thing that empties a bed is
     * moving the patient out of it, and letting the board overwrite that would
     * make the board itself the source of orphaned stays.
     */
    public static function setBedStatus(HealthBed $bed, string $status, ?string $note, ?User $actor): HealthBed
    {
        if (!in_array($status, HealthBed::MANUAL_STATUSES, true)) {
            throw new \RuntimeException(__('health.bed_status_invalid'));
        }

        if ($bed->status === HealthBed::STATUS_OCCUPIED) {
            throw new \RuntimeException(__('health.bed_occupied_locked'));
        }

        // Freeing a reservation as part of blocking/cleaning the bed.
        $bed->reserved_for_admission_id = null;
        $bed->status = $status;
        $bed->status_note = $note;
        $bed->status_changed_at = now();
        $bed->save();

        return $bed;
    }

    /**
     * Beds this stay could go into: free, active, and inside a ward whose
     * gender policy accepts the patient.
     */
    public static function assignableBeds(HealthAdmission $admission, ?User $user)
    {
        $patient = $admission->relationLoaded('patient')
            ? $admission->getRelation('patient')
            : HealthPatient::withoutGlobalScopes()->find($admission->health_patient_id);

        $query = HealthBed::query()
            ->with(['ward', 'room'])
            ->where('is_active', true)
            ->where(function ($q) use ($admission) {
                $q->where('status', HealthBed::STATUS_AVAILABLE)
                    ->orWhere(function ($inner) use ($admission) {
                        // A bed already being held for THIS stay is assignable.
                        $inner->where('status', HealthBed::STATUS_RESERVED)
                            ->where('reserved_for_admission_id', $admission->id);
                    });
            })
            ->orderBy('code');

        HealthScopeService::applyBranchScope($query, $user);

        return $query->get()->filter(
            fn (HealthBed $bed) => !$bed->ward || $bed->ward->acceptsGender($patient?->gender)
        )->values();
    }

    /* ───────────────── internals ───────────────── */

    /**
     * Take a bed for this stay, atomically.
     *
     * The conditional UPDATE is the lock: only a row that is still available
     * (or already held for this very stay) is changed, so the second of two
     * simultaneous claims changes nothing and is told so.
     */
    private static function claimBed(HealthAdmission $stay, int $bedId, ?User $actor): HealthBed
    {
        $bed = HealthBed::withoutGlobalScopes()
            ->with('ward')
            ->where('id', $bedId)
            ->where('company_id', $stay->company_id)
            ->first();

        if (!$bed || !$bed->is_active) {
            throw new \RuntimeException(__('health.bed_not_available'));
        }

        $patient = HealthPatient::withoutGlobalScopes()->find($stay->health_patient_id);
        if ($bed->ward && !$bed->ward->acceptsGender($patient?->gender)) {
            throw new \RuntimeException(__('health.bed_gender_mismatch'));
        }

        $claimed = HealthBed::withoutGlobalScopes()
            ->where('id', $bedId)
            ->where('company_id', $stay->company_id)
            ->where('is_active', true)
            ->where(function ($q) use ($stay) {
                $q->where('status', HealthBed::STATUS_AVAILABLE)
                    ->orWhere(function ($inner) use ($stay) {
                        $inner->where('status', HealthBed::STATUS_RESERVED)
                            ->where('reserved_for_admission_id', $stay->id);
                    });
            })
            ->update([
                'status' => HealthBed::STATUS_OCCUPIED,
                'health_admission_id' => $stay->id,
                'reserved_for_admission_id' => null,
                'status_note' => null,
                'status_changed_at' => now(),
                'updated_at' => now(),
            ]);

        if (!$claimed) {
            throw new \RuntimeException(__('health.bed_not_available'));
        }

        // The stay may have been holding a different bed while it was only a
        // request. It is in this one now, so the other one goes back.
        self::releaseReservationsFor((int) $stay->company_id, (int) $stay->id, $bedId);

        return $bed->refresh();
    }

    /**
     * Give a bed back.
     *
     * Guarded on the admission id so a late/duplicate release can never wipe a
     * bed that has since been given to somebody else.
     */
    private static function releaseBed(int $bedId, int $admissionId, string $status, ?User $actor): void
    {
        HealthBed::withoutGlobalScopes()
            ->where('id', $bedId)
            ->where('health_admission_id', $admissionId)
            ->update([
                'status' => $status,
                'health_admission_id' => null,
                'reserved_for_admission_id' => null,
                'status_changed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Write one timeline row.
     *
     * Public because charges, payments and operations all belong on the same
     * timeline — a stay's history is not complete if the money and the theatre
     * keep their own separate logs.
     */
    public static function event(HealthAdmission $admission, string $event, ?User $actor, array $data = []): void
    {
        HealthAdmissionEvent::create([
            'company_id' => $admission->company_id,
            'health_admission_id' => $admission->id,
            'event' => $event,
            'from_status' => $data['from_status'] ?? null,
            'to_status' => $data['to_status'] ?? null,
            'from_bed_id' => $data['from_bed_id'] ?? null,
            'to_bed_id' => $data['to_bed_id'] ?? null,
            'note' => isset($data['note']) ? mb_substr((string) $data['note'], 0, 500) : null,
            'meta' => isset($data['meta']) ? json_encode($data['meta'], JSON_UNESCAPED_UNICODE) : null,
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'occurred_at' => now(),
        ]);
    }
}
