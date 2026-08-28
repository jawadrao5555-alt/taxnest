<?php

namespace Tests\Feature;

use App\Http\Controllers\Pos\OfflineQueueReportController;
use App\Models\Company;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Offline queue telemetry — the sale screen reporting bills still held on a
 * counter device.
 *
 * The point of this endpoint is to break a specific silence: a device that is
 * ONLINE while bills stay stuck (a poisoned bill, a quota block, an expired
 * session). Nobody could see that queue; the shop found out at day-close when
 * the totals disagreed.
 *
 * Two ways that silence could come back, both locked here:
 *
 *   1. A queue that drains before the screen ever reports would leave the last
 *      positive number sitting on the server as a permanent false alarm.
 *   2. A shop bills from several counters. Each reports only its OWN queue, so
 *      an idle till reporting zero must not erase a busy till's stuck bills.
 *
 * And the invariant underneath all of it: this is telemetry, never a gate. It
 * may not refuse, and it may not fail a sale.
 */
class OfflineQueueReportTest extends TestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        // sqlite :memory: + a minimal companies table — the house pattern for
        // these tests. Only the columns this endpoint touches are needed.
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->unsignedInteger('offline_queue_depth')->nullable();
            $t->timestamp('offline_queue_oldest_at')->nullable();
            $t->timestamp('offline_queue_reported_at')->nullable();
            $t->string('offline_queue_device', 64)->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        $this->company = Company::create(['name' => 'Test Shop']);
        app()->instance('currentCompanyId', $this->company->id);
    }

    private function report(array $payload): array
    {
        $controller = new OfflineQueueReportController();
        $request = Request::create('/pos/api/offline-queue-report', 'POST', $payload);
        $response = $controller($request);

        return json_decode($response->getContent(), true);
    }

    private function fresh(): Company
    {
        return Company::findOrFail($this->company->id);
    }

    public function test_it_records_a_non_empty_queue(): void
    {
        $oldest = now()->subMinutes(40);

        $out = $this->report([
            'depth' => 7,
            'oldest_at' => $oldest->toIso8601String(),
            'device' => 'till-1',
        ]);

        $this->assertTrue($out['success']);
        $this->assertTrue($out['stored']);

        $c = $this->fresh();
        $this->assertSame(7, (int) $c->offline_queue_depth);
        $this->assertSame('till-1', $c->offline_queue_device);
        $this->assertNotNull($c->offline_queue_reported_at);
        $this->assertSame($oldest->format('Y-m-d H:i'), $c->offline_queue_oldest_at->format('Y-m-d H:i'));
    }

    public function test_the_same_device_can_clear_its_own_queue(): void
    {
        $this->report(['depth' => 4, 'oldest_at' => now()->subHour()->toIso8601String(), 'device' => 'till-1']);

        $out = $this->report(['depth' => 0, 'oldest_at' => null, 'device' => 'till-1']);

        $this->assertTrue($out['stored']);
        $c = $this->fresh();
        $this->assertSame(0, (int) $c->offline_queue_depth);
        $this->assertNull($c->offline_queue_oldest_at);
    }

    public function test_an_idle_till_cannot_erase_a_busy_tills_stuck_bills(): void
    {
        // Till 1 is holding real money.
        $this->report(['depth' => 11, 'oldest_at' => now()->subHours(2)->toIso8601String(), 'device' => 'till-1']);

        // Till 2 is fine and says so. That must not wipe till 1's report.
        $out = $this->report(['depth' => 0, 'oldest_at' => null, 'device' => 'till-2']);

        $this->assertFalse($out['stored']);
        $this->assertSame('other_device_pending', $out['reason']);
        $this->assertSame(11, (int) $this->fresh()->offline_queue_depth);
    }

    public function test_a_stale_report_from_another_device_is_allowed_to_clear(): void
    {
        $this->report(['depth' => 11, 'oldest_at' => now()->subHours(9)->toIso8601String(), 'device' => 'till-1']);

        // Age the record past the six-hour worthlessness window.
        Company::whereKey($this->company->id)->update([
            'offline_queue_reported_at' => now()->subHours(7),
        ]);

        $out = $this->report(['depth' => 0, 'oldest_at' => null, 'device' => 'till-2']);

        $this->assertTrue($out['stored']);
        $this->assertSame(0, (int) $this->fresh()->offline_queue_depth);
    }

    public function test_another_device_may_always_raise_a_queue(): void
    {
        $this->report(['depth' => 3, 'oldest_at' => now()->subHour()->toIso8601String(), 'device' => 'till-1']);

        // The protection is only against a zero erasing a queue — a second till
        // reporting its own stuck bills must always get through.
        $out = $this->report(['depth' => 5, 'oldest_at' => now()->subMinutes(10)->toIso8601String(), 'device' => 'till-2']);

        $this->assertTrue($out['stored']);
        $c = $this->fresh();
        $this->assertSame(5, (int) $c->offline_queue_depth);
        $this->assertSame('till-2', $c->offline_queue_device);
    }

    public function test_a_wrong_pc_clock_cannot_post_date_a_queued_bill(): void
    {
        $this->report([
            'depth' => 2,
            'oldest_at' => now()->addDays(3)->toIso8601String(),
            'device' => 'till-1',
        ]);

        $this->assertTrue($this->fresh()->offline_queue_oldest_at->lte(now()->addMinute()));
    }

    public function test_a_zero_is_accepted_when_nothing_is_on_record(): void
    {
        // The first report of a page session is always sent, even a zero: that
        // is what clears a stale positive left behind by an earlier session.
        $out = $this->report(['depth' => 0, 'oldest_at' => null, 'device' => 'till-1']);

        $this->assertTrue($out['stored']);
        $this->assertSame(0, (int) $this->fresh()->offline_queue_depth);
    }

    public function test_a_device_less_report_still_works(): void
    {
        // Older cached sale screens may post without a device key.
        $out = $this->report(['depth' => 6, 'oldest_at' => null]);

        $this->assertTrue($out['stored']);
        $this->assertSame(6, (int) $this->fresh()->offline_queue_depth);
    }

    public function test_it_refuses_a_nonsense_depth(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->report(['depth' => -1]);
    }

    public function test_it_does_not_touch_the_companys_updated_at(): void
    {
        // This runs on the sale screen's hot path. It has no business bumping
        // updated_at or firing model events on every beat.
        $before = $this->fresh()->updated_at;

        $this->travel(2)->minutes();
        $this->report(['depth' => 1, 'oldest_at' => null, 'device' => 'till-1']);

        $this->assertEquals(
            optional($before)->format('Y-m-d H:i:s'),
            optional($this->fresh()->updated_at)->format('Y-m-d H:i:s')
        );
    }
}
