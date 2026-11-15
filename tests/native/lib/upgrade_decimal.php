<?php
declare(strict_types=1);

/** Exact representation-only normalization: never round or convert through float. */
function rcUpgradeDecimal(string $value): string
{
    if (!preg_match('/^-?\\d+(?:\\.\\d+)?$/D', $value)) {
        throw new InvalidArgumentException('Invalid decimal preservation evidence');
    }
    return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
}
