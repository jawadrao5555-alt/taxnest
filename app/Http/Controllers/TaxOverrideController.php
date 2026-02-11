<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SectorTaxRule;
use App\Models\ProvinceTaxRule;
use App\Models\CustomerTaxRule;
use App\Models\SpecialSroRule;
use App\Models\OverrideUsageLog;
use Illuminate\Support\Facades\DB;

class TaxOverrideController extends Controller
{
    private $provinces = ['Punjab', 'Sindh', 'KPK', 'Balochistan', 'Islamabad', 'AJK', 'GB'];
    private $sectorTypes = ['Manufacturing', 'Services', 'Trading', 'IT/Software', 'Textiles', 'Pharmaceuticals', 'Agriculture', 'Construction', 'Retail', 'Export'];

    public function index()
    {
        $user = auth()->user();
        $isSuperAdmin = $user->role === 'super_admin';

        $sectorRules = $isSuperAdmin ? SectorTaxRule::orderBy('created_at', 'desc')->get() : collect();
        $provinceRules = $isSuperAdmin ? ProvinceTaxRule::orderBy('created_at', 'desc')->get() : collect();
        $sroRules = $isSuperAdmin ? SpecialSroRule::orderBy('created_at', 'desc')->get() : collect();

        if ($isSuperAdmin) {
            $customerRules = CustomerTaxRule::orderBy('created_at', 'desc')->get();
        } else {
            $companyId = app('currentCompanyId');
            $customerRules = CustomerTaxRule::where('company_id', $companyId)->orderBy('created_at', 'desc')->get();
        }

        $usageQuery = OverrideUsageLog::with('company', 'invoice');
        if (!$isSuperAdmin) {
            $usageQuery->where('company_id', app('currentCompanyId'));
        }
        $usageLogs = $usageQuery->orderBy('created_at', 'desc')->take(100)->get();

        return view('admin.tax-overrides', compact(
            'sectorRules', 'provinceRules', 'customerRules', 'sroRules',
            'usageLogs', 'isSuperAdmin'
        ))->with('provinces', $this->provinces)->with('sectorTypes', $this->sectorTypes);
    }

    public function storeSectorRule(Request $request)
    {
        if (auth()->user()->role !== 'super_admin') {
            abort(403);
        }

        $request->validate([
            'sector_type' => 'required|string|in:' . implode(',', $this->sectorTypes),
            'hs_code' => 'required|string|max:20',
            'override_tax_rate' => 'nullable|numeric|min:0|max:100',
            'override_schedule_type' => 'nullable|string|max:50',
            'override_sro_required' => 'nullable|boolean',
            'override_mrp_required' => 'nullable|boolean',
            'description' => 'nullable|string|max:500',
        ]);

        SectorTaxRule::create(array_merge(
            $request->only(['sector_type', 'hs_code', 'override_tax_rate', 'override_schedule_type', 'description']),
            [
                'override_sro_required' => $request->boolean('override_sro_required'),
                'override_mrp_required' => $request->boolean('override_mrp_required'),
                'is_active' => true,
            ]
        ));

        return redirect()->back()->with('success', 'Sector tax rule created successfully.');
    }

    public function updateSectorRule(Request $request, $id)
    {
        if (auth()->user()->role !== 'super_admin') {
            abort(403);
        }

        $rule = SectorTaxRule::findOrFail($id);

        $request->validate([
            'sector_type' => 'required|string|in:' . implode(',', $this->sectorTypes),
            'hs_code' => 'required|string|max:20',
            'override_tax_rate' => 'nullable|numeric|min:0|max:100',
            'override_schedule_type' => 'nullable|string|max:50',
            'override_sro_required' => 'nullable|boolean',
            'override_mrp_required' => 'nullable|boolean',
            'description' => 'nullable|string|max:500',
            'is_active' => 'nullable|boolean',
        ]);

        $rule->update(array_merge(
            $request->only(['sector_type', 'hs_code', 'override_tax_rate', 'override_schedule_type', 'description']),
            [
                'override_sro_required' => $request->boolean('override_sro_required'),
                'override_mrp_required' => $request->boolean('override_mrp_required'),
                'is_active' => $request->boolean('is_active', true),
            ]
        ));

        return redirect()->back()->with('success', 'Sector tax rule updated successfully.');
    }

    public function deleteSectorRule($id)
    {
        if (auth()->user()->role !== 'super_admin') {
            abort(403);
        }

        $rule = SectorTaxRule::findOrFail($id);
        $rule->update(['is_active' => false]);

        return redirect()->back()->with('success', 'Sector tax rule deactivated successfully.');
    }

    public function storeProvinceRule(Request $request)
    {
        if (auth()->user()->role !== 'super_admin') {
            abort(403);
        }

        $request->validate([
            'province' => 'required|string|in:' . implode(',', $this->provinces),
            'hs_code' => 'required|string|max:20',
            'override_tax_rate' => 'nullable|numeric|min:0|max:100',
            'override_schedule_type' => 'nullable|string|max:50',
            'override_sro_required' => 'nullable|boolean',
            'override_mrp_required' => 'nullable|boolean',
            'description' => 'nullable|string|max:500',
        ]);

        ProvinceTaxRule::create(array_merge(
            $request->only(['province', 'hs_code', 'override_tax_rate', 'override_schedule_type', 'description']),
            [
                'override_sro_required' => $request->boolean('override_sro_required'),
                'override_mrp_required' => $request->boolean('override_mrp_required'),
                'is_active' => true,
            ]
        ));

        return redirect()->back()->with('success', 'Province tax rule created successfully.');
    }

    public function updateProvinceRule(Request $request, $id)
    {
        if (auth()->user()->role !== 'super_admin') {
            abort(403);
        }

        $rule = ProvinceTaxRule::findOrFail($id);

        $request->validate([
            'province' => 'required|string|in:' . implode(',', $this->provinces),
            'hs_code' => 'required|string|max:20',
            'override_tax_rate' => 'nullable|numeric|min:0|max:100',
            'override_schedule_type' => 'nullable|string|max:50',
            'override_sro_required' => 'nullable|boolean',
            'override_mrp_required' => 'nullable|boolean',
            'description' => 'nullable|string|max:500',
            'is_active' => 'nullable|boolean',
        ]);

        $rule->update(array_merge(
            $request->only(['province', 'hs_code', 'override_tax_rate', 'override_schedule_type', 'description']),
            [
                'override_sro_required' => $request->boolean('override_sro_required'),
                'override_mrp_required' => $request->boolean('override_mrp_required'),
                'is_active' => $request->boolean('is_active', true),
            ]
        ));

        return redirect()->back()->with('success', 'Province tax rule updated successfully.');
    }

    public function deleteProvinceRule($id)
    {
        if (auth()->user()->role !== 'super_admin') {
            abort(403);
        }

        $rule = ProvinceTaxRule::findOrFail($id);
        $rule->update(['is_active' => false]);

        return redirect()->back()->with('success', 'Province tax rule deactivated successfully.');
    }

    public function storeCustomerRule(Request $request)
    {
        $user = auth()->user();
        $isSuperAdmin = $user->role === 'super_admin';

        $rules = [
            'customer_ntn' => 'required|string|max:50',
            'hs_code' => 'required|string|max:20',
            'override_tax_rate' => 'nullable|numeric|min:0|max:100',
            'override_schedule_type' => 'nullable|string|max:50',
            'override_sro_required' => 'nullable|boolean',
            'override_mrp_required' => 'nullable|boolean',
            'description' => 'nullable|string|max:500',
        ];

        if ($isSuperAdmin) {
            $rules['company_id'] = 'required|integer|exists:companies,id';
        }

        $request->validate($rules);

        $companyId = $isSuperAdmin ? $request->company_id : app('currentCompanyId');

        CustomerTaxRule::create(array_merge(
            $request->only(['customer_ntn', 'hs_code', 'override_tax_rate', 'override_schedule_type', 'description']),
            [
                'company_id' => $companyId,
                'override_sro_required' => $request->boolean('override_sro_required'),
                'override_mrp_required' => $request->boolean('override_mrp_required'),
                'is_active' => true,
            ]
        ));

        return redirect()->back()->with('success', 'Customer tax rule created successfully.');
    }

    public function updateCustomerRule(Request $request, $id)
    {
        $user = auth()->user();
        $isSuperAdmin = $user->role === 'super_admin';

        $rule = CustomerTaxRule::findOrFail($id);

        if (!$isSuperAdmin && $rule->company_id !== app('currentCompanyId')) {
            abort(403);
        }

        $rules = [
            'customer_ntn' => 'required|string|max:50',
            'hs_code' => 'required|string|max:20',
            'override_tax_rate' => 'nullable|numeric|min:0|max:100',
            'override_schedule_type' => 'nullable|string|max:50',
            'override_sro_required' => 'nullable|boolean',
            'override_mrp_required' => 'nullable|boolean',
            'description' => 'nullable|string|max:500',
            'is_active' => 'nullable|boolean',
        ];

        if ($isSuperAdmin) {
            $rules['company_id'] = 'required|integer|exists:companies,id';
        }

        $request->validate($rules);

        $data = array_merge(
            $request->only(['customer_ntn', 'hs_code', 'override_tax_rate', 'override_schedule_type', 'description']),
            [
                'override_sro_required' => $request->boolean('override_sro_required'),
                'override_mrp_required' => $request->boolean('override_mrp_required'),
                'is_active' => $request->boolean('is_active', true),
            ]
        );

        if ($isSuperAdmin && $request->has('company_id')) {
            $data['company_id'] = $request->company_id;
        }

        $rule->update($data);

        return redirect()->back()->with('success', 'Customer tax rule updated successfully.');
    }

    public function deleteCustomerRule($id)
    {
        $user = auth()->user();
        $rule = CustomerTaxRule::findOrFail($id);

        if ($user->role !== 'super_admin' && $rule->company_id !== app('currentCompanyId')) {
            abort(403);
        }

        $rule->update(['is_active' => false]);

        return redirect()->back()->with('success', 'Customer tax rule deactivated successfully.');
    }

    public function storeSroRule(Request $request)
    {
        if (auth()->user()->role !== 'super_admin') {
            abort(403);
        }

        $request->validate([
            'hs_code' => 'required|string|max:20',
            'schedule_type' => 'required|string|max:50',
            'sro_number' => 'required|string|max:50',
            'serial_no' => 'nullable|string|max:20',
            'applicable_sector' => 'nullable|string|in:' . implode(',', $this->sectorTypes),
            'applicable_province' => 'nullable|string|in:' . implode(',', $this->provinces),
            'concessionary_rate' => 'nullable|numeric|min:0|max:100',
            'description' => 'nullable|string|max:500',
            'effective_from' => 'nullable|date',
            'effective_until' => 'nullable|date|after_or_equal:effective_from',
        ]);

        SpecialSroRule::create(array_merge(
            $request->only([
                'hs_code', 'schedule_type', 'sro_number', 'serial_no',
                'applicable_sector', 'applicable_province', 'concessionary_rate',
                'description', 'effective_from', 'effective_until',
            ]),
            ['is_active' => true]
        ));

        return redirect()->back()->with('success', 'SRO rule created successfully.');
    }

    public function updateSroRule(Request $request, $id)
    {
        if (auth()->user()->role !== 'super_admin') {
            abort(403);
        }

        $rule = SpecialSroRule::findOrFail($id);

        $request->validate([
            'hs_code' => 'required|string|max:20',
            'schedule_type' => 'required|string|max:50',
            'sro_number' => 'required|string|max:50',
            'serial_no' => 'nullable|string|max:20',
            'applicable_sector' => 'nullable|string|in:' . implode(',', $this->sectorTypes),
            'applicable_province' => 'nullable|string|in:' . implode(',', $this->provinces),
            'concessionary_rate' => 'nullable|numeric|min:0|max:100',
            'description' => 'nullable|string|max:500',
            'effective_from' => 'nullable|date',
            'effective_until' => 'nullable|date|after_or_equal:effective_from',
            'is_active' => 'nullable|boolean',
        ]);

        $rule->update(array_merge(
            $request->only([
                'hs_code', 'schedule_type', 'sro_number', 'serial_no',
                'applicable_sector', 'applicable_province', 'concessionary_rate',
                'description', 'effective_from', 'effective_until',
            ]),
            ['is_active' => $request->boolean('is_active', true)]
        ));

        return redirect()->back()->with('success', 'SRO rule updated successfully.');
    }

    public function deleteSroRule($id)
    {
        if (auth()->user()->role !== 'super_admin') {
            abort(403);
        }

        $rule = SpecialSroRule::findOrFail($id);
        $rule->update(['is_active' => false]);

        return redirect()->back()->with('success', 'SRO rule deactivated successfully.');
    }

    public function overrideAnalytics()
    {
        $byLayer = OverrideUsageLog::select('override_layer', DB::raw('count(*) as count'))
            ->groupBy('override_layer')
            ->get();

        $byCompany = OverrideUsageLog::select('company_id', DB::raw('count(*) as count'))
            ->groupBy('company_id')
            ->with('company:id,name')
            ->orderByDesc('count')
            ->take(20)
            ->get()
            ->map(function ($item) {
                return [
                    'company_id' => $item->company_id,
                    'company_name' => $item->company->name ?? 'Unknown',
                    'count' => $item->count,
                ];
            });

        $trend = OverrideUsageLog::select(
                DB::raw("TO_CHAR(created_at, 'YYYY-MM') as month"),
                DB::raw('count(*) as count')
            )
            ->where('created_at', '>=', now()->subMonths(12))
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return response()->json([
            'by_layer' => $byLayer,
            'by_company' => $byCompany,
            'trend' => $trend,
        ]);
    }
}
