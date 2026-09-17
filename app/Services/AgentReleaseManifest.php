<?php

namespace App\Services;

/**
 * Validates the signed-release inventory before a server advertises or serves
 * an Agent asset. GitHub's release API describes files, but does not make a
 * "largest zip wins" rule an identity guarantee; the release manifest does.
 *
 * This class deliberately performs no I/O. Callers fetch the manifest asset
 * and pass the release API metadata in so validation remains deterministic and
 * directly testable.
 */
final class AgentReleaseManifest
{
    public const PRODUCT = 'taxnest-pra-agent';
    public const ASSET_NAME = 'release-manifest.json';
    public const CANONICAL_ZIP = 'TaxNest-PRA-Agent-Windows.zip';

    /**
     * @param array<string,mixed> $manifest
     * @param array<int,array<string,mixed>> $releaseAssets
     * @return array<string,mixed>|null Canonical, safe-to-advertise inventory.
     */
    public static function validate(array $manifest, string $tag, string $repo, array $releaseAssets): ?array
    {
        $version = self::versionFromTag($tag);
        if ($version === null
            || ($manifest['schema_version'] ?? null) !== 1
            || ($manifest['product'] ?? null) !== self::PRODUCT
            || ($manifest['version'] ?? null) !== $version
            || !self::gitSha($manifest['source_sha'] ?? null)
            || !self::gitSha($manifest['build_sha'] ?? null)
            // The release workflow builds the exact owner-approved source
            // target. A pair of individually valid but different SHAs would
            // make that provenance claim ambiguous, so fail closed.
            || !hash_equals(
                strtolower((string) $manifest['source_sha']),
                strtolower((string) $manifest['build_sha'])
            )) {
            return null;
        }

        $compatibility = $manifest['compatibility'] ?? null;
        if (!is_array($compatibility)
            || !self::semver($compatibility['min_agent_version'] ?? null)
            || !self::semver($compatibility['max_agent_version'] ?? null)
            || version_compare($compatibility['min_agent_version'], $compatibility['max_agent_version'], '>')) {
            return null;
        }

        $assets = $manifest['assets'] ?? null;
        if (!is_array($assets) || count($assets) < 2) {
            return null;
        }

        $releaseByName = [];
        foreach ($releaseAssets as $asset) {
            if (!is_array($asset) || !is_string($asset['name'] ?? null) || isset($releaseByName[$asset['name']])) {
                return null; // duplicate GitHub names make identity ambiguous.
            }
            $releaseByName[$asset['name']] = $asset;
        }

        $inventory = [];
        foreach ($assets as $asset) {
            if (!is_array($asset)
                || !is_string($asset['name'] ?? null)
                || isset($inventory[$asset['name']])
                || !self::sha($asset['sha256'] ?? null)
                || !is_int($asset['size'] ?? null)
                || $asset['size'] <= 0
                || !isset($releaseByName[$asset['name']])) {
                return null;
            }
            $published = $releaseByName[$asset['name']];
            if ((int) ($published['size'] ?? -1) !== $asset['size']
                || !self::isExpectedAssetUrl((string) ($published['url'] ?? ''), $repo, $tag)) {
                return null;
            }
            // GitHub now supplies a digest for some releases. When it does,
            // require it to agree with the manifest instead of ignoring it.
            $digest = strtolower((string) ($published['digest'] ?? ''));
            if ($digest !== '' && $digest !== 'sha256:' . strtolower($asset['sha256'])) {
                return null;
            }
            $inventory[$asset['name']] = [
                'name' => $asset['name'],
                'url' => $published['url'],
                'size' => $asset['size'],
                'sha256' => strtolower($asset['sha256']),
            ];
        }

        $zip = $inventory[self::CANONICAL_ZIP] ?? null;
        $setupName = "TaxNest-Agent-Setup-{$version}.exe";
        $setup = $inventory[$setupName] ?? null;
        if ($zip === null || $setup === null) {
            return null;
        }

        // A second executable/zip is not harmless: old behaviour selected it
        // by size. Refuse such a release until the manifest is corrected.
        foreach (array_keys($releaseByName) as $name) {
            if ((str_ends_with(strtolower($name), '.zip') || str_ends_with(strtolower($name), '.exe'))
                && !isset($inventory[$name])) {
                return null;
            }
        }

        return [
            'tag' => $tag,
            'product' => self::PRODUCT,
            'version' => $version,
            'source_sha' => strtolower($manifest['source_sha']),
            'build_sha' => strtolower($manifest['build_sha']),
            'compatibility' => [
                'min_agent_version' => $compatibility['min_agent_version'],
                'max_agent_version' => $compatibility['max_agent_version'],
            ],
            'assets' => array_values($inventory),
            'zip' => $zip,
            'exe' => $setup,
        ];
    }

    public static function versionFromTag(string $tag): ?string
    {
        return preg_match('/^v?(\d{1,2}\.\d+\.\d+)$/', $tag, $m) ? $m[1] : null;
    }

    private static function semver(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{1,2}\.\d+\.\d+$/', $value) === 1;
    }

    private static function sha(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-fA-F0-9]{64}$/', $value) === 1;
    }

    private static function gitSha(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-fA-F0-9]{40}$/', $value) === 1;
    }

    private static function isExpectedAssetUrl(string $url, string $repo, string $tag): bool
    {
        return str_starts_with($url, 'https://github.com/' . $repo . '/releases/download/' . rawurlencode($tag) . '/');
    }
}