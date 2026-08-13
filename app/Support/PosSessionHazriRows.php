<?php

namespace App\Support;

/**
 * Shared session-based hazri rows builder for the PRA and FBR day-close
 * hazri sections (Task #588 — same pattern as PosBiometricRows). Both
 * PosController and FbrPosController MUST use this so the two prints
 * can never drift apart (heartbeat window, 6AM business day, bina-login
 * bills rule sab ek hi jagah).
 *
 * Hazri rows for one BUSINESS day (6 AM → next 6 AM window, wahi rule jo
 * PosBusinessDay/auto-dayclose ka hai). Ek row per staff member: pehla
 * login, aakhri logout (ya last-seen jab logout kabhi dabaya hi nahi),
 * session count, bills + pehli/aakhri sale, duty minutes.
 *
 * The bills aggregate differs per panel (PosTransaction vs
 * FbrPosTransaction, business_date column presence), so callers pass a
 * closure that receives ($start, $end) and returns the aggregate rows
 * keyed by created_by (each with bill_count, first_sale, last_sale,
 * revenue).
 *
 * Table na ho (prod migrate pending) ya koi bhi error — khali array,
 * report/day-close kabhi na toote.
 */
class PosSessionHazriRows
{
    public static function build(int $companyId, string $date, \Closure $billAggregates, string $logPrefix = 'hazri rows failed'): array
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('pos_user_sessions')) {
                return [];
            }
            $start = \Carbon\Carbon::parse($date, config('app.timezone'))->setTime(6, 0);
            $end = $start->copy()->addDay();

            $sessions = \App\Models\PosUserSession::where('company_id', $companyId)
                ->where('login_at', '>=', $start)
                ->where('login_at', '<', $end)
                ->orderBy('login_at')
                ->get()
                ->groupBy('user_id');

            // Bills of the SAME business day (panel-specific query).
            $bills = $billAggregates($start, $end);

            $userIds = $sessions->keys()->merge($bills->keys())->unique()->filter()->values();
            if ($userIds->isEmpty()) {
                return [];
            }
            $users = \App\Models\User::where('company_id', $companyId)->whereIn('id', $userIds)->get()->keyBy('id');

            $rows = [];
            foreach ($userIds as $uid) {
                $u = $users->get($uid);
                if (!$u) {
                    continue; // deleted/foreign user — skip silently
                }
                $s = $sessions->get($uid, collect());
                $b = $bills->get($uid);
                $openSession = $s->firstWhere('logout_at', null);
                $lastSeen = $s->map(fn ($x) => $x->last_activity_at ?? $x->logout_at ?? $x->login_at)->filter()->max();
                $duty = \App\Support\PosHazriDutyHours::fromSessions($s, $end);
                $rows[] = (object) [
                    'user_id' => $uid,
                    'name' => $u->name,
                    'pos_role' => $u->pos_role ?: ($u->role === 'company_admin' ? 'pos_admin' : null),
                    'first_in' => $s->min('login_at'),
                    'last_out' => $openSession ? null : $s->map(fn ($x) => $x->logout_at)->filter()->max(),
                    'last_seen' => $lastSeen,
                    'still_open' => (bool) $openSession,
                    'session_count' => $s->count(),
                    'bill_count' => $b ? (int) $b->bill_count : 0,
                    'revenue' => $b ? (float) $b->revenue : 0.0,
                    'first_sale' => $b?->first_sale,
                    'last_sale' => $b?->last_sale,
                    'duty_minutes' => $duty->minutes,
                    'duty_open'    => $duty->open,
                ];
            }

            // Pehle jo pehle aaya (first_in), bina-login (sirf bills) sab se aakhir.
            usort($rows, function ($a, $b) {
                if ($a->first_in && $b->first_in) return $a->first_in <=> $b->first_in;
                if ($a->first_in) return -1;
                if ($b->first_in) return 1;
                return strcmp($a->name, $b->name);
            });

            return $rows;
        } catch (\Throwable $e) {
            \Log::warning($logPrefix . ': ' . $e->getMessage());
            return [];
        }
    }
}
