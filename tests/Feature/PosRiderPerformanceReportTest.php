<?php

namespace Tests\Feature;

use App\Http\Controllers\PosRiderTrackingController;
use App\Models\PosRider;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Rider performance report aggregation invariants.
 *
 * The owner-facing report approximates duty from the first and last GPS fix
 * per calendar day, while rejecting GPS noise from its kilometre total.
 * These tests invoke the two private aggregators directly against a minimal
 * SQLite schema so reporting math remains covered without an authenticated UI.
 */
class PosRiderPerformanceReportTest extends TestCase
{
    private const COMPANY = 71;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('pos_rider_locations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('rider_id');
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->dateTime('recorded_at');
            $table->timestamp('created_at')->nullable();
            // Task #1402: insert-time live/late stamp (NULL = pre-migration row).
            $table->boolean('is_offline')->nullable();
            $table->index(['company_id', 'rider_id', 'recorded_at']);
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('rider_id')->nullable();
            $table->string('delivery_status')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamp('rider_assigned_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });
    }

    private function riderId(): int
    {
        static $next = 1;

        return $next++;
    }

    private function point(int $riderId, string $at, float $lat, float $lng): void
    {
        DB::table('pos_rider_locations')->insert([
            'company_id'  => self::COMPANY,
            'rider_id'    => $riderId,
            'lat'         => $lat,
            'lng'         => $lng,
            'recorded_at' => $at,
            'created_at'  => $at,
        ]);
    }

    /**
     * Task #1402: a point whose arrival time differs from its fix time.
     * $isOffline null reproduces a pre-migration row (heuristic classification).
     */
    private function arrivedPoint(int $riderId, string $recordedAt, string $arrivedAt, ?bool $isOffline): void
    {
        DB::table('pos_rider_locations')->insert([
            'company_id'  => self::COMPANY,
            'rider_id'    => $riderId,
            'lat'         => 31.5204000,
            'lng'         => 74.3587000,
            'recorded_at' => $recordedAt,
            'created_at'  => $arrivedAt,
            'is_offline'  => $isOffline,
        ]);
    }

    /** @return array{state: string|null, late_pct: int, lag_unit: string|null, lag_value: int} */
    private function sync(?array $movementRow): array
    {
        $controller = app(PosRiderTrackingController::class);
        $method = new \ReflectionMethod($controller, 'syncSummary');
        $method->setAccessible(true);

        return $method->invoke($controller, $movementRow);
    }

    private function movement(Carbon $from, Carbon $to): array
    {
        $controller = app(PosRiderTrackingController::class);
        $method = new \ReflectionMethod($controller, 'movementStats');
        $method->setAccessible(true);

        return $method->invoke($controller, self::COMPANY, $from, $to);
    }

    private function delivery(Carbon $from, Carbon $to): array
    {
        $controller = app(PosRiderTrackingController::class);
        $method = new \ReflectionMethod($controller, 'deliveryStats');
        $method->setAccessible(true);

        return $method->invoke($controller, self::COMPANY, $from, $to);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function rank(array $rows): array
    {
        $controller = app(PosRiderTrackingController::class);
        $method = new \ReflectionMethod($controller, 'rankReportRows');
        $method->setAccessible(true);

        return $method->invoke($controller, $rows);
    }

    private function day(string $date): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $date, config('app.timezone'))->startOfDay();
    }

    public function test_movement_excludes_jitter_teleports_and_gap_segments_but_keeps_daily_duty_span(): void
    {
        $date = '2026-08-10';
        $day = $this->day($date);

        $jitter = $this->riderId();
        $this->point($jitter, "$date 09:00:00", 31.5204000, 74.3587000);
        // About 5m: below the 12m stationary GPS-noise threshold.
        $this->point($jitter, "$date 09:01:00", 31.5204000, 74.3587500);

        $teleport = $this->riderId();
        $this->point($teleport, "$date 09:00:00", 31.5204000, 74.3587000);
        // About 11km in one minute: far beyond the 90km/h GPS-fix cap.
        $this->point($teleport, "$date 09:01:00", 31.6204000, 74.3587000);

        $gap = $this->riderId();
        $this->point($gap, "$date 09:00:00", 31.5204000, 74.3587000);
        // Exactly five minutes: the report treats the threshold as a break.
        $this->point($gap, "$date 09:05:00", 31.5304000, 74.3587000);

        $valid = $this->riderId();
        $this->point($valid, "$date 09:00:00", 31.5204000, 74.3587000);
        $this->point($valid, "$date 09:02:00", 31.5204000, 74.3597000);
        // This later gap does not add km, but it still proves duty is based on
        // the first and last fix, rather than only accepted movement segments.
        $this->point($valid, "$date 09:10:00", 31.5304000, 74.3597000);

        $stats = $this->movement($day, $day->copy()->endOfDay());

        foreach ([$jitter, $teleport, $gap] as $riderId) {
            $this->assertSame(0.0, $stats[$riderId]['km']);
        }
        $this->assertSame(1, $stats[$jitter]['duty_minutes']);
        $this->assertSame(1, $stats[$teleport]['duty_minutes']);
        $this->assertSame(5, $stats[$gap]['duty_minutes']);

        $expectedKm = PosRider::haversineKm(31.5204000, 74.3587000, 31.5204000, 74.3597000);
        $this->assertEqualsWithDelta($expectedKm, $stats[$valid]['km'], 0.000001);
        $this->assertSame(10, $stats[$valid]['duty_minutes']);
        $this->assertSame(1, $stats[$valid]['days_active']);
    }

    public function test_movement_sums_the_first_to_last_duty_span_for_each_day_in_a_range(): void
    {
        $rider = $this->riderId();

        $this->point($rider, '2026-08-10 09:00:00', 31.5204000, 74.3587000);
        $this->point($rider, '2026-08-10 10:30:00', 31.5304000, 74.3587000);
        $this->point($rider, '2026-08-11 09:15:00', 31.5404000, 74.3587000);
        $this->point($rider, '2026-08-11 10:00:00', 31.5504000, 74.3587000);

        $from = $this->day('2026-08-10');
        $to = $this->day('2026-08-11')->endOfDay();
        $stats = $this->movement($from, $to);

        $this->assertSame(135, $stats[$rider]['duty_minutes']);
        $this->assertSame(2, $stats[$rider]['days_active']);
        $this->assertSame(0.0, $stats[$rider]['km'],
            'Long gaps must not turn the cross-day duty fixture into travel distance.');
    }

    public function test_delivery_average_uses_stamped_spans_only_while_counting_every_delivered_bill(): void
    {
        $rider = $this->riderId();
        $day = $this->day('2026-08-10');
        $createdAt = '2026-08-10 08:00:00';

        $bill = function (?string $assignedAt, ?string $deliveredAt) use ($rider, $createdAt): void {
            DB::table('pos_transactions')->insert([
                'company_id'        => self::COMPANY,
                'rider_id'          => $rider,
                'delivery_status'   => 'delivered',
                'is_archived'       => false,
                'rider_assigned_at' => $assignedAt,
                'delivered_at'      => $deliveredAt,
                'created_at'        => $createdAt,
                'updated_at'        => $createdAt,
            ]);
        };

        $bill('2026-08-10 09:00:00', '2026-08-10 09:30:00'); // 30 minutes
        // Carbon 3 returns signed diffs. A backfilled/reversed pair still
        // contributes its absolute 10-minute span.
        $bill('2026-08-10 11:00:00', '2026-08-10 10:50:00'); // abs = 10 minutes
        $bill(null, '2026-08-10 11:30:00');                   // counted, no average
        $bill('2026-08-10 12:00:00', null);                   // counted via created_at, no average
        $bill('2026-08-09 09:00:00', '2026-08-10 10:00:00'); // 25 hours: stale, no average

        $stats = $this->delivery($day, $day->copy()->endOfDay());

        $this->assertSame(5, $stats[$rider]['delivered']);
        $this->assertSame(20, $stats[$rider]['avg_minutes'],
            'Only the 30- and 10-minute stamped spans should be averaged.');
    }

    public function test_movement_separates_live_points_from_ones_that_arrived_late(): void
    {
        $date = '2026-08-10';
        $day = $this->day($date);

        // Route uploaded in one batch after the shift: the stamp says offline,
        // and the worst fix→arrival delay is the whole afternoon.
        $batched = $this->riderId();
        $this->arrivedPoint($batched, "$date 13:00:00", "$date 19:00:00", true);
        $this->arrivedPoint($batched, "$date 14:00:00", "$date 19:00:00", true);
        $this->arrivedPoint($batched, "$date 19:00:30", "$date 19:00:30", false);

        // Pre-migration rows carry no stamp — the arrival heuristic decides.
        $legacy = $this->riderId();
        $this->arrivedPoint($legacy, "$date 09:00:00", "$date 09:00:10", null); // live
        $this->arrivedPoint($legacy, "$date 09:05:00", "$date 09:20:00", null); // 15 min late

        $stats = $this->movement($day, $day->copy()->endOfDay());

        $this->assertSame(3, $stats[$batched]['points']);
        $this->assertSame(2, $stats[$batched]['late_points']);
        $this->assertSame(6 * 3600, $stats[$batched]['max_lag_secs'],
            'The worst delay must come from the late points, not the live one.');

        $this->assertSame(2, $stats[$legacy]['points']);
        $this->assertSame(1, $stats[$legacy]['late_points'],
            'Without the stamp, an arrival 5+ minutes after the fix is still late.');
        $this->assertSame(15 * 60, $stats[$legacy]['max_lag_secs']);
    }

    public function test_sync_summary_reads_as_plain_wording_with_a_share_and_worst_delay(): void
    {
        $this->assertSame(null, $this->sync(null)['state'],
            'A rider with no route that day gets no verdict.');
        $this->assertSame(null, $this->sync(['points' => 0, 'late_points' => 0, 'max_lag_secs' => 0])['state']);

        $this->assertSame('all_live', $this->sync(['points' => 100, 'late_points' => 0, 'max_lag_secs' => 0])['state']);

        // One late point in a thousand still deserves a visible 1%.
        $rare = $this->sync(['points' => 1000, 'late_points' => 1, 'max_lag_secs' => 60]);
        $this->assertSame('mostly_live', $rare['state']);
        $this->assertSame(1, $rare['late_pct']);
        $this->assertSame(null, $rare['lag_unit'], 'A one-minute delay is not worth printing.');

        $half = $this->sync(['points' => 100, 'late_points' => 50, 'max_lag_secs' => 20 * 60]);
        $this->assertSame('part_late', $half['state']);
        $this->assertSame(50, $half['late_pct']);
        $this->assertSame(['m', 20], [$half['lag_unit'], $half['lag_value']]);

        $batched = $this->sync(['points' => 100, 'late_points' => 96, 'max_lag_secs' => 6 * 3600]);
        $this->assertSame('mostly_late', $batched['state']);
        $this->assertSame(96, $batched['late_pct']);
        $this->assertSame(['h', 6], [$batched['lag_unit'], $batched['lag_value']]);
    }

    public function test_refused_upload_shows_only_on_the_day_it_happened(): void
    {
        $controller = app(PosRiderTrackingController::class);
        $method = new \ReflectionMethod($controller, 'rejectInWindow');
        $method->setAccessible(true);

        $rider = new PosRider();
        $rider->last_reject_reason = 'duty_off';
        $rider->last_reject_at = Carbon::parse('2026-08-10 17:30:00');

        $day = $this->day('2026-08-10');
        $hit = $method->invoke($controller, $rider, $day, $day->copy()->endOfDay());
        $this->assertSame('duty_off', $hit['reason']);

        $other = $this->day('2026-08-09');
        $this->assertNull($method->invoke($controller, $rider, $other, $other->copy()->endOfDay()),
            'Only the newest refusal is stored — it must never be shown on an older day.');

        // An unknown reason code degrades to the plain "refused" wording.
        $rider->last_reject_reason = 'brand_new_code';
        $this->assertSame('other', $method->invoke($controller, $rider, $day, $day->copy()->endOfDay())['reason']);

        $rider->last_reject_reason = null;
        $this->assertNull($method->invoke($controller, $rider, $day, $day->copy()->endOfDay()));
    }

    public function test_ranking_prioritizes_deliveries_average_time_then_kilometres(): void
    {
        $rows = [
            ['rider' => 'fewer-but-fast', 'delivered' => 4, 'avg_minutes' => 5, 'km' => 99.0],
            ['rider' => 'most-deliveries', 'delivered' => 5, 'avg_minutes' => 60, 'km' => 1.0],
            ['rider' => 'slower-average', 'delivered' => 5, 'avg_minutes' => 30, 'km' => 50.0],
            ['rider' => 'faster-average', 'delivered' => 5, 'avg_minutes' => 20, 'km' => 1.0],
            ['rider' => 'more-kilometres', 'delivered' => 5, 'avg_minutes' => 20, 'km' => 12.0],
            ['rider' => 'no-average', 'delivered' => 5, 'avg_minutes' => null, 'km' => 100.0],
        ];

        $ranked = $this->rank($rows);

        $this->assertSame([
            'more-kilometres',
            'faster-average',
            'slower-average',
            'most-deliveries',
            'no-average',
            'fewer-but-fast',
        ], array_column($ranked, 'rider'));
    }
}