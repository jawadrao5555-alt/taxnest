<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HealthPharmacyContext;
use App\Models\HealthMedicine;
use App\Models\HealthPharmacySale;
use App\Models\HealthPrescription;
use App\Models\HealthPrescriptionItem;
use App\Services\BranchStockService;
use App\Services\HealthPharmacyCheckoutService;
use App\Services\HealthPharmacyReportService;
use App\Services\HealthPharmacyStockService;
use App\Services\HealthScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Prescriptions and dispensing (Task 1549).
 *
 * A prescription here is a DISPENSING instruction, not a clinical record: the
 * patient identity is a snapshot taken at intake and no diagnosis is stored
 * (out of scope by design). `patient_id` is carried so the patients module can
 * adopt these rows later without a data migration.
 *
 * Partial fills are the normal case. `dispensed_quantity` only grows and the
 * header status is recomputed from the lines, so "kitna baqi hai" is one
 * derived number every screen agrees on.
 */
class HealthPharmacyPrescriptionController extends Controller
{
    use HealthPharmacyContext;

    public function index(Request $request)
    {
        $companyId = $this->companyId();
        $branchId = $this->viewBranchId();
        $allBranches = BranchStockService::viewingAllBranches($companyId);

        $status = $request->query('status', 'open');
        $search = trim((string) $request->query('q', ''));

        $query = HealthPrescription::withoutGlobalScopes()
            ->with(['patient:id,name,mrn', 'doctor:id,name'])
            ->withCount('items')
            ->where('company_id', $companyId)
            ->when(!$allBranches, fn ($q) => $q->where('branch_id', $branchId))
            ->when($search !== '', function ($builder) use ($search) {
                $builder->where(function ($inner) use ($search) {
                    $inner->where('prescription_no', 'like', "%{$search}%")
                        ->orWhere('patient_name', 'like', "%{$search}%")
                        ->orWhere('patient_mr_no', 'like', "%{$search}%")
                        ->orWhere('patient_phone', 'like', "%{$search}%")
                        ->orWhere('doctor_name', 'like', "%{$search}%")
                        // An OPD prescription carries no snapshot — its names
                        // live on the patient and doctor records.
                        ->orWhereHas('patient', function ($patient) use ($search) {
                            $patient->where('name', 'like', "%{$search}%")
                                ->orWhere('mrn', 'like', "%{$search}%");
                        })
                        ->orWhereHas('doctor', fn ($doctor) => $doctor->where('name', 'like', "%{$search}%"));
                });
            });

        // A prescription the doctor has not issued yet is still being written
        // in the consultation room — it is not the pharmacy's to see.
        $query->where('status', '!=', HealthPrescription::STATUS_DRAFT);

        match ($status) {
            'dispensed' => $query->where('dispense_status', HealthPrescription::DISPENSE_DISPENSED),
            'cancelled' => $query->where('dispense_status', HealthPrescription::DISPENSE_CANCELLED),
            'all' => null,
            default => $query->whereIn('dispense_status', [HealthPrescription::DISPENSE_PENDING, HealthPrescription::DISPENSE_PARTIAL]),
        };

        return view('health.pharmacy.prescriptions', [
            'prescriptions' => $query->orderByDesc('id')->paginate(25)->withQueryString(),
            'status' => $status,
            'search' => $search,
            'medicines' => $this->medicineOptions(),
            'departments' => $this->departments(),
            'branches' => $this->branches(),
            'isMultiBranch' => BranchStockService::isMultiBranch($companyId),
            'canDispense' => \App\Services\HealthAccessService::can($this->user(), 'pharmacy.dispense', $this->company()),
        ]);
    }

    public function store(Request $request)
    {
        $this->assertCan('pharmacy.dispense');

        $data = $request->validate([
            'patient_name' => 'required|string|max:190',
            'patient_mr_no' => 'nullable|string|max:64',
            'patient_phone' => 'nullable|string|max:32',
            'patient_age' => 'nullable|string|max:16',
            'patient_gender' => 'nullable|string|max:10',
            'doctor_name' => 'nullable|string|max:190',
            'prescribed_on' => 'nullable|date',
            'health_department_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'general_instructions' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.medicine_id' => 'nullable|integer',
            'items.*.medicine_name' => 'nullable|string|max:190',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.dosage' => 'nullable|string|max:190',
            'items.*.duration' => 'nullable|string|max:64',
            'items.*.instructions' => 'nullable|string|max:500',
        ]);

        $companyId = $this->companyId();
        $picked = $request->filled('branch_id') ? (int) $data['branch_id'] : null;

        if ($picked !== null && !BranchStockService::actorCanUse($companyId, $picked)) {
            return back()->withInput()->with('error', __('health.dept_branch_not_yours'));
        }

        if (!empty($data['health_department_id'])
            && !HealthScopeService::canAccessDepartment($this->user(), (int) $data['health_department_id'])) {
            return back()->withInput()->with('error', __('health.team_department_not_yours'));
        }

        $prescription = DB::transaction(function () use ($companyId, $data, $picked) {
            $prescription = HealthPrescription::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                'branch_id' => $picked ?? $this->viewBranchId(),
                'health_department_id' => $data['health_department_id'] ?? null,
                'prescription_no' => \App\Services\HealthPharmacyService::nextNumber(
                    $companyId,
                    'health_prescriptions',
                    'prescription_no',
                    \App\Services\HealthPharmacyService::PRESCRIPTION_PREFIX
                ),
                'patient_name' => $data['patient_name'],
                'patient_mr_no' => $data['patient_mr_no'] ?? null,
                'patient_phone' => $data['patient_phone'] ?? null,
                'patient_age' => $data['patient_age'] ?? null,
                'patient_gender' => $data['patient_gender'] ?? null,
                'doctor_name' => $data['doctor_name'] ?? null,
                'prescribed_on' => $data['prescribed_on'] ?? now()->toDateString(),
                // Typed at the counter from a slip the doctor already signed:
                // it is issued the moment it exists, and untouched by dispensing.
                'status' => HealthPrescription::STATUS_ISSUED,
                'issued_at' => now(),
                'dispense_status' => HealthPrescription::DISPENSE_PENDING,
                'general_instructions' => $data['general_instructions'] ?? null,
                'created_by' => $this->user()?->id,
            ]);

            $line = 1;

            foreach ($data['items'] as $row) {
                $medicine = !empty($row['medicine_id'])
                    ? HealthMedicine::withoutGlobalScopes()->where('company_id', $companyId)->find((int) $row['medicine_id'])
                    : null;

                $name = trim((string) ($row['medicine_name'] ?? '')) ?: $medicine?->display_name;
                if (!$name) {
                    continue;
                }

                HealthPrescriptionItem::withoutGlobalScopes()->create([
                    'company_id' => $companyId,
                    'health_prescription_id' => $prescription->id,
                    'line_no' => $line++,
                    'medicine_id' => $medicine?->id,
                    'medicine_name' => $name,
                    'quantity' => round((float) $row['quantity'], 3),
                    'dispensed_quantity' => 0,
                    'frequency' => $row['dosage'] ?? $medicine?->default_dosage,
                    'duration' => $row['duration'] ?? null,
                    'instructions' => $row['instructions'] ?? null,
                ]);
            }

            return $prescription;
        });

        return redirect()->route('health.pharmacy.prescriptions.show', $prescription->id)
            ->with('success', __('health.ph_rx_created', ['number' => $prescription->prescription_no]));
    }

    /**
     * The dispensing screen: what was prescribed, what is left, and which lot
     * the counter would pick for it right now.
     */
    public function show($id)
    {
        $companyId = $this->companyId();
        $branchId = $this->viewBranchId();
        $allBranches = BranchStockService::viewingAllBranches($companyId);

        $prescription = HealthPrescription::withoutGlobalScopes()
            ->with(['items.medicine', 'branch', 'department', 'creator', 'patient:id,name,mrn,phone', 'doctor:id,name'])
            ->where('company_id', $companyId)
            ->findOrFail($id);

        // The queue is branch-scoped; an id typed into the URL is not. A slip
        // written at another branch carries that branch's patient details, so
        // it stops here.
        $this->assertBranchVisible($prescription->branch_id !== null ? (int) $prescription->branch_id : null);

        $medicineIds = $prescription->items->pluck('medicine_id')->filter()->unique()->all();
        $available = HealthPharmacyReportService::availableByMedicine($companyId, $branchId, $allBranches, $medicineIds);

        // The FEFO pick, shown BEFORE anything is dispensed so the pharmacist
        // sees the short-dated warning while there is still a choice.
        $suggestions = [];
        foreach ($prescription->items as $item) {
            if (!$item->medicine_id || $item->remaining_quantity <= 0) {
                continue;
            }

            $plan = HealthPharmacyStockService::plan(
                $companyId,
                $item->medicine,
                $item->remaining_quantity,
                $branchId,
                []
            );

            $suggestions[$item->id] = [
                'batches' => array_map(fn ($allocation) => [
                    'id' => (int) $allocation['batch']->id,
                    'batch_no' => $allocation['batch']->batch_no,
                    'expiry' => $allocation['batch']->expiry_date?->toDateString(),
                    'quantity' => $allocation['quantity'],
                ], $plan['allocations']),
                'shortfall' => $plan['shortfall'],
                'warnings' => $plan['warnings'],
            ];
        }

        // A prescription written in the consultation room names a medicine in
        // words — it carries no link to our shelf. The dispenser must still be
        // able to bind one, so each unlinked line is offered the catalogue rows
        // whose name or generic matches what the doctor wrote. Without this the
        // line is readable but permanently un-dispensable.
        $catalogueMatches = [];
        foreach ($prescription->items as $item) {
            if ($item->medicine_id || $item->remaining_quantity <= 0) {
                continue;
            }

            $needle = trim((string) ($item->generic_name ?: $item->medicine_name));
            $word = preg_split('/\s+/', $needle)[0] ?? '';
            if ($word === '') {
                continue;
            }

            $catalogueMatches[$item->id] = HealthMedicine::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->where(function ($builder) use ($word) {
                    $builder->where('name', 'like', "%{$word}%")
                        ->orWhere('generic_name', 'like', "%{$word}%");
                })
                ->orderBy('name')
                ->limit(8)
                ->get(['id', 'name', 'strength', 'sale_price']);
        }

        $substituteMap = DB::table('health_medicine_substitutes')
            ->join('health_medicines', 'health_medicines.id', '=', 'health_medicine_substitutes.substitute_id')
            ->where('health_medicine_substitutes.company_id', $companyId)
            ->whereIn('health_medicine_substitutes.medicine_id', $medicineIds ?: [0])
            ->where('health_medicines.is_active', true)
            ->get([
                'health_medicine_substitutes.medicine_id',
                'health_medicines.id as substitute_id',
                'health_medicines.name',
                'health_medicines.strength',
                'health_medicines.sale_price',
            ])
            ->groupBy('medicine_id');

        return view('health.pharmacy.prescription-show', [
            'prescription' => $prescription,
            'available' => $available,
            'suggestions' => $suggestions,
            'substituteMap' => $substituteMap,
            'catalogueMatches' => $catalogueMatches,
            'settings' => $this->settings(),
            'sales' => HealthPharmacySale::withoutGlobalScopes()
                ->with('items')
                ->where('company_id', $companyId)
                ->where('prescription_id', $prescription->id)
                ->orderByDesc('id')
                ->get(),
            'canDispense' => \App\Services\HealthAccessService::can($this->user(), 'pharmacy.dispense', $this->company()),
        ]);
    }

    /**
     * Dispense against the prescription. A partial fill is a first-class
     * outcome, not an error: whatever is handed over is billed and recorded,
     * and the rest stays owed on the prescription.
     */
    public function dispense(Request $request, $id)
    {
        $this->assertCan('pharmacy.dispense');

        $companyId = $this->companyId();

        $prescription = HealthPrescription::withoutGlobalScopes()
            ->with('items')
            ->where('company_id', $companyId)
            ->findOrFail($id);

        $this->assertBranchVisible($prescription->branch_id !== null ? (int) $prescription->branch_id : null);

        if ($prescription->dispense_status === HealthPrescription::DISPENSE_CANCELLED) {
            return back()->with('error', __('health.ph_rx_cancelled'));
        }

        $request->validate([
            'payment_method' => 'nullable|string|max:24',
            'paid_amount' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'lines' => 'required|array|min:1',
            'lines.*.prescription_item_id' => 'required|integer',
            'lines.*.quantity' => 'nullable|numeric|min:0',
            'lines.*.medicine_id' => 'nullable|integer',
            'lines.*.unit_price' => 'nullable|numeric|min:0',
        ]);

        $items = $prescription->items->keyBy('id');
        $lines = [];

        foreach ($request->input('lines') as $row) {
            $quantity = round((float) ($row['quantity'] ?? 0), 3);
            if ($quantity <= 0) {
                continue;
            }

            $item = $items->get((int) $row['prescription_item_id']);
            if (!$item) {
                continue;
            }

            // A substitution is an explicit act: the line names a different
            // medicine, and the dispenser's own id is stamped as the approval.
            $medicineId = !empty($row['medicine_id']) ? (int) $row['medicine_id'] : $item->medicine_id;
            if (!$medicineId) {
                return back()->with('error', __('health.ph_rx_line_needs_medicine', ['name' => $item->medicine_name]));
            }

            $isSubstitute = $item->medicine_id && $medicineId !== (int) $item->medicine_id;

            if ($quantity > $item->remaining_quantity + 0.0005) {
                return back()->with('error', __('health.ph_rx_over_dispense', ['name' => $item->medicine_name]));
            }

            $lines[] = [
                'medicine_id' => $medicineId,
                'quantity' => $quantity,
                'unit_price' => $row['unit_price'] ?? null,
                'prescription_item_id' => $item->id,
                'is_substitute' => $isSubstitute,
                'substitute_for_medicine_id' => $isSubstitute ? $item->medicine_id : null,
                'dosage_instructions' => $item->instructions ?: $item->frequency,
            ];
        }

        if (!$lines) {
            return back()->with('error', __('health.ph_rx_nothing_to_dispense'));
        }

        try {
            $sale = HealthPharmacyCheckoutService::sell(
                $companyId,
                [
                    'prescription_id' => $prescription->id,
                    'payment_method' => $request->input('payment_method', 'cash'),
                    'paid_amount' => $request->input('paid_amount'),
                    'discount_amount' => $request->input('discount_amount'),
                    'health_department_id' => $prescription->health_department_id,
                    'lines' => $lines,
                ],
                $prescription->branch_id !== null
                    ? $this->writeBranchId((int) $prescription->branch_id)
                    : $this->writeBranchId(),
                $this->user()?->id,
                $this->company()
            );
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return redirect()->route('health.pharmacy.sales.show', $sale->id)
            ->with('success', __('health.ph_dispensed', ['number' => $sale->sale_number]));
    }

    /** Cancelling stops further dispensing; what already went out stays billed. */
    public function cancel($id)
    {
        $this->assertCan('pharmacy.dispense');

        $prescription = HealthPrescription::withoutGlobalScopes()
            ->where('company_id', $this->companyId())
            ->findOrFail($id);

        $this->assertBranchVisible($prescription->branch_id !== null ? (int) $prescription->branch_id : null);

        $prescription->dispense_status = HealthPrescription::DISPENSE_CANCELLED;
        $prescription->save();

        return back()->with('success', __('health.ph_rx_cancel_done'));
    }

    /** Re-open a cancelled prescription, recomputing status from its lines. */
    public function reopen($id)
    {
        $this->assertCan('pharmacy.dispense');

        $companyId = $this->companyId();
        $prescription = HealthPrescription::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        $this->assertBranchVisible($prescription->branch_id !== null ? (int) $prescription->branch_id : null);

        $prescription->dispense_status = HealthPrescription::DISPENSE_PENDING;
        $prescription->save();

        HealthPharmacyCheckoutService::refreshPrescriptionStatus($companyId, $prescription);

        return back()->with('success', __('health.ph_rx_reopened'));
    }
}
