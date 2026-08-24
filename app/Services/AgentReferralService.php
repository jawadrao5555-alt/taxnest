<?php

namespace App\Services;

use App\Models\Agent;
use Illuminate\Http\Request;

class AgentReferralService
{
    public const SESSION_KEY = 'agent_referral_code';

    public static function rememberFromRequest(Request $request): void
    {
        if (!$request->filled('ref')) {
            return;
        }

        $code = strtoupper(trim((string) $request->query('ref')));
        $agent = self::activeAgentForCode($code);
        if ($agent && !$request->session()->has(self::SESSION_KEY)) {
            $request->session()->put(self::SESSION_KEY, $agent->referral_code);
        }
    }

    public static function activeAgentForCode(?string $code): ?Agent
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '') {
            return null;
        }

        return Agent::where('referral_code', $code)
            ->where('is_active', true)
            ->where('status', 'active')
            ->first();
    }

    public static function agentFromSignup(Request $request): ?Agent
    {
        return self::activeAgentForCode($request->session()->get(self::SESSION_KEY));
    }
}