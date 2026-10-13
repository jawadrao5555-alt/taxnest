<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only, company-scoped human verification; edits create a new version. */
class InvoiceImportVerifiedMapping extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['details' => 'array', 'verified_at' => 'datetime'];
}
