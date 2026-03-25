<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\ScheduleEngine;
use App\Models\HsUsagePattern;
use App\Helpers\DbCompat;

class SroReferenceController extends Controller
{
    public function index(Request $request)
    {
        $tab = $request->get('tab', 'sro');
        $search = $request->get('search', '');
        $scheduleFilter = $request->get('schedule_type', '');
        $like = DbCompat::like();

        $sroRules = DB::table('special_sro_rules')
            ->when($search, function ($q) use ($search, $like) {
                $q->where(function ($q2) use ($search, $like) {
                    $q2->where('sro_number', $like, "%{$search}%")
                        ->orWhere('serial_no', $like, "%{$search}%")
                        ->orWhere('hs_code', $like, "%{$search}%")
                        ->orWhere('description', $like, "%{$search}%");
                });
            })
            ->when($scheduleFilter, fn($q) => $q->where('schedule_type', $scheduleFilter))
            ->where('is_active', true)
            ->orderBy('sro_number')
            ->orderBy('serial_no')
            ->paginate(25, ['*'], 'sro_page')
            ->appends($request->except('sro_page'));

        $hsWithSro = DB::table('hs_master_global')
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('sro_required', true)
                    ->orWhere('serial_required', true)
                    ->orWhereNotNull('default_sro_number');
            })
            ->when($search, function ($q) use ($search, $like) {
                $q->where(function ($q2) use ($search, $like) {
                    $q2->where('hs_code', $like, "%{$search}%")
                        ->orWhere('description', $like, "%{$search}%")
                        ->orWhere('default_sro_number', $like, "%{$search}%")
                        ->orWhere('schedule_type', $like, "%{$search}%");
                });
            })
            ->when($scheduleFilter, fn($q) => $q->where('schedule_type', $scheduleFilter))
            ->orderBy('hs_code')
            ->paginate(25, ['*'], 'hs_page')
            ->appends($request->except('hs_page'));

        $scheduleTypes = ScheduleEngine::$scheduleTypes;

        $learnedPatternsQuery = HsUsagePattern::whereNotNull('sro_schedule_no')
            ->where('sro_schedule_no', '!=', '')
            ->where('success_count', '>=', 3)
            ->where(function ($q) {
                $q->where('admin_status', 'approved')
                  ->orWhere('admin_status', 'auto');
            })
            ->when($search, function ($q) use ($search, $like) {
                $q->where(function ($q2) use ($search, $like) {
                    $q2->where('hs_code', $like, "%{$search}%")
                        ->orWhere('sro_schedule_no', $like, "%{$search}%")
                        ->orWhere('sro_item_serial_no', $like, "%{$search}%")
                        ->orWhere('schedule_type', $like, "%{$search}%");
                });
            })
            ->when($scheduleFilter, fn($q) => $q->where('schedule_type', $scheduleFilter))
            ->orderByDesc('success_count')
            ->orderByDesc('confidence_score');

        $learnedPatterns = $learnedPatternsQuery->paginate(25, ['*'], 'learned_page')
            ->appends($request->except('learned_page'));

        $stats = [
            'total_sro_rules' => DB::table('special_sro_rules')->where('is_active', true)->count(),
            'total_hs_sro' => DB::table('hs_master_global')->where('is_active', true)->where('sro_required', true)->count(),
            'total_hs_serial' => DB::table('hs_master_global')->where('is_active', true)->where('serial_required', true)->count(),
            'total_learned' => HsUsagePattern::whereNotNull('sro_schedule_no')->where('sro_schedule_no', '!=', '')->where('success_count', '>=', 3)->count(),
            'schedule_breakdown' => DB::table('special_sro_rules')->where('is_active', true)
                ->select('schedule_type', DB::raw('count(*) as count'))
                ->groupBy('schedule_type')
                ->pluck('count', 'schedule_type')
                ->toArray(),
        ];

        return view('sro-reference', compact('sroRules', 'hsWithSro', 'learnedPatterns', 'scheduleTypes', 'stats', 'tab', 'search', 'scheduleFilter'));
    }

    public function apiSearch(Request $request)
    {
        $search = $request->get('q', '');
        $scheduleType = $request->get('schedule_type', '');
        $hsCode = $request->get('hs_code', '');
        $like = DbCompat::like();

        $learnedPatterns = $this->getLearnedPatterns($search, $scheduleType, $hsCode);

        if (strlen($search) < 2 && !$scheduleType) {
            return response()->json(['sro_rules' => [], 'hs_items' => [], 'learned_patterns' => $learnedPatterns]);
        }

        $sroResults = DB::table('special_sro_rules')
            ->where('is_active', true)
            ->when($search, function ($q) use ($search, $like) {
                $q->where(function ($q2) use ($search, $like) {
                    $q2->where('sro_number', $like, "%{$search}%")
                        ->orWhere('serial_no', $like, "%{$search}%")
                        ->orWhere('hs_code', $like, "%{$search}%")
                        ->orWhere('description', $like, "%{$search}%");
                });
            })
            ->when($scheduleType, fn($q) => $q->where('schedule_type', $scheduleType))
            ->orderBy('sro_number')
            ->limit(20)
            ->get();

        $hsResults = DB::table('hs_master_global')
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('sro_required', true)
                    ->orWhere('serial_required', true)
                    ->orWhereNotNull('default_sro_number');
            })
            ->when($search, function ($q) use ($search, $like) {
                $q->where(function ($q2) use ($search, $like) {
                    $q2->where('hs_code', $like, "%{$search}%")
                        ->orWhere('description', $like, "%{$search}%")
                        ->orWhere('default_sro_number', $like, "%{$search}%");
                });
            })
            ->when($scheduleType, fn($q) => $q->where('schedule_type', $scheduleType))
            ->orderBy('hs_code')
            ->limit(20)
            ->get();

        return response()->json([
            'sro_rules' => $sroResults,
            'hs_items' => $hsResults,
            'learned_patterns' => $learnedPatterns,
        ]);
    }

    private function getLearnedPatterns(string $search, string $scheduleType, string $hsCode = ''): array
    {
        $like = DbCompat::like();

        return HsUsagePattern::whereNotNull('sro_schedule_no')
            ->where('sro_schedule_no', '!=', '')
            ->where('success_count', '>=', 3)
            ->where(function ($q) {
                $q->where('admin_status', 'approved')
                  ->orWhere('admin_status', 'auto');
            })
            ->when($hsCode, function ($q) use ($hsCode, $like) {
                $cleanHs = preg_replace('/[^0-9.]/', '', $hsCode);
                $q->where('hs_code', $like, $cleanHs . '%');
            })
            ->when($search && !$hsCode, function ($q) use ($search, $like) {
                $q->where(function ($q2) use ($search, $like) {
                    $q2->where('hs_code', $like, "%{$search}%")
                        ->orWhere('sro_schedule_no', $like, "%{$search}%")
                        ->orWhere('sro_item_serial_no', $like, "%{$search}%")
                        ->orWhere('schedule_type', $like, "%{$search}%");
                });
            })
            ->when($scheduleType, fn($q) => $q->where('schedule_type', $scheduleType))
            ->orderByDesc('success_count')
            ->orderByDesc('confidence_score')
            ->limit(20)
            ->get()
            ->map(function ($p) {
                return [
                    'id' => $p->id,
                    'hs_code' => $p->hs_code,
                    'sro_number' => $p->sro_schedule_no,
                    'serial_no' => $p->sro_item_serial_no,
                    'schedule_type' => $p->schedule_type,
                    'tax_rate' => (float) $p->tax_rate,
                    'success_count' => $p->success_count,
                    'confidence_score' => (float) $p->confidence_score,
                    'source' => 'learned',
                ];
            })
            ->toArray();
    }
}
