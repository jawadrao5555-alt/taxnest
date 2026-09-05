<?php

namespace App\Services;

use App\Models\HealthAppointment;
use App\Models\HealthDoctor;
use App\Models\HealthPatient;
use App\Models\HealthVisit;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The OPD lifecycle — the one place that moves an appointment through the desk
 * and into a consultation.
 *
 * Reception books (or hands out a token) → the patient arrives and is checked
 * in, which is the moment the ENCOUNTER is born and the consultation fee is
 * captured → the doctor starts and finishes it. Every transition is written
 * here, in a transaction, so the appointment and its visit can never disagree
 * about what happened.
 *
 * The fee lives on the visit, against the doctor who was actually seen. It is
 * captured at check-in rather than at booking because a booking can be moved to
 * another doctor, and a fee that quietly belongs to the wrong practitioner is
 * worse than no fee at all.
 */
class HealthOpdService
{
    /**
     * Decide whether this is a new consultation or a follow-up.
     *
     * A follow-up is a return to the SAME doctor inside that doctor's own
     * follow-up window. The window is per doctor because it is a commercial
     * decision each practitioner makes, not a clinic-wide rule. A doctor with a
     * window of zero never produces follow-ups, which is the honest answer for
     * someone who charges full fee every time.
     */
    public static function suggestVisitType(HealthPatient $patient, HealthDoctor $doctor, ?string $onDate = null): string
    {
        $days = (int) $doctor->follow_up_days;
        if ($days <= 0) {
            return HealthVisit::TYPE_NEW;
        }

        $date = $onDate ? Carbon::parse($onDate) : now();

        $recent = HealthVisit::withoutGlobalScopes()
            ->where('company_id', $doctor->company_id)
            ->where('health_patient_id', $patient->id)
            ->where('health_doctor_id', $doctor->id)
            ->where('status', HealthVisit::STATUS_COMPLETED)
            ->whereDate('visit_date', '>=', $date->copy()->subDays($days)->toDateString())
            ->whereDate('visit_date', '<=', $date->toDateString())
            ->exists();

        return $recent ? HealthVisit::TYPE_FOLLOW_UP : HealthVisit::TYPE_NEW;
    }

    /**
     * Book an appointment, or hand out a walk-in token.
     *
     * A walk-in gets its token immediately; a scheduled booking does not,
     * because a token is a position in TODAY's queue and a booking for next
     * Tuesday has no position yet. The token is allocated when they arrive.
     */
    public static function book(array $data, ?User $actor): HealthAppointment
    {
        return DB::transaction(function () use ($data, $actor) {
            $kind = ($data['kind'] ?? HealthAppointment::KIND_SCHEDULED) === HealthAppointment::KIND_WALKIN
                ? HealthAppointment::KIND_WALKIN
                : HealthAppointment::KIND_SCHEDULED;

            $appointment = HealthAppointment::create([
                'company_id' => $data['company_id'],
                'branch_id' => $data['branch_id'] ?? null,
                'health_department_id' => $data['health_department_id'] ?? null,
                'health_patient_id' => $data['health_patient_id'],
                'health_doctor_id' => $data['health_doctor_id'],
                'kind' => $kind,
                'appointment_date' => $data['appointment_date'],
                'appointment_time' => $data['appointment_time'] ?? null,
                'status' => HealthAppointment::STATUS_BOOKED,
                'reason' => $data['reason'] ?? null,
                'created_by' => $actor?->id,
            ]);

            if ($kind === HealthAppointment::KIND_WALKIN) {
                $appointment->token_no = HealthNumberService::tokenNumber(
                    (int) $data['company_id'],
                    (int) $data['health_doctor_id'],
                    (string) $data['appointment_date']
                );
                $appointment->save();
            }

            return $appointment;
        });
    }

    /**
     * The patient has arrived. Allocate their token if they do not have one,
     * open the encounter, and capture the consultation fee.
     *
     * Idempotent on purpose: a double-clicked check-in must not create a second
     * encounter or burn a second token, so an appointment that already carries
     * a visit simply returns it.
     */
    public static function checkIn(HealthAppointment $appointment, ?User $actor, array $overrides = []): HealthVisit
    {
        return DB::transaction(function () use ($appointment, $actor, $overrides) {
            $fresh = HealthAppointment::withoutGlobalScopes()
                ->where('id', $appointment->id)
                ->lockForUpdate()
                ->first();

            if ($fresh && $fresh->health_visit_id) {
                $existing = HealthVisit::withoutGlobalScopes()->find($fresh->health_visit_id);
                if ($existing) {
                    return $existing;
                }
            }

            $doctor = HealthDoctor::withoutGlobalScopes()->findOrFail($appointment->health_doctor_id);
            $patient = HealthPatient::withoutGlobalScopes()->findOrFail($appointment->health_patient_id);

            $companyId = (int) $appointment->company_id;
            $date = $appointment->appointment_date
                ? Carbon::parse($appointment->appointment_date)->toDateString()
                : now()->toDateString();

            $token = $appointment->token_no
                ?: HealthNumberService::tokenNumber($companyId, (int) $doctor->id, $date);

            $visitType = $overrides['visit_type'] ?? self::suggestVisitType($patient, $doctor, $date);
            if (!in_array($visitType, HealthVisit::TYPES, true)) {
                $visitType = HealthVisit::TYPE_NEW;
            }

            $fee = array_key_exists('fee_amount', $overrides) && $overrides['fee_amount'] !== null
                ? round((float) $overrides['fee_amount'], 2)
                : $doctor->feeFor($visitType);
            $concession = round((float) ($overrides['concession_amount'] ?? 0), 2);
            // A concession can never exceed the fee — that would be a refund,
            // and a refund is not something the front desk invents on a slip.
            $concession = max(0.0, min($concession, $fee));

            $visit = HealthVisit::create([
                'company_id' => $companyId,
                'branch_id' => $appointment->branch_id,
                'health_department_id' => $appointment->health_department_id ?? $doctor->health_department_id,
                'health_patient_id' => $patient->id,
                'health_doctor_id' => $doctor->id,
                'health_appointment_id' => $appointment->id,
                'visit_no' => HealthNumberService::visitNumber($companyId),
                'visit_date' => $date,
                'visit_type' => $visitType,
                'status' => HealthVisit::STATUS_WAITING,
                'chief_complaint' => $appointment->reason,
                'fee_amount' => $fee,
                'concession_amount' => $concession,
                'concession_reason' => $overrides['concession_reason'] ?? null,
                'net_fee' => round($fee - $concession, 2),
                'fee_status' => HealthVisit::FEE_PENDING,
                'opened_by' => $actor?->id,
            ]);

            $appointment->token_no = $token;
            $appointment->status = HealthAppointment::STATUS_CHECKED_IN;
            $appointment->checked_in_at = now();
            $appointment->health_visit_id = $visit->id;
            $appointment->save();

            return $visit;
        });
    }

    /** The doctor has called the patient in. */
    public static function startConsultation(HealthVisit $visit): void
    {
        if ($visit->status === HealthVisit::STATUS_COMPLETED || $visit->status === HealthVisit::STATUS_CANCELLED) {
            return;
        }

        DB::transaction(function () use ($visit) {
            $visit->status = HealthVisit::STATUS_IN_CONSULTATION;
            $visit->consultation_started_at = $visit->consultation_started_at ?? now();
            $visit->save();

            if ($visit->health_appointment_id) {
                HealthAppointment::withoutGlobalScopes()
                    ->where('id', $visit->health_appointment_id)
                    ->update([
                        'status' => HealthAppointment::STATUS_IN_CONSULTATION,
                        'started_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
        });
    }

    /** The consultation is finished. Closes the appointment with it. */
    public static function complete(HealthVisit $visit, ?User $actor): void
    {
        DB::transaction(function () use ($visit, $actor) {
            $visit->status = HealthVisit::STATUS_COMPLETED;
            $visit->closed_by = $actor?->id;
            $visit->closed_at = now();
            $visit->consultation_started_at = $visit->consultation_started_at ?? now();
            $visit->save();

            if ($visit->health_appointment_id) {
                HealthAppointment::withoutGlobalScopes()
                    ->where('id', $visit->health_appointment_id)
                    ->update([
                        'status' => HealthAppointment::STATUS_COMPLETED,
                        'completed_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
        });
    }

    /**
     * Cancel an appointment.
     *
     * If it had already been checked in, the encounter is cancelled with it —
     * an open visit left behind would sit in the doctor's queue forever and
     * would keep counting as workload for someone who was never seen.
     */
    public static function cancel(HealthAppointment $appointment, ?string $reason): void
    {
        DB::transaction(function () use ($appointment, $reason) {
            $appointment->status = HealthAppointment::STATUS_CANCELLED;
            $appointment->cancelled_at = now();
            $appointment->cancel_reason = $reason;
            $appointment->save();

            if ($appointment->health_visit_id) {
                HealthVisit::withoutGlobalScopes()
                    ->where('id', $appointment->health_visit_id)
                    ->where('status', '!=', HealthVisit::STATUS_COMPLETED)
                    ->update([
                        'status' => HealthVisit::STATUS_CANCELLED,
                        'closed_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
        });
    }

    /**
     * The patient never came.
     *
     * Only a booking that was never checked in can be a no-show — somebody who
     * walked in, was seen and left is not, however the row was later edited.
     */
    public static function markNoShow(HealthAppointment $appointment): bool
    {
        if ($appointment->checked_in_at || $appointment->health_visit_id) {
            return false;
        }

        $appointment->status = HealthAppointment::STATUS_NO_SHOW;
        $appointment->no_show_at = now();
        $appointment->save();

        return true;
    }

    /**
     * Record the consultation fee decision.
     *
     * Gross, concession and net are stored as three separate numbers. A single
     * "amount taken" would lose both the list price and the reason a discount
     * was given, and a concession nobody can explain later is indistinguishable
     * from money that went missing.
     */
    public static function applyFee(HealthVisit $visit, array $data, ?User $actor): void
    {
        $fee = round((float) ($data['fee_amount'] ?? $visit->fee_amount), 2);
        $concession = round((float) ($data['concession_amount'] ?? 0), 2);
        $concession = max(0.0, min($concession, $fee));

        $status = $data['fee_status'] ?? $visit->fee_status;
        if (!in_array($status, HealthVisit::FEE_STATUSES, true)) {
            $status = HealthVisit::FEE_PENDING;
        }

        $method = $data['payment_method'] ?? null;
        if ($method !== null && !in_array($method, HealthVisit::PAYMENT_METHODS, true)) {
            $method = null;
        }

        $visit->fee_amount = $fee;
        $visit->concession_amount = $concession;
        $visit->concession_reason = $concession > 0 ? ($data['concession_reason'] ?? $visit->concession_reason) : null;
        $visit->net_fee = round($fee - $concession, 2);
        $visit->fee_status = $status;
        $visit->payment_method = $status === HealthVisit::FEE_PAID ? $method : null;

        if ($status === HealthVisit::FEE_PAID) {
            // Stamp the collection once. Re-saving the same paid visit must not
            // keep moving the moment the money arrived.
            $visit->fee_collected_at = $visit->fee_collected_at ?? now();
            $visit->fee_collected_by = $visit->fee_collected_by ?? $actor?->id;
        } else {
            $visit->fee_collected_at = null;
            $visit->fee_collected_by = null;
        }

        $visit->save();
    }
}
