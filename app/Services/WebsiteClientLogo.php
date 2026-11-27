<?php

namespace App\Services;

use App\Models\Company;
use App\Models\WebsiteClient;
use Illuminate\Support\Facades\Storage;

final class WebsiteClientLogo
{
    private const MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

    private const MAX_BYTES = 2 * 1024 * 1024;

    public static function hasPublicLogo(WebsiteClient $entry): bool
    {
        return in_array($entry->logo_mime, self::MIME_TYPES, true)
            || ($entry->sync_company_logo && self::companyImage($entry->company) !== null);
    }

    /** @return array{bytes: string, mime: string}|null */
    public static function publicImage(WebsiteClient $entry): ?array
    {
        return self::manualImage($entry)
            ?? ($entry->sync_company_logo ? self::companyImage($entry->company) : null);
    }

    /** @return array{bytes: string, mime: string}|null */
    public static function manualImage(WebsiteClient $entry): ?array
    {
        if (!in_array($entry->logo_mime, self::MIME_TYPES, true) || !$entry->logo_data) {
            return null;
        }

        return self::rasterImage(base64_decode($entry->logo_data, true));
    }

    /** @return array{bytes: string, mime: string}|null */
    public static function companyImage(?Company $company): ?array
    {
        if (!$company) {
            return null;
        }

        if (($company->product_type ?: 'di') === 'di') {
            $branding = DiBrandingService::stored($company);
            if ($branding['enabled'] && $branding['logo_path'] && DiFeatureService::planAllows($company, 'white_label')) {
                $image = self::storedImage($branding['logo_path'], DiBrandingService::LOGO_DIR);
                if ($image) {
                    return $image;
                }
            }
        }

        return self::storedImage($company->logo_path, 'company-logos');
    }

    /** @return array{bytes: string, mime: string}|null */
    private static function storedImage(?string $path, string $directory): ?array
    {
        // Only files in the two application-owned logo directories are eligible.
        if (!$path || !preg_match('/\A'.preg_quote($directory, '/').'\/[a-z0-9][a-z0-9._-]*\.(?:png|jpe?g|webp)\z/i', $path)) {
            return null;
        }

        $stream = Storage::disk('public')->readStream($path);
        if (!is_resource($stream)) {
            return null;
        }

        try {
            return self::rasterImage(stream_get_contents($stream, self::MAX_BYTES + 1));
        } finally {
            fclose($stream);
        }
    }

    /** @return array{bytes: string, mime: string}|null */
    private static function rasterImage(string|false $bytes): ?array
    {
        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            return null;
        }

        $mime = @getimagesizefromstring($bytes)['mime'] ?? null;

        return in_array($mime, self::MIME_TYPES, true) ? ['bytes' => $bytes, 'mime' => $mime] : null;
    }
}
