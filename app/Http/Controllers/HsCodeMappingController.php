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

        return view('admin.hs-mapping-engine', compact('mappings', 'stats', 'search', 'saleTypeFilter'));
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

    public function apiSuggestions($hsCode)
    {
        $mappings = HsCodeMapping::active()
            ->forHsCode($hsCode)
            ->orderBy('priority')
            ->get()
            ->map(function ($m) {
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
                ];
            });

        return response()->json(['mappings' => $mappings]);
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

        return response()->json(['status' => 'recorded']);
    }

    public function analytics()
    {
        $topAccepted = DB::table('hs_mapping_responses')
            ->join('hs_code_mappings', 'hs_mapping_responses.hs_code_mapping_id', '=', 'hs_code_mappings.id')
            ->where('hs_mapping_responses.action', 'accepted')
            ->select('hs_code_mappings.hs_code', 'hs_code_mappings.label', DB::raw('count(*) as accept_count'))
            ->groupBy('hs_code_mappings.hs_code', 'hs_code_mappings.label')
            ->orderByDesc('accept_count')
            ->limit(10)
            ->get();

        $topRejected = DB::table('hs_mapping_responses')
            ->join('hs_code_mappings', 'hs_mapping_responses.hs_code_mapping_id', '=', 'hs_code_mappings.id')
            ->where('hs_mapping_responses.action', 'rejected')
            ->select('hs_code_mappings.hs_code', 'hs_code_mappings.label', DB::raw('count(*) as reject_count'))
            ->groupBy('hs_code_mappings.hs_code', 'hs_code_mappings.label')
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

        return response()->json([
            'top_accepted' => $topAccepted,
            'top_rejected' => $topRejected,
            'by_company' => $byCompany,
        ]);
    }
}
