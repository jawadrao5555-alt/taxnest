<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosService extends Model
{
    protected $table = 'pos_services';

    protected $fillable = [
        'company_id', 'name', 'description', 'price', 'tax_rate', 'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
