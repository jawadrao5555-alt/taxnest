<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosPromotion extends Model
{
    protected $fillable = ['company_id', 'title', 'percent', 'applies_to', 'product_ids', 'priority', 'timezone', 'starts_at', 'ends_at', 'is_active'];
    protected $casts = ['percent'=>'decimal:2', 'product_ids'=>'array', 'priority'=>'integer', 'is_active'=>'boolean'];

    public function version(): string
    {
        return hash('sha256', json_encode($this->only(['id','company_id','title','percent','applies_to','product_ids','priority','timezone','starts_at','ends_at','is_active']), JSON_THROW_ON_ERROR));
    }
}
