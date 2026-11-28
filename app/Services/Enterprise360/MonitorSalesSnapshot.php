<?php

declare(strict_types=1);

namespace App\Services\Enterprise360;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class MonitorSalesSnapshot
{
    /** @var list<MonitorSalesEvent> */
    public array $events;

    public function __construct(
        public int $installationId,
        public DateTimeImmutable $windowStart,
        public DateTimeImmutable $windowEnd,
        public DateTimeImmutable $capturedAt,
        public bool $complete,
        MonitorSalesEvent ...$events,
    ) {
        if ($installationId < 1 || $windowStart >= $windowEnd || $windowEnd > $capturedAt) {
            throw new InvalidArgumentException('A sales snapshot requires an installation and a closed reporting window.');
        }

        $this->events = array_values($events);
    }
}
