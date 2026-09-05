<?php

namespace App\Support;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Identity is scoped to a PRODUCT, never to the whole SaaS (owner ruling,
 * 5 Sep 2026).
 *
 * The three product lines are deliberately isolated: a business owner may run
 * a hotel on PRA POS, a Tier-1 retail outlet on FBR POS and a distribution
 * house on Digital Invoice. In real life all three sit under ONE NTN, and the
 * same person signs up for all three with the same email and phone. Our old
 * rule — "an email/phone/username/NTN may exist once in the entire system" —
 * made that legitimate customer impossible to onboard: the second product
 * refused the signup, and the third had to invent a fake NTN.
 *
 * So:
 *   - the system's own identity is companies.account_code (PRA-00026), NOT the
 *     regulator's NTN;
 *   - every credential (email, phone, username, NTN, CNIC) is unique only
 *     INSIDE its product line;
 *   - within one product it must stay unique, otherwise that panel's login
 *     could not decide which account the person meant.
 *
 * users.product_type mirrors the owning company's product so the database can
 * enforce the per-product uniqueness in an index. The mirror is written by the
 * User model on every save and re-synced when a company changes product, but
 * LOGIN lookups deliberately scope through the company row itself (the truth),
 * so a stale mirror can never lock anybody out.
 */
final class IdentityScope
{
    /** Product bucket for an account that belongs to no product line. */
    public const NONE = '';

    private static ?bool $usersScoped = null;

    /** company_id => product bucket, for the life of one request. */
    private static array $productCache = [];

    /** PROD schema drift guard — the mirror column may not exist yet. */
    public static function usersScoped(): bool
    {
        if (self::$usersScoped === null) {
            try {
                self::$usersScoped = Schema::hasColumn('users', 'product_type');
            } catch (\Throwable $e) {
                self::$usersScoped = false;
            }
        }

        return self::$usersScoped;
    }

    /** Canonical product bucket ('' when the value belongs to no product). */
    public static function normalize(?string $productType): string
    {
        return ProductCatalog::normalize($productType) ?? self::NONE;
    }

    /** @param array<int,string|null>|string|null $products */
    public static function buckets(array|string|null $products): array
    {
        $out = [];
        foreach ((array) $products as $product) {
            $out[] = self::normalize($product);
        }

        return array_values(array_unique($out ?: [self::NONE]));
    }

    /** The bucket a company's people belong to. */
    public static function ofCompany(?Company $company): string
    {
        return self::normalize($company?->product_type);
    }

    public static function ofCompanyId($companyId): string
    {
        if (empty($companyId)) {
            return self::NONE;
        }

        // Every user save asks this question; one lookup per company per
        // request is plenty (a company's product does not change mid-request
        // except through the admin action, which clears this cache).
        if (array_key_exists((int) $companyId, self::$productCache)) {
            return self::$productCache[(int) $companyId];
        }

        $product = Company::withTrashed()->whereKey($companyId)->value('product_type');

        return self::$productCache[(int) $companyId] = self::normalize($product);
    }

    public static function forgetCompany($companyId): void
    {
        unset(self::$productCache[(int) $companyId]);
    }

    /** The bucket a user belongs to, resolved from the company (the truth). */
    public static function ofUser(?User $user): string
    {
        if (!$user) {
            return self::NONE;
        }

        return self::ofCompanyId($user->company_id);
    }

    // ---------------------------------------------------------------- rules

    /** users.email unique INSIDE this product line. */
    public static function uniqueEmail(?string $product, ?int $exceptUserId = null): Unique
    {
        return self::userUnique('email', $product, $exceptUserId);
    }

    /** users.phone unique INSIDE this product line. */
    public static function uniquePhone(?string $product, ?int $exceptUserId = null): Unique
    {
        return self::userUnique('phone', $product, $exceptUserId);
    }

    /** users.username unique INSIDE this product line. */
    public static function uniqueUsername(?string $product, ?int $exceptUserId = null): Unique
    {
        return self::userUnique('username', $product, $exceptUserId);
    }

    /**
     * companies.ntn unique INSIDE this product line. The SAME NTN on PRA POS,
     * FBR POS and Digital Invoice is normal and allowed — one taxpayer, three
     * regulator integrations.
     */
    public static function uniqueNtn(?string $product, ?int $exceptCompanyId = null): Unique
    {
        $bucket = self::normalize($product);
        $rule = Rule::unique('companies', 'ntn')->where(function ($query) use ($bucket) {
            if ($bucket === self::NONE) {
                // No product line (legacy admin-created rows): the database
                // cannot police those, so validation keeps them exclusive
                // among themselves.
                $query->where(function ($inner) {
                    $inner->whereNull('product_type')->orWhere('product_type', '');
                });

                return;
            }
            $query->whereIn('product_type', (array) self::storedProductTypes($bucket));
        });

        return $exceptCompanyId ? $rule->ignore($exceptCompanyId) : $rule;
    }

    private static function userUnique(string $column, ?string $product, ?int $exceptUserId): Unique
    {
        $rule = Rule::unique('users', $column);

        if (self::usersScoped()) {
            $bucket = self::normalize($product);
            $rule->where(fn ($query) => $query->where('product_type', $bucket));
        }

        return $exceptUserId ? $rule->ignore($exceptUserId) : $rule;
    }

    /**
     * A bucket can be stored under more than one spelling (Nest ERPS still
     * answers to the legacy 'health'). Unique rules compare against the
     * canonical spelling; callers that query companies directly should use
     * storedProductTypes() so legacy rows are not missed.
     *
     * @return string|array<int,string>
     */
    public static function storedProductTypes(string $bucket): string|array
    {
        if ($bucket === ProductCatalog::ERPS) {
            return NestErps::PRODUCT_TYPES;
        }

        return $bucket;
    }

    // -------------------------------------------------------------- lookups

    /**
     * Users of the given product line(s) — scoped through the COMPANY row, so
     * the login never depends on the denormalised mirror column.
     *
     * Pass null inside $products to also allow company-less accounts (the DI
     * panel does this for legacy rows).
     *
     * @param array<int,string|null>|string|null $products
     */
    public static function users(array|string|null $products)
    {
        $buckets = self::buckets($products);
        $allowNone = in_array(self::NONE, $buckets, true);
        $real = array_values(array_filter($buckets, fn ($b) => $b !== self::NONE));

        return User::query()->where(function ($query) use ($real, $allowNone) {
            if ($real) {
                $stored = [];
                foreach ($real as $bucket) {
                    foreach ((array) self::storedProductTypes($bucket) as $spelling) {
                        $stored[] = $spelling;
                    }
                }
                $query->whereHas('company', fn ($q) => $q->withTrashed()->whereIn('product_type', $stored));
            }
            if ($allowNone) {
                $query->orWhereNull('company_id');
            }
            if (!$real && !$allowNone) {
                $query->whereRaw('1 = 0');
            }
        });
    }

    /** @param array<int,string|null>|string|null $products */
    public static function findUserByEmail(string $email, array|string|null $products): ?User
    {
        return self::users($products)->where('email', $email)->first();
    }

    /** @param array<int,string|null>|string|null $products */
    public static function findUserByPhone(string $phone, array|string|null $products): ?User
    {
        return self::users($products)->where('phone', $phone)->first();
    }

    // --------------------------------------------------------- account code

    /** Short, stable, human-quotable identity: PRA-00026, FBR-00039, DI-00022. */
    public static function accountCodePrefix(?string $productType): string
    {
        return match (self::normalize($productType)) {
            ProductCatalog::POS    => 'PRA',
            ProductCatalog::FBRPOS => 'FBR',
            ProductCatalog::DI     => 'DI',
            ProductCatalog::ERPS   => 'ERP',
            default                => 'TN',
        };
    }

    public static function accountCodeFor(?string $productType, int $companyId): string
    {
        return self::accountCodePrefix($productType) . '-' . str_pad((string) $companyId, 5, '0', STR_PAD_LEFT);
    }
}
