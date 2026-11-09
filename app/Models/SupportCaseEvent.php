<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportCaseEvent extends Model
{
    protected $fillable = [
        'support_case_id',
        'actor_admin_id',
        'event_type',
        'from_status',
        'to_status',
        'resolution_build',
        'note',
        'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    public function supportCase()
    {
        return $this->belongsTo(SupportCase::class);
    }

    public function actor()
    {
        return $this->belongsTo(AdminUser::class, 'actor_admin_id');
    }
}
