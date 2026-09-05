<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A business group — the SAME owner running more than one product line.
 *
 * The three products stay completely isolated (own login, own subscription,
 * own data). A group is an ADMIN-SIDE view only: it tells us that PRA-00026,
 * FBR-00039 and DI-00022 are the same customer, so support sees the whole
 * picture, sales sees which product is still missing, and repeated free
 * trials from one person stop being invisible.
 *
 * Membership is discovered automatically from shared identity (CNIC, NTN,
 * email, phone). CNIC/NTN are treated as STRONG evidence, email/phone as
 * WEAK — an accountant's email can sit on two unrelated businesses — so every
 * member row records WHY it was joined and an admin can detach it.
 */
class CompanyGroup extends Model
{
    protected $fillable = ['code', 'name', 'notes'];

    public function members()
    {
        return $this->hasMany(CompanyGroupMember::class);
    }

    public function companies()
    {
        return $this->belongsToMany(Company::class, 'company_group_members', 'company_group_id', 'company_id')
            ->withPivot(['match_type', 'match_value', 'strength', 'is_manual'])
            ->withTimestamps();
    }

    /** Display name — falls back to the first member's owner/company name. */
    public function displayName(): string
    {
        if (filled($this->name)) {
            return $this->name;
        }

        $first = $this->companies()->orderBy('companies.id')->first();

        return $first?->owner_name ?: ($first?->name ?: $this->code);
    }
}
