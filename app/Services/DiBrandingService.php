<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\Storage;

/**
 * Task 140: DI Premium white-label branding (plan gate key: `white_label`).
 *
 * Single resolver for the branding applied to DI invoice PDFs, the public
 * share page and invoice delivery emails. Hard rules:
 *
 *   - Branding is COSMETIC ONLY (header logo, accent color, footer lines,
 *     platform-credit visibility). FBR-required elements — QR code, FBR
 *     invoice number, tax breakdown — are never touched by anything here.
 *   - Fail closed: unless the company's plan CURRENTLY allows the
 *     `white_label` gate AND the company switched branding on, every field
 *     resolves to the platform default (null/empty/false) so templates
 *     render exactly as they did before this feature existed. Downgrades
 *     therefore auto-revert branding without any cleanup job.
 *   - DomPDF safety: logos are embedded as base64 data URIs read from the
 *     local public disk — PDF rendering never fetches a remote URL.
 */
class DiBrandingService
{
    public const LOGO_DIR = 'branding-logos';

    /** Upload cap enforced in the controller (KB for the validator). */
    public const MAX_LOGO_KB = 1024;

    /** @var array<int, array> per-request cache keyed by company id */
    protected static array $cache = [];

    /**
     * Neutral defaults = platform branding untouched. Non-premium companies
     * (and premium companies with the toggle off) always get exactly this.
     */
    public static function defaults(): array
    {
        return [
            'active' => false,        // gate open AND company toggle on
            'allowed' => false,       // plan gate result (settings UI uses it)
            'logo_data_uri' => null,  // embedded logo for DomPDF
            'logo_url' => null,       // absolute URL for web pages / emails
            'accent' => null,         // validated #rrggbb or null
            'accent_text' => '#ffffff', // readable text color on the accent
            'footer_lines' => [],     // up to 2 custom footer lines
            'hide_platform' => false, // hide "TaxNest" credit line
        ];
    }

    /** Effective branding for rendering. Safe with null/unsaved companies. */
    public static function forCompany(?Company $company): array
    {
        if (!$company || !$company->id) {
            return self::defaults();
        }
        if (isset(self::$cache[$company->id])) {
            return self::$cache[$company->id];
        }

        return self::$cache[$company->id] = self::resolve($company);
    }

    protected static function resolve(Company $company): array
    {
        $out = self::defaults();

        try {
            $out['allowed'] = DiFeatureService::planAllows($company, 'white_label');
        } catch (\Throwable $e) {
            \Log::warning('DiBrandingService gate check failed', ['company_id' => $company->id, 'error' => $e->getMessage()]);

            return $out; // fail closed → default branding
        }

        $stored = self::stored($company);
        if (!$out['allowed'] || !$stored['enabled']) {
            return $out;
        }

        $out['active'] = true;
        $out['hide_platform'] = $stored['hide_platform'];
        $out['footer_lines'] = $stored['footer_lines'];

        // Accent is strictly re-validated at render time (defense in depth —
        // an unexpected DB value must never reach a <style> block).
        $accent = self::sanitizeAccent($stored['accent']);
        if ($accent) {
            $out['accent'] = $accent;
            $out['accent_text'] = self::contrastText($accent);
        }

        if ($stored['logo_path']) {
            try {
                $disk = Storage::disk('public');
                if ($disk->exists($stored['logo_path'])) {
                    $out['logo_url'] = url($disk->url($stored['logo_path']));
                    $out['logo_data_uri'] = self::dataUri($disk->path($stored['logo_path']));
                }
            } catch (\Throwable $e) {
                \Log::warning('DiBrandingService logo read failed', ['company_id' => $company->id, 'error' => $e->getMessage()]);
            }
        }

        return $out;
    }

    /**
     * Raw saved settings, normalized — NO plan gate applied. The settings
     * form uses this so a downgraded company still sees what it saved.
     */
    public static function stored(Company $company): array
    {
        $raw = $company->di_branding;
        if (!is_array($raw)) {
            $raw = [];
        }

        $line1 = trim((string) ($raw['footer_line1'] ?? ''));
        $line2 = trim((string) ($raw['footer_line2'] ?? ''));
        $lines = array_values(array_filter([$line1, $line2], fn ($l) => $l !== ''));

        $logoPath = $raw['logo_path'] ?? null;
        if (!is_string($logoPath) || trim($logoPath) === '') {
            $logoPath = null;
        }

        return [
            'enabled' => filter_var($raw['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'logo_path' => $logoPath,
            'accent' => is_string($raw['accent'] ?? null) ? $raw['accent'] : null,
            'footer_line1' => $line1,
            'footer_line2' => $line2,
            'footer_lines' => $lines,
            'hide_platform' => filter_var($raw['hide_platform'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /** Strict #rrggbb (6 hex digits) or null. Normalized to lowercase. */
    public static function sanitizeAccent(?string $hex): ?string
    {
        if (!is_string($hex)) {
            return null;
        }
        $hex = trim($hex);

        return preg_match('/^#[0-9a-fA-F]{6}$/', $hex) ? strtolower($hex) : null;
    }

    /** Readable text color (white/near-black) for a given background, via YIQ luminance. */
    public static function contrastText(string $hex): string
    {
        $r = hexdec(substr($hex, 1, 2));
        $g = hexdec(substr($hex, 3, 2));
        $b = hexdec(substr($hex, 5, 2));
        $yiq = ($r * 299 + $g * 587 + $b * 114) / 1000;

        return $yiq >= 150 ? '#111111' : '#ffffff';
    }

    /** Base64 data URI for DomPDF embedding. Null when unreadable/oversized/unsupported. */
    protected static function dataUri(string $absPath): ?string
    {
        if (!is_file($absPath)) {
            return null;
        }
        $size = @filesize($absPath);
        if (!$size || $size > self::MAX_LOGO_KB * 1024 * 2) {
            // Hard stop at 2x the upload cap — protects DomPDF memory even
            // if an oversized file lands on disk through some other path.
            return null;
        }
        $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => null, // webp etc. are rejected at upload (DomPDF-unsafe)
        };
        if (!$mime) {
            return null;
        }
        $bin = @file_get_contents($absPath);

        return $bin === false ? null : ('data:' . $mime . ';base64,' . base64_encode($bin));
    }

    /** Reset the per-request cache (settings save + tests). */
    public static function flushCache(): void
    {
        self::$cache = [];
    }
}
