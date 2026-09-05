<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One company's membership of a business group, WITH the evidence.
 *
 * match_type says what tied this account to the group (cnic / ntn / email /
 * phone / manual / seed) and strength says how much to trust it. The admin
 * screen shows both, because a weak (email/phone) link can legitimately be
 * wrong and must be detachable in one click.
 */
class CompanyGroupMember extends Model
{
    protected $fillable = [
        'company_group_id',
        'company_id',
        'match_type',
        'match_value',
        'strength',
        'is_manual',
    ];

    protected $casts = [
        'is_manual' => 'boolean',
    ];

    public function group()
    {
        return $this->belongsTo(CompanyGroup::class, 'company_group_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class)->withTrashed();
    }

    /** Human sentence for the admin screen: "same CNIC", "same email", … */
    public function reason(): string
    {
        return match ($this->match_type) {
            'cnic'   => 'Same CNIC',
            'ntn'    => 'Same NTN',
            'email'  => 'Same email',
            'phone'  => 'Same phone',
            'manual' => 'Linked by admin',
            default  => 'First account of this group',
        };
    }
}
