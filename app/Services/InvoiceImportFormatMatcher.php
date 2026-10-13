<?php

namespace App\Services;

/**
 * Deterministic match for previously approved DI import column mappings.
 *
 * This is suggestion-only. It must not authorize invoice submission or turn
 * an unreviewed source row into a valid FBR item.
 */
final class InvoiceImportFormatMatcher
{
    /**
     * @param list<string> $headers Actual source headers (in any order).
     * @param list<array{id:int,mapping:array<string,string>}> $presets
     * @return array{preset_id:int|null,reason:string}
     */
    public function match(array $headers, array $presets): array
    {
        $available = [];
        foreach ($headers as $header) {
            $key = self::normalize($header);
            if ($key !== '') {
                $available[$key] = ($available[$key] ?? 0) + 1;
            }
        }

        $matches = [];
        foreach ($presets as $preset) {
            $mapped = array_filter($preset['mapping'] ?? [], fn ($v) => is_string($v) && trim($v) !== '');
            if (empty($mapped)) {
                continue;
            }

            $sourceKeys = array_map(self::normalize(...), array_values($mapped));
            // Duplicate source mappings or duplicate headers are ambiguous.
            if (in_array('', $sourceKeys, true) || count(array_unique($sourceKeys)) !== count($sourceKeys)) {
                continue;
            }

            $allFound = true;
            foreach ($sourceKeys as $key) {
                if (($available[$key] ?? 0) !== 1) {
                    $allFound = false;
                    break;
                }
            }
            if ($allFound) {
                $matches[] = (int) $preset['id'];
            }
        }

        if (count($matches) === 1) {
            return ['preset_id' => $matches[0], 'reason' => 'unique_exact_header_match'];
        }

        return [
            'preset_id' => null,
            'reason' => count($matches) > 1 ? 'ambiguous_presets' : 'no_exact_match',
        ];
    }

    private static function normalize(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(trim($value))) ?? '';
    }
}
