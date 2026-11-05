<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HealthDiagnosticOrder extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['result' => 'encrypted', 'released_at' => 'datetime', 'collected_at' => 'datetime'];

    public function amendments()
    {
        return $this->hasMany(HealthDiagnosticAmendment::class)->orderBy('revision');
    }
}
