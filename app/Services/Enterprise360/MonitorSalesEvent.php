<?php

declare(strict_types=1);

namespace App\Services\Enterprise360;

use InvalidArgumentException;

final readonly class MonitorSalesEvent
{
    private function __construct(
        public string $sourceId,
        public ?int $netPaisa,
    ) {
        if ($sourceId === '' || trim($sourceId) !== $sourceId) {
            throw new InvalidArgumentException('Monitor events require a stable source ID.');
        }
    }

    public static function native(string $sourceId, int $netPaisa): self
    {
        return new self($sourceId, $netPaisa);
    }

    public static function fiscalAcknowledgement(string $sourceId): self
    {
        return new self($sourceId, null);
    }
}
