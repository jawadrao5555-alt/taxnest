<?php

namespace App\Services\Pharmacy;

use App\Models\MedicineCatalogueEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Validate source observations; an observation never changes a shop's price. */
final class MedicineSourceReview
{
    public static function validate(array $row): void
    {
        if (trim((string) ($row['brand_name'] ?? '')) === '') {
            throw new \InvalidArgumentException('brand_name required');
        }
        $price = $row['mrp'] ?? null;
        if ($price !== null && $price !== '' && (! is_numeric($price) || ! is_finite((float) $price)
            || round((float) $price, 2) <= 0 || (float) $price > 999999999999.99)) {
            throw new \InvalidArgumentException('MRP must be a positive numeric source value or explicitly absent.');
        }
        $date = $row['effective_date'] ?? null;
        if ($date !== null && $date !== '') {
            $parsed = is_string($date) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
            if (! $parsed || $parsed->format('Y-m-d') !== $date) {
                throw new \InvalidArgumentException('Invalid source effective date; YYYY-MM-DD is required.');
            }
        }
    }

    public static function disposition(array $row, ?MedicineCatalogueEntry $entry): string
    {
        $date = $row['effective_date'] ?? null;
        $price = isset($row['mrp']) && $row['mrp'] !== '' ? round((float) $row['mrp'], 2) : null;
        if ($price === null) {
            return 'applied';
        }
        if ($date && $date > now()->timezone('Asia/Karachi')->toDateString()) {
            return 'pending';
        }
        $current = $entry?->effective_date?->toDateString();
        if ($current && $date && $date < $current) {
            return 'stale';
        }
        if ($entry && $entry->mrp !== null && abs((float) $entry->mrp - $price) >= 0.005) {
            if ($current && ! $date) {
                return 'undated_conflict';
            }
            if ($current && $date === $current) {
                return 'date_conflict';
            }
        }

        return 'applied';
    }

    public static function record(array $row, string $source, ?int $syncId, ?MedicineCatalogueEntry $entry, string $disposition, ?string $reason = null): void
    {
        if (! Schema::hasTable('medicine_source_observations')) {
            if ($disposition === 'pending') {
                throw new \RuntimeException('Source observation migration is required before queuing future prices.');
            }

            return;
        }
        $fields = ['brand_name', 'composition', 'drap_reg_no', 'manufacturer', 'manufacturer_licence', 'category',
            'category_label', 'pack_size', 'mrp', 'effective_date', 'generic_name', 'strength', 'dosage_form'];
        $payload = array_intersect_key($row, array_flip($fields));
        foreach ($payload as $key => $value) {
            $payload[$key] = is_scalar($value) ? mb_substr((string) $value, 0, 2000) : null;
        }
        ksort($payload);
        $hash = hash('sha256', json_encode([$source, $payload], JSON_THROW_ON_ERROR));
        $date = $row['effective_date'] ?? null;
        $data = ['catalogue_id' => $entry?->id, 'dedupe_key' => $entry?->dedupe_key, 'source' => $source,
            'sync_id' => $syncId, 'effective_date' => $disposition === 'invalid' ? null : ($date ?: null),
            'mrp' => $disposition === 'invalid' || ! is_numeric($row['mrp'] ?? null) ? null : round((float) $row['mrp'], 2),
            'disposition' => $disposition, 'reason' => $reason, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'source_hash' => $hash, 'observed_at' => now(), 'applied_at' => $disposition === 'applied' ? now() : null];
        DB::table('medicine_source_observations')->insertOrIgnore($data);
        // A due observation may advance once; stale/conflicting/invalid evidence is never relabelled applied.
        if ($disposition === 'applied') {
            DB::table('medicine_source_observations')->where('source_hash', $hash)
                ->where('disposition', 'pending')->update(['disposition' => 'applied', 'catalogue_id' => $entry?->id,
                    'dedupe_key' => $entry?->dedupe_key, 'applied_at' => now()]);
        }
    }

    public static function applyDue(MedicineCatalogueSyncService $service, int $limit = 500): int
    {
        if (! Schema::hasTable('medicine_source_observations')) {
            return 0;
        }
        $rows = DB::table('medicine_source_observations')->where('disposition', 'pending')
            ->whereDate('effective_date', '<=', now()->timezone('Asia/Karachi')->toDateString())
            ->orderByDesc('effective_date')->orderBy('id')->limit($limit)->get();
        $applied = 0;
        foreach ($rows as $row) {
            DB::transaction(function () use ($row, $service, &$applied) {
                $current = DB::table('medicine_source_observations')->where('id', $row->id)->lockForUpdate()->first();
                if (! $current || $current->disposition !== 'pending') {
                    return;
                }
                $conflict = DB::table('medicine_source_observations')->where('dedupe_key', $current->dedupe_key)
                    ->where('effective_date', $current->effective_date)->where('mrp', '!=', $current->mrp)
                    ->whereIn('disposition', ['pending', 'date_conflict'])->exists();
                if ($conflict) {
                    DB::table('medicine_source_observations')->where('id', $row->id)->update([
                        'disposition' => 'date_conflict', 'reason' => 'Different prices share the same source effective date; review required.']);

                    return;
                }
                $result = $service->upsertRow(json_decode($current->payload, true, 512, JSON_THROW_ON_ERROR), $current->source, $current->sync_id);
                DB::table('medicine_source_observations')->where('id', $row->id)->where('disposition', 'pending')
                    ->update(['disposition' => $result['price_changed'] ? 'applied' : 'stale',
                        'applied_at' => $result['price_changed'] ? now() : null]);
                if ($result['price_changed']) {
                    $applied++;
                }
            }, 3);
        }

        return $applied;
    }
}
