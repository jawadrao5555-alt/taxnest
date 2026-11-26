<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
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

    protected $fillable = ['display_name', 'product'];
    protected $hidden = ['logo_data'];

    public static function publicCards(?string $product = null): Collection
    {
        try {
            if (Schema::hasTable('website_clients')) {
                return self::query()->select('id', 'display_name', 'product', 'logo_mime', 'revision')
                    ->whereNotNull('published_at')
                    ->when($product !== null, fn ($query) => $query->where('product', $product))
                    ->orderByDesc('published_at')->orderBy('id')->limit(6)->get();
            }
        } catch (QueryException $exception) {
            report($exception);
        }

        return collect();
    }
}
