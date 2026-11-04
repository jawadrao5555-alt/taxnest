<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HealthExternalReferral extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['instructions' => 'encrypted'];
}
