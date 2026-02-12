<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HsMasterGlobal;
use App\Models\HsUnmappedQueue;
use App\Services\AuditLogService;
use App\Services\HsIntelligenceService;
use Illuminate\Http\Request;

class HsMasterController extends Controller
{
    public function index(Request $request)
    {
        $query = HsMasterGlobal::query();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('hs_code', 'ilike', "%{$search}%")
                  ->orWhere('description', 'ilike', "%{$search}%");
            });
        }

        if ($schedule = $request->input('schedule_type')) {
            $query->where('schedule_type', $schedule);
        }

        if ($request->filled('tax_rate')) {
            $query->where('default_tax_rate', $request->input('tax_rate'));
        }

        $records = $query->orderBy('hs_code')->paginate(25)->appends($request->query());

        $scheduleTypes = HsMasterGlobal::whereNotNull('schedule_type')
            ->distinct()->pluck('schedule_type')->sort()->values();

        $taxRates = HsMasterGlobal::whereNotNull('default_tax_rate')
            ->distinct()->pluck('default_tax_rate')->sort()->values();

        $stats = [
            'total' => HsMasterGlobal::count(),
            'active' => HsMasterGlobal::where('is_active', true)->count(),
            'unmapped_queue' => HsUnmappedQueue::count(),
        ];

        return view('admin.hs_master.index', compact('records', 'scheduleTypes', 'taxRates', 'stats'));
    }

    public function edit($id)
    {
        $record = HsMasterGlobal::findOrFail($id);
        return view('admin.hs_master.edit', compact('record'));
    }

    public function update(Request $request, $id)
    {
        $record = HsMasterGlobal::findOrFail($id);

        $validated = $request->validate([
            'hs_code' => 'required|string',
            'description' => 'nullable|string',
            'schedule_type' => 'nullable|string',
            'default_tax_rate' => 'nullable|numeric|min:0|max:99.99',
            'sro_required' => 'nullable|boolean',
            'default_sro_number' => 'nullable|string',
            'serial_required' => 'nullable|boolean',
            'default_serial_no' => 'nullable|string',
            'mrp_required' => 'nullable|boolean',
            'st_withheld_applicable' => 'nullable|boolean',
            'petroleum_levy_applicable' => 'nullable|boolean',
            'default_uom' => 'nullable|string',
            'confidence_score' => 'nullable|integer|min:0|max:100',
            'is_active' => 'nullable|boolean',
        ]);

        $oldValues = $record->toArray();

        $validated['sro_required'] = $request->boolean('sro_required');
        $validated['serial_required'] = $request->boolean('serial_required');
        $validated['mrp_required'] = $request->boolean('mrp_required');
        $validated['st_withheld_applicable'] = $request->boolean('st_withheld_applicable');
        $validated['petroleum_levy_applicable'] = $request->boolean('petroleum_levy_applicable');
        $validated['is_active'] = $request->boolean('is_active');
        $validated['last_source'] = 'admin_edit';

        $record->update($validated);

        AuditLogService::log(
            'hs_master_global_update',
            'hs_master_global',
            $record->id,
            $oldValues,
            $record->fresh()->toArray()
        );

        return redirect()->route('admin.hs-master-global.index')
            ->with('success', "HS Code {$record->hs_code} updated successfully.");
    }

    public function unmapped()
    {
        $records = HsUnmappedQueue::with('company')
            ->orderByDesc('usage_count')
            ->paginate(25);

        $suggestions = [];
        $rejections = [];
        foreach ($records as $r) {
            $suggestion = HsIntelligenceService::getLatestSuggestion($r->hs_code);
            if (!$suggestion) {
                $suggestion = HsIntelligenceService::generateSuggestion($r->hs_code);
                if ($suggestion) {
                    $suggestion = (object) $suggestion;
                }
            }
            $suggestions[$r->id] = $suggestion;

            $rejection = HsIntelligenceService::getRejectionHistory($r->hs_code);
            $rejections[$r->id] = $rejection;
        }

        return view('admin.hs_master.unmapped', compact('records', 'suggestions', 'rejections'));
    }

    public function mapFromQueue(Request $request, $id)
    {
        $unmapped = HsUnmappedQueue::findOrFail($id);

        $existing = HsMasterGlobal::where('hs_code', $unmapped->hs_code)->first();
        if ($existing) {
            $unmapped->delete();
            return redirect()->route('admin.hs-master-global.unmapped')
                ->with('success', "HS Code {$unmapped->hs_code} already exists in master. Removed from queue.");
        }

        $decisionType = $request->input('decision_type', 'manual');

        $finalData = [
            'hs_code' => $unmapped->hs_code,
            'description' => $request->input('description'),
            'schedule_type' => $request->input('schedule_type'),
            'default_tax_rate' => $request->input('default_tax_rate'),
            'sro_required' => $request->boolean('sro_required'),
            'serial_required' => $request->boolean('serial_required'),
            'mrp_required' => $request->boolean('mrp_required'),
            'st_withheld_applicable' => $request->boolean('st_withheld_applicable'),
            'petroleum_levy_applicable' => $request->boolean('petroleum_levy_applicable'),
            'default_uom' => $request->input('default_uom'),
            'confidence_score' => $request->input('confidence_score', 50),
            'last_source' => 'mapped_from_queue_' . $decisionType,
            'is_active' => true,
        ];

        $newRecord = HsMasterGlobal::create($finalData);

        $integrityHash = hash('sha256', json_encode([
            'hs_code' => $newRecord->hs_code,
            'schedule_type' => $newRecord->schedule_type,
            'default_tax_rate' => $newRecord->default_tax_rate,
            'decision_type' => $decisionType,
            'admin_id' => auth()->id(),
            'timestamp' => now()->toIso8601String(),
        ]));

        AuditLogService::log(
            'hs_master_intelligence_mapped',
            'hs_master_global',
            $newRecord->id,
            [
                'source' => 'hs_unmapped_queue',
                'queue_id' => $unmapped->id,
                'decision_type' => $decisionType,
                'integrity_hash' => $integrityHash,
            ],
            $newRecord->toArray()
        );

        $unmapped->delete();

        return redirect()->route('admin.hs-master-global.unmapped')
            ->with('success', "HS Code {$newRecord->hs_code} mapped successfully ({$decisionType}). Integrity hash: " . substr($integrityHash, 0, 12) . '...');
    }

    public function rejectSuggestion(Request $request, $id)
    {
        $unmapped = HsUnmappedQueue::findOrFail($id);
        $reason = $request->input('rejection_reason', 'Admin rejected suggestion');

        HsIntelligenceService::recordRejection($unmapped->hs_code, $reason);

        HsIntelligenceService::generateSuggestion($unmapped->hs_code);

        return redirect()->route('admin.hs-master-global.unmapped')
            ->with('success', "Suggestion rejected for HS Code {$unmapped->hs_code}. Rejection recorded and new suggestion generated.");
    }

    public function regenerateSuggestion($id)
    {
        $unmapped = HsUnmappedQueue::findOrFail($id);

        HsIntelligenceService::generateSuggestion($unmapped->hs_code);

        return redirect()->route('admin.hs-master-global.unmapped')
            ->with('success', "Suggestion regenerated for HS Code {$unmapped->hs_code}.");
    }
}
