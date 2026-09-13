<?php

namespace App\Support;

/**
 * Customer-visible What's New titles must never expose deploy SHA / provenance.
 *
 * CI still stores `{title} [deploy {40-char sha}]` on app_updates.title so
 * Elaan freshness/idempotency stays exact-SHA. Only the display layer strips
 * the suffix. Admin screens keep the stored title.
 */
final class CustomerFacingUpdateTitle
{
    public const SUFFIX_PATTERN = '/\s*\[deploy [0-9a-f]{40}\]\s*$/i';

    public static function display(?string $title): string
    {
        return trim((string) preg_replace(self::SUFFIX_PATTERN, '', (string) $title));
    }

    public static function containsDeploySuffix(?string $title): bool
    {
        return (bool) preg_match(self::SUFFIX_PATTERN, (string) $title);
    }
}
