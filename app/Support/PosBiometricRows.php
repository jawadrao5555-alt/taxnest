<?php

namespace App\Support;

/**
 * Shared biometric punch rows builder for the PRA and FBR day-close
 * hazri sections (Task #571). Both PosController and FbrPosController
 * MUST use this so the two prints can never drift apart.
 *
 * One row per staff member (or unmapped PIN) for one BUSINESS day
 * (6 AM → 6 AM window) with first check-in, last check-out, punch
 * counts, sources (adms / csv_import) and duty hours via
 * PosHazriDutyHours. Returns [] when the table is missing or on any error.
 */
class PosBiometricRows
{
    /**
     * @param string|null $lateAfter  Optional 'HH:MM' late-arrival threshold
     *                                (companies.pos_bio_late_after). When given,
     *                                each row also carries `late_minutes`: 0 =
     *                                on time, >0 = minutes late (first check-in
     *                                after the threshold on this business day),
     *                                null = no check-in punch to judge from.
     *                                Callers that pass nothing are unchanged.
     */
    public static function build(int $companyId, string $date, ?string $lateAfter = null): array
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('pos_biometric_punches')) {
                return [];
            }
            $start = \Carbon\Carbon::parse($date, config('app.timezone'))->setTime(6, 0);
            $end   = $start->copy()->addDay();

            // Late-arrival threshold: wall-clock time inside THIS business-day
            // window (06:00 on $date → 05:59 next day). A pre-06:00 threshold
            // (night shift, e.g. 03:00) falls on the NEXT calendar day — without
            // the rollover it would sit before the window and mark everyone late.
            $lateThreshold = null;
            if ($lateAfter && preg_match('/^\d{1,2}:\d{2}$/', $lateAfter)) {
                $lateThreshold = \Carbon\Carbon::parse($date . ' ' . $lateAfter, config('app.timezone'));
                if ($lateThreshold->lt($start)) {
                    $lateThreshold->addDay();
                }
            }

            $punches = \App\Models\PosBiometricPunch::where('company_id', $companyId)
                ->where('punched_at', '>=', $start)
                ->where('punched_at', '<', $end)
                ->orderBy('punched_at')
                ->get();

            if ($punches->isEmpty()) {
                return [];
            }

            // Resolve user names for mapped punches
            $userIds = $punches->pluck('user_id')->filter()->unique();
            $users   = \App\Models\User::whereIn('id', $userIds)->get()->keyBy('id');

            // Group by user_id (for mapped) or device_pin (for unmapped)
            $groups = [];
            foreach ($punches as $p) {
                $key = $p->user_id ? 'u_' . $p->user_id : 'pin_' . ($p->device_pin ?? 'unknown');
                if (!isset($groups[$key])) {
                    $groups[$key] = [
                        'user_id'    => $p->user_id,
                        'device_pin' => $p->device_pin,
                        'punches'    => [],
                    ];
                }
                $groups[$key]['punches'][] = $p;
            }

            $rows = [];
            foreach ($groups as $g) {
                $ps       = $g['punches'];
                $user     = $g['user_id'] ? $users->get($g['user_id']) : null;
                $ins      = array_values(array_filter($ps, fn ($p) => $p->punch_type === 'check_in'));
                $outs     = array_values(array_filter($ps, fn ($p) => $p->punch_type === 'check_out'));
                $firstIn  = collect($ins)->min('punched_at');
                $lastOut  = collect($outs)->max('punched_at');
                $sources  = collect($ps)->pluck('source')->unique()->values()->all();

                $duty = \App\Support\PosHazriDutyHours::fromPunches($ps, $end);

                // Late marking judges the FIRST CHECK-IN only — an open duty
                // (missing checkout) is a different state and keeps its own
                // amber * convention. No check-in at all → null (unknowable).
                $lateMinutes = null;
                if ($lateThreshold && $firstIn) {
                    $in = \Carbon\Carbon::parse($firstIn, config('app.timezone'));
                    $lateMinutes = $in->gt($lateThreshold)
                        ? (int) round($lateThreshold->diffInMinutes($in))
                        : 0;
                }

                $rows[] = (object) [
                    'user_id'      => $g['user_id'],
                    'name'         => $user?->name,
                    'device_pin'   => $g['device_pin'],
                    'first_in'     => $firstIn,
                    'last_out'     => $lastOut,
                    'in_count'     => count($ins),
                    'out_count'    => count($outs),
                    'total'        => count($ps),
                    'sources'      => $sources,
                    'duty_minutes' => $duty->minutes,
                    'duty_open'    => $duty->open,
                    'late_minutes' => $lateMinutes,
                ];
            }

            usort($rows, function ($a, $b) {
                if ($a->first_in && $b->first_in) {
                    return $a->first_in <=> $b->first_in;
                }
                if ($a->first_in) return -1;
                if ($b->first_in) return 1;
                return strcmp($a->name ?? $a->device_pin, $b->name ?? $b->device_pin);
            });

            return $rows;
        } catch (\Throwable $e) {
            \Log::warning('biometric rows failed: ' . $e->getMessage());
            return [];
        }
    }
}
