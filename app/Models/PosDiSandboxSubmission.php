<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Separate evidence only: never an Invoice, stock movement or ledger entry. */
class PosDiSandboxSubmission extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'snapshot' => 'encrypted:array',
        'payload' => 'encrypted:array',
        'attempts' => 'encrypted:array',
        'attempted_at' => 'datetime',
    ];
}
