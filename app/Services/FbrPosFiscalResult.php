<?php

namespace App\Services;

final class FbrPosFiscalResult
{
    public static function number(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        $number = trim($value);
        return preg_match('/^[A-Za-z0-9-]{6,100}$/D', $number) && preg_match('/[0-9]/', $number)
            ? $number : null;
    }

    public static function fromResponse(?array $response): ?string
    {
        if (!$response) return null;
        $numbers = [];
        foreach (['InvoiceNumber', 'FBRInvoiceNumber', 'invoiceNumber'] as $key) {
            if (!isset($response[$key]) || trim((string) $response[$key]) === '') continue;
            $number = self::number($response[$key]);
            if (!$number) return null;
            $numbers[] = $number;
        }
        if (!$numbers) {
            $legacy = $response['Response'] ?? null;
            return is_string($legacy) && preg_match('/^(?:[0-9]{6}|[A-Z][A-Z-]*-[0-9]{3,}$)/', trim($legacy)) ? self::number($legacy) : null;
        }
        return count(array_unique($numbers)) === 1 ? $numbers[0] : null;
    }
}
