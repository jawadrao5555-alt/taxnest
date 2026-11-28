<?php

declare(strict_types=1);

namespace Tests\Unit\Enterprise360;

use App\Services\Enterprise360\MonitorInstallationAccess;
use App\Services\Enterprise360\MonitorSalesEvent;
use App\Services\Enterprise360\MonitorSalesProjection;
use App\Services\Enterprise360\MonitorSalesSnapshot;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MonitorSalesProjectionTest extends TestCase
{
    private DateTimeImmutable $start;

    private DateTimeImmutable $end;

    private DateTimeImmutable $asOf;

    protected function setUp(): void
    {
        parent::setUp();

        $this->start = new DateTimeImmutable('2026-11-12T00:00:00+05:00');
        $this->end = new DateTimeImmutable('2026-11-13T00:00:00+05:00');
        $this->asOf = $this->end->modify('+10 minutes');
    }

    public function test_verified_installations_only_exact_totals_deduplication_and_fiscal_evidence(): void
    {
        $result = $this->project(
            [42, 43, 42],
            $this->snapshot(42, '+10 minutes', true,
                MonitorSalesEvent::fiscalAcknowledgement('invoice-7'),
                MonitorSalesEvent::native('invoice-7', PHP_INT_MAX),
                MonitorSalesEvent::native('invoice-7', PHP_INT_MAX),
                MonitorSalesEvent::native('return-9', -100),
            ),
            $this->snapshot(43, '+9 minutes', true,
                MonitorSalesEvent::native('invoice-7', 500),
                MonitorSalesEvent::fiscalAcknowledgement('invoice-2'),
            ),
            $this->snapshot(44, '+10 minutes', true, MonitorSalesEvent::native('restricted', 5000)),
        );

        self::assertSame([
            'currency' => 'PKR',
            'coverage' => 'complete',
            'fresh_installations' => 2,
            'authorized_installations' => 2,
            'net_paisa' => '9223372036854776207',
            'fresh_subtotal_paisa' => '9223372036854776207',
            'installations' => [
                ['installation_id' => 42, 'status' => 'fresh', 'captured_at' => '2026-11-13T00:10:00.000+05:00', 'net_paisa' => '9223372036854775707'],
                ['installation_id' => 43, 'status' => 'fresh', 'captured_at' => '2026-11-13T00:09:00.000+05:00', 'net_paisa' => '500'],
            ],
        ], $result);
    }

    public function test_complete_empty_source_is_known_zero_and_stale_missing_or_incomplete_sources_are_unknown(): void
    {
        $result = $this->project(
            [41, 42, 43, 44, 45],
            $this->snapshot(41, '+5 minutes', true),
            $this->snapshot(42, '+4 minutes 59 seconds', true, MonitorSalesEvent::native('stale', 500)),
            $this->snapshot(43, '+9 minutes', false, MonitorSalesEvent::native('incomplete', 600)),
            $this->snapshot(44, '+11 minutes', true, MonitorSalesEvent::native('future', 700)),
        );

        self::assertSame('partial', $result['coverage']);
        self::assertNull($result['net_paisa']);
        self::assertSame('0', $result['fresh_subtotal_paisa']);
        self::assertSame(1, $result['fresh_installations']);
        self::assertSame(['fresh', 'stale', 'unknown', 'unknown', 'unknown'], array_column($result['installations'], 'status'));
        self::assertSame(['0', null, null, null, null], array_column($result['installations'], 'net_paisa'));
    }

    public function test_conflicting_native_evidence_does_not_publish_an_amount(): void
    {
        $result = $this->project(
            [42],
            $this->snapshot(42, '+10 minutes', true,
                MonitorSalesEvent::native('invoice-1', 100),
                MonitorSalesEvent::native('invoice-1', 101),
            ),
        );

        self::assertSame('unavailable', $result['coverage']);
        self::assertSame('conflict', $result['installations'][0]['status']);
        self::assertNull($result['net_paisa']);
        self::assertNull($result['fresh_subtotal_paisa']);
    }

    public function test_ambiguous_snapshots_or_wrong_window_never_inflate_coverage(): void
    {
        $sameWindow = $this->snapshot(41, '+10 minutes', true);
        $otherWindow = new MonitorSalesSnapshot(42, $this->start->modify('-1 day'), $this->end, $this->asOf, true);
        $result = $this->project([41, 42], $sameWindow, $sameWindow, $otherWindow);

        self::assertSame('unavailable', $result['coverage']);
        self::assertSame(['conflict', 'unknown'], array_column($result['installations'], 'status'));
        self::assertNull($result['net_paisa']);
    }

    public function test_freshness_compares_elapsed_time_across_a_clock_change(): void
    {
        $this->start = new DateTimeImmutable('2026-03-08T01:00:00-05:00');
        $this->end = new DateTimeImmutable('2026-03-08T01:54:00-05:00');
        $this->asOf = new DateTimeImmutable('2026-03-08 03:00:20', new DateTimeZone('America/New_York'));

        $result = $this->project([42], $this->snapshot(42, '+2 minutes', true, MonitorSalesEvent::native('sale', 500)));

        self::assertSame('fresh', $result['installations'][0]['status']);
        self::assertSame('500', $result['net_paisa']);
    }

    public function test_actor_without_verified_grants_is_denied_even_when_data_was_provided(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->project([], $this->snapshot(42, '+10 minutes', true));
    }

    public function test_malformed_installation_grants_fail_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->project(['42'], $this->snapshot(42, '+10 minutes', true));
    }

    private function project(array $allowed, MonitorSalesSnapshot ...$snapshots): array
    {
        $access = new class($allowed) implements MonitorInstallationAccess
        {
            public function __construct(private array $allowed) {}

            public function readableInstallationIds(int $actorId): array
            {
                TestCase::assertSame(81, $actorId);

                return $this->allowed;
            }
        };

        return (new MonitorSalesProjection($access))->project(81, $this->start, $this->end, $this->asOf, 300, ...$snapshots);
    }

    private function snapshot(int $installation, string $captured, bool $complete, MonitorSalesEvent ...$events): MonitorSalesSnapshot
    {
        return new MonitorSalesSnapshot($installation, $this->start, $this->end, $this->end->modify($captured), $complete, ...$events);
    }
}
