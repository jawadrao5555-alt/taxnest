<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HealthDiagnosticAmendment extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['result' => 'encrypted', 'reason' => 'encrypted', 'signed_at' => 'datetime'];
}
