<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opt-in guard for the additive Agent Core protocol.
 *
 * AgentAuth has already resolved the key to its company. Keeping this separate
 * from AgentAuth means every existing /api/agent endpoint keeps its exact v1
 * behaviour while Core can be enabled per company.
 */
class AgentCoreEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $company = $request->attributes->get('agent_company');

        if (!$company || !$company->agent_core_enabled) {
            return response()->json(['error' => 'Agent Core is not enabled for this company'], 403);
        }

        return $next($request);
    }
}