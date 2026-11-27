<?php

namespace App\Models;

use App\Services\CompanyProductId;
use App\Services\WebsiteClientPaidSubscription;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WebsiteClient extends Model
{
    public const PRODUCTS = [
        'pos' => 'PRA POS',
        'fbrpos' => 'FBR POS (IMS)',
        'di' => 'Digital Invoice',
        'standalone' => 'Standalone POS',
        'erp' => 'TaxNest ERP',
    ];

    private const SOURCE_PRODUCT = <<<'SQL'
        CASE
            WHEN companies.product_type = 'pos' AND companies.pos_integration_mode = 'standalone' THEN 'standalone'
            WHEN companies.product_type IN ('erps', 'health') THEN 'erp'
            ELSE COALESCE(NULLIF(companies.product_type, ''), 'di')
        END
        SQL;

    protected $fillable = ['display_name', 'product'];
    protected $hidden = ['company_id', 'logo_data'];
    protected $casts = ['sync_company_logo' => 'boolean'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public static function companySources(?string $product = null): Builder
    {
        $query = self::eligibleCompany(Company::query())
            ->when($product !== null, fn (Builder $query) => $query->where(DB::raw(self::SOURCE_PRODUCT), $product))
            ->select('companies.id', 'companies.name', 'companies.account_code')
            ->selectRaw(self::SOURCE_PRODUCT.' as website_product');

        if (class_exists(CompanyProductId::class) && Schema::hasColumn('companies', 'product_company_id')) {
            $query->addSelect('companies.product_company_id');
        }

        return $query;
    }

    public static function matchingCompanyId(string $identifier): ?int
    {
        if (class_exists(CompanyProductId::class)) {
            return CompanyProductId::resolve($identifier)?->id;
        }

        $id = filter_var($identifier, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? Company::query()->where('account_code', strtoupper($identifier))->value('id') : $id;
    }

    public function scopeApprovedForWebsite(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')
            ->whereHas('company', fn (Builder $company) => self::eligibleCompany($company)
                ->whereColumn(DB::raw(self::SOURCE_PRODUCT), 'website_clients.product'));
    }

    private static function eligibleCompany(Builder $query): Builder
    {
        return $query->where('companies.status', 'approved')
            ->where('companies.company_status', 'active')
            ->whereNull('companies.suspended_at')
            ->where(fn (Builder $company) => $company->whereNull('companies.is_internal_account')
                ->orWhere('companies.is_internal_account', false))
            ->whereIn(DB::raw(self::SOURCE_PRODUCT), array_keys(self::PRODUCTS));
    }

    public static function publicCards(string|array|null $product = null): Collection
    {
        $cards = collect();
        try {
            if (!Schema::hasTable('website_clients')) {
                return $cards;
            }

            self::query()->approvedForWebsite()
                ->select('id', 'company_id', 'display_name', 'product', 'logo_mime', 'revision', 'sync_company_logo')
                ->with('company:id,product_type,logo_path,di_branding')
                ->when($product !== null, fn ($query) => $query->whereIn('product', (array) $product))
                ->orderByDesc('published_at')->orderBy('id')
                ->chunk(24, function (Collection $entries) use ($cards, $product) {
                    $eligible = array_flip(WebsiteClientPaidSubscription::eligibleCompanyIds($entries->pluck('company_id')->all()));
                    foreach ($entries as $entry) {
                        if (isset($eligible[$entry->company_id])) {
                            $cards->push($entry);
                        }
                        if ($product === null && $cards->count() === 6) {
                            return false;
                        }
                    }

                    return true;
                });
        } catch (QueryException $exception) {
            report($exception);

            return collect();
        }

        return $cards;
    }

    public static function publicEntry(int $id, int $revision): ?self
    {
        $entry = self::query()->approvedForWebsite()->whereKey($id)->where('revision', $revision)
            ->with('company:id,product_type,logo_path,di_branding')->first();

        return $entry && WebsiteClientPaidSubscription::eligibleCompanyIds([$entry->company_id]) ? $entry : null;
    }
}
