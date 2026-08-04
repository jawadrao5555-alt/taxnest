<?php

namespace Tests\Unit;

use App\Support\PosHazriDutyHours;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Unit tests for the shared duty-hours helper used by the Z-Report biometric
 * section and the Staff Hazri report.
 *
 * Tests cover:
 *  - Normal paired punches (in → out)
 *  - Unpaired open punch at end (counted up to cutoff, open=true)
 *  - Multiple sessions summed
 *  - Consecutive ins without an intervening out (only first paired)
 *  - fromSessions: closed + open session mix
 *  - format() edge cases
 */
class PosHazriDutyHoursTest extends TestCase
{
    private Carbon $cutoff;

    protected function setUp(): void
    {
        parent::setUp();
        // Business-day end: 6 AM next morning
        $this->cutoff = Carbon::parse('2024-08-15 06:00:00');
    }

    // ── fromPunches ──────────────────────────────────────────────────────────

    public function test_single_paired_punch(): void
    {
        $punches = [
            $this->punch('check_in',  '2024-08-14 09:00:00'),
            $this->punch('check_out', '2024-08-14 17:30:00'),
        ];
        $result = PosHazriDutyHours::fromPunches($punches, $this->cutoff);

        $this->assertSame(510, $result->minutes); // 8h 30m
        $this->assertFalse($result->open);
    }

    public function test_multiple_paired_punches_summed(): void
    {
        // Morning shift 9–13 (240 min) + afternoon 14–18 (240 min) = 480 min
        $punches = [
            $this->punch('check_in',  '2024-08-14 09:00:00'),
            $this->punch('check_out', '2024-08-14 13:00:00'),
            $this->punch('check_in',  '2024-08-14 14:00:00'),
            $this->punch('check_out', '2024-08-14 18:00:00'),
        ];
        $result = PosHazriDutyHours::fromPunches($punches, $this->cutoff);

        $this->assertSame(480, $result->minutes);
        $this->assertFalse($result->open);
    }

    public function test_unpaired_punch_counted_to_cutoff(): void
    {
        // check_in 09:00, no check_out, cutoff 2024-08-15 06:00 (past → used as-is).
        // 2024-08-14 09:00 → 2024-08-15 06:00 = 21 h = 1260 min exactly.
        $punches = [
            $this->punch('check_in', '2024-08-14 09:00:00'),
        ];
        $result = PosHazriDutyHours::fromPunches($punches, $this->cutoff);

        $this->assertSame(1260, $result->minutes);
        $this->assertTrue($result->open);
    }

    public function test_unpaired_punch_open_flag_true(): void
    {
        // Span 1 closed: 09:00→13:00 = 240 min.
        // Span 2 open:   15:00→cutoff 06:00 next day = 15 h = 900 min.
        // Total: 1140 min, open=true.
        $punches = [
            $this->punch('check_in',  '2024-08-14 09:00:00'),
            $this->punch('check_out', '2024-08-14 13:00:00'),
            $this->punch('check_in',  '2024-08-14 15:00:00'), // unpaired
        ];
        $result = PosHazriDutyHours::fromPunches($punches, $this->cutoff);

        $this->assertSame(1140, $result->minutes);
        $this->assertTrue($result->open);
    }

    public function test_consecutive_ins_before_out_exact(): void
    {
        // Reproduces the reported bug: 09:00 in, 09:05 in (duplicate/heartbeat),
        // 17:00 out.  Expected: exactly 480 min (09:00→17:00), open=false.
        // The buggy index-walk code gave 480 + ~1255 = 1735 min and open=true.
        $punches = [
            $this->punch('check_in',  '2024-08-14 09:00:00'),
            $this->punch('check_in',  '2024-08-14 09:05:00'), // duplicate
            $this->punch('check_out', '2024-08-14 17:00:00'),
        ];
        $result = PosHazriDutyHours::fromPunches($punches, $this->cutoff);

        $this->assertSame(480, $result->minutes); // 09:00→17:00 = 8 h = 480 min
        $this->assertFalse($result->open);
    }

    public function test_three_consecutive_ins_one_out(): void
    {
        // Three consecutive check_ins before a single check_out.
        // Only the first in should open the span; the other two are ignored.
        $punches = [
            $this->punch('check_in',  '2024-08-14 09:00:00'),
            $this->punch('check_in',  '2024-08-14 09:03:00'),
            $this->punch('check_in',  '2024-08-14 09:07:00'),
            $this->punch('check_out', '2024-08-14 17:00:00'),
        ];
        $result = PosHazriDutyHours::fromPunches($punches, $this->cutoff);

        $this->assertSame(480, $result->minutes); // 09:00→17:00 = 480 min
        $this->assertFalse($result->open);
    }

    public function test_duplicate_in_at_start_then_normal_pair(): void
    {
        // 09:00 in, 09:05 duplicate in, 13:00 out, 14:00 in, 18:00 out.
        // Span 1: 09:00→13:00 = 240 min (09:05 ignored).
        // Span 2: 14:00→18:00 = 240 min.
        // Total: 480 min, open=false.
        $punches = [
            $this->punch('check_in',  '2024-08-14 09:00:00'),
            $this->punch('check_in',  '2024-08-14 09:05:00'),
            $this->punch('check_out', '2024-08-14 13:00:00'),
            $this->punch('check_in',  '2024-08-14 14:00:00'),
            $this->punch('check_out', '2024-08-14 18:00:00'),
        ];
        $result = PosHazriDutyHours::fromPunches($punches, $this->cutoff);

        $this->assertSame(480, $result->minutes);
        $this->assertFalse($result->open);
    }

    public function test_all_outs_no_ins_returns_zero(): void
    {
        $punches = [
            $this->punch('check_out', '2024-08-14 09:00:00'),
        ];
        $result = PosHazriDutyHours::fromPunches($punches, $this->cutoff);

        $this->assertSame(0, $result->minutes);
        $this->assertFalse($result->open);
    }

    public function test_empty_punches_returns_zero(): void
    {
        $result = PosHazriDutyHours::fromPunches([], $this->cutoff);

        $this->assertSame(0, $result->minutes);
        $this->assertFalse($result->open);
    }

    // ── fromSessions ─────────────────────────────────────────────────────────

    public function test_sessions_closed_summed(): void
    {
        // 2 h + 3 h = 5 h = 300 min
        $sessions = collect([
            $this->makeSession('2024-08-14 09:00:00', '2024-08-14 11:00:00'),
            $this->makeSession('2024-08-14 12:00:00', '2024-08-14 15:00:00'),
        ]);
        $result = PosHazriDutyHours::fromSessions($sessions, $this->cutoff);

        $this->assertSame(300, $result->minutes);
        $this->assertFalse($result->open);
    }

    public function test_sessions_open_counted_to_cutoff(): void
    {
        // Closed span: 09:00→11:00 = 120 min.
        // Open span:   14:00→cutoff 06:00 next day = 16 h = 960 min.
        // Total: 1080 min, open=true.
        $sessions = collect([
            $this->makeSession('2024-08-14 09:00:00', '2024-08-14 11:00:00'),
            $this->makeSession('2024-08-14 14:00:00', null), // open
        ]);
        $result = PosHazriDutyHours::fromSessions($sessions, $this->cutoff);

        $this->assertSame(1080, $result->minutes);
        $this->assertTrue($result->open);
    }

    public function test_sessions_only_open_session(): void
    {
        // 09:00→cutoff 06:00 next day = 21 h = 1260 min, open=true.
        $sessions = collect([
            $this->makeSession('2024-08-14 09:00:00', null),
        ]);
        $result = PosHazriDutyHours::fromSessions($sessions, $this->cutoff);

        $this->assertSame(1260, $result->minutes);
        $this->assertTrue($result->open);
    }

    // ── format() ─────────────────────────────────────────────────────────────

    public function test_format_zero(): void
    {
        $this->assertSame('0m', PosHazriDutyHours::format(0));
    }

    public function test_format_minutes_only(): void
    {
        $this->assertSame('45m', PosHazriDutyHours::format(45));
    }

    public function test_format_hours_only(): void
    {
        $this->assertSame('8h', PosHazriDutyHours::format(480));
    }

    public function test_format_hours_and_minutes(): void
    {
        $this->assertSame('8h 25m', PosHazriDutyHours::format(505));
    }

    public function test_format_negative_treated_as_zero(): void
    {
        $this->assertSame('0m', PosHazriDutyHours::format(-60));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function punch(string $type, string $at): object
    {
        return (object) [
            'punch_type' => $type,
            'punched_at' => Carbon::parse($at),
        ];
    }

    private function makeSession(string $loginAt, ?string $logoutAt): object
    {
        return (object) [
            'login_at'         => Carbon::parse($loginAt),
            'logout_at'        => $logoutAt ? Carbon::parse($logoutAt) : null,
            'last_activity_at' => null,
        ];
    }
}
