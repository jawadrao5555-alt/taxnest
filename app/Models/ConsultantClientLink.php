<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Consent-based link between a consultant USER and a client COMPANY.
 * One row per pair (unique index); status transitions:
 *   pending → active   (client admin approves, or client invite code redeemed)
 *   pending → revoked  (client rejects / consultant cancels)
 *   active  → revoked  (either side, or SaaS admin)
 *   revoked → pending  (consultant re-requests — needs fresh client consent)
 */
class ConsultantClientLink extends Model
{
    protected $fillable = [
        'consultant_user_id',
        'company_id',
        'status',
        'initiated_by',
        'approved_by_user_id',
        'approved_at',
        'revoked_by',
        'revoked_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function consultant()
    {
        return $this->belongsTo(User::class, 'consultant_user_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
