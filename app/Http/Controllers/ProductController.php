<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $companyId = app('currentCompanyId');
        $search = $request->get('search', '');

        $query = Product::where('company_id', $companyId);

        if ($search) {
            $like = \App\Helpers\DbCompat::like();
            $query->where(function($q) use ($search, $like) {
                $q->where('name', $like, "%{$search}%")
                  ->orWhere('hs_code', $like, "%{$search}%")
                  ->orWhere('pct_code', $like, "%{$search}%")
                  ->orWhere('schedule_type', $like, "%{$search}%");
            });
        }

        $products = $query->orderBy('name')->paginate(20);
        // Usage-vs-cap banner (Task 362): visibility for companies at/over their
        // plan's product cap (e.g. after a downgrade). null = unlimited.
        $productLimitStatus = \App\Services\PlanLimitService::productLimitStatus((int) $companyId, 'fbr');
        return view('products.index', compact('products', 'search', 'productLimitStatus'));
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
            'barcode' => 'nullable|string|max:80',
            'sku' => 'nullable|string|max:80',
            'aliases_text' => 'nullable|string|max:4000',
            'hs_code' => 'required|string|max:50',
            'pct_code' => 'nullable|string|max:50',
            'default_tax_rate' => 'required|integer|min:0|max:100',
            'tax_type' => 'nullable|in:taxable,exempt,custom',
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

        $taxType = $request->tax_type ?? 'taxable';
        if ($scheduleType === 'exempt' || $scheduleType === 'zero_rated') {
            $taxType = 'exempt';
        } elseif ($taxRate != 18 && $taxRate > 0) {
            $taxType = 'custom';
        }

        $product = Product::create([
            'company_id' => $companyId,
            'name' => $request->name,
            'barcode' => $request->barcode,
            'sku' => $request->sku,
            'hs_code' => $request->hs_code,
            'pct_code' => $request->pct_code,
            'default_tax_rate' => $request->default_tax_rate,
            'tax_type' => $taxType,
            'uom' => $request->uom,
            'schedule_type' => $request->schedule_type,
            'sro_reference' => $request->sro_reference,
            'serial_number' => $request->serial_number,
            'mrp' => $request->mrp,
            'default_price' => $request->default_price,
        ]);
        $this->syncAliases($product, (string) $request->input('aliases_text', ''));

        return redirect('/products')->with('success', 'Product created successfully.');
    }

    public function edit(Product $product)
    {
        $companyId = app('currentCompanyId');
        if ($product->company_id !== $companyId) abort(403);
        $aliasesText = '';
        if (Schema::hasTable('product_aliases')) {
            $aliasesText = $product->aliases()->where('is_active', true)->orderBy('alias')->pluck('alias')->implode("\n");
        }
        return view('products.edit', compact('product', 'aliasesText'));
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
            'barcode' => 'nullable|string|max:80',
            'sku' => 'nullable|string|max:80',
            'aliases_text' => 'nullable|string|max:4000',
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

        $taxType = $request->tax_type ?? 'taxable';
        if ($scheduleType === 'exempt' || $scheduleType === 'zero_rated') {
            $taxType = 'exempt';
        } elseif ($taxRate != 18 && $taxRate > 0) {
            $taxType = 'custom';
        }

        $product->update(array_merge($request->only([
            'name', 'barcode', 'sku', 'hs_code', 'pct_code', 'default_tax_rate',
            'uom', 'schedule_type', 'sro_reference', 'serial_number', 'mrp', 'default_price'
        ]), ['tax_type' => $taxType]));
        $this->syncAliases($product, (string) $request->input('aliases_text', ''));

        return redirect('/products')->with('success', 'Product updated successfully.');
    }

    public function deactivate(Product $product)
    {
        $companyId = app('currentCompanyId');
        if ($product->company_id !== $companyId) abort(403);
        $product->update(['is_active' => !$product->is_active]);
        return redirect('/products')->with('success', 'Product status updated.');
    }

    public function destroy(Product $product)
    {
        $companyId = app('currentCompanyId');
        if ($product->company_id !== $companyId) abort(403);

        $productName = $product->name;
        $product->delete();

        return redirect('/products')->with('success', "Product \"{$productName}\" deleted successfully.");
    }

    public function search(Request $request)
    {
        $companyId = app('currentCompanyId');
        $query = $request->get('q', '');
        $products = Product::where('company_id', $companyId)
            ->where('is_active', true)
            ->where(function($q) use ($query) {
                $like = \App\Helpers\DbCompat::like();
                $q->where('name', $like, "%{$query}%")
                  ->orWhere('hs_code', $like, "%{$query}%");
            })
            ->take(20)
            ->get(['id', 'name', 'hs_code', 'pct_code', 'default_tax_rate', 'tax_type', 'uom', 'default_price', 'schedule_type', 'sro_reference', 'serial_number', 'mrp']);

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

        // Subscription access gate (Task 362 review): this route has NO
        // plan.limit middleware, so the middleware's Step-1 access check is
        // applied here — suspended/expired/trial-ended companies are blocked.
        // FAIL CLOSED; the only pass-through is the schema-compat guard.
        if (\Illuminate\Support\Facades\Schema::hasTable('subscriptions')) {
            $accessCompany = \App\Models\Company::find($companyId);
            if ($accessCompany) {
                $access = \App\Services\SubscriptionAccessService::hasAccess($accessCompany);
                if (!$access['allowed']) {
                    $reason = \App\Services\SubscriptionAccessService::localizedLockReason($access['reason']);
                    return response()->json(['error' => $reason, 'message' => $reason], 403);
                }
            }
        }

        // Plan product cap (Task 362): enforce the same cap as products.store.
        // 🔒 ATOMIC QUOTA ADMISSION (import pattern): count + insert in ONE
        // transaction under a company-row lock — concurrent quick-creates at
        // the last free slot serialize and can never exceed the cap.
        try {
            $product = \Illuminate\Support\Facades\DB::transaction(function () use ($companyId, $request) {
                \App\Models\Company::where('id', $companyId)->lockForUpdate()->get();
                $remaining = \App\Services\PlanLimitService::remainingProductAllowance($companyId, 'fbr');
                if ($remaining !== null && $remaining <= 0) {
                    throw new \App\Exceptions\PlanLimitReachedException();
                }
                return Product::create([
                    'company_id' => $companyId,
                    'name' => $request->name,
                    'hs_code' => $request->hs_code,
                    'default_price' => $request->default_price ?? 0,
                    'uom' => $request->uom ?? 'Numbers, pieces, units',
                    'schedule_type' => $request->schedule_type ?? 'standard',
                    'default_tax_rate' => $request->default_tax_rate ?? 18,
                    'is_active' => true,
                ]);
            });
        } catch (\App\Exceptions\PlanLimitReachedException $e) {
            $msg = 'Product limit reached for your plan. Please upgrade your subscription to add more products.';
            return response()->json(['error' => $msg, 'message' => $msg], 403);
        }

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

    /** Approved aliases are exact supplier/invoice spellings, never AI guesses. */
    private function syncAliases(Product $product, string $text): void
    {
        if (!Schema::hasTable('product_aliases')) {
            return;
        }
        $aliases = collect(preg_split('/\R/u', $text) ?: [])
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique(fn ($value) => mb_strtolower($value))
            ->take(50)
            ->values();
        $product->aliases()->delete();
        foreach ($aliases as $alias) {
            $product->aliases()->create([
                'company_id' => $product->company_id,
                'alias' => mb_substr($alias, 0, 255),
                'is_active' => true,
            ]);
        }
    }
}
