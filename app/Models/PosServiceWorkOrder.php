<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosServiceWorkOrder extends Model
{
    protected $fillable = [
        'company_id', 'branch_id', 'category', 'job_number', 'service_id',
        'customer_name', 'customer_phone', 'title', 'scheduled_at', 'due_at',
        'status', 'quantity', 'unit_price', 'total_amount', 'details', 'notes',
        'created_by', 'completed_at',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime', 'due_at' => 'datetime', 'completed_at' => 'datetime',
        'quantity' => 'decimal:3', 'unit_price' => 'decimal:2', 'total_amount' => 'decimal:2',
        'details' => 'array',
    ];

    public function events()
    {
        return $this->hasMany(PosServiceWorkOrderEvent::class, 'work_order_id')->orderBy('id');
    }

    public function service()
    {
        return $this->belongsTo(PosService::class, 'service_id');
    }
}
