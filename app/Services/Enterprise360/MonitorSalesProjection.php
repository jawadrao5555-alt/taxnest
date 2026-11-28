<?php

declare(strict_types=1);

namespace App\Services\Enterprise360;

use Brick\Math\BigInteger;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

final readonly class MonitorSalesProjection
{
    public function __construct(private MonitorInstallationAccess $access) {}

    /**
     * Amounts are exact PKR minor-unit strings. A missing or stale source
     * invalidates the total. The fresh subtotal remains separately labeled.
     *
     * @return array{
     *     currency: 'PKR',
     *     coverage: 'complete'|'partial'|'unavailable',
     *     fresh_installations: int,
     *     authorized_installations: int,
     *     net_paisa: ?string,
     *     fresh_subtotal_paisa: ?string,
     *     installations: list<array{
     *         installation_id: int,
     *         status: 'fresh'|'stale'|'unknown'|'conflict',
     *         captured_at: ?string,
     *         net_paisa: ?string
     *     }>
     * }
     */
    public function project(
        int $actorId,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
        DateTimeImmutable $asOf,
        int $maxAgeSeconds,
        MonitorSalesSnapshot ...$snapshots,
    ): array {
        if ($actorId < 1) {
            throw new AuthorizationException('Monitor reporting requires a verified installation grant.');
        }
        $authorized = $this->access->readableInstallationIds($actorId);
        foreach ($authorized as $installationId) {
            if (! is_int($installationId) || $installationId < 1) {
                throw new InvalidArgumentException('Monitor installation grants require positive integer IDs.');
            }
        }
        $authorized = array_unique($authorized);
        if ($authorized === []) {
            throw new AuthorizationException('Monitor reporting requires a verified installation grant.');
        }
        sort($authorized, SORT_NUMERIC);

        if ($windowStart >= $windowEnd || $windowEnd > $asOf || $maxAgeSeconds < 0) {
            throw new InvalidArgumentException('Monitor reporting requires a closed window and a nonnegative freshness limit.');
        }

        $byInstallation = array_fill_keys($authorized, []);
        foreach ($snapshots as $snapshot) {
            if (isset($byInstallation[$snapshot->installationId])
                && $snapshot->windowStart == $windowStart
                && $snapshot->windowEnd == $windowEnd
                && $snapshot->capturedAt <= $asOf) {
                $byInstallation[$snapshot->installationId][] = $snapshot;
            }
        }

        $installations = [];
        $subtotal = BigInteger::zero();
        $freshCount = 0;
        $freshAfter = $asOf->sub(new DateInterval("PT{$maxAgeSeconds}S"));
        foreach ($byInstallation as $installationId => $matches) {
            $result = [
                'installation_id' => $installationId,
                'status' => 'unknown',
                'captured_at' => null,
                'net_paisa' => null,
            ];

            if (count($matches) > 1) {
                $result['status'] = 'conflict';
            } elseif (count($matches) === 1) {
                $snapshot = $matches[0];
                $result['captured_at'] = $snapshot->capturedAt->format(DATE_RFC3339_EXTENDED);
                if ($snapshot->complete) {
                    if ($snapshot->capturedAt < $freshAfter) {
                        $result['status'] = 'stale';
                    } else {
                        $net = $this->snapshotNet($snapshot);
                        if ($net === null) {
                            $result['status'] = 'conflict';
                        } else {
                            $subtotal = $subtotal->plus($net);
                            $freshCount++;
                            $result['status'] = 'fresh';
                            $result['net_paisa'] = (string) $net;
                        }
                    }
                }
            }

            $installations[] = $result;
        }

        $complete = $freshCount === count($byInstallation);

        return [
            'currency' => 'PKR',
            'coverage' => $complete ? 'complete' : ($freshCount === 0 ? 'unavailable' : 'partial'),
            'fresh_installations' => $freshCount,
            'authorized_installations' => count($byInstallation),
            'net_paisa' => $complete ? (string) $subtotal : null,
            'fresh_subtotal_paisa' => $freshCount === 0 ? null : (string) $subtotal,
            'installations' => $installations,
        ];
    }

    private function snapshotNet(MonitorSalesSnapshot $snapshot): ?BigInteger
    {
        $bySource = [];
        $net = BigInteger::zero();

        foreach ($snapshot->events as $event) {
            if ($event->netPaisa === null) {
                continue;
            }

            if (isset($bySource[$event->sourceId])) {
                if ($bySource[$event->sourceId] !== $event->netPaisa) {
                    return null;
                }

                continue;
            }

            $bySource[$event->sourceId] = $event->netPaisa;
            $net = $net->plus($event->netPaisa);
        }

        return $net;
    }
}
