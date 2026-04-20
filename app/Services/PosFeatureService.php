<?php

namespace App\Services;

use App\Models\Company;

class PosFeatureService
{
    public const ALL_FLAGS = [
        'kot', 'tables', 'kitchen', 'kitchen_notes', 'recipes',
        'inventory', 'delivery', 'barcode', 'prescription',
        'service_jobs', 'customer_profile', 'bulk_pricing',
        'multi_branch', 'customer_loyalty',
    ];

    public const CATEGORY_DEFAULTS = [
        'restaurant' => [
            'kot' => true, 'tables' => true, 'kitchen' => true,
            'kitchen_notes' => true, 'recipes' => true, 'inventory' => true,
            'delivery' => true, 'customer_profile' => true,
        ],
        'retail' => [
            'barcode' => true, 'inventory' => true, 'customer_profile' => true,
        ],
        'pharmacy' => [
            'barcode' => true, 'inventory' => true,
            'prescription' => true, 'delivery' => true, 'customer_profile' => true,
        ],
        'salon' => [
            'tables' => true, 'service_jobs' => true,
            'customer_profile' => true, 'customer_loyalty' => true,
        ],
        'grocery' => [
            'barcode' => true, 'inventory' => true, 'delivery' => true,
        ],
        'wholesale' => [
            'inventory' => true, 'bulk_pricing' => true,
            'customer_profile' => true, 'multi_branch' => true,
        ],
    ];

    public const DEPENDENCIES = [
        'kot' => ['kitchen'],
        'recipes' => ['inventory'],
        'delivery' => ['customer_profile'],
        'prescription' => ['customer_profile'],
        'customer_loyalty' => ['customer_profile'],
    ];

    public static function forCompany(?Company $company): object
    {
        if (!$company) {
            return self::flagsToObject(self::baseDefaults());
        }

        $category = $company->business_category ?: ($company->restaurant_mode ? 'restaurant' : 'retail');
        $defaults = self::CATEGORY_DEFAULTS[$category] ?? [];
        $overrides = is_array($company->feature_flags) ? $company->feature_flags : [];
        $resolved = self::resolve(array_merge(self::baseDefaults(), $defaults, $overrides));

        return self::flagsToObject($resolved);
    }

    public static function defaultsForCategory(string $category): array
    {
        $defaults = self::CATEGORY_DEFAULTS[$category] ?? [];
        return array_merge(self::baseDefaults(), $defaults);
    }

    public static function categories(): array
    {
        return array_keys(self::CATEGORY_DEFAULTS);
    }

    protected static function baseDefaults(): array
    {
        return array_fill_keys(self::ALL_FLAGS, false);
    }

    protected static function resolve(array $flags): array
    {
        foreach (self::DEPENDENCIES as $child => $parents) {
            if (!empty($flags[$child])) {
                foreach ($parents as $parent) {
                    if (empty($flags[$parent])) {
                        $flags[$child] = false;
                    }
                }
            }
        }
        return $flags;
    }

    protected static function flagsToObject(array $flags): object
    {
        $obj = new \stdClass();
        foreach (self::ALL_FLAGS as $key) {
            $obj->{$key} = (bool) ($flags[$key] ?? false);
        }
        $obj->_all = $flags;
        return $obj;
    }
}
