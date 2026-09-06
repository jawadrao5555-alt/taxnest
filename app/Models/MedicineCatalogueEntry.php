<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row of the GLOBAL medicine catalogue (Task 1579).
 *
 * Seeded from DRAP's public Pharmaceutical Product Price Index (Government of
 * Pakistan data — https://e.dra.gov.pk/public/price) and supplemented by the
 * SaaS admin (manual rows / Excel imports such as distributor price lists).
 *
 * Identity = DRAP registration number × pack size × manufacturer (dedupe_key),
 * because one registration is listed once per pack and a pack may be re-listed
 * under a new registration holder. The raw DRAP composition text is kept
 * verbatim; generic/strength/dosage form are heuristics an admin may correct.
 */
class MedicineCatalogueEntry extends Model
{
    protected $table = 'medicine_catalogue';

    public const SOURCE_DRAP = 'drap';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_IMPORT = 'import';

    public const CATEGORY_ESSENTIAL = 'essential';
    public const CATEGORY_LOW_PRICE = 'low_price';
    public const CATEGORY_NORMAL = 'normal';

    protected $fillable = [
        'brand_name', 'composition', 'generic_name', 'strength', 'dosage_form',
        'manufacturer', 'manufacturer_licence', 'drap_reg_no', 'category',
        'pack_size', 'mrp', 'effective_date', 'source', 'is_active',
        'dedupe_key', 'checksum', 'last_seen_at',
    ];

    protected $casts = [
        'mrp' => 'decimal:2',
        'effective_date' => 'date',
        'is_active' => 'boolean',
        'last_seen_at' => 'datetime',
    ];

    public function prices()
    {
        return $this->hasMany(MedicineCataloguePrice::class, 'catalogue_id')->orderByDesc('id');
    }

    /** The idempotent upsert key — every writer (DRAP crawl, Excel import, manual) must use this. */
    public static function dedupeKey(?string $regNo, ?string $packSize, ?string $manufacturer): string
    {
        $norm = fn (?string $s) => preg_replace('/\s+/', ' ', mb_strtolower(trim((string) $s)));

        return sha1($norm($regNo) . '|' . $norm($packSize) . '|' . $norm($manufacturer));
    }

    /** DRAP's badge text → our category code. Unknown/blank badge = 'normal'. */
    public static function categoryFromLabel(?string $label): string
    {
        $l = mb_strtolower(trim((string) $label));
        if ($l === '') {
            return self::CATEGORY_NORMAL;
        }
        if (str_contains($l, 'essential')) {
            return self::CATEGORY_ESSENTIAL;
        }
        if (str_contains($l, 'low price') || str_contains($l, 'low_price') || str_contains($l, 'lowprice')) {
            return self::CATEGORY_LOW_PRICE;
        }

        return self::CATEGORY_NORMAL;
    }

    public static function categoryLabel(?string $code): string
    {
        return match ($code) {
            self::CATEGORY_ESSENTIAL => 'Essential',
            self::CATEGORY_LOW_PRICE => 'Low Price',
            default => 'Normal',
        };
    }

    /** One-line label a shop sees in the picker: brand · composition · maker · pack · MRP. */
    public function toPickerArray(): array
    {
        return [
            'id' => (int) $this->id,
            'brand_name' => (string) $this->brand_name,
            'composition' => (string) ($this->composition ?? ''),
            'generic_name' => (string) ($this->generic_name ?? ''),
            'strength' => (string) ($this->strength ?? ''),
            'dosage_form' => (string) ($this->dosage_form ?? ''),
            'manufacturer' => (string) ($this->manufacturer ?? ''),
            'pack_size' => (string) ($this->pack_size ?? ''),
            'mrp' => $this->mrp !== null ? (float) $this->mrp : null,
            'effective_date' => $this->effective_date?->format('Y-m-d'),
            'drap_reg_no' => (string) ($this->drap_reg_no ?? ''),
            'category' => (string) ($this->category ?? self::CATEGORY_NORMAL),
        ];
    }
}
