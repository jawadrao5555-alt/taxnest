<?php

namespace App\Http\Controllers;

use App\Models\GlobalHsMaster;
use App\Models\HsUnmappedLog;
use App\Services\GlobalHsService;
use App\Services\ScheduleEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GlobalHsMasterController extends Controller
{
    public function index(Request $request)
    {
        $tab = $request->get('tab', 'all');
        $search = $request->get('search');

        $allHsQuery = GlobalHsMaster::query()
            ->search($search)
            ->bySchedule($request->get('schedule'))
            ->bySector($request->get('sector'))
            ->byMapping($request->get('mapping'));

        if ($request->get('tax_rate') !== null && $request->get('tax_rate') !== '') {
            $allHsQuery->where('tax_rate', floatval($request->get('tax_rate')));
        }

        $allHs = $allHsQuery->orderBy('hs_code')->paginate(25, ['*'], 'all_page')->appends($request->query());

        $unmappedQuery = HsUnmappedLog::select(
                'hs_unmapped_log.hs_code',
                DB::raw('SUM(hs_unmapped_log.frequency_count) as total_frequency'),
                DB::raw('COUNT(DISTINCT hs_unmapped_log.company_id) as company_count'),
                DB::raw('MIN(hs_unmapped_log.first_seen_at) as earliest_seen'),
                DB::raw('MAX(hs_unmapped_log.last_seen_at) as latest_seen')
            )
            ->groupBy('hs_unmapped_log.hs_code')
            ->orderByDesc('total_frequency');

        if ($search) {
            $unmappedQuery->where('hs_unmapped_log.hs_code', 'ilike', "%{$search}%");
        }

        $unmappedHs = $unmappedQuery->paginate(25, ['*'], 'unmapped_page')->appends($request->query());

        $insights = GlobalHsService::getInsights();

        $scheduleTypes = array_keys(ScheduleEngine::$scheduleTypes);
        $sectors = GlobalHsMaster::whereNotNull('sector_tag')
            ->distinct()
            ->pluck('sector_tag')
            ->toArray();

        return view('admin.hs-master', compact(
            'tab', 'allHs', 'unmappedHs', 'insights', 'search',
            'scheduleTypes', 'sectors'
        ));
    }

    public function store(Request $request)
    {
        $request->validate([
            'hs_code' => 'required|string|max:20|unique:global_hs_master,hs_code',
            'description' => 'nullable|string|max:500',
            'pct_code' => 'nullable|string|max:30',
            'schedule_type' => 'required|string|in:standard,reduced,3rd_schedule,exempt,zero_rated',
            'tax_rate' => 'required|numeric|min:0|max:100',
            'default_uom' => 'nullable|string|max:100',
            'sro_number' => 'nullable|string|max:100',
            'sro_item_serial_no' => 'nullable|string|max:100',
            'sector_tag' => 'nullable|string|max:100',
            'risk_weight' => 'nullable|numeric|min:0|max:100',
            'mapping_status' => 'required|string|in:Mapped,Partial,Unmapped',
        ]);

        $rules = ScheduleEngine::resolveValidationRules($request->schedule_type, floatval($request->tax_rate));

        GlobalHsMaster::create([
            'hs_code' => preg_replace('/[^0-9]/', '', $request->hs_code),
            'description' => $request->description,
            'pct_code' => $request->pct_code,
            'schedule_type' => $request->schedule_type,
            'tax_rate' => $request->tax_rate,
            'default_uom' => $request->default_uom,
            'sro_required' => $rules['requires_sro'],
            'sro_number' => $request->sro_number,
            'sro_item_serial_no' => $request->sro_item_serial_no,
            'mrp_required' => $rules['requires_mrp'],
            'sector_tag' => $request->sector_tag,
            'risk_weight' => $request->risk_weight ?? 0,
            'mapping_status' => $request->mapping_status,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        return redirect('/admin/hs-master')->with('success', 'HS code added to Global Master.');
    }

    public function update(Request $request, $id)
    {
        $hs = GlobalHsMaster::findOrFail($id);

        $request->validate([
            'description' => 'nullable|string|max:500',
            'pct_code' => 'nullable|string|max:30',
            'schedule_type' => 'required|string|in:standard,reduced,3rd_schedule,exempt,zero_rated',
            'tax_rate' => 'required|numeric|min:0|max:100',
            'default_uom' => 'nullable|string|max:100',
            'sro_number' => 'nullable|string|max:100',
            'sro_item_serial_no' => 'nullable|string|max:100',
            'sector_tag' => 'nullable|string|max:100',
            'risk_weight' => 'nullable|numeric|min:0|max:100',
            'mapping_status' => 'required|string|in:Mapped,Partial,Unmapped',
        ]);

        $rules = ScheduleEngine::resolveValidationRules($request->schedule_type, floatval($request->tax_rate));

        $hs->update([
            'description' => $request->description,
            'pct_code' => $request->pct_code,
            'schedule_type' => $request->schedule_type,
            'tax_rate' => $request->tax_rate,
            'default_uom' => $request->default_uom,
            'sro_required' => $rules['requires_sro'],
            'sro_number' => $request->sro_number,
            'sro_item_serial_no' => $request->sro_item_serial_no,
            'mrp_required' => $rules['requires_mrp'],
            'sector_tag' => $request->sector_tag,
            'risk_weight' => $request->risk_weight ?? 0,
            'mapping_status' => $request->mapping_status,
            'updated_by' => auth()->id(),
        ]);

        return redirect('/admin/hs-master')->with('success', 'HS code updated successfully.');
    }

    public function seed()
    {
        $result = GlobalHsService::seedFromExistingSources();
        return redirect('/admin/hs-master?tab=insights')->with('success',
            "Seeded {$result['seeded']} new HS codes. {$result['skipped']} already existed. Total: {$result['total']}."
        );
    }

    public function mapUnmapped(Request $request)
    {
        $request->validate([
            'hs_code' => 'required|string|max:20',
            'description' => 'nullable|string|max:500',
            'schedule_type' => 'required|string|in:standard,reduced,3rd_schedule,exempt,zero_rated',
            'tax_rate' => 'required|numeric|min:0|max:100',
            'sector_tag' => 'nullable|string|max:100',
        ]);

        $hsCode = preg_replace('/[^0-9]/', '', $request->hs_code);
        $rules = ScheduleEngine::resolveValidationRules($request->schedule_type, floatval($request->tax_rate));

        GlobalHsMaster::updateOrCreate(
            ['hs_code' => $hsCode],
            [
                'description' => $request->description,
                'schedule_type' => $request->schedule_type,
                'tax_rate' => $request->tax_rate,
                'sro_required' => $rules['requires_sro'],
                'mrp_required' => $rules['requires_mrp'],
                'sector_tag' => $request->sector_tag,
                'mapping_status' => 'Mapped',
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]
        );

        HsUnmappedLog::where('hs_code', $hsCode)->delete();

        return redirect('/admin/hs-master?tab=unmapped')->with('success', "HS {$hsCode} mapped successfully and removed from unmapped log.");
    }

    public function apiLookup(Request $request)
    {
        $hsCode = $request->get('hs_code', '');
        $companyId = app('currentCompanyId');
        $company = \App\Models\Company::find($companyId);
        $standardTaxRate = $company ? $company->getStandardTaxRateValue() : 18.0;

        $result = GlobalHsService::resolveForInvoiceItem($hsCode, $standardTaxRate, $companyId);

        if (isset($result['found']) && $result['found']) {
            $sroSuggestion = GlobalHsService::suggestSro(
                $hsCode,
                $result['schedule_type'] ?? 'standard',
                $result['tax_rate'] ?? null,
                $standardTaxRate
            );
            if ($sroSuggestion) {
                $result['sro_suggestion'] = $sroSuggestion;
            }
            $result['standard_tax_rate'] = $standardTaxRate;
            $result['st_withheld_applicable'] = self::isStWithheldApplicable($hsCode, $result['schedule_type'] ?? 'standard');
            $result['petroleum_levy_applicable'] = self::isPetroleumLevyApplicable($hsCode);
            return response()->json($result);
        }

        $customerNtn = $request->get('customer_ntn');
        $resolved = \App\Services\TaxResolutionService::resolve($hsCode, $company, $customerNtn);
        if ($resolved['pct_code']) {
            $rules = ScheduleEngine::resolveValidationRules($resolved['schedule_type'], $resolved['tax_rate'], $standardTaxRate);
            $resolved['requires_sro'] = $rules['requires_sro'];
            $resolved['requires_serial'] = $rules['requires_serial'];
            $resolved['requires_mrp'] = $rules['requires_mrp'];
            $resolved['standard_tax_rate'] = $standardTaxRate;
            $resolved['st_withheld_applicable'] = self::isStWithheldApplicable($hsCode, $resolved['schedule_type'] ?? 'standard');
            $resolved['petroleum_levy_applicable'] = self::isPetroleumLevyApplicable($hsCode);
            return response()->json($resolved);
        }

        $scheduleResult = ScheduleEngine::lookupByHsCode($hsCode, $standardTaxRate);
        if ($scheduleResult) {
            $scheduleResult['standard_tax_rate'] = $standardTaxRate;
            $scheduleResult['st_withheld_applicable'] = self::isStWithheldApplicable($hsCode, $scheduleResult['schedule_type'] ?? 'standard');
            $scheduleResult['petroleum_levy_applicable'] = self::isPetroleumLevyApplicable($hsCode);
        }
        return response()->json($scheduleResult ?: ['found' => false]);
    }

    public function apiSearch(Request $request)
    {
        $search = $request->get('q', '');
        $limit = min((int) $request->get('limit', 20), 100);

        $query = GlobalHsMaster::query();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('hs_code', 'ilike', "%{$search}%")
                  ->orWhere('description', 'ilike', "%{$search}%")
                  ->orWhere('schedule_type', 'ilike', "%{$search}%")
                  ->orWhere('sector_tag', 'ilike', "%{$search}%")
                  ->orWhere('sro_number', 'ilike', "%{$search}%")
                  ->orWhere('pct_code', 'ilike', "%{$search}%");
            });
        }

        if ($request->get('schedule')) {
            $query->where('schedule_type', $request->get('schedule'));
        }
        if ($request->get('sector')) {
            $query->where('sector_tag', $request->get('sector'));
        }
        if ($request->get('tax_rate') !== null && $request->get('tax_rate') !== '') {
            $query->where('tax_rate', floatval($request->get('tax_rate')));
        }

        $results = $query->orderBy('hs_code')->limit($limit)->get();

        $unmappedFreq = [];
        if ($request->get('include_frequency')) {
            $unmappedFreq = HsUnmappedLog::select('hs_code', DB::raw('SUM(frequency_count) as freq'))
                ->whereIn('hs_code', $results->pluck('hs_code'))
                ->groupBy('hs_code')
                ->pluck('freq', 'hs_code')
                ->toArray();
        }

        return response()->json([
            'results' => $results,
            'frequency' => $unmappedFreq,
            'total' => GlobalHsMaster::count(),
        ]);
    }

    private static function isStWithheldApplicable(string $hsCode, string $scheduleType): bool
    {
        $stWithheldPrefixes = ['2523', '7213', '7214', '7216', '7228', '7308', '8544'];
        $normalized = preg_replace('/[^0-9]/', '', $hsCode);
        foreach ($stWithheldPrefixes as $prefix) {
            if (str_starts_with($normalized, $prefix)) return true;
        }
        return false;
    }

    private static function isPetroleumLevyApplicable(string $hsCode): bool
    {
        $petroleumPrefixes = ['2709', '2710', '2711', '2713'];
        $normalized = preg_replace('/[^0-9]/', '', $hsCode);
        foreach ($petroleumPrefixes as $prefix) {
            if (str_starts_with($normalized, $prefix)) return true;
        }
        return false;
    }
}
