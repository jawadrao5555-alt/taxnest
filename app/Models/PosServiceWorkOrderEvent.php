<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosServiceWorkOrderEvent extends Model
{
    protected $fillable = ['company_id', 'branch_id', 'work_order_id', 'from_status', 'to_status', 'note', 'actor_id', 'occurred_at'];

    protected $casts = ['occurred_at' => 'datetime'];
}
