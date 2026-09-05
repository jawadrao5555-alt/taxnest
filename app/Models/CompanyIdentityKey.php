<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One normalised identity value belonging to a company (CNIC, NTN, email,
 * phone). Grouping compares these rows instead of re-normalising columns on
 * every screen, so "same owner" is a single indexed lookup and the reason for
 * a match is always known.
 */
class CompanyIdentityKey extends Model
{
    protected $fillable = ['company_id', 'key_type', 'key_value'];

    public function company()
    {
        return $this->belongsTo(Company::class)->withTrashed();
    }
}
