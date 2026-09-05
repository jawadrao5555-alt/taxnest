<?php

namespace Tests\Concerns;

use App\Support\SharedServiceNotice;
use Illuminate\Support\Carbon;

/**
 * Clock control for the shared domain/Agent announcement window.
 *
 * While that window is live, both POS panels deliberately suppress the What's
 * New popup, the survey auto-open and the PRA elaan popup. Any test that
 * asserts one of those popups renders must therefore say WHEN it is running
 * instead of inheriting today's date — otherwise it passes or fails purely
 * because of the calendar and breaks again the next time an announcement is
 * scheduled.
 *
 * Both instants are derived from App\Support\SharedServiceNotice, never from
 * hardcoded dates, so rescheduling the announcement cannot turn the suite red.
 */
trait ControlsSharedServiceNotice
{
    /** Freeze the clock at a moment when NO announcement is live. */
    protected function travelOutsideAnnouncementWindow(): void
    {
        SharedServiceNotice::forgetTestingSchedule();
        Carbon::setTestNow(SharedServiceNotice::momentOutsideWindow());

        $this->assertFalse(
            SharedServiceNotice::isLive(),
            'Expected no shared announcement to be live at the travelled-to instant.'
        );
    }

    /**
     * Freeze the clock INSIDE an announcement window. Uses the scheduled
     * window when there is one, and pins a synthetic one when there is not,
     * so the suppression rule is covered even between announcements.
     */
    protected function travelInsideAnnouncementWindow(): void
    {
        if (SharedServiceNotice::startsAt() === null) {
            SharedServiceNotice::scheduleForTesting('2099-01-05');
        }

        Carbon::setTestNow(SharedServiceNotice::momentInsideWindow());

        $this->assertTrue(
            SharedServiceNotice::isLive(),
            'Expected a shared announcement to be live at the travelled-to instant.'
        );
    }

    /** Must run in tearDown: the override is a static and would leak forward. */
    protected function releaseAnnouncementWindow(): void
    {
        SharedServiceNotice::forgetTestingSchedule();
        Carbon::setTestNow();
    }
}
