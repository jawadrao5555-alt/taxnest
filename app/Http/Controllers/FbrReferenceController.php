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
        $query = DB::table('fbr_hs_codes')->where('is_active', 1);
        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('code', 'like', $q.'%')
                  ->orWhere('description', 'like', '%'.$q.'%');
            });
        }
        $rows = $query->orderByRaw('CASE WHEN code LIKE ? THEN 0 ELSE 1 END', [$q.'%'])
                      ->limit(20)
                      ->get(['code','description']);
        return response()->json(['count' => $rows->count(), 'results' => $rows]);
    }

    public function searchSro(Request $request)
    {
        $q = trim((string) $request->input('q', ''));
        $query = DB::table('fbr_sros')->where('is_active', 1);
        if ($q !== '') $query->where('sro_number', 'like', '%'.$q.'%');
        return response()->json([
            'results' => $query->orderBy('sro_number')->limit(30)->pluck('sro_number'),
        ]);
    }
}
