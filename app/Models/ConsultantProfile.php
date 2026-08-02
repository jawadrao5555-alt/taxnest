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
        'payout_method',
        'payout_account_title',
        'payout_account_number',
        'payout_bank_name',
    ];

    // Account details are encrypted at rest (columns are TEXT — encrypted
    // payloads overflow varchar(255) even for tiny plaintext).
    protected $casts = [
        'commission_rate' => 'decimal:2',
        'payout_account_title' => 'encrypted',
        'payout_account_number' => 'encrypted',
        'payout_bank_name' => 'encrypted',
    ];

    public const PAYOUT_METHODS = [
        'bank' => 'Bank Transfer',
        'jazzcash' => 'JazzCash',
        'easypaisa' => 'EasyPaisa',
    ];

    public function payoutMethodLabel(): ?string
    {
        return $this->payout_method ? (self::PAYOUT_METHODS[$this->payout_method] ?? $this->payout_method) : null;
    }

    public function hasPayoutDetails(): bool
    {
        return $this->payout_method && $this->payout_account_number;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
