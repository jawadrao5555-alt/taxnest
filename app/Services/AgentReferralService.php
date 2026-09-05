<?php

namespace App\Services;

use App\Models\Agent;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
        // The visible field is authoritative. In particular, clearing a
        // referral-link prefill means "Direct Customer"; a stale session must
        // never silently restore attribution.
        $code = $request->has('distributor_reference_code')
            ? $request->input('distributor_reference_code')
            : $request->session()->get(self::SESSION_KEY);

        if (trim((string) $code) === '') {
            return null;
        }

        $agent = self::activeAgentForCode($code);
        if (!$agent) {
            throw ValidationException::withMessages([
                'distributor_reference_code' => 'This Distributor Reference Code is invalid or inactive. Remove it for a Direct Customer signup, or ask your distributor for the correct active code.',
            ]);
        }

        return $agent;
    }

    /** Value used to prefill the public signup field from a valid ?ref= link. */
    public static function prefill(Request $request): string
    {
        return (string) old(
            'distributor_reference_code',
            $request->session()->get(self::SESSION_KEY, '')
        );
    }
}