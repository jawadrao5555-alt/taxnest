<?php

namespace App\Support;

/**
 * Safe printer-queue identity for claim / failover matching.
 *
 * The stored `target_printer` snapshot is never rewritten here. Matching
 * only folds case and interior whitespace so "Kitchen Printer" and
 * "kitchen  printer" stay the same queue, while "Kitchen" vs "Kitchen-LAN"
 * stay distinct.
 */
final class PrinterIdentity
{
    public static function normalize(?string $name): string
    {
        $name = strtolower(trim((string) $name));
        if ($name === '') {
            return '';
        }

        return preg_replace('/\s+/u', ' ', $name) ?? $name;
    }

    public static function same(?string $left, ?string $right): bool
    {
        $a = self::normalize($left);
        $b = self::normalize($right);

        return $a !== '' && $a === $b;
    }

    /**
     * @param  iterable<int|string, mixed>  $reported
     */
    public static function reportedHas(iterable $reported, ?string $target): bool
    {
        foreach ($reported as $entry) {
            $name = is_array($entry) ? ($entry['name'] ?? '') : $entry;
            if (self::same(is_string($name) ? $name : null, $target)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when a saved routing name is no longer among the last reported
     * queues. Settings are left untouched — this is a surface-only warning.
     *
     * @param  iterable<int|string, mixed>  $reported
     */
    public static function savedNameLooksStale(?string $saved, iterable $reported): bool
    {
        $saved = self::normalize($saved);
        if ($saved === '') {
            return false;
        }
        $have = false;
        foreach ($reported as $entry) {
            $name = is_array($entry) ? ($entry['name'] ?? '') : $entry;
            if (self::normalize(is_string($name) ? $name : null) !== '') {
                $have = true;
                break;
            }
        }

        return $have && !self::reportedHas($reported, $saved);
    }
}
