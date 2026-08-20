<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BulkAiImageItem extends Model
{
    protected $fillable = [
        'batch_id', 'company_id', 'source_uuid', 'position', 'original_filename',
        'mime_type', 'expected_bytes', 'uploaded_bytes', 'total_chunks',
        'content_hash', 'storage_path', 'status', 'reservation_status',
        'parse_id', 'invoice_id', 'warnings_json', 'details_json', 'error',
        'retry_count', 'processed_at', 'source_deleted_at',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
        'source_deleted_at' => 'datetime',
    ];

    public function batch()
    {
        return $this->belongsTo(BulkAiImageBatch::class, 'batch_id');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function warningsArray(): array
    {
        $value = json_decode($this->warnings_json ?? '[]', true);
        return is_array($value) ? $value : [];
    }

    public function detailsArray(): array
    {
        $value = json_decode($this->details_json ?? '[]', true);
        return is_array($value) ? $value : [];
    }
}