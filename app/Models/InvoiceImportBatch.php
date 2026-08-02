<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceImportBatch extends Model
{
    protected $fillable = [
        'company_id',
        'user_id',
        'original_filename',
        'source_format',
        'status',
        'total_rows',
        'valid_rows',
        'invalid_rows',
        'processed_rows',
        'created_invoices',
        'failed_rows',
        'rows_json',
        'result_json',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Decoded rows ([{row, data, valid, errors}, ...]). */
    public function rowsArray(): array
    {
        $decoded = json_decode($this->rows_json ?? '[]', true);
        return is_array($decoded) ? $decoded : [];
    }

    /** Decoded result summary ({created: [], row_errors: [], message}). */
    public function resultArray(): array
    {
        $decoded = json_decode($this->result_json ?? '[]', true);
        return is_array($decoded) ? $decoded : [];
    }
}
