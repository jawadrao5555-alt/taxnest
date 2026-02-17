<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HsMappingResponse extends Model
{
    protected $fillable = [
        'hs_code_mapping_id', 'company_id', 'user_id', 'invoice_id',
        'hs_code', 'action', 'custom_values',
    ];

    protected $casts = [
        'custom_values' => 'array',
    ];

    public function mapping()
    {
        return $this->belongsTo(HsCodeMapping::class, 'hs_code_mapping_id');
    }
}
