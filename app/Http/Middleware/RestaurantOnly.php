<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Company;

class RestaurantOnly
{
    public function handle(Request $request, Closure $next)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        if (!$company || ($company->pos_type !== 'restaurant' && !$company->restaurant_mode)) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Restaurant module not enabled'], 403);
            }
            return redirect('/pos/dashboard')->with('error', 'Restaurant module is not enabled for your business type.');
        }

        return $next($request);
    }
}
