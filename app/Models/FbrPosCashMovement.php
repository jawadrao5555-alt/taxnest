<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FbrPosCashMovement extends Model
{
    protected $fillable = ['shift_id', 'user_id', 'type', 'amount', 'reason'];
    protected $casts = ['amount' => 'decimal:2'];
}
