<?php

namespace App\Http\Controllers;

use App\Models\HsCodeMapping;
use App\Models\HsMappingResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;

class HsCodeMappingController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->get('search', '');
        $saleTypeFilter = $request->get('sale_type', '');
        $statusFilter = $request->get('status', '');
        $tab = $request->get('tab', 'mappings');

        $query = HsCodeMapping::query();

        if ($search) {
            $like = \App\Helpers\DbCompat::like();
            $query->where(function ($q) use ($search, $like) {
                $q->where('hs_code', $like, "%{$search}%")
                  ->orWhere('label', $like, "%{$search}%")
                  ->orWhere('sro_number', $like, "%{$search}%")
                  ->orWhere('pct_code', $like, "%{$search}%");
            });
        }

        if ($saleTypeFilter) {
            $query->where('sale_type', $saleTypeFilter);
        }

        if ($statusFilter === 'active') {
            $query->where('is_active', true);
        } elseif ($statusFilter === 'inactive') {
            $query->where('is_active', false);
        }

        $mappings = $query->orderBy('hs_code')->orderBy('priority')->paginate(25)->appends($request->query());

        $stats = [
            'total' => HsCodeMapping::count(),
            'active' => HsCodeMapping::where('is_active', true)->count(),
            'inactive' => HsCodeMapping::where('is_active', false)->count(),
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

        $historyData = null;
        if ($tab === 'history') {
            $historyData = $this->getHistoryData($request);
        }

        return view('admin.hs-mapping-engine', compact('mappings', 'stats', 'search', 'saleTypeFilter', 'statusFilter', 'tab', 'analyticsData', 'historyData'));
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

        $mapping = HsCodeMapping::create($validated);

        $this->logAudit($mapping->id, 'created', null, null, null, $mapping->toArray());

        return redirect()->route('admin.hs-mapping-engine')->with('success', 'HS Code Mapping created successfully.');
    }

    public function update(Request $request, $id)
    {
        $mapping = HsCodeMapping::findOrFail($id);
        $oldValues = $mapping->toArray();

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

        $trackFields = ['hs_code', 'label', 'sale_type', 'tax_rate', 'sro_applicable', 'sro_number',
            'serial_number_applicable', 'serial_number_value', 'mrp_required', 'pct_code', 'default_uom',
            'buyer_type', 'priority', 'is_active'];
        foreach ($trackFields as $field) {
            $old = $oldValues[$field] ?? null;
            $new = $mapping->$field;
            if ((string) $old !== (string) $new) {
                $this->logAudit($mapping->id, 'updated', $field, $old, $new);
            }
        }

        return redirect()->route('admin.hs-mapping-engine')->with('success', 'Mapping updated successfully.');
    }

    public function destroy($id)
    {
        $mapping = HsCodeMapping::findOrFail($id);
        $this->logAudit($mapping->id, 'deleted', null, null, null, $mapping->toArray());
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

        $this->logAudit($clone->id, 'cloned', 'source_id', null, $original->id, $clone->toArray());

        return redirect()->route('admin.hs-mapping-engine')->with('success', 'Mapping cloned successfully (ID: ' . $clone->id . ').');
    }

    public function exportCsv()
    {
        $mappings = HsCodeMapping::orderBy('hs_code')->orderBy('priority')->get();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="hs_code_mappings_' . date('Y-m-d_His') . '.csv"',
        ];

        $callback = function () use ($mappings) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['hs_code', 'label', 'sale_type', 'tax_rate', 'sro_applicable', 'sro_number',
                'serial_number_applicable', 'serial_number_value', 'mrp_required', 'pct_code', 'default_uom',
                'buyer_type', 'priority', 'is_active', 'notes']);

            foreach ($mappings as $m) {
                fputcsv($file, [
                    $m->hs_code, $m->label, $m->sale_type, $m->tax_rate,
                    $m->sro_applicable ? '1' : '0', $m->sro_number,
                    $m->serial_number_applicable ? '1' : '0', $m->serial_number_value,
                    $m->mrp_required ? '1' : '0', $m->pct_code, $m->default_uom,
                    $m->buyer_type ?: '', $m->priority, $m->is_active ? '1' : '0', $m->notes,
                ]);
            }
            fclose($file);
        };

        return Response::stream($callback, 200, $headers);
    }

    public function importCsv(Request $request)
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        $file = $request->file('csv_file');
        $handle = fopen($file->getPathname(), 'r');

        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            return redirect()->route('admin.hs-mapping-engine')->with('error', 'CSV file is empty or invalid.');
        }

        $header = array_map('trim', array_map('strtolower', $header));
        $requiredCols = ['hs_code', 'sale_type', 'tax_rate'];
        foreach ($requiredCols as $col) {
            if (!in_array($col, $header)) {
                fclose($handle);
                return redirect()->route('admin.hs-mapping-engine')
                    ->with('error', 'CSV missing required column: ' . $col . '. Required columns: hs_code, sale_type, tax_rate');
            }
        }

        $imported = 0;
        $skipped = 0;
        $errors = [];
        $lineNum = 1;
        $validSaleTypes = ['standard', 'reduced', '3rd_schedule', 'exempt', 'zero_rated'];
        $validBuyerTypes = ['registered', 'unregistered', ''];

        while (($row = fgetcsv($handle)) !== false) {
            $lineNum++;
            if (count($row) < count($header)) {
                $errors[] = "Line {$lineNum}: Insufficient columns";
                continue;
            }

            $row = array_slice(array_map('trim', $row), 0, count($header));
            $data = array_combine($header, $row);
            if ($data === false) {
                $errors[] = "Line {$lineNum}: Malformed row";
                continue;
            }

            if (empty($data['hs_code'])) {
                $errors[] = "Line {$lineNum}: Empty HS code";
                continue;
            }
            if (!in_array($data['sale_type'] ?? '', $validSaleTypes)) {
                $errors[] = "Line {$lineNum}: Invalid sale_type '" . ($data['sale_type'] ?? '') . "'";
                continue;
            }
            $taxRate = floatval($data['tax_rate'] ?? 0);
            if ($taxRate < 0 || $taxRate > 100) {
                $errors[] = "Line {$lineNum}: Tax rate out of range (0-100)";
                continue;
            }

            $buyerType = !empty($data['buyer_type']) && in_array($data['buyer_type'], ['registered', 'unregistered']) ? $data['buyer_type'] : null;

            $dupQuery = HsCodeMapping::where('hs_code', $data['hs_code'])
                ->where('sale_type', $data['sale_type'])
                ->where('tax_rate', $taxRate);
            if ($buyerType) {
                $dupQuery->where('buyer_type', $buyerType);
            } else {
                $dupQuery->where(function ($q) { $q->whereNull('buyer_type')->orWhere('buyer_type', ''); });
            }

            if ($dupQuery->exists()) {
                $skipped++;
                continue;
            }

            $mapping = HsCodeMapping::create([
                'hs_code' => $data['hs_code'],
                'label' => $data['label'] ?? null,
                'sale_type' => $data['sale_type'],
                'tax_rate' => $taxRate,
                'sro_applicable' => ($data['sro_applicable'] ?? '0') === '1',
                'sro_number' => $data['sro_number'] ?? null,
                'serial_number_applicable' => ($data['serial_number_applicable'] ?? '0') === '1',
                'serial_number_value' => $data['serial_number_value'] ?? null,
                'mrp_required' => ($data['mrp_required'] ?? '0') === '1',
                'pct_code' => $data['pct_code'] ?? null,
                'default_uom' => $data['default_uom'] ?? null,
                'buyer_type' => $buyerType,
                'priority' => intval($data['priority'] ?? 10),
                'is_active' => ($data['is_active'] ?? '1') !== '0',
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $this->logAudit($mapping->id, 'imported', null, null, null, $mapping->toArray());
            $imported++;
        }
        fclose($handle);

        $msg = "Import complete: {$imported} created, {$skipped} duplicates skipped.";
        if (count($errors) > 0) {
            $msg .= ' ' . count($errors) . ' errors: ' . implode('; ', array_slice($errors, 0, 5));
            if (count($errors) > 5) $msg .= '... and ' . (count($errors) - 5) . ' more.';
        }

        return redirect()->route('admin.hs-mapping-engine')->with($imported > 0 ? 'success' : 'error', $msg);
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

    private function logAudit($mappingId, $action, $fieldName = null, $oldValue = null, $newValue = null, $snapshot = null)
    {
        DB::table('hs_mapping_audit_logs')->insert([
            'hs_code_mapping_id' => $mappingId,
            'action' => $action,
            'field_name' => $fieldName,
            'old_value' => is_array($oldValue) || is_object($oldValue) ? json_encode($oldValue) : $oldValue,
            'new_value' => is_array($newValue) || is_object($newValue) ? json_encode($newValue) : $newValue,
            'changed_by' => auth()->id(),
            'snapshot' => $snapshot ? json_encode($snapshot) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function getHistoryData(Request $request)
    {
        $query = DB::table('hs_mapping_audit_logs')
            ->leftJoin('users', 'hs_mapping_audit_logs.changed_by', '=', 'users.id')
            ->select(
                'hs_mapping_audit_logs.*',
                'users.name as user_name'
            )
            ->orderByDesc('hs_mapping_audit_logs.created_at');

        $hsFilter = $request->get('history_hs', '');
        if ($hsFilter) {
            $mappingIds = HsCodeMapping::where('hs_code', \App\Helpers\DbCompat::like(), "%{$hsFilter}%")->pluck('id');
            $query->whereIn('hs_mapping_audit_logs.hs_code_mapping_id', $mappingIds);
        }

        return $query->paginate(20)->appends($request->query());
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
