<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BulkAiImageBatch extends Model
{
    protected $fillable = [
        'company_id', 'user_id', 'batch_uuid', 'status', 'total_images',
        'processed_images', 'ready_images', 'needs_review_images',
        'duplicate_images', 'failed_images', 'reserved_credits',
        'finished_at', 'retention_until',
        'annexure_filename', 'annexure_storage_path', 'annexure_status',
        'annexure_headers_json', 'annexure_samples_json', 'annexure_rows_json',
        'annexure_mapping_json', 'annexure_uploaded_at',
    ];

    protected $casts = [
        'finished_at' => 'datetime',
        'retention_until' => 'datetime',
        'annexure_uploaded_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(BulkAiImageItem::class, 'batch_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function annexureRowsArray(): array
    {
        $value = json_decode($this->annexure_rows_json ?? '[]', true);
        return is_array($value) ? $value : [];
    }

    public function annexureHeadersArray(): array
    {
        $value = json_decode($this->annexure_headers_json ?? '[]', true);
        return is_array($value) ? $value : [];
    }

    public function annexureSamplesArray(): array
    {
        $value = json_decode($this->annexure_samples_json ?? '[]', true);
        return is_array($value) ? $value : [];
    }

    public function annexureMappingArray(): array
    {
        $value = json_decode($this->annexure_mapping_json ?? '[]', true);
        return is_array($value) ? $value : [];
    }
}