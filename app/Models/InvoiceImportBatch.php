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
        'ai_suggestions_json',
        'error_message',
        'started_at',
        'finished_at',
        'pruned_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'pruned_at' => 'datetime',
    ];

    /** True once the retention pruner has cleared the heavy JSON columns. */
    public function isPruned(): bool
    {
        return $this->pruned_at !== null
            || ($this->rows_json === null && $this->result_json === null && (int) $this->total_rows > 0);
    }

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

    /**
     * Task 1238: decoded AI fix suggestions, keyed by row number:
     * { "<row>": { fixes: [{field, value, old}], note } }.
     * Empty when the user never used AI help (or the column predates the
     * migration on a drifted install — attribute reads just return null).
     */
    public function aiSuggestionsArray(): array
    {
        $decoded = json_decode($this->ai_suggestions_json ?? '[]', true);
        return is_array($decoded) ? $decoded : [];
    }
}
