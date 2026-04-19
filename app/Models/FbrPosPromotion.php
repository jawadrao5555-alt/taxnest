<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class FbrPosPromotion extends Model
{
    protected $fillable = [
        'company_id', 'name', 'code', 'type', 'value',
        'min_amount', 'max_discount', 'applies_to', 'product_ids',
        'valid_from', 'valid_until', 'usage_limit', 'usage_count', 'is_active',
    ];

    protected $casts = [
        'product_ids' => 'array',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'is_active' => 'boolean',
        'value' => 'decimal:2',
        'min_amount' => 'decimal:2',
        'max_discount' => 'decimal:2',
    ];

    public function isUsable(float $cartTotal = 0): array
    {
        if (!$this->is_active) return ['ok' => false, 'msg' => 'Promotion inactive'];
        $today = Carbon::today();
        if ($this->valid_from && $today->lt($this->valid_from)) return ['ok' => false, 'msg' => 'Not yet valid'];
        if ($this->valid_until && $today->gt($this->valid_until)) return ['ok' => false, 'msg' => 'Expired'];
        if ($this->min_amount > 0 && $cartTotal < $this->min_amount) {
            return ['ok' => false, 'msg' => 'Minimum amount Rs ' . number_format($this->min_amount, 0) . ' required'];
        }
        if ($this->usage_limit && $this->usage_count >= $this->usage_limit) {
            return ['ok' => false, 'msg' => 'Usage limit reached'];
        }
        return ['ok' => true, 'msg' => 'Valid'];
    }

    public function calcDiscount(float $cartSubtotal): float
    {
        $disc = 0;
        if ($this->type === 'percent') {
            $disc = $cartSubtotal * ($this->value / 100);
        } elseif ($this->type === 'fixed') {
            $disc = $this->value;
        }
        if ($this->max_discount && $disc > $this->max_discount) {
            $disc = $this->max_discount;
        }
        return min($disc, $cartSubtotal);
    }
}
