<?php
namespace App\Support\Hr;

use DateTimeImmutable;

final class ProductEntitlement
{
    /** Independent HR entitlement; never mutates or replaces existing POS/DI subscriptions. */
    public static function active(string $product, DateTimeImmutable $now, DateTimeImmutable $startsAt, DateTimeImmutable $endsAt, bool $enabled): bool
    {
        return $product === 'hr' && $enabled
            && $now->getTimestamp() >= $startsAt->getTimestamp()
            && $now->getTimestamp() < $endsAt->getTimestamp();
    }
}
