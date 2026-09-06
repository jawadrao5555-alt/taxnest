<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One "customer asked, shop did not have it" moment at a pharmacy counter.
 *
 * Written by the sale screen (no-match Enter, or the cashier's "Nahi hai" on
 * the alternatives panel) and read grouped-by-term on the Missed sales report.
 * Never touches money or stock — it is a purchase-planning breadcrumb.
 */
class PharmacyMissedSale extends Model
{
    public const REASON_NO_MATCH = 'no_match';
    public const REASON_OUT_OF_STOCK = 'out_of_stock';

    public const REASONS = [self::REASON_NO_MATCH, self::REASON_OUT_OF_STOCK];

    protected $fillable = [
        'company_id',
        'branch_id',
        'user_id',
        'term',
        'term_key',
        'quantity',
        'reason',
        'product_id',
        'client_uuid',
        'handled_at',
        'handled_by',
    ];

    protected $casts = [
        'quantity' => 'float',
        'handled_at' => 'datetime',
    ];

    /**
     * The grouping key: case-, spacing- and punctuation-insensitive, so the
     * report counts "Brufen 400", "brufen  400" and "BRUFEN-400" as one ask.
     */
    public static function keyFor(string $term): string
    {
        $key = mb_strtolower(trim($term));
        $key = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $key) ?? $key;
        $key = trim(preg_replace('/\s+/u', ' ', $key) ?? $key);

        return mb_substr($key, 0, 150);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
