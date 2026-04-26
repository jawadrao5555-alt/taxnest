<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\PricingService;
use Illuminate\Http\Request;

/**
 * 🎯 PHASE 3 — Smart Pricing Admin UI
 *
 * Shows products with PricingService suggestions + apply-to-product action.
 */
class PricingController extends Controller
{
    public function __construct(private PricingService $pricing) {}

    public function index(Request $request)
    {
        $companyId = $request->input('company_id');
        $verdictFilter = $request->input('verdict'); // fast_selling | slow_selling | typical | insufficient_data

        $productsQ = Product::query()->where('is_active', 1);
        if ($companyId) {
            $productsQ->where('company_id', $companyId);
        }
        $products = $productsQ->orderBy('name')->limit(100)->get();

        // Compute suggestions in-memory (limit 100 for speed)
        $suggestions = $products->map(function (Product $p) {
            $s = $this->pricing->suggestPrice($p->id);
            $s['name'] = $p->name;
            $s['company_id'] = $p->company_id;
            $s['cost_price'] = $p->cost_price ?? null;
            $s['stored_suggested'] = $p->suggested_price ?? null;
            $s['stored_strategy'] = $p->pricing_strategy ?? 'manual';
            return $s;
        });

        if ($verdictFilter) {
            $suggestions = $suggestions->where('verdict', $verdictFilter)->values();
        }

        $summary = [
            'total' => $suggestions->count(),
            'fast_selling' => $suggestions->where('verdict', 'fast_selling')->count(),
            'slow_selling' => $suggestions->where('verdict', 'slow_selling')->count(),
            'typical' => $suggestions->where('verdict', 'typical')->count(),
            'insufficient_data' => $suggestions->where('verdict', 'insufficient_data')->count(),
        ];

        return view('admin.pricing.suggestions', compact('suggestions', 'summary', 'companyId', 'verdictFilter'));
    }

    public function applySuggestion(Request $request, Product $product)
    {
        $s = $this->pricing->suggestPrice($product->id);

        if ($s['suggested_price'] === null || $s['verdict'] === 'insufficient_data') {
            return back()->withErrors(['msg' => 'No actionable suggestion (insufficient data).']);
        }

        $product->update([
            'default_price' => $s['suggested_price'],
            'suggested_price' => $s['suggested_price'],
            'pricing_strategy' => PricingService::STRATEGY_DYNAMIC,
        ]);

        return back()->with('success', "Updated {$product->name}: PKR " . number_format($s['current_price'], 2)
            . " → PKR " . number_format($s['suggested_price'], 2) . " ({$s['verdict']})");
    }
}
