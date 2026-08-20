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
    ];

    protected $casts = [
        'finished_at' => 'datetime',
        'retention_until' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(BulkAiImageItem::class, 'batch_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}