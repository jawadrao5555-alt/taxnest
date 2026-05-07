<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FbrReferenceController extends Controller
{
    public function demo()
    {
        $stats = [
            'fbr_hs_codes'             => DB::table('fbr_hs_codes')->count(),
            'fbr_sros'                 => DB::table('fbr_sros')->count(),
            'fbr_sale_types'           => DB::table('fbr_sale_types')->count(),
            'fbr_uoms'                 => DB::table('fbr_uoms')->count(),
            'fbr_rates'                => DB::table('fbr_rates')->count(),
            'fbr_provinces'            => DB::table('fbr_provinces')->count(),
            'fbr_buyer_types'          => DB::table('fbr_buyer_types')->count(),
            'fbr_document_types'       => DB::table('fbr_document_types')->count(),
            'fbr_reasons'              => DB::table('fbr_reasons')->count(),
            'fbr_petroleum_levy_types' => DB::table('fbr_petroleum_levy_types')->count(),
            'fbr_item_sr_numbers'      => DB::table('fbr_item_sr_numbers')->count(),
        ];

        $saleTypes = DB::table('fbr_sale_types')->orderBy('name')->pluck('name');
        $uoms = DB::table('fbr_uoms')->orderBy('name')->pluck('name');
        $provinces = DB::table('fbr_provinces')->orderBy('name')->pluck('name');
        $buyerTypes = DB::table('fbr_buyer_types')->orderBy('name')->pluck('name');
        $docTypes = DB::table('fbr_document_types')->orderBy('name')->pluck('name');
        $reasons = DB::table('fbr_reasons')->orderBy('name')->pluck('name');
        $rates = DB::table('fbr_rates')->orderBy('label')->get(['label', 'numeric_value']);

        return view('fbr-reference.demo', compact(
            'stats','saleTypes','uoms','provinces','buyerTypes','docTypes','reasons','rates'
        ));
    }

    public function searchHs(Request $request)
    {
        $q = trim((string) $request->input('q', ''));
        // Self-join: subcodes inherit parent description (e.g. 0902.1000 → "Tea" from 0902)
        $query = DB::table('fbr_hs_codes as h')
            ->leftJoin('fbr_hs_codes as p', function ($j) {
                $j->on('p.code', '=', DB::raw("SUBSTRING_INDEX(h.code, '.', 1)"))
                  ->whereRaw("LOCATE('.', h.code) > 0");
            })
            ->where('h.is_active', 1);

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('h.code', 'like', $q.'%')
                  ->orWhere('h.description', 'like', '%'.$q.'%')
                  ->orWhere('p.description', 'like', '%'.$q.'%');
            });
        }

        $rows = $query->orderByRaw('CASE WHEN h.code LIKE ? THEN 0 ELSE 1 END', [$q.'%'])
                      ->orderBy('h.code')
                      ->limit(25)
                      ->get([
                          'h.code',
                          DB::raw('COALESCE(h.description, p.description) as description'),
                          DB::raw('CASE WHEN h.description IS NULL AND p.description IS NOT NULL THEN p.code ELSE NULL END as inherited_from'),
                      ]);

        return response()->json(['count' => $rows->count(), 'results' => $rows]);
    }

    public function searchSro(Request $request)
    {
        $q = trim((string) $request->input('q', ''));
        $query = DB::table('fbr_sros')->where('is_active', 1);
        if ($q !== '') $query->where('sro_number', 'like', '%'.$q.'%');
        return response()->json([
            'results' => $query->orderBy('sro_number')->limit(40)->get(['sro_number']),
        ]);
    }

    public function searchItemSr(Request $request)
    {
        $q = trim((string) $request->input('q', ''));
        $query = DB::table('fbr_item_sr_numbers')->where('is_active', 1);
        if ($q !== '') $query->where('sr_no', 'like', '%'.$q.'%');
        return response()->json([
            'results' => $query->orderByRaw('CAST(sr_no AS UNSIGNED), sr_no')->limit(50)->get(['sr_no']),
        ]);
    }
}
