<?php

namespace App\Http\Controllers\Health;

use App\Models\HealthAdmission;
use App\Models\HealthBill;
use App\Models\HealthCashierShift;
use App\Models\HealthCharge;
use App\Models\HealthPatient;
use App\Models\HealthPayment;
use App\Models\HealthTaxCategory;
use App\Services\HealthBillFbrService;
use App\Services\HealthBillingReportService;
use App\Services\HealthBillingService;
use App\Services\HealthChargeIngestService;
use App\Services\HealthChargeService;
use App\Services\HealthRecordAccessService;
use App\Services\HealthScopeService;
use App\Services\HealthTaxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * The billing counter.
 *
 * One screen family for every kind of healthcare money: the OPD fee, the
 * pharmacy sale, the lab test, the ward's daily charges, the theatre, and
 * anything a cashier posts by hand. They all arrive on the same ledger, leave
 * on the same bill, and reconcile against the same shift.
 *
 * Three capabilities meet here and are kept apart on purpose:
 *
 *   billing.view      see the counter, a patient's account, a bill, a receipt
 *   billing.charge    move money — post charges, raise bills, take payments
 *   accounts.manage   the tax rulebook, the day-close, cancelling a bill
 *
 * The split is what lets a receptionist read a patient's outstanding balance
 * without being able to waive it, and lets a cashier take money without being
 * able to decide what the regulator is told.
 */
class HealthBillingController extends HealthPanelController
{
    /**
     * The counter: today's bills, today's money, and a patient search.
     */
    public function index(Request $request)
    {
        $this->require('billing.view');
        $company = $this->company();
        $companyId = (int) $company->id;

        $search = trim((string) $request->query('q', ''));
        $patients = collect();
        if ($search !== '') {
            $query = HealthPatient::query()
                ->where(function ($q) use ($search) {
                    $digits = preg_replace('/\D+/', '', $search);
                    $q->where('name', 'like', '%' . $search . '%')
                        ->orWhere('mrn', 'like', '%' . $search . '%');
                    if ($digits !== '') {
                        $q->orWhere('phone_digits', 'like', '%' . $digits . '%');
                    }
                })
                ->orderByDesc('id')
                ->limit(25);
            HealthScopeService::applyBranchScope($query, $this->user());
            HealthRecordAccessService::hideConfidential($query, $this->user(), 'id');
            $patients = $query->get();
        }

        $billQuery = HealthBill::query()
            ->with(['patient:id,name,mrn', 'department:id,name'])
            ->orderByDesc('id')
            ->limit(60);
        $this->scope($billQuery);

        $status = $request->query('status');
        if (in_array($status, HealthBill::STATUSES, true)) {
            $billQuery->where('status', $status);
        } elseif ($status === 'fbr_pending') {
            $billQuery->where('fbr_eligible', true)->whereNull('fbr_invoice_number');
        }

        $bills = $billQuery->get();

        $today = HealthBillingReportService::daySummary($companyId, now()->toDateString(), null);
        $shift = HealthBillingService::openShiftFor($companyId, $this->user());

        return view('health.billing.index', [
            'search' => $search,
            'patients' => $patients,
            'bills' => $bills,
            'today' => $today,
            'shift' => $shift,
            'status' => $status,
            'mayCharge' => $this->can('billing.charge'),
            'mayManage' => $this->can('accounts.manage'),
        ]);
    }

    /**
     * One patient's whole financial picture.
     *
     * The ledger is refreshed from the other modules on the way in, so a cashier
     * never has to remember to press a "sync" button before billing somebody —
     * a pharmacy sale made two minutes ago is already on the account.
     */
    public function patient(Request $request, int $id)
    {
        $this->require('billing.view');
        $company = $this->company();
        $companyId = (int) $company->id;

        $patient = $this->findPatient($id);

        if ($this->can('billing.charge')) {
            HealthChargeIngestService::syncPatient($companyId, (int) $patient->id, $this->user());
        }

        $account = HealthBillingService::patientAccount($companyId, (int) $patient->id);

        $admissions = Schema::hasTable('health_admissions')
            ? HealthAdmission::query()
                ->where('health_patient_id', $patient->id)
                ->orderByDesc('id')
                ->limit(10)
                ->get()
            : collect();

        return view('health.billing.patient', [
            'patient' => $patient,
            'account' => $account,
            'admissions' => $admissions,
            'departments' => $this->departments(),
            'taxRules' => HealthTaxService::allRules($companyId),
            'shift' => HealthBillingService::openShiftFor($companyId, $this->user()),
            'mayCharge' => $this->can('billing.charge'),
            'mayManage' => $this->can('accounts.manage'),
        ]);
    }

    /** Pull anything the other modules have produced since the last look. */
    public function syncCharges(Request $request, int $id)
    {
        $this->require('billing.charge');
        $patient = $this->findPatient($id);

        $result = HealthChargeIngestService::syncPatient((int) $this->company()->id, (int) $patient->id, $this->user());

        return back()->with('success', __('health.led_synced', [
            'posted' => $result['posted'],
            'scanned' => $result['scanned'],
        ]));
    }

    /** Post a charge by hand — a service the modules do not produce. */
    public function storeCharge(Request $request, int $id)
    {
        $this->require('billing.charge');
        $patient = $this->findPatient($id);

        $data = $request->validate([
            'category' => ['required', Rule::in(HealthCharge::MANUAL_CATEGORIES)],
            'description' => ['required', 'string', 'max:300'],
            'reference' => ['nullable', 'string', 'max:120'],
            'health_department_id' => ['nullable', 'integer'],
            'health_admission_id' => ['nullable', 'integer'],
            'health_tax_category_id' => ['nullable', 'integer'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'concession_amount' => ['nullable', 'numeric', 'min:0'],
            'concession_reason' => ['nullable', 'string', 'max:300'],
            'charge_date' => ['nullable', 'date'],
        ]);

        $charge = HealthChargeService::post([
            'company_id' => (int) $this->company()->id,
            'branch_id' => $patient->branch_id ?? ($this->user()->branch_id ?? null),
            'health_department_id' => $data['health_department_id'] ?? null,
            'health_patient_id' => (int) $patient->id,
            'health_admission_id' => $data['health_admission_id'] ?? null,
            'charge_date' => $data['charge_date'] ?? now()->toDateString(),
            'category' => $data['category'],
            'description' => $data['description'],
            'reference' => $data['reference'] ?? null,
            'source_type' => HealthCharge::SOURCE_MANUAL,
            'unit_price' => (float) $data['unit_price'],
            'quantity' => (float) $data['quantity'],
            'concession_amount' => (float) ($data['concession_amount'] ?? 0),
            'concession_reason' => $data['concession_reason'] ?? null,
            'health_tax_category_id' => $data['health_tax_category_id'] ?? null,
            // Hand-posted charges carry no dedupe key: two identical services
            // really can be given twice in one day, and refusing the second is
            // worse than letting the counter reverse a mistake.
        ], $this->user());

        if (!$charge) {
            return back()->withInput()->with('error', __('health.led_post_failed'));
        }

        return back()->with('success', __('health.led_posted', ['no' => $charge->charge_no]));
    }

    public function reverseCharge(Request $request, int $id)
    {
        $this->require('billing.charge');
        $charge = $this->findCharge($id);

        $result = HealthChargeService::reverse($charge, $this->user(), (string) $request->input('reason', ''));
        if (!$result['ok']) {
            return back()->with('error', __('health.led_err_' . $result['reason']));
        }

        return back()->with('success', __('health.led_reversed', ['no' => $charge->charge_no]));
    }

    public function concession(Request $request, int $id)
    {
        $this->require('billing.charge');
        $charge = $this->findCharge($id);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:300'],
        ]);

        $result = HealthChargeService::applyConcession($charge, (float) $data['amount'], $data['reason'], $this->user());
        if (!$result['ok']) {
            return back()->with('error', __('health.led_err_' . $result['reason']));
        }

        return back()->with('success', __('health.led_concession_saved'));
    }

    /**
     * Move a charge onto a different tax rule.
     *
     * accounts.manage, not billing.charge: changing what the regulator is told
     * is an accounting decision, not a counter one.
     */
    public function reclassify(Request $request, int $id)
    {
        $this->require('accounts.manage');
        $charge = $this->findCharge($id);

        $data = $request->validate([
            'health_tax_category_id' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string', 'max:300'],
        ]);

        $result = HealthChargeService::reclassify(
            $charge,
            $data['health_tax_category_id'] ?? null,
            $this->user(),
            (string) ($data['reason'] ?? '')
        );

        if (!$result['ok']) {
            return back()->with('error', __('health.led_err_' . $result['reason']));
        }

        return back()->with('success', __('health.led_reclassified'));
    }

    /** Raise a bill (or an estimate) from the selected charges. */
    public function storeBill(Request $request, int $id)
    {
        $this->require('billing.charge');
        $patient = $this->findPatient($id);

        $data = $request->validate([
            'charge_ids' => ['required', 'array', 'min:1'],
            'charge_ids.*' => ['integer'],
            'doc_type' => ['nullable', Rule::in(HealthBill::TYPES)],
            'scope' => ['nullable', Rule::in(HealthBill::SCOPES)],
            'payer_type' => ['nullable', Rule::in(HealthBill::PAYER_TYPES)],
            'payer_name' => ['nullable', 'string', 'max:150'],
            'payer_reference' => ['nullable', 'string', 'max:80'],
            'insurance_amount' => ['nullable', 'numeric', 'min:0'],
            'corporate_amount' => ['nullable', 'numeric', 'min:0'],
            'health_admission_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $result = HealthBillingService::createBill(
            (int) $this->company()->id,
            (int) $patient->id,
            $data['charge_ids'],
            $data,
            $this->user()
        );

        if (!$result['ok']) {
            return back()->withInput()->with('error', __('health.bill_err_' . $result['reason']));
        }

        return redirect()->route('health.billing.bill', $result['bill']->id)
            ->with('success', __('health.bill_created', ['no' => $result['bill']->bill_no]));
    }

    /** Sweep a whole stay onto one final settlement bill. */
    public function settleAdmission(Request $request, int $id, int $admissionId)
    {
        $this->require('billing.charge');
        $patient = $this->findPatient($id);

        $result = HealthBillingService::settleAdmission(
            (int) $this->company()->id,
            (int) $patient->id,
            $admissionId,
            $request->only(['payer_type', 'payer_name', 'payer_reference', 'insurance_amount', 'corporate_amount']),
            $this->user()
        );

        if (!$result['ok']) {
            return back()->with('error', __('health.bill_err_' . $result['reason']));
        }

        return redirect()->route('health.billing.bill', $result['bill']->id)
            ->with('success', __('health.bill_created', ['no' => $result['bill']->bill_no]));
    }

    public function bill(Request $request, int $id)
    {
        $this->require('billing.view');
        $bill = $this->findBill($id);

        // A retry that succeeded through the shared sync job must show up here
        // rather than leaving the screen insisting the filing failed.
        if ($bill->fbr_pos_transaction_id && !$bill->fbr_invoice_number) {
            HealthBillFbrService::reconcile($bill);
            $bill->refresh();
        }

        return view('health.billing.bill', [
            'bill' => $bill->load(['lines', 'payments', 'patient', 'department']),
            'account' => HealthBillingService::patientAccount((int) $bill->company_id, (int) $bill->health_patient_id),
            'eligibility' => HealthBillFbrService::eligibility($bill),
            'submissions' => $bill->submissions()->limit(20)->get(),
            'mayCharge' => $this->can('billing.charge'),
            'mayManage' => $this->can('accounts.manage'),
        ]);
    }

    public function finalize(Request $request, int $id)
    {
        $this->require('billing.charge');
        $bill = $this->findBill($id);

        $result = HealthBillingService::finalize($bill, $this->user());
        if (!$result['ok']) {
            return back()->with('error', __('health.bill_err_' . $result['reason']));
        }

        return back()->with('success', __('health.bill_finalized', ['no' => $bill->bill_no]));
    }

    public function cancelBill(Request $request, int $id)
    {
        $this->require('accounts.manage');
        $bill = $this->findBill($id);

        $result = HealthBillingService::cancel($bill, $this->user(), (string) $request->input('reason', ''));
        if (!$result['ok']) {
            return back()->with('error', __('health.bill_err_' . $result['reason']));
        }

        return back()->with('success', __('health.bill_cancelled'));
    }

    /** Take money against a bill. */
    public function pay(Request $request, int $id)
    {
        $this->require('billing.charge');
        $bill = $this->findBill($id);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in(HealthPayment::METHODS)],
            'kind' => ['nullable', Rule::in([
                HealthPayment::KIND_PAYMENT,
                HealthPayment::KIND_INSURANCE,
                HealthPayment::KIND_CORPORATE,
            ])],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $result = HealthBillingService::recordPayment(
            (int) $bill->company_id,
            (int) $bill->health_patient_id,
            array_merge($data, ['health_bill_id' => $bill->id]),
            $this->user()
        );

        if (!$result['ok']) {
            return back()->withInput()->with('error', __('health.pay_err_' . $result['reason']));
        }

        $message = __('health.pay_recorded', ['no' => $result['payment']->receipt_no]);

        // The counter has to be told the change was kept as credit, or they will
        // hand the cash back a second time out of the drawer.
        if (round((float) ($result['credited'] ?? 0), 2) > 0) {
            $message .= ' ' . __('health.pay_surplus_note', [
                'amount' => number_format((float) $result['credited'], 2),
            ]);
        }

        return back()->with('success', $message);
    }

    /** Take an advance with no bill behind it yet. */
    public function deposit(Request $request, int $id)
    {
        $this->require('billing.charge');
        $patient = $this->findPatient($id);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in(HealthPayment::METHODS)],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:300'],
            'health_admission_id' => ['nullable', 'integer'],
        ]);

        $result = HealthBillingService::recordPayment(
            (int) $this->company()->id,
            (int) $patient->id,
            array_merge($data, ['kind' => HealthPayment::KIND_DEPOSIT]),
            $this->user()
        );

        if (!$result['ok']) {
            return back()->withInput()->with('error', __('health.pay_err_' . $result['reason']));
        }

        return back()->with('success', __('health.pay_deposit_taken', ['no' => $result['payment']->receipt_no]));
    }

    public function refund(Request $request, int $id)
    {
        $this->require('accounts.manage');
        $bill = $this->findBill($id);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in(HealthPayment::METHODS)],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $result = HealthBillingService::refund($bill, (float) $data['amount'], $data, $this->user());
        if (!$result['ok']) {
            return back()->withInput()->with('error', __('health.pay_err_' . $result['reason']));
        }

        return back()->with('success', __('health.pay_refunded', ['no' => $result['payment']->receipt_no]));
    }

    public function applyCredit(Request $request, int $id)
    {
        $this->require('billing.charge');
        $bill = $this->findBill($id);

        $result = HealthBillingService::applyCredit($bill, $this->user());
        if (!$result['ok']) {
            return back()->with('error', __('health.pay_err_' . $result['reason']));
        }

        return back()->with('success', __('health.pay_credit_applied', [
            'amount' => number_format((float) ($result['applied'] ?? 0), 2),
        ]));
    }

    public function reversePayment(Request $request, int $id)
    {
        $this->require('accounts.manage');

        $payment = $this->findPayment($id);

        HealthBillingService::reversePayment($payment, $this->user(), (string) $request->input('reason', ''));

        return back()->with('success', __('health.pay_reversed'));
    }

    /* ── Documents ─────────────────────────────────────────────────────── */

    /**
     * The printed receipt.
     *
     * Standalone document, no panel chrome: it is printed on a thermal roll or
     * on A4 and handed over. `size` picks the paper — 58mm, 80mm or A4 — and
     * everything else about it is identical, because a receipt that says one
     * thing on the roll and another on the sheet is not a receipt.
     */
    public function receipt(Request $request, int $id)
    {
        $this->require('billing.view');
        $bill = $this->findBill($id);

        $size = $request->query('size', '80');
        if (!in_array($size, ['58', '80', 'a4'], true)) {
            $size = '80';
        }

        return view('health.billing.receipt', [
            'bill' => $bill->load(['lines', 'payments', 'patient', 'department']),
            'company' => $this->company(),
            'size' => $size,
        ]);
    }

    /** The patient's account statement — every bill, every receipt, one sheet. */
    public function statement(Request $request, int $id)
    {
        $this->require('billing.view');
        $patient = $this->findPatient($id);

        return view('health.billing.statement', [
            'patient' => $patient,
            'company' => $this->company(),
            'account' => HealthBillingService::patientAccount((int) $this->company()->id, (int) $patient->id),
        ]);
    }

    /* ── FBR ───────────────────────────────────────────────────────────── */

    public function fbr(Request $request, int $id)
    {
        $this->require('billing.view');
        $bill = $this->findBill($id);

        HealthBillFbrService::reconcile($bill);
        $bill->refresh();

        return view('health.billing.fbr', [
            'bill' => $bill,
            'submissions' => $bill->submissions()->get(),
            'reportable' => HealthBillFbrService::reportableLines($bill),
            'eligibility' => HealthBillFbrService::eligibility($bill),
            'mayManage' => $this->can('accounts.manage'),
        ]);
    }

    public function submitFbr(Request $request, int $id)
    {
        $this->require('accounts.manage');
        $bill = $this->findBill($id);

        $result = HealthBillFbrService::submit($bill, $this->user());

        if (!empty($result['ok'])) {
            return back()->with('success', __('health.fbr_filed', ['no' => $result['invoice_number']]));
        }

        if (!empty($result['reason'])) {
            return back()->with('error', __('health.fbr_err_' . $result['reason']));
        }

        return back()->with('error', $result['message'] ?: __('health.fbr_err_failed'));
    }

    public function reconcileFbr(Request $request, int $id)
    {
        $this->require('billing.view');
        $bill = $this->findBill($id);

        $changed = HealthBillFbrService::reconcile($bill);

        return back()->with('success', $changed ? __('health.fbr_reconciled') : __('health.fbr_no_change'));
    }

    /* ── Shifts and reconciliation ─────────────────────────────────────── */

    public function shifts(Request $request)
    {
        $this->require('billing.view');
        $companyId = (int) $this->company()->id;

        $query = HealthCashierShift::query()->with('user:id,name')->orderByDesc('id')->limit(60);
        HealthScopeService::applyBranchScope($query, $this->user());
        // A cashier sees their own drawer; accounts sees the whole counter.
        if (!$this->can('accounts.view')) {
            $query->where('user_id', $this->user()->id);
        }

        $shifts = $query->get();
        $open = HealthBillingService::openShiftFor($companyId, $this->user());

        return view('health.billing.shifts', [
            'shifts' => $shifts,
            'open' => $open,
            'openTotals' => $open ? HealthBillingReportService::shiftTotals($open) : null,
            'mayCharge' => $this->can('billing.charge'),
        ]);
    }

    public function openShift(Request $request)
    {
        $this->require('billing.charge');

        $data = $request->validate([
            'opening_float' => ['nullable', 'numeric', 'min:0'],
        ]);

        HealthBillingReportService::openShift(
            (int) $this->company()->id,
            $this->user(),
            (float) ($data['opening_float'] ?? 0),
            $this->user()->branch_id ?? null
        );

        return back()->with('success', __('health.shift_opened'));
    }

    public function closeShift(Request $request, int $id)
    {
        $this->require('billing.charge');

        $shift = $this->findShift($id);
        // A drawer is closed by the person who opened it, or by accounts. Nobody
        // else gets to declare somebody else's cash counted.
        if ((int) $shift->user_id !== (int) $this->user()->id && !$this->can('accounts.manage')) {
            abort(403, __('health.denied_no_permission'));
        }

        $data = $request->validate([
            'counted_cash' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $result = HealthBillingReportService::closeShift(
            $shift,
            array_key_exists('counted_cash', $data) && $data['counted_cash'] !== null
                ? (float) $data['counted_cash']
                : null,
            $this->user(),
            (string) ($data['note'] ?? '')
        );

        if (!$result['ok']) {
            return back()->with('error', __('health.shift_err_' . $result['reason']));
        }

        return back()->with('success', __('health.shift_closed'));
    }

    /** The day's reconciliation: billed, collected, filed — all from one truth. */
    public function dayClose(Request $request)
    {
        $this->require('accounts.view');
        $companyId = (int) $this->company()->id;

        $date = $request->query('date', now()->toDateString());
        $branchId = $request->filled('branch_id') ? (int) $request->query('branch_id') : null;

        $from = $request->query('from', $date);
        $to = $request->query('to', $date);

        return view('health.billing.day-close', [
            'date' => $date,
            'from' => $from,
            'to' => $to,
            'branchId' => $branchId,
            'branches' => $this->branches(),
            'summary' => HealthBillingReportService::daySummary($companyId, $date, $branchId),
            'departments' => HealthBillingReportService::departmentBreakdown($companyId, $from, $to, $branchId),
            'categories' => HealthBillingReportService::categoryBreakdown($companyId, $from, $to, $branchId),
        ]);
    }

    /* ── The tax rulebook ──────────────────────────────────────────────── */

    public function taxCategories(Request $request)
    {
        $this->require('accounts.manage');
        $companyId = (int) $this->company()->id;

        return view('health.billing.tax-categories', [
            'rules' => HealthTaxService::allRules($companyId),
            'categories' => HealthCharge::CATEGORIES,
        ]);
    }

    public function storeTaxCategory(Request $request)
    {
        $this->require('accounts.manage');

        $data = $this->validateTaxCategory($request);
        $data['company_id'] = (int) $this->company()->id;
        $data['created_by'] = $this->user()->id ?? null;

        $rule = HealthTaxCategory::query()->create($data);
        $this->keepSingleDefault($rule);

        return back()->with('success', __('health.taxcat_saved'));
    }

    public function updateTaxCategory(Request $request, int $id)
    {
        $this->require('accounts.manage');

        $rule = HealthTaxCategory::query()->find($id);
        if (!$rule) {
            abort(404, __('health.taxcat_not_found'));
        }

        $rule->update($this->validateTaxCategory($request, $id));
        $this->keepSingleDefault($rule->fresh());

        return back()->with('success', __('health.taxcat_saved'));
    }

    /**
     * Retire or revive a rule.
     *
     * Never deleted: charges already carry its id, and a receipt that can no
     * longer say which rule it was billed under is a receipt that cannot be
     * defended.
     */
    public function toggleTaxCategory(Request $request, int $id)
    {
        $this->require('accounts.manage');

        $rule = HealthTaxCategory::query()->find($id);
        if (!$rule) {
            abort(404, __('health.taxcat_not_found'));
        }

        $rule->update(['is_active' => !$rule->is_active]);

        return back()->with('success', __('health.taxcat_saved'));
    }

    /** Give a hospital with no rulebook a starting point it can edit. */
    public function seedTaxCategories(Request $request)
    {
        $this->require('accounts.manage');

        $made = HealthTaxService::seedDefaults((int) $this->company()->id, $this->user()->id ?? null);

        return back()->with('success', $made > 0
            ? __('health.taxcat_seeded', ['count' => $made])
            : __('health.taxcat_seed_skipped'));
    }

    /* ── Helpers ───────────────────────────────────────────────────────── */

    private function validateTaxCategory(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:40'],
            'treatment' => ['required', Rule::in(HealthTaxCategory::TREATMENTS)],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'pct_code' => ['nullable', 'string', 'max:8'],
            'sro_reference' => ['nullable', 'string', 'max:80'],
            'applies_to' => ['nullable', 'array'],
            'applies_to.*' => [Rule::in(HealthCharge::CATEGORIES)],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:300'],
        ]);

        // Only an FBR rule may carry a rate. Storing 18% against a local rule
        // would leave a number on screen that never applies to anything, which
        // is exactly how somebody later concludes the software "lost" the tax.
        if ($data['treatment'] !== HealthTaxCategory::TREATMENT_FBR) {
            $data['tax_rate'] = 0;
        }

        $data['applies_to'] = array_values($data['applies_to'] ?? []);
        $data['is_default'] = (bool) ($data['is_default'] ?? false);
        $data['is_active'] = (bool) ($data['is_active'] ?? true);

        return $data;
    }

    /** Exactly one default rule per company, or "the default" means nothing. */
    private function keepSingleDefault(?HealthTaxCategory $rule): void
    {
        if (!$rule || !$rule->is_default) {
            return;
        }

        HealthTaxCategory::query()
            ->where('id', '!=', $rule->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    private function departments()
    {
        if (!Schema::hasTable('health_departments')) {
            return collect();
        }

        return \App\Models\HealthDepartment::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    private function findPatient(int $id): HealthPatient
    {
        $query = HealthPatient::query()->where('id', $id);
        HealthScopeService::applyBranchScope($query, $this->user());
        HealthRecordAccessService::hideConfidential($query, $this->user(), 'id');

        $patient = $query->first();
        if (!$patient) {
            abort(404, __('health.patient_not_found'));
        }

        return $patient;
    }

    /*
     * Every single-record lookup below goes through the SAME branch and
     * department boundary the list screens use. Company isolation alone is not
     * enough: two branches of one hospital are two different cash counters, and
     * a guessed id must not let a counter in one branch read — or worse, edit,
     * pay or reverse — the other branch's money.
     *
     * The boundary is expressed as "not found" rather than "forbidden" so an id
     * outside the caller's branch does not confirm that it exists.
     */

    private function findCharge(int $id): HealthCharge
    {
        $query = HealthCharge::query()->where('id', $id);
        $this->scope($query);

        $charge = $query->first();
        if (!$charge) {
            abort(404, __('health.led_not_found'));
        }

        return $charge;
    }

    private function findBill(int $id): HealthBill
    {
        $query = HealthBill::query()->where('id', $id);
        $this->scope($query);

        $bill = $query->first();
        if (!$bill) {
            abort(404, __('health.bill_not_found'));
        }

        return $bill;
    }

    /** Receipts carry no department of their own — branch is the boundary. */
    private function findPayment(int $id): HealthPayment
    {
        $query = HealthPayment::query()->where('id', $id);
        HealthScopeService::applyBranchScope($query, $this->user());

        $payment = $query->first();
        if (!$payment) {
            abort(404, __('health.pay_not_found'));
        }

        return $payment;
    }

    /** A drawer belongs to a branch; another branch's drawer is not visible. */
    private function findShift(int $id): HealthCashierShift
    {
        $query = HealthCashierShift::query()->where('id', $id);
        HealthScopeService::applyBranchScope($query, $this->user());

        $shift = $query->first();
        if (!$shift) {
            abort(404, __('health.shift_not_found'));
        }

        return $shift;
    }
}
