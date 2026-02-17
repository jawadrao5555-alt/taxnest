<?php

namespace App\Http\Controllers;

use App\Models\HsCodeMapping;
use App\Models\HsMappingResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HsCodeMappingController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->get('search', '');
        $saleTypeFilter = $request->get('sale_type', '');
        $tab = $request->get('tab', 'mappings');

        $query = HsCodeMapping::query();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('hs_code', 'ilike', "%{$search}%")
                  ->orWhere('label', 'ilike', "%{$search}%")
                  ->orWhere('sro_number', 'ilike', "%{$search}%")
                  ->orWhere('pct_code', 'ilike', "%{$search}%");
            });
        }

        if ($saleTypeFilter) {
            $query->where('sale_type', $saleTypeFilter);
        }

        $mappings = $query->orderBy('hs_code')->orderBy('priority')->paginate(25)->appends($request->query());

        $stats = [
            'total' => HsCodeMapping::count(),
            'active' => HsCodeMapping::where('is_active', true)->count(),
            'sro_applicable' => HsCodeMapping::where('sro_applicable', true)->count(),
            'total_responses' => HsMappingResponse::count(),
            'accepted' => HsMappingResponse::where('action', 'accepted')->count(),
            'rejected' => HsMappingResponse::where('action', 'rejected')->count(),
        ];

        $hsGroups = HsCodeMapping::select('hs_code', DB::raw('count(*) as mapping_count'))
            ->groupBy('hs_code')
            ->having(DB::raw('count(*)'), '>', 1)
            ->count();
        $stats['multi_mapped'] = $hsGroups;

        $analyticsData = null;
        if ($tab === 'analytics') {
            $analyticsData = $this->getAnalyticsData();
        }

        return view('admin.hs-mapping-engine', compact('mappings', 'stats', 'search', 'saleTypeFilter', 'tab', 'analyticsData'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'hs_code' => 'required|string|max:20',
            'label' => 'nullable|string|max:255',
            'sale_type' => 'required|in:standard,reduced,3rd_schedule,exempt,zero_rated',
            'tax_rate' => 'required|numeric|min:0|max:100',
            'sro_applicable' => 'boolean',
            'sro_number' => 'nullable|string|max:255',
            'serial_number_applicable' => 'boolean',
            'serial_number_value' => 'nullable|string|max:255',
            'mrp_required' => 'boolean',
            'pct_code' => 'nullable|string|max:50',
            'default_uom' => 'nullable|string|max:50',
            'buyer_type' => 'nullable|in:registered,unregistered',
            'notes' => 'nullable|string|max:1000',
            'priority' => 'integer|min:1|max:100',
        ]);

        $validated['sro_applicable'] = $request->boolean('sro_applicable');
        $validated['serial_number_applicable'] = $request->boolean('serial_number_applicable');
        $validated['mrp_required'] = $request->boolean('mrp_required');
        $validated['created_by'] = auth()->id();

        $dupQuery = HsCodeMapping::where('hs_code', $validated['hs_code'])
            ->where('sale_type', $validated['sale_type'])
            ->where('tax_rate', $validated['tax_rate']);
        $bt = $validated['buyer_type'] ?? null;
        if ($bt) {
            $dupQuery->where('buyer_type', $bt);
        } else {
            $dupQuery->where(function ($q) { $q->whereNull('buyer_type')->orWhere('buyer_type', ''); });
        }
        $duplicate = $dupQuery->first();

        if ($duplicate) {
            return redirect()->route('admin.hs-mapping-engine')
                ->with('error', 'A mapping with the same HS Code (' . $validated['hs_code'] . '), Sale Type, Buyer Type and Tax Rate already exists (ID: ' . $duplicate->id . '). Please edit the existing mapping or use different values.');
        }

        HsCodeMapping::create($validated);

        return redirect()->route('admin.hs-mapping-engine')->with('success', 'HS Code Mapping created successfully.');
    }

    public function update(Request $request, $id)
    {
        $mapping = HsCodeMapping::findOrFail($id);

        $validated = $request->validate([
            'hs_code' => 'required|string|max:20',
            'label' => 'nullable|string|max:255',
            'sale_type' => 'required|in:standard,reduced,3rd_schedule,exempt,zero_rated',
            'tax_rate' => 'required|numeric|min:0|max:100',
            'sro_applicable' => 'boolean',
            'sro_number' => 'nullable|string|max:255',
            'serial_number_applicable' => 'boolean',
            'serial_number_value' => 'nullable|string|max:255',
            'mrp_required' => 'boolean',
            'pct_code' => 'nullable|string|max:50',
            'default_uom' => 'nullable|string|max:50',
            'buyer_type' => 'nullable|in:registered,unregistered',
            'notes' => 'nullable|string|max:1000',
            'priority' => 'integer|min:1|max:100',
            'is_active' => 'boolean',
        ]);

        $validated['sro_applicable'] = $request->boolean('sro_applicable');
        $validated['serial_number_applicable'] = $request->boolean('serial_number_applicable');
        $validated['mrp_required'] = $request->boolean('mrp_required');
        $validated['is_active'] = $request->boolean('is_active');
        $validated['updated_by'] = auth()->id();

        $mapping->update($validated);

        return redirect()->route('admin.hs-mapping-engine')->with('success', 'Mapping updated successfully.');
    }

    public function destroy($id)
    {
        $mapping = HsCodeMapping::findOrFail($id);
        $mapping->delete();

        return redirect()->route('admin.hs-mapping-engine')->with('success', 'Mapping deleted successfully.');
    }

    public function duplicate($id)
    {
        $original = HsCodeMapping::findOrFail($id);
        $clone = $original->replicate();
        $clone->label = ($clone->label ? $clone->label . ' (Copy)' : 'Copy');
        $clone->priority = $clone->priority + 1;
        $clone->created_by = auth()->id();
        $clone->updated_by = null;
        $clone->save();

        return redirect()->route('admin.hs-mapping-engine')->with('success', 'Mapping cloned successfully (ID: ' . $clone->id . ').');
    }

    public function apiHsAutoFill($hsCode)
    {
        $master = DB::table('global_hs_master')->where('hs_code', $hsCode)->first();
        if ($master) {
            return response()->json([
                'found' => true,
                'description' => $master->description,
                'pct_code' => $master->pct_code,
                'schedule_type' => $master->schedule_type,
                'tax_rate' => $master->tax_rate,
                'default_uom' => $master->default_uom,
                'sro_required' => $master->sro_required,
                'sro_number' => $master->sro_number,
                'mrp_required' => $master->mrp_required,
            ]);
        }
        return response()->json(['found' => false]);
    }

    public function apiSuggestions(Request $request, $hsCode)
    {
        $buyerType = $request->get('buyer_type');

        $query = HsCodeMapping::active()
            ->forHsCode($hsCode)
            ->orderBy('priority');

        if ($buyerType) {
            $query->where(function ($q) use ($buyerType) {
                $q->where('buyer_type', $buyerType)
                  ->orWhereNull('buyer_type')
                  ->orWhere('buyer_type', '');
            });
        }

        $mappings = $query->get();

        $acceptCounts = HsMappingResponse::whereIn('hs_code_mapping_id', $mappings->pluck('id'))
            ->where('action', 'accepted')
            ->select('hs_code_mapping_id', DB::raw('count(*) as cnt'))
            ->groupBy('hs_code_mapping_id')
            ->pluck('cnt', 'hs_code_mapping_id');

        $rejectCounts = HsMappingResponse::whereIn('hs_code_mapping_id', $mappings->pluck('id'))
            ->where('action', 'rejected')
            ->select('hs_code_mapping_id', DB::raw('count(*) as cnt'))
            ->groupBy('hs_code_mapping_id')
            ->pluck('cnt', 'hs_code_mapping_id');

        $result = $mappings->map(function ($m) use ($acceptCounts, $rejectCounts) {
            $accepted = $acceptCounts->get($m->id, 0);
            $rejected = $rejectCounts->get($m->id, 0);
            $total = $accepted + $rejected;
            $confidence = 'new';
            if ($total >= 5) {
                $ratio = $accepted / $total;
                if ($ratio >= 0.7) $confidence = 'high';
                elseif ($ratio >= 0.4) $confidence = 'medium';
                else $confidence = 'low';
            } elseif ($total > 0) {
                $confidence = 'building';
            }

            return [
                'id' => $m->id,
                'hs_code' => $m->hs_code,
                'label' => $m->label,
                'sale_type' => $m->sale_type,
                'tax_rate' => (float) $m->tax_rate,
                'sro_applicable' => $m->sro_applicable,
                'sro_number' => $m->sro_number,
                'serial_number_applicable' => $m->serial_number_applicable,
                'serial_number_value' => $m->serial_number_value,
                'mrp_required' => $m->mrp_required,
                'pct_code' => $m->pct_code,
                'default_uom' => $m->default_uom,
                'buyer_type' => $m->buyer_type,
                'notes' => $m->notes,
                'priority' => $m->priority,
                'confidence' => $confidence,
                'accepted_count' => $accepted,
                'rejected_count' => $rejected,
            ];
        });

        return response()->json(['mappings' => $result]);
    }

    public function apiRecordResponse(Request $request)
    {
        $validated = $request->validate([
            'hs_code_mapping_id' => 'required|exists:hs_code_mappings,id',
            'hs_code' => 'required|string|max:20',
            'action' => 'required|in:accepted,rejected,custom',
            'custom_values' => 'nullable|array',
            'invoice_id' => 'nullable|integer',
        ]);

        $validated['company_id'] = auth()->user()->company_id;
        $validated['user_id'] = auth()->id();

        HsMappingResponse::create($validated);

        if ($validated['action'] === 'custom' && !empty($validated['custom_values'])) {
            $cv = $validated['custom_values'];
            $companyId = auth()->user()->company_id;
            $companyName = auth()->user()->company->name ?? 'Unknown';

            if (!empty($cv['sale_type'])) {
                $existsQuery = HsCodeMapping::where('hs_code', $validated['hs_code'])
                    ->where('sale_type', $cv['sale_type'])
                    ->where('tax_rate', $cv['tax_rate'] ?? 0);
                $cvBuyer = $cv['buyer_type'] ?? null;
                if ($cvBuyer) {
                    $existsQuery->where('buyer_type', $cvBuyer);
                } else {
                    $existsQuery->where(function ($q) { $q->whereNull('buyer_type')->orWhere('buyer_type', ''); });
                }

                if (!$existsQuery->exists()) {
                    HsCodeMapping::create([
                        'hs_code' => $validated['hs_code'],
                        'label' => 'Company Custom: ' . $companyName,
                        'sale_type' => $cv['sale_type'],
                        'tax_rate' => $cv['tax_rate'] ?? 0,
                        'sro_applicable' => !empty($cv['sro_schedule_no']),
                        'sro_number' => $cv['sro_schedule_no'] ?? null,
                        'serial_number_applicable' => !empty($cv['serial_no']),
                        'serial_number_value' => $cv['serial_no'] ?? null,
                        'buyer_type' => $cvBuyer,
                        'mrp_required' => false,
                        'priority' => 50,
                        'is_active' => true,
                        'created_by' => auth()->id(),
                        'notes' => 'Auto-created from company custom input (Company ID: ' . $companyId . ', ' . $companyName . ')',
                    ]);
                }
            }
        }

        return response()->json(['status' => 'recorded']);
    }

    private function getAnalyticsData()
    {
        $topAccepted = DB::table('hs_mapping_responses')
            ->join('hs_code_mappings', 'hs_mapping_responses.hs_code_mapping_id', '=', 'hs_code_mappings.id')
            ->where('hs_mapping_responses.action', 'accepted')
            ->select('hs_code_mappings.hs_code', 'hs_code_mappings.label', 'hs_code_mappings.sale_type', DB::raw('count(*) as accept_count'))
            ->groupBy('hs_code_mappings.hs_code', 'hs_code_mappings.label', 'hs_code_mappings.sale_type')
            ->orderByDesc('accept_count')
            ->limit(10)
            ->get();

        $topRejected = DB::table('hs_mapping_responses')
            ->join('hs_code_mappings', 'hs_mapping_responses.hs_code_mapping_id', '=', 'hs_code_mappings.id')
            ->where('hs_mapping_responses.action', 'rejected')
            ->select('hs_code_mappings.hs_code', 'hs_code_mappings.label', 'hs_code_mappings.sale_type', DB::raw('count(*) as reject_count'))
            ->groupBy('hs_code_mappings.hs_code', 'hs_code_mappings.label', 'hs_code_mappings.sale_type')
            ->orderByDesc('reject_count')
            ->limit(10)
            ->get();

        $byCompany = DB::table('hs_mapping_responses')
            ->join('companies', 'hs_mapping_responses.company_id', '=', 'companies.id')
            ->select('companies.name as company_name', 'hs_mapping_responses.action', DB::raw('count(*) as count'))
            ->groupBy('companies.name', 'hs_mapping_responses.action')
            ->orderByDesc('count')
            ->limit(20)
            ->get();

        $recentResponses = DB::table('hs_mapping_responses')
            ->join('hs_code_mappings', 'hs_mapping_responses.hs_code_mapping_id', '=', 'hs_code_mappings.id')
            ->join('companies', 'hs_mapping_responses.company_id', '=', 'companies.id')
            ->select(
                'hs_code_mappings.hs_code', 'hs_code_mappings.label', 'hs_code_mappings.sale_type',
                'companies.name as company_name', 'hs_mapping_responses.action',
                'hs_mapping_responses.created_at'
            )
            ->orderByDesc('hs_mapping_responses.created_at')
            ->limit(15)
            ->get();

        $dailyTrend = DB::table('hs_mapping_responses')
            ->select(DB::raw("DATE(created_at) as date"), 'action', DB::raw('count(*) as count'))
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy(DB::raw("DATE(created_at)"), 'action')
            ->orderBy('date')
            ->get();

        return [
            'top_accepted' => $topAccepted,
            'top_rejected' => $topRejected,
            'by_company' => $byCompany,
            'recent_responses' => $recentResponses,
            'daily_trend' => $dailyTrend,
        ];
    }
}
