<?php

namespace App\Observers;

use App\Services\HealthAudit\HealthAuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The generic recorder for healthcare model changes (Task 1554).
 *
 * ONE observer for every audited model rather than sixty near-identical ones.
 * Each model contributes a small descriptor — what to call its events, which
 * column is its human label, which is its money — and everything else (who,
 * when, which branch, what actually changed) is derived the same way for all of
 * them. Sixty hand-written observers is sixty chances for one module to quietly
 * stop recording who reversed a charge.
 *
 * Deliberately NOT registered for health_batch_movements: the movement ledger
 * is already an append-only trail of exactly these acts, and mirroring it would
 * double every pharmacy row in the timeline for no extra evidence.
 */
class HealthAuditObserver
{
    /**
     * model class => descriptor.
     *
     *   event    dotted prefix; the action is appended (charge.created)
     *   label    column holding the number a human would quote
     *   amount   column holding the money on the row, if any
     *   patient  column pointing at the patient, if any
     *   doctor   column pointing at the practitioner, if any
     *   reason   columns to look in for a recorded reason, in order
     *   scope    FK column => parent table, tried in order: where the row has no
     *            branch/department of its own, the PARENT's is filed on the
     *            event. A receipt has no ward column but its bill does, and a
     *            ward-confined auditor must not see the receipts of another
     *            ward just because the receipts table never grew the column.
     */
    public const MAP = [
        // ── Patients and clinical activity ────────────────────────────────
        \App\Models\HealthPatient::class => [
            'event' => 'clinical.patient', 'category' => 'clinical',
            'label' => 'mrn', 'patient' => 'id',
            // Allow-list: only these columns keep their VALUE in the trail. A
            // patient's name, CNIC, phone, address and everything else are
            // recorded as "this field changed" and nothing more.
            'fields' => [
                'mrn', 'company_id', 'branch_id', 'is_active', 'is_confidential',
                'registered_by', 'consent_treatment', 'consent_share_reports',
                'consent_contact', 'consent_recorded_at', 'consent_recorded_by',
            ],
        ],
        \App\Models\HealthAppointment::class => [
            'event' => 'clinical.appointment', 'category' => 'clinical',
            'label' => 'token_no', 'patient' => 'health_patient_id',
            'doctor' => 'health_doctor_id', 'reason' => ['cancel_reason'],
        ],
        \App\Models\HealthVisit::class => [
            'event' => 'clinical.visit', 'category' => 'clinical',
            'label' => 'visit_no', 'amount' => 'net_fee',
            'patient' => 'health_patient_id', 'doctor' => 'health_doctor_id',
            'reason' => ['concession_reason'],
        ],
        \App\Models\HealthOperation::class => [
            'event' => 'clinical.operation', 'category' => 'clinical',
            'label' => 'operation_no', 'amount' => 'price',
            'patient' => 'health_patient_id', 'doctor' => 'primary_surgeon_id',
            'reason' => ['cancel_reason', 'concession_reason'],
        ],
        \App\Models\HealthAdmission::class => [
            'event' => 'clinical.admission', 'category' => 'clinical',
            'label' => 'admission_no', 'patient' => 'health_patient_id',
            'doctor' => 'health_doctor_id',
        ],
        \App\Models\HealthPrescription::class => [
            'event' => 'clinical.prescription', 'category' => 'clinical',
            'label' => 'prescription_no', 'patient' => 'health_patient_id',
            'doctor' => 'health_doctor_id',
        ],

        // ── Money owed and money taken ────────────────────────────────────
        \App\Models\HealthCharge::class => [
            'event' => 'billing.charge', 'category' => 'billing',
            'label' => 'charge_no', 'amount' => 'total_amount',
            'patient' => 'health_patient_id',
            'reason' => ['reversal_reason', 'concession_reason'],
        ],
        \App\Models\HealthAdmissionCharge::class => [
            'event' => 'billing.ipd_charge', 'category' => 'billing',
            'label' => 'reference', 'amount' => 'net_amount',
            'patient' => 'health_patient_id',
            'reason' => ['reversal_reason', 'concession_reason'],
            'scope' => ['health_admission_id' => 'health_admissions'],
        ],
        \App\Models\HealthChargeAdjustment::class => [
            'event' => 'billing.adjustment', 'category' => 'billing',
            'label' => 'reference', 'amount' => 'amount',
            'reason' => ['reason'],
            'scope' => ['health_charge_id' => 'health_charges'],
        ],
        \App\Models\HealthBill::class => [
            'event' => 'billing.bill', 'category' => 'billing',
            'label' => 'bill_no', 'amount' => 'total_amount',
            'patient' => 'health_patient_id', 'reason' => ['cancel_reason'],
        ],
        \App\Models\HealthPayment::class => [
            'event' => 'payment.receipt', 'category' => 'payment',
            'label' => 'receipt_no', 'amount' => 'amount',
            'patient' => 'health_patient_id', 'reason' => ['reversal_reason'],
            'scope' => ['health_bill_id' => 'health_bills', 'health_admission_id' => 'health_admissions'],
        ],
        \App\Models\HealthCashierShift::class => [
            'event' => 'payment.shift', 'category' => 'payment',
            'amount' => 'counted_cash', 'reason' => ['note'],
            'scope' => ['user_id' => 'users'],
        ],

        // ── Pharmacy ──────────────────────────────────────────────────────
        \App\Models\HealthMedicineBatch::class => [
            'event' => 'stock.batch', 'category' => 'stock',
            'label' => 'batch_no', 'reason' => ['quarantine_reason'],
        ],
        \App\Models\HealthPharmacySale::class => [
            'event' => 'stock.sale', 'category' => 'stock',
            'label' => 'sale_number', 'amount' => 'total_amount',
        ],
        \App\Models\HealthPharmacyReturn::class => [
            'event' => 'stock.return', 'category' => 'stock',
            'label' => 'return_number', 'amount' => 'total_amount',
            'reason' => ['reason'],
        ],
        \App\Models\HealthMedicine::class => [
            'event' => 'stock.medicine', 'category' => 'stock',
            'label' => 'name',
        ],

        // ── The books, and what the doctors are owed ──────────────────────
        \App\Models\HealthJournal::class => [
            'event' => 'accounts.journal', 'category' => 'accounts',
            'label' => 'journal_no', 'amount' => 'total_debit',
            'reason' => ['reversal_reason', 'memo'],
        ],
        \App\Models\HealthExpense::class => [
            'event' => 'accounts.expense', 'category' => 'accounts',
            'label' => 'expense_no', 'amount' => 'total_amount',
            'reason' => ['reversal_reason', 'description'],
        ],
        \App\Models\HealthDoctorShare::class => [
            'event' => 'accounts.doctor_share', 'category' => 'accounts',
            'amount' => 'share_amount', 'doctor' => 'health_doctor_id',
            'patient' => 'health_patient_id',
            'reason' => ['exclusion_reason', 'reversal_reason'],
        ],
        \App\Models\HealthDoctorShareRule::class => [
            'event' => 'accounts.share_rule', 'category' => 'accounts',
            'label' => 'name', 'doctor' => 'health_doctor_id',
        ],
        \App\Models\HealthDoctorSettlement::class => [
            'event' => 'accounts.settlement', 'category' => 'accounts',
            'label' => 'settlement_no', 'amount' => 'net_amount',
            'doctor' => 'health_doctor_id',
            'reason' => ['reversal_reason', 'deduction_reason'],
            'scope' => ['health_doctor_id' => 'health_doctors'],
        ],
        \App\Models\HealthFundTransfer::class => [
            'event' => 'accounts.transfer', 'category' => 'accounts',
            'label' => 'reference', 'amount' => 'amount',
        ],
        \App\Models\HealthFiscalPeriod::class => [
            'event' => 'accounts.period', 'category' => 'accounts',
            'label' => 'name',
        ],
        \App\Models\HealthAccountReconciliation::class => [
            'event' => 'accounts.reconciliation', 'category' => 'accounts',
            'amount' => 'closing_balance',
        ],

        // ── Attendance, where payroll is quietly decided ──────────────────
        \App\Models\HealthAttendanceCorrection::class => [
            'event' => 'hr.correction', 'category' => 'hr',
            'reason' => ['reason', 'review_note'],
            'scope' => ['user_id' => 'users'],
        ],
        \App\Models\HealthAttendancePunch::class => [
            'event' => 'hr.punch', 'category' => 'hr',
            'reason' => ['disregard_reason', 'note'],
        ],
        \App\Models\HealthAttendanceDay::class => [
            'event' => 'hr.attendance_day', 'category' => 'hr',
        ],
        \App\Models\HealthLeaveRequest::class => [
            'event' => 'hr.leave', 'category' => 'hr',
            'reason' => ['reason', 'review_note'],
            'scope' => ['user_id' => 'users'],
        ],
        \App\Models\HealthStaffProfile::class => [
            'event' => 'hr.staff_profile', 'category' => 'hr',
            'scope' => ['user_id' => 'users'],
        ],

        // ── The shape of the organisation itself ──────────────────────────
        \App\Models\HealthDoctor::class => [
            'event' => 'access.doctor', 'category' => 'access',
            'label' => 'name', 'doctor' => 'id',
        ],
        \App\Models\HealthDepartment::class => [
            'event' => 'access.department', 'category' => 'access',
            'label' => 'name',
        ],
    ];

    /** Register every mapped model against this observer. */
    public static function registerAll(): void
    {
        foreach (array_keys(self::MAP) as $model) {
            if (class_exists($model)) {
                $model::observe(static::class);
            }
        }
    }

    public function created($model): void
    {
        self::write('created', $model, null);
    }

    public function updated($model): void
    {
        // The RAW original is the row as it was READ, in the same shape as
        // getAttributes() gives the after-image (getOriginal() would hand back
        // cast values — arrays for JSON columns — and the diff would compare an
        // array against its own JSON string). So the diff is genuinely
        // before/after, column for column.
        self::write('updated', $model, $model->getRawOriginal());
    }

    public function deleted($model): void
    {
        self::write('deleted', $model, $model->getRawOriginal());
    }

    /**
     * Write the event.
     *
     * Recording must never break the act being recorded — the recorder already
     * swallows its own failures, and a missing event is visible later through
     * the chain verifier. A refused save because the trail had a bad day would
     * be a hospital that cannot bill.
     */
    protected static function write(string $action, $model, ?array $original): void
    {
        $descriptor = self::MAP[get_class($model)] ?? null;

        if (!$descriptor) {
            return;
        }

        $attributes = $model->getAttributes();
        [$branchId, $departmentId] = self::scopeFor($descriptor, $attributes);

        HealthAuditRecorder::recordModelChange(
            $descriptor['event'] . '.' . $action,
            $model,
            $original,
            [
                'company_id' => $attributes['company_id'] ?? null,
                'branch_id' => $branchId,
                'health_department_id' => $departmentId,
                'category' => $descriptor['category'],
                'action' => $action,
                'entity_type' => $model->getTable(),
                'entity_id' => $model->getKey(),
                'entity_label' => self::labelFor($descriptor, $attributes, $model),
                'health_patient_id' => self::column($descriptor, 'patient', $attributes),
                'health_doctor_id' => self::column($descriptor, 'doctor', $attributes),
                'amount' => self::column($descriptor, 'amount', $attributes),
                'reason' => self::reasonFor($descriptor, $attributes),
                'sensitive' => self::isSensitive($descriptor, $attributes),
                'fields' => $descriptor['fields'] ?? null,
            ]
        );
    }

    /**
     * The branch and department this act is filed under.
     *
     * The row's own columns win. Where the row has none, the first parent in
     * the descriptor's `scope` list that is actually set supplies them — a
     * receipt takes its bill's ward, a shift its cashier's posting. A row with
     * neither is genuinely organisation-wide (a patient, a journal that spans
     * wards) and is filed with NULL, which the readers treat as shared.
     *
     * One indexed primary-key lookup per save, and only for rows that lack a
     * column of their own. Deliberately NOT memoised: a process-wide memo
     * keyed by id outlives the row in long-running workers and test suites.
     *
     * @return array{0:int|null,1:int|null}
     */
    protected static function scopeFor(array $descriptor, array $attributes): array
    {
        $branchId = isset($attributes['branch_id']) ? (int) $attributes['branch_id'] : null;
        $departmentId = isset($attributes['health_department_id']) ? (int) $attributes['health_department_id'] : null;

        $scope = $descriptor['scope'] ?? [];
        if (!$scope || ($branchId && $departmentId)) {
            return [$branchId ?: null, $departmentId ?: null];
        }

        foreach ($scope as $foreignKey => $table) {
            $parentId = (int) ($attributes[$foreignKey] ?? 0);
            if (!$parentId) {
                continue;
            }

            $parent = self::parentScope($table, $parentId);
            if ($parent === null) {
                continue;
            }

            $branchId = $branchId ?: ($parent[0] ?: null);
            $departmentId = $departmentId ?: ($parent[1] ?: null);
            break;
        }

        return [$branchId ?: null, $departmentId ?: null];
    }

    /** @return array{0:int|null,1:int|null}|null */
    protected static function parentScope(string $table, int $id): ?array
    {
        // A staff member's posting lives under different column names.
        $branchColumn = $table === 'users' ? 'default_branch_id' : 'branch_id';
        $departmentColumn = 'health_department_id';

        try {
            $columns = [];
            if (Schema::hasColumn($table, $branchColumn)) {
                $columns[] = $branchColumn;
            }
            if (Schema::hasColumn($table, $departmentColumn)) {
                $columns[] = $departmentColumn;
            }

            $row = $columns ? DB::table($table)->where('id', $id)->first($columns) : null;

            return $row
                ? [(int) ($row->{$branchColumn} ?? 0) ?: null, (int) ($row->{$departmentColumn} ?? 0) ?: null]
                : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected static function labelFor(array $descriptor, array $attributes, $model)
    {
        $label = isset($descriptor['label']) ? ($attributes[$descriptor['label']] ?? null) : null;

        return $label !== null && $label !== '' ? (string) $label : ('#' . $model->getKey());
    }

    protected static function column(array $descriptor, string $key, array $attributes)
    {
        if (!isset($descriptor[$key])) {
            return null;
        }

        return $attributes[$descriptor[$key]] ?? null;
    }

    /**
     * The first reason column that actually has something in it.
     *
     * "Why" is the single field that turns a reversal from a red flag into a
     * closed question, so it is lifted out of the payload and onto the event
     * where the checks and the timeline can both see it.
     */
    protected static function reasonFor(array $descriptor, array $attributes): ?string
    {
        foreach ($descriptor['reason'] ?? [] as $column) {
            $value = $attributes[$column] ?? null;

            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * Does this act touch a file the patient asked be kept private?
     *
     * Only the patient row itself carries the flag, so a change to a
     * confidential patient is marked and everything hanging off that patient is
     * marked by the explicit view recorder instead — guessing here would mean
     * an extra query on every single save.
     */
    protected static function isSensitive(array $descriptor, array $attributes): bool
    {
        return !empty($attributes['is_confidential']);
    }
}
