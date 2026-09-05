<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HealthPharmacyContext;
use App\Models\HealthMedicine;
use App\Models\HealthPharmacyReturn;
use App\Models\HealthPharmacySale;
use App\Services\BranchStockService;
use App\Services\HealthPharmacyCheckoutService;
use App\Services\HealthPharmacyReportService;
use App\Services\HealthPharmacyStockService;
use App\Services\HealthPlatformService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Pharmacy counter — walk-in and patient-linked sales, returns and the receipt
 * (Task 1549).
 *
 * Money and medicine settle in HealthPharmacyCheckoutService, never here. This
 * controller owns the screen, the branch the sale belongs to, and the
 * capability check; keeping the settlement in one service is what guarantees a
 * counter sale and a prescription fill hit stock identically.
 */
class HealthPharmacySaleController extends Controller
{
    use HealthPharmacyContext;

    public function counter()
    {
        $this->assertCan('pharmacy.dispense');

        $companyId = $this->companyId();
        $branchId = $this->viewBranchId();
        $allBranches = BranchStockService::viewingAllBranches($companyId);

        $medicines = $this->medicineOptions();

        $available = HealthPharmacyReportService::availableByMedicine(
            $companyId,
            $branchId,
            $allBranches,
            $medicines->pluck('id')->all()
        );

        // The counter is a live screen: every medicine carries its sellable
        // quantity and its nearest expiry, so the cashier is warned at the
        // moment of picking, not after the money is taken.
        $earliest = \App\Models\HealthMedicineBatch::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->when(!$allBranches, fn ($q) => $q->where('branch_id', $branchId))
            ->where('status', \App\Models\HealthMedicineBatch::STATUS_ACTIVE)
            ->where('quantity', '>', 0)
            ->whereNotNull('expiry_date')
            ->groupBy('medicine_id')
            ->selectRaw('medicine_id, MIN(expiry_date) as expiry')
            ->pluck('expiry', 'medicine_id');

        return view('health.pharmacy.counter', [
            'medicines' => $medicines,
            'available' => $available,
            'earliestExpiry' => $earliest,
            'settings' => $this->settings(),
            'branches' => $this->branches(),
            'isMultiBranch' => BranchStockService::isMultiBranch($companyId),
            'viewBranchId' => $branchId,
            'mustPickBranch' => $this->mustPickBranch(null),
            'paymentMethods' => HealthPharmacySale::PAYMENT_METHODS,
            'fbr' => HealthPlatformService::fbrReadiness($this->company()),
        ]);
    }

    public function store(Request $request)
    {
        $this->assertCan('pharmacy.dispense');

        $request->validate([
            'branch_id' => 'nullable|integer',
            'patient_name' => 'nullable|string|max:190',
            'patient_mr_no' => 'nullable|string|max:64',
            'patient_phone' => 'nullable|string|max:32',
            'payment_method' => 'nullable|string|max:24',
            'paid_amount' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string|max:500',
            'lines' => 'required|array|min:1',
            'lines.*.medicine_id' => 'required|integer',
            'lines.*.quantity' => 'required|numeric|min:0',
            'lines.*.unit_price' => 'nullable|numeric|min:0',
            'lines.*.discount_amount' => 'nullable|numeric|min:0',
            'lines.*.batch_id' => 'nullable|integer',
        ]);

        $picked = $request->filled('branch_id') ? (int) $request->input('branch_id') : null;

        if ($this->mustPickBranch($picked)) {
            return back()->withInput()->with('error', __('health.ph_pick_branch'));
        }

        // A bill-level discount is spread across the lines here so the
        // checkout service only ever deals in per-line money.
        $lines = $this->spreadBillDiscount(
            (array) $request->input('lines'),
            round((float) $request->input('discount_amount', 0), 2)
        );

        try {
            $sale = HealthPharmacyCheckoutService::sell(
                $this->companyId(),
                [
                    'sale_type' => $request->filled('patient_name')
                        ? HealthPharmacySale::TYPE_PATIENT
                        : HealthPharmacySale::TYPE_COUNTER,
                    'patient_name' => $request->input('patient_name'),
                    'patient_mr_no' => $request->input('patient_mr_no'),
                    'patient_phone' => $request->input('patient_phone'),
                    'payment_method' => $request->input('payment_method', 'cash'),
                    'paid_amount' => $request->input('paid_amount'),
                    'tax_rate' => $request->input('tax_rate'),
                    'notes' => $request->input('notes'),
                    'allow_expired' => $request->boolean('allow_expired'),
                    'lines' => $lines,
                ],
                $this->writeBranchId($picked),
                $this->user()?->id,
                $this->company()
            );
        } catch (ValidationException $e) {
            return back()->withInput()->with('error', collect($e->errors())->flatten()->first());
        }

        $warnings = collect($sale->dispenseWarnings)
            ->filter(fn ($w) => ($w['type'] ?? null) === 'short_dated')
            ->map(fn ($w) => $w['medicine'] ?? null)
            ->filter()->unique()->implode(', ');

        $redirect = redirect()->route('health.pharmacy.sales.show', $sale->id)
            ->with('success', __('health.ph_sale_saved', [
                'number' => $sale->sale_number,
                'amount' => number_format((float) $sale->total_amount, 2),
            ]));

        // Short-dated stock still sells — but the counter is told, so the shop
        // can warn the patient rather than discover it on the strip at home.
        if ($warnings !== '') {
            $redirect->with('warning', __('health.ph_short_dated_sold', ['names' => $warnings]));
        }

        return $redirect;
    }

    public function index(Request $request)
    {
        $companyId = $this->companyId();
        $branchId = $this->viewBranchId();
        $allBranches = BranchStockService::viewingAllBranches($companyId);

        [$from, $to] = HealthPharmacyReportService::range($request->query('from'), $request->query('to'));
        $search = trim((string) $request->query('q', ''));

        // One filter, applied to two SEPARATE builders on purpose. Cloning the
        // list query for the totals drags its eager loads and withCount along,
        // and MySQL's only_full_group_by then rejects the aggregate outright
        // (a 500 that sqlite never reproduces).
        $filter = function ($builder) use ($companyId, $allBranches, $branchId, $from, $to, $search) {
            return $builder
                ->where('company_id', $companyId)
                ->when(!$allBranches, fn ($q) => $q->where('branch_id', $branchId))
                ->whereBetween('business_date', [$from, $to])
                ->when($search !== '', function ($inner) use ($search) {
                    $inner->where(function ($group) use ($search) {
                        $group->where('sale_number', 'like', "%{$search}%")
                            ->orWhere('patient_name', 'like', "%{$search}%")
                            ->orWhere('patient_mr_no', 'like', "%{$search}%")
                            ->orWhere('patient_phone', 'like', "%{$search}%");
                    });
                });
        };

        $query = $filter(
            HealthPharmacySale::withoutGlobalScopes()->with(['branch', 'creator'])->withCount('items')
        );

        $totals = $filter(HealthPharmacySale::withoutGlobalScopes())
            ->where('status', '!=', HealthPharmacySale::STATUS_VOID)
            ->selectRaw('COUNT(*) as bills')
            ->selectRaw('COALESCE(SUM(total_amount), 0) as gross')
            ->selectRaw('COALESCE(SUM(refunded_amount), 0) as refunded')
            ->selectRaw('COALESCE(SUM(cost_amount), 0) as cost')
            ->first();

        return view('health.pharmacy.sales', [
            'sales' => $query->orderByDesc('id')->paginate(30)->withQueryString(),
            'totals' => $totals,
            'from' => $from,
            'to' => $to,
            'search' => $search,
        ]);
    }

    public function show($id)
    {
        $sale = $this->sale($id);

        return view('health.pharmacy.sale-show', [
            'sale' => $sale,
            'returns' => HealthPharmacyReturn::withoutGlobalScopes()
                ->with('items')
                ->where('company_id', $this->companyId())
                ->where('sale_id', $sale->id)
                ->orderByDesc('id')
                ->get(),
            'canDispense' => \App\Services\HealthAccessService::can($this->user(), 'pharmacy.dispense', $this->company()),
            'fbr' => HealthPlatformService::fbrReadiness($this->company()),
        ]);
    }

    /** Printable slip. Standalone page, no panel chrome — it goes to paper. */
    public function receipt($id)
    {
        return view('health.pharmacy.receipt', [
            'sale' => $this->sale($id),
            'company' => $this->company(),
            'settings' => $this->settings(),
            'fbr' => HealthPlatformService::fbrReadiness($this->company()),
        ]);
    }

    public function refund(Request $request, $id)
    {
        $this->assertCan('pharmacy.dispense');

        $request->validate([
            'reason' => 'nullable|string|max:190',
            'lines' => 'required|array|min:1',
            'lines.*.sale_item_id' => 'required|integer',
            'lines.*.quantity' => 'nullable|numeric|min:0',
        ]);

        $sale = $this->sale($id);

        try {
            $return = HealthPharmacyCheckoutService::refund(
                $this->companyId(),
                $sale,
                (array) $request->input('lines'),
                $request->boolean('restock', true),
                $request->input('reason'),
                $this->user()?->id
            );
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return back()->with('success', __('health.ph_return_saved', [
            'number' => $return->return_number,
            'amount' => number_format((float) $return->refund_amount, 2),
        ]));
    }

    /** Live batch list for a medicine, used when the counter pins a lot. */
    public function batches(Request $request)
    {
        $companyId = $this->companyId();

        $medicine = HealthMedicine::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail((int) $request->query('medicine_id'));

        $branchId = $request->filled('branch_id')
            ? (int) $request->query('branch_id')
            : $this->viewBranchId();

        if ($request->filled('branch_id') && !BranchStockService::actorCanUse($companyId, $branchId)) {
            abort(403);
        }

        $batches = HealthPharmacyStockService::sellableBatches($companyId, (int) $medicine->id, $branchId)->get();
        $nearDays = (int) $this->settings()->near_expiry_days;

        return response()->json([
            'ok' => true,
            'batches' => $batches->map(fn ($batch) => [
                'id' => (int) $batch->id,
                'batch_no' => $batch->batch_no,
                'expiry_date' => $batch->expiry_date?->toDateString(),
                'quantity' => round((float) $batch->quantity, 3),
                'sale_price' => round((float) $batch->sale_price, 2),
                'expired' => $batch->isExpired(),
                'short_dated' => $batch->isShortDated($nearDays),
            ])->values(),
        ]);
    }

    // ═══════════════════════ Internals ═══════════════════════

    private function sale($id): HealthPharmacySale
    {
        $sale = HealthPharmacySale::withoutGlobalScopes()
            ->with(['items.medicine', 'branch', 'creator', 'prescription'])
            ->where('company_id', $this->companyId())
            ->findOrFail($id);

        // One gate for the bill, the receipt AND the refund: they all arrive
        // here by id, so a branch this person does not work in must stop at
        // this line rather than at each caller's discretion.
        $this->assertBranchVisible($sale->branch_id !== null ? (int) $sale->branch_id : null);

        return $sale;
    }

    /**
     * Split a bill-level discount across the lines by value, giving the
     * remainder to the last line so the rupees always add up exactly.
     */
    private function spreadBillDiscount(array $lines, float $discount): array
    {
        $lines = array_values(array_filter($lines, fn ($line) => (float) ($line['quantity'] ?? 0) > 0));

        if ($discount <= 0 || !$lines) {
            return $lines;
        }

        $values = array_map(
            fn ($line) => (float) ($line['quantity'] ?? 0) * (float) ($line['unit_price'] ?? 0),
            $lines
        );
        $total = array_sum($values);

        if ($total <= 0) {
            return $lines;
        }

        $left = $discount;
        foreach ($lines as $index => $line) {
            $share = $index === count($lines) - 1
                ? round($left, 2)
                : round($discount * ($values[$index] / $total), 2);

            $lines[$index]['discount_amount'] = round((float) ($line['discount_amount'] ?? 0) + $share, 2);
            $left = round($left - $share, 2);
        }

        return $lines;
    }
}
