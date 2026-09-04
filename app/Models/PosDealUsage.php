<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosDealUsage extends Model
{
    protected $fillable = ['company_id', 'deal_id', 'usage_date', 'units_used'];
}