<?php

namespace App\Services;

use App\Models\InvoiceImportMapping;
use App\Models\InvoiceImportMappingVersion;

/** Caller holds the company row lock before changing a preset. */
final class InvoiceImportMappingHistory
{
    public function append(InvoiceImportMapping $mapping, array $headers, string $extension, bool $approved): InvoiceImportMappingVersion
    {
        $snapshot = [
            'name' => $mapping->name,
            'mapping' => $mapping->mappingArray(),
            'defaults' => $mapping->defaultsArray(),
            'source_headers' => $headers,
            'source_format' => $extension,
            'header_fingerprint' => $headers ? $this->fingerprint($headers, $extension) : null,
        ];

        return InvoiceImportMappingVersion::create([
            'company_id' => $mapping->company_id,
            'mapping_id' => $mapping->id,
            'version' => 1 + (int) InvoiceImportMappingVersion::where('company_id', $mapping->company_id)
                ->where('mapping_id', $mapping->id)->max('version'),
            'snapshot' => $snapshot,
            'snapshot_hash' => hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            // Historical presets are never retroactively marked approved.
            'approved_by' => $approved ? auth()->id() : null,
            'approved_at' => $approved ? now() : null,
        ]);
    }

    public function current(InvoiceImportMapping $mapping): ?InvoiceImportMappingVersion
    {
        return InvoiceImportMappingVersion::where('company_id', $mapping->company_id)
            ->where('mapping_id', $mapping->id)->orderByDesc('version')->first();
    }

    public function accepts(InvoiceImportMapping $mapping, array $headers, string $extension): bool
    {
        $version = $this->current($mapping);

        // Preserve old presets; never invent approval metadata for them.
        return ! $version || ! $version->approved_at ||
            ($version->snapshot['header_fingerprint'] ?? null) === $this->fingerprint($headers, $extension);
    }

    public function fingerprint(array $headers, string $extension): string
    {
        $keys = array_map(InvoiceImportService::normalizeHeaderKey(...), $headers);
        sort($keys, SORT_STRING);

        return hash('sha256', json_encode(['format' => $extension, 'headers' => $keys], JSON_THROW_ON_ERROR));
    }
}
