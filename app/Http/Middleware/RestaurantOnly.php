<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Company;
use App\Services\PosFeatureService;

/**
 * Legacy "restaurant" gate — now purely feature-flag driven.
 * Passes when ANY kitchen/table workflow feature is enabled for the company.
 * (Business-category modes were removed; features are individual toggles.)
 */
class RestaurantOnly
{
    public function handle(Request $request, Closure $next)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $f = PosFeatureService::forCompany($company);

        if (!$company || !($f->tables || $f->kitchen || $f->kot || $f->recipes)) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Kitchen/table features are not enabled'], 403);
            }
            return redirect('/pos/dashboard')->with('error', 'Kitchen/table features are not enabled. Turn them on from Customize POS → Modules.');
        }

        return $next($request);
    }
}
