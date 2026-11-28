<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UpcomingProductInterest extends Model
{
    public const PURPOSES = [
        'interest' => 'Register Your Interest',
        'early_access' => 'Request Early Access',
        'notification' => 'Notify Me When Available',
    ];

    public const CONSENT_VERSION = 'upcoming-v1';

    protected $fillable = [
        'upcoming_product_id', 'email_key', 'email', 'purpose', 'token_hash',
        'consent_version', 'consented_at', 'confirmation_sent_at', 'confirmed_at', 'withdrawn_at',
    ];

    protected $casts = [
        'email' => 'encrypted',
        'consented_at' => 'datetime',
        'confirmation_sent_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'withdrawn_at' => 'datetime',
    ];

    protected $hidden = ['email_key', 'email', 'token_hash'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(UpcomingProduct::class, 'upcoming_product_id');
    }
}
