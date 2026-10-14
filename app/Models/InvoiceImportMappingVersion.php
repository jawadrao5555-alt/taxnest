<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class InvoiceImportMappingVersion extends Model
{
    protected $fillable = ['company_id', 'mapping_id', 'version', 'snapshot', 'snapshot_hash', 'approved_by', 'approved_at'];

    protected $casts = ['snapshot' => 'array', 'approved_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Import format history is append-only.'));
        static::deleting(fn () => throw new LogicException('Import format history must be retained.'));
    }
}
