<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnnexureProductAudit extends Model
{
    protected $fillable = [
        'company_id', 'batch_id', 'product_id', 'user_id', 'action', 'decision',
        'annexure_row', 'idempotency_key', 'previous_values_json', 'approved_values_json',
    ];

    protected $casts = [
        'previous_values_json' => 'array',
        'approved_values_json' => 'array',
    ];

    public function batch()
    {
        return $this->belongsTo(BulkAiImageBatch::class, 'batch_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}