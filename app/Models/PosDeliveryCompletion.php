<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosDeliveryCompletion extends Model
{
    protected $fillable = [
        'company_id', 'transaction_id', 'rider_id', 'customer_place_id',
        'client_event_id', 'assignment_revision', 'place_type', 'place_label',
        'destination_lat', 'destination_lng', 'completed_lat', 'completed_lng',
        'accuracy_m', 'captured_at', 'distance_m', 'proximity_verified',
        'evidence_source',
    ];

    protected $casts = [
        'destination_lat' => 'float',
        'destination_lng' => 'float',
        'completed_lat' => 'float',
        'completed_lng' => 'float',
        'accuracy_m' => 'integer',
        'captured_at' => 'datetime',
        'distance_m' => 'integer',
        'proximity_verified' => 'boolean',
    ];

    public function place()
    {
        return $this->belongsTo(PosCustomerPlace::class, 'customer_place_id')->withTrashed();
    }

    public function rider()
    {
        return $this->belongsTo(PosRider::class, 'rider_id');
    }
}