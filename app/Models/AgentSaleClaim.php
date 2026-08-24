<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentSaleClaim extends Model
{
    protected $fillable = [
        'agent_id', 'identifier', 'identifier_type', 'note', 'company_id',
        'status', 'admin_note', 'reviewed_by_admin_id', 'reviewed_at',
    ];

    protected $casts = ['reviewed_at' => 'datetime'];

    public function agent()
    {
        return $this->belongsTo(Agent::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}