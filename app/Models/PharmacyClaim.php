<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A distributor expiry / damage claim (Task 1558).
 *
 * Every medical store hands its supplier a list of expired or damaged stock and
 * expects a credit note back. The claim is the printable list plus its own
 * lifecycle, so the shop can see what is still owed to it.
 */
class PharmacyClaim extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_RAISED = 'raised';
    public const STATUS_SETTLED = 'settled';
    public const STATUS_CREDITED = 'credited';
    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_RAISED,
        self::STATUS_SETTLED,
        self::STATUS_CREDITED,
        self::STATUS_REJECTED,
    ];

    /** A closed claim can never be edited again. */
    public const CLOSED_STATUSES = [self::STATUS_SETTLED, self::STATUS_CREDITED, self::STATUS_REJECTED];

    protected $fillable = [
        'company_id',
        'branch_id',
        'supplier_id',
        'supplier_name',
        'claim_number',
        'status',
        'total_amount',
        'settled_amount',
        'raised_at',
        'settled_at',
        'settlement_reference',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'total_amount' => 'float',
        'settled_amount' => 'float',
        'raised_at' => 'date',
        'settled_at' => 'date',
    ];

    public function items()
    {
        return $this->hasMany(PharmacyClaimItem::class, 'claim_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED_STATUSES, true);
    }
}
