<?php

namespace App\Models;

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

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public static function companySources(): Builder
    {
        return self::eligibleCompany(Company::query())
            ->select('companies.id', 'companies.name', 'companies.account_code')
            ->selectRaw(self::SOURCE_PRODUCT.' as website_product');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')
            ->where(fn (Builder $visibility) => $visibility->whereNull('company_id')
                ->orWhereHas('company', fn (Builder $company) => self::eligibleCompany($company)
                    ->whereColumn(DB::raw(self::SOURCE_PRODUCT), 'website_clients.product')));
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

    public static function publicCards(?string $product = null): Collection
    {
        try {
            if (Schema::hasTable('website_clients')) {
                return self::query()->published()->select('id', 'display_name', 'product', 'logo_mime', 'revision')
                    ->when($product !== null, fn ($query) => $query->where('product', $product))
                    ->orderByDesc('published_at')->orderBy('id')->limit(6)->get();
            }
        } catch (QueryException $exception) {
            report($exception);
        }

        return collect();
    }
}
