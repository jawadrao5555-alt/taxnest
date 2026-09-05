<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HealthPharmacyContext;
use App\Models\HealthMedicine;
use App\Services\BranchStockService;
use App\Services\HealthPharmacyReportService;
use App\Services\HealthPharmacyService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pharmacy hub, medicine catalogue and pharmacy policy (Task 1549).
 *
 * Reading is `pharmacy.view` (enforced for the whole /health/pharmacy prefix by
 * HealthAuth's path map); every write here re-checks `pharmacy.manage` in the
 * controller, because a hidden button never stopped a POST.
 */
class HealthPharmacyController extends Controller
{
    use HealthPharmacyContext;

    /** The counter's home screen: what needs attention, and where to go. */
    public function index()
    {
        $companyId = $this->companyId();
        $branchId = $this->viewBranchId();
        $allBranches = BranchStockService::viewingAllBranches($companyId);

        $settings = $this->settings();

        return view('health.pharmacy.index', [
            'company' => $this->company(),
            'summary' => HealthPharmacyReportService::summary($companyId, $branchId, $allBranches),
            'nearExpiry' => HealthPharmacyReportService::nearExpiryQuery($companyId, $branchId, $allBranches, (int) $settings->near_expiry_days)
                ->limit(6)->get(),
            'lowStock' => array_slice(HealthPharmacyReportService::lowStock($companyId, $branchId, $allBranches), 0, 6),
            'expired' => HealthPharmacyReportService::expiredQuery($companyId, $branchId, $allBranches)->limit(6)->get(),
            'settings' => $settings,
            'branchName' => $allBranches ? null : BranchStockService::branchName($companyId, $branchId),
        ]);
    }

    // ═══════════════════════ Catalogue ═══════════════════════

    public function medicines(Request $request)
    {
        $companyId = $this->companyId();
        $branchId = $this->viewBranchId();
        $allBranches = BranchStockService::viewingAllBranches($companyId);

        $search = trim((string) $request->query('q', ''));

        $query = HealthMedicine::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->when($search !== '', function ($builder) use ($search) {
                $builder->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('generic_name', 'like', "%{$search}%")
                        ->orWhere('manufacturer', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                });
            })
            ->when($request->query('status') === 'inactive', fn ($b) => $b->where('is_active', false))
            ->when($request->query('status') !== 'inactive', fn ($b) => $b->where('is_active', true))
            ->orderBy('name');

        $medicines = $query->paginate(30)->withQueryString();

        $available = HealthPharmacyReportService::availableByMedicine(
            $companyId,
            $branchId,
            $allBranches,
            $medicines->pluck('id')->all()
        );

        // Substitute names, resolved in one read instead of per row (live throws
        // on lazy loading, and a per-row query would be 30 round trips).
        $substitutes = \Illuminate\Support\Facades\DB::table('health_medicine_substitutes')
            ->join('health_medicines', 'health_medicines.id', '=', 'health_medicine_substitutes.substitute_id')
            ->where('health_medicine_substitutes.company_id', $companyId)
            ->whereIn('health_medicine_substitutes.medicine_id', $medicines->pluck('id')->all())
            ->get(['health_medicine_substitutes.medicine_id', 'health_medicines.id as substitute_id', 'health_medicines.name'])
            ->groupBy('medicine_id');

        return view('health.pharmacy.medicines', [
            'medicines' => $medicines,
            'available' => $available,
            'substituteMap' => $substitutes,
            'allMedicines' => $this->medicineOptions(),
            'forms' => HealthMedicine::FORMS,
            'search' => $search,
            'status' => $request->query('status') === 'inactive' ? 'inactive' : 'active',
            'canManage' => \App\Services\HealthAccessService::can($this->user(), 'pharmacy.manage', $this->company()),
        ]);
    }

    public function storeMedicine(Request $request)
    {
        $this->assertCan('pharmacy.manage');

        $data = $this->validatedMedicine($request, null);

        $medicine = HealthPharmacyService::createMedicine($this->companyId(), $data, $this->user()?->id);
        HealthPharmacyService::syncSubstitutes($medicine, (array) $request->input('substitutes', []));

        return redirect()->route('health.pharmacy.medicines')
            ->with('success', __('health.ph_medicine_created', ['name' => $medicine->display_name]));
    }

    public function updateMedicine(Request $request, $id)
    {
        $this->assertCan('pharmacy.manage');

        $medicine = HealthMedicine::withoutGlobalScopes()
            ->where('company_id', $this->companyId())
            ->findOrFail($id);

        $data = $this->validatedMedicine($request, (int) $medicine->id);

        HealthPharmacyService::updateMedicine($medicine, $data);
        HealthPharmacyService::syncSubstitutes($medicine, (array) $request->input('substitutes', []));

        return redirect()->route('health.pharmacy.medicines')
            ->with('success', __('health.ph_medicine_updated', ['name' => $medicine->display_name]));
    }

    /**
     * Switch a medicine off rather than delete it: old batches, sales and
     * prescriptions point at this row and must keep resolving.
     */
    public function toggleMedicine($id)
    {
        $this->assertCan('pharmacy.manage');

        $medicine = HealthMedicine::withoutGlobalScopes()
            ->where('company_id', $this->companyId())
            ->findOrFail($id);

        $medicine->is_active = !$medicine->is_active;
        $medicine->save();

        if ($medicine->product_id) {
            \App\Models\Product::withoutGlobalScopes()
                ->where('company_id', $this->companyId())
                ->whereKey($medicine->product_id)
                ->update(['is_active' => $medicine->is_active]);
        }

        return back()->with('success', $medicine->is_active
            ? __('health.ph_medicine_activated', ['name' => $medicine->display_name])
            : __('health.ph_medicine_deactivated', ['name' => $medicine->display_name]));
    }

    // ═══════════════════════ Policy ═══════════════════════

    public function settingsPage()
    {
        $this->assertCan('pharmacy.manage');

        return view('health.pharmacy.settings', [
            'settings' => $this->settings(),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $this->assertCan('pharmacy.manage');

        $data = $request->validate([
            'near_expiry_days' => 'required|integer|min:1|max:1095',
            'low_stock_threshold' => 'required|numeric|min:0',
            'default_tax_rate' => 'required|numeric|min:0|max:100',
            'sale_prefix' => 'required|string|max:8|regex:/^[A-Za-z0-9]+$/',
        ]);

        $data['block_expired_dispense'] = $request->boolean('block_expired_dispense');
        $data['warn_short_dated'] = $request->boolean('warn_short_dated');
        $data['require_prescription_for_controlled'] = $request->boolean('require_prescription_for_controlled');
        $data['allow_negative_stock'] = $request->boolean('allow_negative_stock');
        $data['sale_prefix'] = strtoupper($data['sale_prefix']);

        HealthPharmacyService::saveSettings($this->companyId(), $data);

        return redirect()->route('health.pharmacy.settings')->with('success', __('health.ph_settings_saved'));
    }

    // ═══════════════════════ Internals ═══════════════════════

    private function validatedMedicine(Request $request, ?int $ignoreId): array
    {
        $companyId = $this->companyId();

        $data = $request->validate([
            'name' => 'required|string|max:190',
            'generic_name' => 'nullable|string|max:190',
            'strength' => 'nullable|string|max:64',
            'form' => ['required', Rule::in(HealthMedicine::FORMS)],
            'manufacturer' => 'nullable|string|max:190',
            'category' => 'nullable|string|max:120',
            'code' => [
                'nullable', 'string', 'max:64',
                Rule::unique('health_medicines', 'code')
                    ->where(fn ($q) => $q->where('company_id', $companyId))
                    ->ignore($ignoreId),
            ],
            'barcode' => 'nullable|string|max:64',
            'unit_uom' => 'nullable|string|max:24',
            'pack_uom' => 'nullable|string|max:24',
            'pack_size' => 'nullable|numeric|min:0.001',
            'purchase_price' => 'nullable|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'hs_code' => 'nullable|string|max:32',
            'uom_code' => 'nullable|string|max:32',
            'reorder_level' => 'nullable|numeric|min:0',
            'max_level' => 'nullable|numeric|min:0',
            'default_dosage' => 'nullable|string|max:190',
            'notes' => 'nullable|string|max:500',
            'substitutes' => 'nullable|array',
            'substitutes.*' => 'integer',
        ]);

        $data['requires_prescription'] = $request->boolean('requires_prescription');
        $data['is_controlled'] = $request->boolean('is_controlled');
        $data['is_narcotic'] = $request->boolean('is_narcotic');
        $data['is_refrigerated'] = $request->boolean('is_refrigerated');
        $data['is_active'] = $request->boolean('is_active', true);
        $data['code'] = $data['code'] ?: null;

        return $data;
    }
}
