<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MadadgarMessage extends Model
{
    protected $fillable = [
        'company_id', 'user_id', 'session_id', 'role', 'content', 'escalation_id', 'source', 'answer_provenance',
    ];

    protected $casts = ['answer_provenance' => 'array'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
