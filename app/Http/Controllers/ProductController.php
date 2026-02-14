<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $companyId = app('currentCompanyId');
        $search = $request->get('search', '');

        $query = Product::where('company_id', $companyId);

        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('hs_code', 'ilike', "%{$search}%")
                  ->orWhere('pct_code', 'ilike', "%{$search}%")
                  ->orWhere('schedule_type', 'ilike', "%{$search}%");
            });
        }

        $products = $query->orderBy('name')->paginate(20);
        return view('products.index', compact('products', 'search'));
    }

    public function create()
    {
        return view('products.create');
    }

    public function store(Request $request)
    {
        $scheduleType = $request->schedule_type ?? 'standard';
        $taxRate = (float) ($request->default_tax_rate ?? 18);
        $rules = \App\Services\ScheduleEngine::resolveValidationRules($scheduleType, $taxRate);

        $validationRules = [
            'name' => 'required|string|max:255',
            'hs_code' => 'required|string|max:50',
            'pct_code' => 'nullable|string|max:50',
            'default_tax_rate' => 'required|integer|min:0|max:100',
            'uom' => 'required|string|max:20',
            'schedule_type' => 'nullable|string|max:100',
            'sro_reference' => 'nullable|string|max:100',
            'serial_number' => 'nullable|string|max:100',
            'mrp' => 'nullable|numeric|min:0',
            'default_price' => 'required|numeric|min:0',
        ];

        if ($rules['requires_sro']) {
            $validationRules['sro_reference'] = 'required|string|max:100';
        }
        if ($rules['requires_serial']) {
            $validationRules['serial_number'] = 'required|string|max:100';
        }
        if ($rules['requires_mrp']) {
            $validationRules['mrp'] = 'required|numeric|min:0.01';
        }

        $request->validate($validationRules);

        $companyId = app('currentCompanyId');

        Product::create([
            'company_id' => $companyId,
            'name' => $request->name,
            'hs_code' => $request->hs_code,
            'pct_code' => $request->pct_code,
            'default_tax_rate' => $request->default_tax_rate,
            'uom' => $request->uom,
            'schedule_type' => $request->schedule_type,
            'sro_reference' => $request->sro_reference,
            'serial_number' => $request->serial_number,
            'mrp' => $request->mrp,
            'default_price' => $request->default_price,
        ]);

        return redirect('/products')->with('success', 'Product created successfully.');
    }

    public function edit(Product $product)
    {
        $companyId = app('currentCompanyId');
        if ($product->company_id !== $companyId) abort(403);
        return view('products.edit', compact('product'));
    }

    public function update(Request $request, Product $product)
    {
        $companyId = app('currentCompanyId');
        if ($product->company_id !== $companyId) abort(403);

        $scheduleType = $request->schedule_type ?? 'standard';
        $taxRate = (float) ($request->default_tax_rate ?? 18);
        $engineRules = \App\Services\ScheduleEngine::resolveValidationRules($scheduleType, $taxRate);

        $validationRules = [
            'name' => 'required|string|max:255',
            'hs_code' => 'required|string|max:50',
            'pct_code' => 'nullable|string|max:50',
            'default_tax_rate' => 'required|integer|min:0|max:100',
            'uom' => 'required|string|max:20',
            'schedule_type' => 'nullable|string|max:100',
            'sro_reference' => 'nullable|string|max:100',
            'serial_number' => 'nullable|string|max:100',
            'mrp' => 'nullable|numeric|min:0',
            'default_price' => 'required|numeric|min:0',
        ];

        if ($engineRules['requires_sro']) {
            $validationRules['sro_reference'] = 'required|string|max:100';
        }
        if ($engineRules['requires_serial']) {
            $validationRules['serial_number'] = 'required|string|max:100';
        }
        if ($engineRules['requires_mrp']) {
            $validationRules['mrp'] = 'required|numeric|min:0.01';
        }

        $request->validate($validationRules);

        $product->update($request->only([
            'name', 'hs_code', 'pct_code', 'default_tax_rate',
            'uom', 'schedule_type', 'sro_reference', 'serial_number', 'mrp', 'default_price'
        ]));

        return redirect('/products')->with('success', 'Product updated successfully.');
    }

    public function deactivate(Product $product)
    {
        $companyId = app('currentCompanyId');
        if ($product->company_id !== $companyId) abort(403);
        $product->update(['is_active' => !$product->is_active]);
        return redirect('/products')->with('success', 'Product status updated.');
    }

    public function search(Request $request)
    {
        $companyId = app('currentCompanyId');
        $query = $request->get('q', '');
        $products = Product::where('company_id', $companyId)
            ->where('is_active', true)
            ->where(function($q) use ($query) {
                $q->where('name', 'ilike', "%{$query}%")
                  ->orWhere('hs_code', 'ilike', "%{$query}%");
            })
            ->take(20)
            ->get(['id', 'name', 'hs_code', 'pct_code', 'default_tax_rate', 'uom', 'default_price', 'schedule_type', 'sro_reference', 'serial_number', 'mrp']);

        $products->transform(function ($product) {
            $rules = \App\Services\ScheduleEngine::resolveValidationRules($product->schedule_type ?? 'standard', (float)($product->default_tax_rate ?? 18));
            $product->requires_sro = $rules['requires_sro'];
            $product->requires_serial = $rules['requires_serial'];
            $product->requires_mrp = $rules['requires_mrp'];
            return $product;
        });

        return response()->json($products);
    }

    public function quickCreate(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'hs_code' => 'required|string|max:50',
            'default_price' => 'nullable|numeric|min:0',
            'uom' => 'nullable|string|max:100',
            'schedule_type' => 'nullable|string|max:100',
            'default_tax_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        $companyId = app('currentCompanyId');

        $product = Product::create([
            'company_id' => $companyId,
            'name' => $request->name,
            'hs_code' => $request->hs_code,
            'default_price' => $request->default_price ?? 0,
            'uom' => $request->uom ?? 'Numbers, pieces, units',
            'schedule_type' => $request->schedule_type ?? 'standard',
            'default_tax_rate' => $request->default_tax_rate ?? 18,
            'is_active' => true,
        ]);

        return response()->json([
            'id' => $product->id,
            'name' => $product->name,
            'hs_code' => $product->hs_code,
            'pct_code' => $product->pct_code,
            'default_price' => $product->default_price,
            'uom' => $product->uom,
            'schedule_type' => $product->schedule_type,
            'default_tax_rate' => $product->default_tax_rate,
            'sro_reference' => $product->sro_reference,
        ]);
    }
}
