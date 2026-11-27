<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Services\DiProductTaxProfile;
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
            'product_id' => 'nullable|integer|min:1',
            'profile_revision' => ['required_with:product_id', 'nullable', 'regex:/^[a-f0-9]{64}$/D'],
            'schedule_type' => ['required_without:product_id', 'nullable', Rule::in(['standard', 'reduced', '3rd_schedule', 'exempt', 'zero_rated', 'fed_services', 'services'])],
            'hs' => ['nullable', 'regex:/^\d{4}\.?\d{4}$/D'],
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'sro' => 'nullable|string|max:100',
        ]);

        $company = Company::findOrFail(app('currentCompanyId'));
        $branch = isset($data['branch_id'])
            ? Branch::where('company_id', $company->id)->findOrFail($data['branch_id'])
            : null;
        $province = $fbr->normalizeProvince($branch?->province ?? $company->province);
        if (isset($data['product_id'])) {
            $product = Product::where('company_id', $company->id)->where('is_active', true)->findOrFail($data['product_id']);
            $errors = DiProductTaxProfile::hasVerifiedDefaults($product) && $product->di_tax_verification['fingerprint'] === $data['profile_revision']
                ? app(DiProductTaxProfile::class)->errors($company, $data['invoice_date'], $province, $product->only(DiProductTaxProfile::FIELDS))
                : ['The saved product defaults have changed. Select the product again or review the invoice tax fields.'];

            return response()->json(['province' => $province, 'errors' => $errors])->header('Cache-Control', 'private, no-store');
        }
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
