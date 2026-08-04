<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Shared helper: compute total duty hours from biometric punches OR
 * POS session (pos_user_sessions) records.
 *
 * Rule for UNPAIRED / OPEN punches / sessions:
 *   - If a check_in has no following check_out (biometric), or a session
 *     has no logout_at (POS sessions), count the duration up to $cutoff
 *     (the end of the business day = 6 AM the next morning) and set
 *     open = true on the result so views can display an asterisk (*).
 *   - Consecutive check_ins without an intervening check_out: only the
 *     FIRST one of the consecutive run is used as the start of that pair;
 *     later duplicate ins before an out are ignored (common when a device
 *     logs an in-type heartbeat).
 *
 * Both methods return an stdClass with:
 *   ->minutes  (int)   total worked minutes (rounded down to minute)
 *   ->open     (bool)  true if any unpaired/unclosed span was counted
 */
class PosHazriDutyHours
{
    /**
     * Compute duty minutes from biometric punch records.
     *
     * @param  array<object>  $punches  Punch objects/rows; each must have
     *                                  ->punch_type ('check_in'|'check_out')
     *                                  and ->punched_at (Carbon-parseable).
     * @param  Carbon         $cutoff   End of business day (start + 24 h = 6 AM next day).
     */
    public static function fromPunches(array $punches, Carbon $cutoff): object
    {
        // Separate and sort ins/outs
        $ins  = [];
        $outs = [];
        foreach ($punches as $p) {
            $ts = Carbon::parse($p->punched_at);
            if ($p->punch_type === 'check_in') {
                $ins[] = $ts;
            } elseif ($p->punch_type === 'check_out') {
                $outs[] = $ts;
            }
        }
        usort($ins,  fn ($a, $b) => $a <=> $b);
        usort($outs, fn ($a, $b) => $a <=> $b);

        return self::pairAndSum($ins, $outs, $cutoff);
    }

    /**
     * Compute duty minutes from POS session records (pos_user_sessions).
     *
     * @param  Collection  $sessions  Eloquent models / stdClass rows with
     *                                ->login_at, ->logout_at (nullable),
     *                                ->last_activity_at (nullable Carbon/string).
     * @param  Carbon      $cutoff    End of business day.
     */
    public static function fromSessions(Collection $sessions, Carbon $cutoff): object
    {
        $ins  = [];
        $outs = [];

        foreach ($sessions as $s) {
            $ins[] = Carbon::parse($s->login_at);

            if ($s->logout_at) {
                $outs[] = Carbon::parse($s->logout_at);
            }
            // Open sessions (no logout_at) contribute no out-time to the
            // pairing array; pairAndSum will handle them via the cutoff path.
        }

        usort($ins,  fn ($a, $b) => $a <=> $b);
        usort($outs, fn ($a, $b) => $a <=> $b);

        return self::pairAndSum($ins, $outs, $cutoff);
    }

    /**
     * Format a minute count as compact "Xh Ym" string (e.g. "8h 25m").
     * Used on both the A4 PDF and the narrow thermal receipt.
     * 0 minutes → "0m".
     */
    public static function format(int $minutes): string
    {
        if ($minutes <= 0) {
            return '0m';
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        if ($h === 0) {
            return "{$m}m";
        }
        if ($m === 0) {
            return "{$h}h";
        }
        return "{$h}h {$m}m";
    }

    // ── internal ────────────────────────────────────────────────────────────

    /**
     * State-machine pairing of in/out events.
     *
     * All in-times and out-times are merged into one chronological event
     * stream.  A single $pendingIn variable tracks whether a span is
     * currently open:
     *
     *   • check_in  when NO span open  → open a new span (set $pendingIn)
     *   • check_in  when span ALREADY open → ignored (duplicate / heartbeat)
     *   • check_out when span IS open  → close the span, accumulate minutes
     *   • check_out when NO span open  → orphan out, ignored
     *
     * After the loop, if $pendingIn is still set the span is unclosed: count
     * up to $cutoff (or now(), whichever is earlier) and set open = true.
     *
     * This correctly handles the [09:00 in, 09:05 in, 17:00 out] case:
     *   09:00 opens span; 09:05 is ignored (span already open); 17:00 closes
     *   span → 480 min, open = false.
     *
     * @param  Carbon[]  $ins   Unsorted is fine; merged+sorted internally.
     * @param  Carbon[]  $outs  Same.
     */
    private static function pairAndSum(array $ins, array $outs, Carbon $cutoff): object
    {
        // Build a flat, time-sorted event list.
        $events = [];
        foreach ($ins  as $t) { $events[] = ['time' => $t, 'type' => 'in']; }
        foreach ($outs as $t) { $events[] = ['time' => $t, 'type' => 'out']; }
        // Stable sort: ties broken so 'in' comes before 'out' at the same
        // second (a checkout at the exact same second as a new check_in
        // should close the old span before opening the new one — swap the
        // comparison direction for 'in' vs 'out' at equal time).
        usort($events, function (array $a, array $b): int {
            $cmp = $a['time']->getTimestamp() <=> $b['time']->getTimestamp();
            if ($cmp !== 0) {
                return $cmp;
            }
            // Same second: out before in so the old span closes cleanly.
            return ($a['type'] === 'out' ? 0 : 1) <=> ($b['type'] === 'out' ? 0 : 1);
        });

        $totalMinutes = 0;
        $pendingIn    = null;   // Carbon of the currently-open span's start

        foreach ($events as $ev) {
            if ($ev['type'] === 'in') {
                if ($pendingIn === null) {
                    $pendingIn = $ev['time'];   // open a new span
                }
                // else: span already open — ignore duplicate check_in
            } else {
                if ($pendingIn !== null) {
                    $totalMinutes += (int) $pendingIn->diffInMinutes($ev['time']);
                    $pendingIn = null;           // span closed
                }
                // else: orphan check_out with no matching in — ignore
            }
        }

        // Unclosed final span: count to cutoff (capped at now() for live days)
        $open = false;
        if ($pendingIn !== null) {
            $effectiveCutoff = $cutoff->isBefore(now()) ? $cutoff : now();
            $spanMinutes = (int) $pendingIn->diffInMinutes($effectiveCutoff);
            if ($spanMinutes > 0) {
                $totalMinutes += $spanMinutes;
            }
            $open = true;
        }

        return (object) [
            'minutes' => max(0, $totalMinutes),
            'open'    => $open,
        ];
    }
}
