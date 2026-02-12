<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\ScheduleEngine;

class SroReferenceController extends Controller
{
    public function index(Request $request)
    {
        $tab = $request->get('tab', 'sro');
        $search = $request->get('search', '');
        $scheduleFilter = $request->get('schedule_type', '');

        $sroRules = DB::table('special_sro_rules')
            ->when($search, function ($q) use ($search) {
                $q->where(function ($q2) use ($search) {
                    $q2->where('sro_number', 'ilike', "%{$search}%")
                        ->orWhere('serial_no', 'ilike', "%{$search}%")
                        ->orWhere('hs_code', 'ilike', "%{$search}%")
                        ->orWhere('description', 'ilike', "%{$search}%");
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
            ->when($search, function ($q) use ($search) {
                $q->where(function ($q2) use ($search) {
                    $q2->where('hs_code', 'ilike', "%{$search}%")
                        ->orWhere('description', 'ilike', "%{$search}%")
                        ->orWhere('default_sro_number', 'ilike', "%{$search}%")
                        ->orWhere('schedule_type', 'ilike', "%{$search}%");
                });
            })
            ->when($scheduleFilter, fn($q) => $q->where('schedule_type', $scheduleFilter))
            ->orderBy('hs_code')
            ->paginate(25, ['*'], 'hs_page')
            ->appends($request->except('hs_page'));

        $scheduleTypes = ScheduleEngine::$scheduleTypes;

        $stats = [
            'total_sro_rules' => DB::table('special_sro_rules')->where('is_active', true)->count(),
            'total_hs_sro' => DB::table('hs_master_global')->where('is_active', true)->where('sro_required', true)->count(),
            'total_hs_serial' => DB::table('hs_master_global')->where('is_active', true)->where('serial_required', true)->count(),
            'schedule_breakdown' => DB::table('special_sro_rules')->where('is_active', true)
                ->select('schedule_type', DB::raw('count(*) as count'))
                ->groupBy('schedule_type')
                ->pluck('count', 'schedule_type')
                ->toArray(),
        ];

        return view('sro-reference', compact('sroRules', 'hsWithSro', 'scheduleTypes', 'stats', 'tab', 'search', 'scheduleFilter'));
    }

    public function apiSearch(Request $request)
    {
        $search = $request->get('q', '');
        $scheduleType = $request->get('schedule_type', '');

        if (strlen($search) < 2 && !$scheduleType) {
            return response()->json(['sro_rules' => [], 'hs_items' => []]);
        }

        $sroResults = DB::table('special_sro_rules')
            ->where('is_active', true)
            ->when($search, function ($q) use ($search) {
                $q->where(function ($q2) use ($search) {
                    $q2->where('sro_number', 'ilike', "%{$search}%")
                        ->orWhere('serial_no', 'ilike', "%{$search}%")
                        ->orWhere('hs_code', 'ilike', "%{$search}%")
                        ->orWhere('description', 'ilike', "%{$search}%");
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
            ->when($search, function ($q) use ($search) {
                $q->where(function ($q2) use ($search) {
                    $q2->where('hs_code', 'ilike', "%{$search}%")
                        ->orWhere('description', 'ilike', "%{$search}%")
                        ->orWhere('default_sro_number', 'ilike', "%{$search}%");
                });
            })
            ->when($scheduleType, fn($q) => $q->where('schedule_type', $scheduleType))
            ->orderBy('hs_code')
            ->limit(20)
            ->get();

        return response()->json([
            'sro_rules' => $sroResults,
            'hs_items' => $hsResults,
        ]);
    }
}
