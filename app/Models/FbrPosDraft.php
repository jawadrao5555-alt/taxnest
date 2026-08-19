<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Task 1271 — FBR POS cart draft (PRA sale-screen parity, law-neutral).
 * JSON cart snapshot + customer reference; NEVER an FbrPosTransaction row —
 * drafts must not consume FBR invoice serials and must never be visible to
 * the FBR submission/retry schedulers (see the create-table migration note).
 *
 * Edit lock: user-keyed (the FBR sale screen has no terminal picker), same
 * 5-minute auto-expiry as the PRA PosTransaction lock so an abandoned
 * browser tab never bricks a draft.
 */
class FbrPosDraft extends Model
{
    public const LOCK_MINUTES = 5;

    protected $fillable = [
        'company_id', 'user_id',
        'customer_id', 'customer_name', 'customer_phone',
        'cart_data', 'total_amount', 'items_count',
        'locked_by_user_id', 'lock_time',
    ];

    protected $casts = [
        'cart_data' => 'array',
        'lock_time' => 'datetime',
        'total_amount' => 'decimal:2',
    ];

    public function lockedBy()
    {
        return $this->belongsTo(User::class, 'locked_by_user_id');
    }

    /** Locked by ANOTHER user and the lock hasn't expired yet. */
    public function isLockedForUser(int $userId): bool
    {
        return $this->locked_by_user_id
            && (int) $this->locked_by_user_id !== $userId
            && $this->lock_time
            && $this->lock_time->gt(now()->subMinutes(self::LOCK_MINUTES));
    }

    /**
     * Rows whose lock does NOT belong to another live editor: unlocked,
     * locked by $userId, or expired. Every mutation (save/lock/unlock/delete)
     * must run its UPDATE/DELETE through this predicate so the database —
     * not a read-then-write check — decides the winner under concurrency.
     */
    public function scopeLockFreeFor($query, int $userId)
    {
        $cutoff = now()->subMinutes(self::LOCK_MINUTES);

        return $query->where(function ($q) use ($userId, $cutoff) {
            $q->whereNull('locked_by_user_id')
                ->orWhere('locked_by_user_id', $userId)
                ->orWhereNull('lock_time')
                ->orWhere('lock_time', '<', $cutoff);
        });
    }

    /**
     * Rows freshly locked by $userId. MySQL affected-rows GOTCHA: an UPDATE
     * whose SET values equal the current row reports 0 affected rows (Laravel
     * doesn't enable FOUND_ROWS), so a same-second renewal/claim by the lock
     * holder looks like a lost claim. Every 0-row conditional claim must fall
     * back to this ownership probe before declaring a conflict.
     */
    public function scopeFreshlyOwnedBy($query, int $userId)
    {
        return $query->where('locked_by_user_id', $userId)
            ->where('lock_time', '>=', now()->subMinutes(self::LOCK_MINUTES));
    }
}
