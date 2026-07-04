<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegisteredCredential extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'credential_type',
        'credential_value',
        'product_type',
        'company_id',
        'created_at',
    ];
}
