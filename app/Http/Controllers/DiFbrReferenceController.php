<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Services\DiFbrReferences;
use App\Services\FbrService;
use App\Services\ScheduleEngine;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class DiFbrReferenceController extends Controller
{
    public function __invoke(Request $request, DiFbrReferences $references, FbrService $fbr)
    {
        $data = $request->validate([
            'invoice_date' => 'required|date_format:Y-m-d|before_or_equal:today',
            'branch_id' => 'nullable|integer|min:1',
            'schedule_type' => ['required', Rule::in(['standard', 'reduced', '3rd_schedule', 'exempt', 'zero_rated', 'fed_services', 'services'])],
            'hs' => ['nullable', 'regex:/^\d{4}\.?\d{4}$/D'],
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'sro' => 'nullable|string|max:100',
        ]);

        $company = Company::findOrFail(app('currentCompanyId'));
        $branch = isset($data['branch_id'])
            ? Branch::where('company_id', $company->id)->findOrFail($data['branch_id'])
            : null;
        $province = $fbr->normalizeProvince($branch?->province ?? $company->province);
        $rate = $data['schedule_type'] === 'exempt' ? 'Exempt' : (isset($data['tax_rate']) ? $data['tax_rate'].'%' : '');

        return response()->json([
            'province' => $province,
            'options' => $references->options($company, $data['invoice_date'], $province, [
                'sale_type' => ScheduleEngine::mapSaleType($data['schedule_type']),
                'hs' => $data['hs'] ?? '',
                'rate' => $rate,
                'schedule' => $data['sro'] ?? '',
            ]),
        ])->header('Cache-Control', 'private, no-store');
    }
}
