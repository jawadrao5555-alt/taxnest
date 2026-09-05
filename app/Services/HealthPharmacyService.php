<?php

namespace App\Services;

use App\Models\HealthMedicine;
use App\Models\HealthPharmacySetting;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Pharmacy catalogue + policy (Task 1549).
 *
 * Two jobs, both of them "single truth" jobs:
 *
 *  1. SETTINGS — every expiry/stock decision in the module reads its policy
 *     from here, so a company that never opened the settings screen still gets
 *     the safe defaults instead of each screen inventing its own.
 *
 *  2. CATALOGUE ↔ PRODUCT — a medicine owns a shared `products` row. Creating
 *     or renaming a medicine keeps that row in step, which is what allows the
 *     platform's inventory, purchase-order and fiscal services to be reused
 *     verbatim rather than re-implemented for healthcare.
 */
class HealthPharmacyService
{
    /** Document number prefixes. Sale prefix is company-configurable. */
    public const PRESCRIPTION_PREFIX = 'RX';
    public const RETURN_PREFIX = 'PHR';
    public const PURCHASE_PREFIX = 'MED';

    /** companyId => settings row, per request. */
    private static array $settingsMemo = [];

    /** Tests rebuild the schema between cases; a stale memo must not survive. */
    public static function forget(): void
    {
        self::$settingsMemo = [];
    }

    /**
     * The company's pharmacy policy. Created on first read with the same
     * defaults the migration declares, so there is exactly one set of numbers.
     */
    public static function settings(int $companyId): HealthPharmacySetting
    {
        if (isset(self::$settingsMemo[$companyId])) {
            return self::$settingsMemo[$companyId];
        }

        $settings = HealthPharmacySetting::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->first();

        if (!$settings) {
            $settings = HealthPharmacySetting::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                'near_expiry_days' => 90,
                'block_expired_dispense' => true,
                'warn_short_dated' => true,
                'require_prescription_for_controlled' => true,
                'allow_negative_stock' => false,
                'default_tax_rate' => 0,
                'low_stock_threshold' => 10,
                'sale_prefix' => 'PH',
            ]);
        }

        return self::$settingsMemo[$companyId] = $settings;
    }

    public static function saveSettings(int $companyId, array $values): HealthPharmacySetting
    {
        $settings = self::settings($companyId);
        $settings->fill($values);
        $settings->save();

        self::$settingsMemo[$companyId] = $settings;

        return $settings;
    }

    /**
     * The next document number for a company, e.g. PH-000123.
     *
     * The last row of the series is locked before the new one is computed, so
     * two counters submitting at the same instant cannot mint the same number.
     * The unique index on (company_id, number) is the second line of defence.
     */
    public static function nextNumber(int $companyId, string $table, string $column, string $prefix): string
    {
        $last = DB::table($table)
            ->where('company_id', $companyId)
            ->where($column, 'like', $prefix . '-%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value($column);

        $sequence = 0;
        if ($last && preg_match('/(\d+)$/', (string) $last, $matches)) {
            $sequence = (int) $matches[1];
        }

        return $prefix . '-' . str_pad((string) ($sequence + 1), 6, '0', STR_PAD_LEFT);
    }

    /** Purchase numbers stay date-stamped, matching the platform's PUR- style. */
    public static function nextPurchaseNumber(): string
    {
        return self::PURCHASE_PREFIX . '-' . date('ymd') . '-' . strtoupper(\Illuminate\Support\Str::random(4));
    }

    /**
     * Create a medicine and the shared catalogue row it stocks as.
     *
     * The product row is what `inventory_stocks`, `inventory_movements` and
     * `purchase_order_items` point at. Without it a medicine would need its own
     * parallel stock engine — exactly what this module refuses to build.
     */
    public static function createMedicine(int $companyId, array $data, ?int $userId = null): HealthMedicine
    {
        return DB::transaction(function () use ($companyId, $data, $userId) {
            $product = Product::withoutGlobalScopes()->create(self::productAttributes($companyId, $data));

            $medicine = HealthMedicine::withoutGlobalScopes()->create(array_merge(
                self::medicineAttributes($data),
                [
                    'company_id' => $companyId,
                    'product_id' => $product->id,
                    'created_by' => $userId,
                ]
            ));

            return $medicine;
        });
    }

    public static function updateMedicine(HealthMedicine $medicine, array $data): HealthMedicine
    {
        return DB::transaction(function () use ($medicine, $data) {
            $medicine->fill(self::medicineAttributes($data));
            $medicine->save();

            // A medicine whose product row went missing (restored backup, old
            // drifted row) gets one back instead of silently losing its stock
            // identity on the next purchase.
            $product = $medicine->product_id
                ? Product::withoutGlobalScopes()->find($medicine->product_id)
                : null;

            if (!$product) {
                $product = Product::withoutGlobalScopes()->create(
                    self::productAttributes((int) $medicine->company_id, $data)
                );
                $medicine->product_id = $product->id;
                $medicine->save();

                return $medicine;
            }

            $product->fill(self::productAttributes((int) $medicine->company_id, $data));
            $product->save();

            return $medicine;
        });
    }

    /** Shared-catalogue projection of a medicine. */
    private static function productAttributes(int $companyId, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $strength = trim((string) ($data['strength'] ?? ''));

        // A medicine may legitimately carry NO tax rate of its own — that means
        // "charge whatever the pharmacy charges". The shared products table has
        // no such concept: default_tax_rate is NOT NULL there, so a blank rate
        // must be resolved to the pharmacy's own default before it is written,
        // not passed through as null (which fails the insert outright).
        $rate = $data['tax_rate'] ?? null;
        $rate = ($rate === null || $rate === '')
            ? (float) self::settings($companyId)->default_tax_rate
            : (float) $rate;

        return [
            'company_id' => $companyId,
            'name' => trim($name . ' ' . $strength),
            'barcode' => $data['barcode'] ?? null,
            'sku' => $data['code'] ?? null,
            'hs_code' => $data['hs_code'] ?? null,
            'default_tax_rate' => $rate,
            'uom' => $data['unit_uom'] ?? 'unit',
            'default_price' => (float) ($data['sale_price'] ?? 0),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    private static function medicineAttributes(array $data): array
    {
        $form = $data['form'] ?? 'tablet';

        return [
            'name' => trim((string) ($data['name'] ?? '')),
            'generic_name' => $data['generic_name'] ?? null,
            'strength' => $data['strength'] ?? null,
            'form' => HealthMedicine::isForm($form) ? $form : 'other',
            'manufacturer' => $data['manufacturer'] ?? null,
            'category' => $data['category'] ?? null,
            'code' => $data['code'] ?? null,
            'barcode' => $data['barcode'] ?? null,
            'unit_uom' => $data['unit_uom'] ?? 'unit',
            'pack_uom' => $data['pack_uom'] ?? null,
            'pack_size' => max(0.001, (float) ($data['pack_size'] ?? 1)),
            'purchase_price' => (float) ($data['purchase_price'] ?? 0),
            'sale_price' => (float) ($data['sale_price'] ?? 0),
            'tax_rate' => isset($data['tax_rate']) && $data['tax_rate'] !== '' ? (float) $data['tax_rate'] : null,
            'hs_code' => $data['hs_code'] ?? null,
            'uom_code' => $data['uom_code'] ?? null,
            'requires_prescription' => (bool) ($data['requires_prescription'] ?? false),
            'is_controlled' => (bool) ($data['is_controlled'] ?? false),
            'is_narcotic' => (bool) ($data['is_narcotic'] ?? false),
            'is_refrigerated' => (bool) ($data['is_refrigerated'] ?? false),
            'reorder_level' => (float) ($data['reorder_level'] ?? 0),
            'max_level' => isset($data['max_level']) && $data['max_level'] !== '' ? (float) $data['max_level'] : null,
            'default_dosage' => $data['default_dosage'] ?? null,
            'notes' => $data['notes'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /**
     * Replace a medicine's substitute set. Both directions are written so the
     * counter finds the alternative from whichever medicine it started at — a
     * substitute list that only works one way is the pharmacist's classic trap.
     */
    public static function syncSubstitutes(HealthMedicine $medicine, array $substituteIds): void
    {
        $companyId = (int) $medicine->company_id;

        $valid = HealthMedicine::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('id', array_map('intval', $substituteIds))
            ->where('id', '!=', $medicine->id)
            ->pluck('id')
            ->all();

        DB::transaction(function () use ($medicine, $companyId, $valid) {
            $existing = DB::table('health_medicine_substitutes')
                ->where('company_id', $companyId)
                ->where('medicine_id', $medicine->id)
                ->pluck('substitute_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $removed = array_diff($existing, $valid);
            if ($removed) {
                DB::table('health_medicine_substitutes')
                    ->where('company_id', $companyId)
                    ->where('medicine_id', $medicine->id)
                    ->whereIn('substitute_id', $removed)
                    ->delete();

                DB::table('health_medicine_substitutes')
                    ->where('company_id', $companyId)
                    ->where('substitute_id', $medicine->id)
                    ->whereIn('medicine_id', $removed)
                    ->delete();
            }

            foreach (array_diff($valid, $existing) as $substituteId) {
                foreach ([[$medicine->id, $substituteId], [$substituteId, $medicine->id]] as [$left, $right]) {
                    DB::table('health_medicine_substitutes')->insertOrIgnore([
                        'company_id' => $companyId,
                        'medicine_id' => $left,
                        'substitute_id' => $right,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }
}
