<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Task 1343: one emailed Bulk AI review summary hand-off, per recipient.
 *
 * The sender's name is denormalised at send time so the history list needs no
 * join (and keeps reading correctly after a user is renamed or removed).
 */
class BulkAiReportShare extends Model
{
    protected $fillable = [
        'batch_id', 'company_id', 'user_id', 'sent_by',
        'recipient', 'status', 'error',
    ];

    public function batch()
    {
        return $this->belongsTo(BulkAiImageBatch::class, 'batch_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
