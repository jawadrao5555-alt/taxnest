<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantOrderEditAttempt extends Model
{
    protected $fillable = ['company_id', 'order_id', 'edit_uuid', 'revision', 'kot_status', 'add_payload', 'void_payload', 'kot_error'];

    protected $casts = ['revision' => 'integer', 'add_payload' => 'array', 'void_payload' => 'array'];
}