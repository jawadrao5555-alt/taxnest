<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeatureSuggestion extends Model
{
    protected $fillable = [
        'company_id', 'user_id', 'product', 'title', 'details', 'status', 'admin_note',
    ];

    public const STATUSES = ['pending', 'planned', 'completed', 'rejected'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
