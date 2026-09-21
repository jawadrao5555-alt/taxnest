<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Revision for files that shape the cache-first PRA/FBR sale-screen shell.
 *
 * A cached document can only refresh itself after it compares its baked boot
 * fingerprint with the live boot-check endpoint. Keep every shared layout and
 * runtime dependency here so a header-only or asset-only deploy also changes
 * that fingerprint.
 */
final class PosSaleShellRevision
{
    /**
     * Keep the shell hash inside the long-established `s` boot-fingerprint key.
     * Already-cached documents only compare the legacy key set, so adding a new
     * sibling key would not invalidate those documents.
     */
    public static function bootScreenRevision(
        string $panel,
        string $screenRevision,
        string $languageRevision
    ): string {
        return $screenRevision.'-'.$languageRevision.'-'.self::for($panel);
    }

    public static function for(string $panel): string
    {
        $variantFiles = match ($panel) {
            'pra' => [
                resource_path('views/layouts/pos-app.blade.php'),
                resource_path('views/pos/universal.blade.php'),
            ],
            'fbr' => [
                resource_path('views/layouts/fbr-pos-app.blade.php'),
                resource_path('views/fbr-pos/universal.blade.php'),
            ],
            default => throw new InvalidArgumentException("Unsupported POS panel [{$panel}]."),
        };

        return self::hashFiles(array_merge($variantFiles, [
            resource_path('views/partials/alpine-runtime-loader.blade.php'),
            resource_path('views/partials/whats-new-detail-modals.blade.php'),
            public_path('build/manifest.json'),
            public_path('sw.js'),
        ]));
    }

    /**
     * @param  list<string>  $paths
     */
    public static function hashFiles(array $paths): string
    {
        $parts = [];

        foreach ($paths as $path) {
            $parts[] = is_file($path)
                ? hash_file('sha256', $path)
                : 'missing:'.basename($path);
        }

        return hash('sha256', implode('|', $parts));
    }
}