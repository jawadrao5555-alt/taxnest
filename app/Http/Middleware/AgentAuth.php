<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Company;
use Symfony\Component\HttpFoundation\Response;

class AgentAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->bearerToken() ?: $request->header('X-Agent-Key');

        if (!$key) {
            return response()->json(['error' => 'Missing agent API key'], 401);
        }

        $company = Company::where('agent_api_key', $key)
            ->where('agent_enabled', true)
            ->first();

        if (!$company) {
            return response()->json(['error' => 'Invalid or disabled agent key'], 401);
        }

        $request->attributes->set('agent_company', $company);

        return $next($request);
    }
}
