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
        $rules = [
            'name' => 'required|string|max:255',
            'hs_code' => 'required|string|max:50',
            'pct_code' => 'nullable|string|max:50',
            'default_tax_rate' => 'required|integer|min:0|max:100',
            'uom' => 'required|string|max:20',
            'schedule_type' => 'nullable|string|max:100',
            'sro_reference' => 'nullable|string|max:100',
            'default_price' => 'required|numeric|min:0',
        ];

        if ((int) $request->default_tax_rate < 18) {
            $rules['sro_reference'] = 'required|string|max:100';
        }

        $request->validate($rules);

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

        $rules = [
            'name' => 'required|string|max:255',
            'hs_code' => 'required|string|max:50',
            'pct_code' => 'nullable|string|max:50',
            'default_tax_rate' => 'required|integer|min:0|max:100',
            'uom' => 'required|string|max:20',
            'schedule_type' => 'nullable|string|max:100',
            'sro_reference' => 'nullable|string|max:100',
            'default_price' => 'required|numeric|min:0',
        ];

        if ((int) $request->default_tax_rate < 18) {
            $rules['sro_reference'] = 'required|string|max:100';
        }

        $request->validate($rules);

        $product->update($request->only([
            'name', 'hs_code', 'pct_code', 'default_tax_rate',
            'uom', 'schedule_type', 'sro_reference', 'default_price'
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
            ->get(['id', 'name', 'hs_code', 'pct_code', 'default_tax_rate', 'uom', 'default_price', 'schedule_type', 'sro_reference']);

        $products->transform(function ($product) {
            $config = \App\Services\ScheduleEngine::getScheduleConfig($product->schedule_type ?? 'standard');
            $product->requires_sro = $config['requires_sro'];
            $product->requires_serial = $config['requires_serial'];
            $product->requires_mrp = $config['requires_mrp'];
            return $product;
        });

        return response()->json($products);
    }
}
