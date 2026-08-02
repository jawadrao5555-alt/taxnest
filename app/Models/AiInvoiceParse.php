<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Task 142: one AI Invoice Reader extraction attempt.
 * status: success | failed. invoice_id set once the user saves the draft
 * (a consumed parse can't be reviewed again).
 */
class AiInvoiceParse extends Model
{
    protected $fillable = [
        'company_id', 'user_id', 'status', 'source_type', 'original_filename',
        'payload_json', 'error', 'model', 'total_tokens', 'invoice_id',
    ];

    protected $casts = [
        'payload_json' => 'array',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
