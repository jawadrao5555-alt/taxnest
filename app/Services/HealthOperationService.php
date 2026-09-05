<?php

namespace App\Services;

use App\Models\HealthAdmission;
use App\Models\HealthAdmissionCharge;
use App\Models\HealthAdmissionEvent;
use App\Models\HealthDoctor;
use App\Models\HealthOperation;
use App\Models\HealthOperationConsumable;
use App\Models\HealthOperationTeamMember;
use App\Models\HealthOperationTheatre;
use App\Models\HealthProcedure;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The theatre: scheduling, the team, what was used, and what came of it.
 *
 * Two things this class exists to make impossible:
 *
 *  1. Two operations in one theatre at one time. The overlap check runs inside
 *     the transaction that writes the booking, not before it, because a check
 *     that has already released its read is only a suggestion.
 *  2. Billing the same operation twice. Completion stamps `charge_posted_at`
 *     and the charge itself carries a dedupe key, so a retried "complete"
 *     lands on an already-posted stay without adding a rupee.
 */
class HealthOperationService
{
    /**
     * Book a theatre slot.
     *
     * The price is frozen onto the operation from the catalogue at booking
     * time. A procedure repriced next month must not silently rewrite an
     * operation that was quoted to a patient today — the same rule the OPD
     * consultation fee follows.
     */
    public static function schedule(array $data, ?User $actor): HealthOperation
    {
        return DB::transaction(function () use ($data, $actor) {
            $companyId = (int) $data['company_id'];

            $procedure = !empty($data['health_procedure_id'])
                ? HealthProcedure::withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->find($data['health_procedure_id'])
                : null;

            $start = !empty($data['scheduled_start']) ? Carbon::parse($data['scheduled_start']) : null;
            $end = !empty($data['scheduled_end']) ? Carbon::parse($data['scheduled_end']) : null;

            if ($start && !$end) {
                $minutes = (int) ($procedure->estimated_minutes ?? 60);
                $end = $start->copy()->addMinutes($minutes > 0 ? $minutes : 60);
            }

            $theatreId = !empty($data['health_operation_theatre_id']) ? (int) $data['health_operation_theatre_id'] : null;
            if ($theatreId && $start && $end) {
                self::lockTheatre($companyId, $theatreId);
                self::assertTheatreFree($companyId, $theatreId, $start, $end, null);
            }

            $isPackage = $procedure ? (bool) $procedure->is_package : (bool) ($data['is_package'] ?? false);
            $price = array_key_exists('price', $data) && $data['price'] !== null && $data['price'] !== ''
                ? max(0, round((float) $data['price'], 2))
                : ($procedure ? $procedure->effectivePrice() : 0.0);

            $operation = HealthOperation::create([
                'company_id' => $companyId,
                'branch_id' => $data['branch_id'] ?? null,
                'health_department_id' => $data['health_department_id'] ?? ($procedure->health_department_id ?? null),
                'health_patient_id' => (int) $data['health_patient_id'],
                'health_admission_id' => $data['health_admission_id'] ?? null,
                'health_procedure_id' => $procedure?->id,
                'health_operation_theatre_id' => $theatreId,
                'operation_no' => HealthNumberService::operationNumber($companyId),
                'title' => mb_substr((string) ($data['title'] ?: ($procedure->name ?? '')), 0, 200),
                'status' => HealthOperation::STATUS_SCHEDULED,
                'urgency' => in_array($data['urgency'] ?? '', HealthOperation::URGENCIES, true)
                    ? $data['urgency']
                    : 'elective',
                'scheduled_start' => $start,
                'scheduled_end' => $end,
                'primary_surgeon_id' => $data['primary_surgeon_id'] ?? null,
                'anaesthetist_id' => $data['anaesthetist_id'] ?? null,
                'anaesthesia_type' => $data['anaesthesia_type'] ?? ($procedure->default_anaesthesia ?? null),
                'pre_op_checklist' => self::buildChecklist($procedure, $data['pre_op_checklist'] ?? null),
                'consent_reference' => $data['consent_reference'] ?? null,
                'is_package' => $isPackage,
                'price' => $price,
                'created_by' => $actor?->id,
            ]);

            self::syncPrimaryTeam($operation, $actor);

            if ($operation->health_admission_id) {
                $admission = HealthAdmission::withoutGlobalScopes()->find($operation->health_admission_id);
                if ($admission) {
                    HealthIpdService::event($admission, HealthAdmissionEvent::OPERATION_SCHEDULED, $actor, [
                        'note' => $operation->title,
                        'meta' => ['operation_id' => $operation->id, 'operation_no' => $operation->operation_no],
                    ]);
                }
            }

            return $operation;
        });
    }

    /** Move a booked operation to a different slot, theatre or team. */
    public static function reschedule(HealthOperation $operation, array $data, ?User $actor): HealthOperation
    {
        return DB::transaction(function () use ($operation, $data, $actor) {
            if (!in_array($operation->status, [HealthOperation::STATUS_SCHEDULED, HealthOperation::STATUS_POSTPONED], true)) {
                throw new \RuntimeException(__('health.op_not_reschedulable'));
            }

            $start = !empty($data['scheduled_start']) ? Carbon::parse($data['scheduled_start']) : $operation->scheduled_start;
            $end = !empty($data['scheduled_end']) ? Carbon::parse($data['scheduled_end']) : $operation->scheduled_end;
            if ($start && (!$end || $end->lte($start))) {
                $end = $start->copy()->addMinutes(60);
            }

            $theatreId = array_key_exists('health_operation_theatre_id', $data)
                ? ($data['health_operation_theatre_id'] ?: null)
                : $operation->health_operation_theatre_id;

            if ($theatreId && $start && $end) {
                self::lockTheatre((int) $operation->company_id, (int) $theatreId);
                self::assertTheatreFree($operation->company_id, (int) $theatreId, $start, $end, $operation->id);
            }

            $operation->fill([
                'health_operation_theatre_id' => $theatreId,
                'scheduled_start' => $start,
                'scheduled_end' => $end,
                'status' => HealthOperation::STATUS_SCHEDULED,
                'title' => $data['title'] ?? $operation->title,
                'urgency' => in_array($data['urgency'] ?? '', HealthOperation::URGENCIES, true)
                    ? $data['urgency']
                    : $operation->urgency,
                'primary_surgeon_id' => array_key_exists('primary_surgeon_id', $data)
                    ? ($data['primary_surgeon_id'] ?: null)
                    : $operation->primary_surgeon_id,
                'anaesthetist_id' => array_key_exists('anaesthetist_id', $data)
                    ? ($data['anaesthetist_id'] ?: null)
                    : $operation->anaesthetist_id,
                'anaesthesia_type' => $data['anaesthesia_type'] ?? $operation->anaesthesia_type,
                'consent_reference' => $data['consent_reference'] ?? $operation->consent_reference,
            ]);

            if (array_key_exists('price', $data) && $data['price'] !== null && $data['price'] !== '') {
                $operation->price = max(0, round((float) $data['price'], 2));
            }

            $operation->save();

            self::syncPrimaryTeam($operation, $actor);

            return $operation;
        });
    }

    /** Tick off the pre-op checklist. */
    public static function savePreOp(HealthOperation $operation, array $ticked, ?string $notes, ?User $actor): HealthOperation
    {
        $items = $operation->checklist();
        foreach ($items as $index => $item) {
            $items[$index]['done'] = in_array((string) $index, array_map('strval', $ticked), true);
        }

        $operation->pre_op_checklist = json_encode(array_values($items), JSON_UNESCAPED_UNICODE);
        $operation->pre_op_notes = $notes;

        if ($items !== [] && $operation->preOpReady()) {
            $operation->pre_op_completed_at = now();
            $operation->pre_op_completed_by = $actor?->id;
        } else {
            $operation->pre_op_completed_at = null;
            $operation->pre_op_completed_by = null;
        }

        $operation->save();

        return $operation;
    }

    /**
     * Wheels in. Refuses while the pre-op checklist has an unticked item —
     * a safety checklist that can be walked past is decoration.
     */
    public static function start(HealthOperation $operation, ?User $actor): HealthOperation
    {
        if ($operation->status === HealthOperation::STATUS_IN_PROGRESS) {
            return $operation;
        }

        if (!in_array($operation->status, [HealthOperation::STATUS_SCHEDULED, HealthOperation::STATUS_POSTPONED], true)) {
            throw new \RuntimeException(__('health.op_not_startable'));
        }

        if (!$operation->preOpReady()) {
            throw new \RuntimeException(__('health.op_preop_incomplete'));
        }

        $operation->status = HealthOperation::STATUS_IN_PROGRESS;
        $operation->actual_start = $operation->actual_start ?: now();
        $operation->save();

        return $operation;
    }

    /**
     * Finished. Records the outcome and posts the charge to the linked stay.
     *
     * The `charge_posted_at` stamp makes this safe to call twice: the second
     * call updates the clinical record if the caller sent new text, but never
     * bills again. A day-care operation with no admission simply has no ledger
     * to post to — its money is billed at the counter, which is a different
     * module's job.
     */
    public static function complete(HealthOperation $operation, array $data, ?User $actor): HealthOperation
    {
        return DB::transaction(function () use ($operation, $data, $actor) {
            /** @var HealthOperation $op */
            $op = HealthOperation::withoutGlobalScopes()
                ->where('id', $operation->id)
                ->lockForUpdate()
                ->first();

            if (!$op) {
                throw new \RuntimeException(__('health.op_not_found'));
            }

            if ($op->status === HealthOperation::STATUS_CANCELLED) {
                throw new \RuntimeException(__('health.op_cancelled_locked'));
            }

            $op->status = HealthOperation::STATUS_COMPLETED;
            $op->actual_start = $op->actual_start ?: ($data['actual_start'] ?? now());
            $op->actual_end = $data['actual_end'] ?? now();
            $op->operative_notes = $data['operative_notes'] ?? $op->operative_notes;
            $op->findings = $data['findings'] ?? $op->findings;
            $op->outcome = in_array($data['outcome'] ?? '', HealthOperation::OUTCOMES, true)
                ? $data['outcome']
                : ($op->outcome ?: 'successful');
            $op->complications = $data['complications'] ?? $op->complications;
            $op->blood_loss_ml = $data['blood_loss_ml'] ?? $op->blood_loss_ml;
            $op->specimen_sent = (bool) ($data['specimen_sent'] ?? $op->specimen_sent);
            $op->post_op_instructions = $data['post_op_instructions'] ?? $op->post_op_instructions;

            if (array_key_exists('concession_amount', $data) && $data['concession_amount'] !== null && $data['concession_amount'] !== '') {
                $op->concession_amount = min((float) $op->price, max(0, round((float) $data['concession_amount'], 2)));
                $op->concession_reason = $data['concession_reason'] ?? null;
            }

            $op->completed_at = $op->completed_at ?: now();
            $op->completed_by = $op->completed_by ?: $actor?->id;
            $op->save();

            if (!$op->charge_posted_at) {
                self::postCharges($op, $actor);
                $op->forceFill(['charge_posted_at' => now()])->save();
            }

            if ($op->health_admission_id) {
                $admission = HealthAdmission::withoutGlobalScopes()->find($op->health_admission_id);
                if ($admission) {
                    HealthIpdService::event($admission, HealthAdmissionEvent::OPERATION_COMPLETED, $actor, [
                        'note' => $op->title,
                        'meta' => ['operation_id' => $op->id, 'outcome' => $op->outcome],
                    ]);
                }
            }

            return $op->refresh();
        });
    }

    /**
     * Called off. A COMPLETED operation cannot be cancelled — reverse its
     * charges instead; pretending it never happened would take the record of a
     * procedure the patient actually underwent out of their file.
     */
    public static function cancel(HealthOperation $operation, ?string $reason, ?User $actor, bool $postpone = false): HealthOperation
    {
        return DB::transaction(function () use ($operation, $reason, $actor, $postpone) {
            if ($operation->status === HealthOperation::STATUS_COMPLETED) {
                throw new \RuntimeException(__('health.op_completed_locked'));
            }

            $operation->status = $postpone ? HealthOperation::STATUS_POSTPONED : HealthOperation::STATUS_CANCELLED;
            $operation->cancel_reason = $reason;
            $operation->cancelled_at = now();
            $operation->cancelled_by = $actor?->id;
            if ($postpone) {
                // A postponed operation gives its slot back.
                $operation->scheduled_start = null;
                $operation->scheduled_end = null;
            }
            $operation->save();

            if ($operation->health_admission_id) {
                $admission = HealthAdmission::withoutGlobalScopes()->find($operation->health_admission_id);
                if ($admission) {
                    HealthIpdService::event($admission, HealthAdmissionEvent::OPERATION_CANCELLED, $actor, [
                        'note' => $reason ?: $operation->title,
                        'meta' => ['operation_id' => $operation->id, 'postponed' => $postpone],
                    ]);
                }
            }

            return $operation;
        });
    }

    /** Replace the operating team in one go. */
    public static function saveTeam(HealthOperation $operation, array $rows, ?User $actor): void
    {
        DB::transaction(function () use ($operation, $rows) {
            HealthOperationTeamMember::withoutGlobalScopes()
                ->where('company_id', $operation->company_id)
                ->where('health_operation_id', $operation->id)
                ->delete();

            foreach ($rows as $row) {
                $doctorId = !empty($row['health_doctor_id']) ? (int) $row['health_doctor_id'] : null;
                $name = trim((string) ($row['name'] ?? ''));

                if ($doctorId && $name === '') {
                    $doctor = HealthDoctor::withoutGlobalScopes()
                        ->where('company_id', $operation->company_id)
                        ->find($doctorId);
                    $name = (string) ($doctor->name ?? '');
                }

                if ($name === '') {
                    continue;
                }

                HealthOperationTeamMember::create([
                    'company_id' => $operation->company_id,
                    'health_operation_id' => $operation->id,
                    'health_doctor_id' => $doctorId,
                    'name' => mb_substr($name, 0, 150),
                    'role' => in_array($row['role'] ?? '', HealthOperationTeamMember::ROLES, true)
                        ? $row['role']
                        : 'assistant',
                    'fee_amount' => max(0, round((float) ($row['fee_amount'] ?? 0), 2)),
                    'note' => isset($row['note']) ? mb_substr((string) $row['note'], 0, 300) : null,
                ]);
            }
        });
    }

    /** Replace the consumable usage in one go. */
    public static function saveConsumables(HealthOperation $operation, array $rows): void
    {
        DB::transaction(function () use ($operation, $rows) {
            HealthOperationConsumable::withoutGlobalScopes()
                ->where('company_id', $operation->company_id)
                ->where('health_operation_id', $operation->id)
                ->delete();

            foreach ($rows as $row) {
                $item = trim((string) ($row['item_name'] ?? ''));
                if ($item === '') {
                    continue;
                }

                $quantity = max(0, round((float) ($row['quantity'] ?? 1), 2));
                $unitPrice = max(0, round((float) ($row['unit_price'] ?? 0), 2));

                HealthOperationConsumable::create([
                    'company_id' => $operation->company_id,
                    'health_operation_id' => $operation->id,
                    'item_name' => mb_substr($item, 0, 200),
                    'unit' => isset($row['unit']) ? mb_substr((string) $row['unit'], 0, 20) : null,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'amount' => round($quantity * $unitPrice, 2),
                    // A package already covers its consumables, so usage is
                    // still recorded but never charged on top of it.
                    'is_billable' => $operation->is_package ? false : (bool) ($row['is_billable'] ?? true),
                    'note' => isset($row['note']) ? mb_substr((string) $row['note'], 0, 300) : null,
                ]);
            }
        });
    }

    /**
     * Theatre bookings that clash with this window.
     *
     * Overlap is strict: two lists that merely touch (one ends exactly as the
     * next begins) do not clash, plus the theatre's own turnaround.
     */
    public static function conflicts(int $companyId, int $theatreId, Carbon $start, Carbon $end, ?int $ignoreId = null)
    {
        $theatre = \App\Models\HealthOperationTheatre::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->find($theatreId);

        $turnaround = (int) ($theatre->turnaround_minutes ?? 0);
        $windowStart = $start->copy()->subMinutes($turnaround);
        $windowEnd = $end->copy()->addMinutes($turnaround);

        return HealthOperation::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('health_operation_theatre_id', $theatreId)
            ->whereIn('status', HealthOperation::BLOCKING_STATUSES)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->whereNotNull('scheduled_start')
            ->whereNotNull('scheduled_end')
            ->where('scheduled_start', '<', $windowEnd)
            ->where('scheduled_end', '>', $windowStart)
            ->get();
    }

    /**
     * Serialise everything that books this theatre.
     *
     * The overlap check is a SELECT: two clerks scheduling the same 09:00 slot
     * at the same moment can both read "free" and both commit, and the theatre
     * finds out on the morning. Taking the theatre's own row FOR UPDATE first
     * means the second request waits, then re-reads and sees the first
     * booking. The lock is per theatre, so a busy hospital's other lists are
     * unaffected.
     */
    private static function lockTheatre(int $companyId, int $theatreId): void
    {
        HealthOperationTheatre::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('id', $theatreId)
            ->lockForUpdate()
            ->first();
    }

    private static function assertTheatreFree(int $companyId, int $theatreId, Carbon $start, Carbon $end, ?int $ignoreId): void
    {
        $clash = self::conflicts($companyId, $theatreId, $start, $end, $ignoreId)->first();

        if ($clash) {
            throw new \RuntimeException(__('health.op_theatre_busy', [
                'no' => $clash->operation_no,
                'time' => optional($clash->scheduled_start)->format('d/m/Y H:i'),
            ]));
        }
    }

    /**
     * Post the operation's money onto the linked stay's ledger.
     *
     * One line for the procedure, one per billable consumable. Everything
     * carries a dedupe key derived from the operation so a retry cannot
     * duplicate it.
     */
    private static function postCharges(HealthOperation $operation, ?User $actor): void
    {
        if (!$operation->health_admission_id) {
            return;
        }

        $admission = HealthAdmission::withoutGlobalScopes()->find($operation->health_admission_id);
        if (!$admission) {
            return;
        }

        if ((float) $operation->price > 0) {
            HealthIpdBillingService::postCharge($admission, [
                'category' => HealthAdmissionCharge::CAT_PROCEDURE,
                'description' => $operation->title,
                'reference' => $operation->operation_no,
                'source_type' => 'operation',
                'source_id' => $operation->id,
                'unit_price' => (float) $operation->price,
                'quantity' => 1,
                'concession_amount' => (float) $operation->concession_amount,
                'concession_reason' => $operation->concession_reason,
                'charge_date' => optional($operation->actual_end ?: $operation->completed_at ?: now())->toDateString(),
                'dedupe_key' => 'operation:' . $operation->id,
            ], $actor);
        }

        if ($operation->is_package) {
            return;   // the package price already covers what was used
        }

        foreach ($operation->consumables()->where('is_billable', true)->get() as $consumable) {
            if ((float) $consumable->amount <= 0) {
                continue;
            }

            HealthIpdBillingService::postCharge($admission, [
                'category' => HealthAdmissionCharge::CAT_CONSUMABLE,
                'description' => $consumable->item_name,
                'reference' => $operation->operation_no,
                'source_type' => 'operation_consumable',
                'source_id' => $consumable->id,
                'unit_price' => (float) $consumable->unit_price,
                'quantity' => (float) $consumable->quantity,
                'charge_date' => optional($operation->actual_end ?: now())->toDateString(),
                'dedupe_key' => 'operation_consumable:' . $consumable->id,
            ], $actor);
        }
    }

    /**
     * Keep the team list in step with the surgeon/anaesthetist named on the
     * operation itself, so the register never shows an operation with nobody
     * at the table just because the team screen was never opened.
     */
    private static function syncPrimaryTeam(HealthOperation $operation, ?User $actor): void
    {
        $pairs = [
            HealthOperationTeamMember::ROLE_SURGEON => $operation->primary_surgeon_id,
            HealthOperationTeamMember::ROLE_ANAESTHETIST => $operation->anaesthetist_id,
        ];

        foreach ($pairs as $role => $doctorId) {
            HealthOperationTeamMember::withoutGlobalScopes()
                ->where('company_id', $operation->company_id)
                ->where('health_operation_id', $operation->id)
                ->where('role', $role)
                ->delete();

            if (!$doctorId) {
                continue;
            }

            $doctor = HealthDoctor::withoutGlobalScopes()
                ->where('company_id', $operation->company_id)
                ->find($doctorId);

            if (!$doctor) {
                continue;
            }

            HealthOperationTeamMember::create([
                'company_id' => $operation->company_id,
                'health_operation_id' => $operation->id,
                'health_doctor_id' => $doctor->id,
                'name' => $doctor->name,
                'role' => $role,
                'fee_amount' => 0,
            ]);
        }

        $operation->setRelation('team', $operation->team()->get());
    }

    /** Merge the catalogue's default checklist with anything typed on the form. */
    private static function buildChecklist(?HealthProcedure $procedure, $typed): ?string
    {
        $items = $procedure ? $procedure->checklistItems() : [];

        if (is_string($typed) && trim($typed) !== '') {
            foreach (preg_split('/\r\n|\r|\n/', $typed) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '' && !in_array($line, $items, true)) {
                    $items[] = $line;
                }
            }
        }

        if ($items === []) {
            return null;
        }

        return json_encode(
            array_map(fn ($item) => ['item' => $item, 'done' => false, 'note' => ''], $items),
            JSON_UNESCAPED_UNICODE
        );
    }
}
