<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A bookable operation theatre.
 *
 * Its own table rather than a room with type='ot', because a theatre is booked
 * against a clock and double-booking one is the specific failure this module
 * exists to prevent. `turnaround_minutes` is part of that: the theatre is not
 * free the second the previous list finishes.
 */
class HealthOperationTheatre extends Model
{
    protected $table = 'health_operation_theatres';

    protected $fillable = [
        'company_id',
        'branch_id',
        'name',
        'code',
        'notes',
        'turnaround_minutes',
        'is_active',
    ];

    protected $casts = [
        'turnaround_minutes' => 'integer',
        'is_active' => 'boolean',
        'company_id' => 'integer',
        'branch_id' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function operations()
    {
        return $this->hasMany(HealthOperation::class, 'health_operation_theatre_id');
    }
}
