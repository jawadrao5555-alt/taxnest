<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsultantProfile extends Model
{
    protected $fillable = [
        'user_id',
        'referral_code',
        'status',
        'commission_rate',
        'payout_notes',
    ];

    protected $casts = [
        'commission_rate' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
