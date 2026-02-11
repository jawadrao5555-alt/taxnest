<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CompanyIsolation
{
    public function handle(Request $request, Closure $next): Response
    {
        if (auth()->check()) {
            $companyId = auth()->user()->company_id;

            if (!$companyId) {
                return redirect('/billing/plans')->with('error', 'Please set up your company first.');
            }

            app()->instance('currentCompanyId', $companyId);
        }

        return $next($request);
    }
}
