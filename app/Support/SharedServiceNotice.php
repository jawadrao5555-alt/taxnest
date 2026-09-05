<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Single owner of the shared domain/Agent service-announcement window.
 *
 * While the window is live the announcement is the ONLY interruption a POS
 * user is allowed to get: the What's New popup, the survey auto-open and the
 * PRA elaan popup all stay closed (unread items stay reachable from the bell
 * / the header pill). That suppression is deliberate product behaviour.
 *
 * Both POS layouts AND the notice component read the window from here, so a
 * future announcement is scheduled in exactly ONE place and the panels can
 * never drift apart. Tests read the same authority instead of hardcoding
 * dates, so scheduling a new window can never turn the suite red.
 *
 * To schedule the next announcement: change WINDOW_START (and WINDOW_DAYS if
 * it should not run for a week). To have no announcement at all, set
 * WINDOW_START to an empty string.
 */
final class SharedServiceNotice
{
    /** The window is always expressed in shop-local (Pakistan) time. */
    public const TZ = 'Asia/Karachi';

    /** Announcement start date, Asia/Karachi midnight. '' = nothing scheduled. */
    private const WINDOW_START = '2026-09-05';

    /** How many days the announcement stays live from its start. */
    private const WINDOW_DAYS = 7;

    /**
     * Test-only window override. Production code never writes this; it exists
     * so a test can prove the suppression rule without depending on whichever
     * announcement happens to be scheduled in the constants above.
     *
     * @var array{start: string, days: int}|null
     */
    private static ?array $override = null;

    /** Inclusive start of the announcement window, or null when none is scheduled. */
    public static function startsAt(): ?CarbonImmutable
    {
        $start = self::$override['start'] ?? self::WINDOW_START;

        if (trim((string) $start) === '') {
            return null;
        }

        return CarbonImmutable::parse($start, self::TZ)->startOfDay();
    }

    /** EXCLUSIVE end of the announcement window, or null when none is scheduled. */
    public static function endsAt(): ?CarbonImmutable
    {
        $start = self::startsAt();

        return $start?->addDays(self::days());
    }

    public static function days(): int
    {
        return max(1, (int) (self::$override['days'] ?? self::WINDOW_DAYS));
    }

    /** True while the shared announcement is on screen for shops right now. */
    public static function isLive(): bool
    {
        $start = self::startsAt();
        $end = self::endsAt();

        if ($start === null || $end === null) {
            return false;
        }

        $now = CarbonImmutable::now(self::TZ);

        return $now->greaterThanOrEqualTo($start) && $now->lessThan($end);
    }

    /**
     * An instant at which NO announcement is live — the moment a test should
     * travel to when it wants the ordinary popups back. Derived from whatever
     * window is currently scheduled, so it keeps working after a reschedule.
     */
    public static function momentOutsideWindow(): CarbonImmutable
    {
        $end = self::endsAt();

        // Deliberately the morning right AFTER the window closes (endsAt is
        // exclusive): far enough out to be quiet, close enough that anything
        // created during the window is still inside its own live period.
        return $end === null
            ? CarbonImmutable::now(self::TZ)
            : $end->addHours(9);
    }

    /**
     * An instant INSIDE the scheduled window, or null when nothing is
     * scheduled — a test that needs a live window should call
     * scheduleForTesting() first.
     */
    public static function momentInsideWindow(): ?CarbonImmutable
    {
        return self::startsAt()?->addHours(12);
    }

    /** TEST ONLY: pin a window so the suppression rule can be exercised. */
    public static function scheduleForTesting(string $startDate, ?int $days = null): void
    {
        self::$override = [
            'start' => $startDate,
            'days' => $days ?? self::WINDOW_DAYS,
        ];
    }

    /** TEST ONLY: drop the override and fall back to the scheduled window. */
    public static function forgetTestingSchedule(): void
    {
        self::$override = null;
    }
}
