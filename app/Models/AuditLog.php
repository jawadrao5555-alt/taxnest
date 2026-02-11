<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'old_values',
        'new_values',
        'ip_address',
        'sha256_hash',
        'created_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($log) {
            if (!$log->created_at) {
                $log->created_at = now();
            }
            $log->sha256_hash = hash('sha256', implode('|', [
                $log->action,
                $log->entity_type,
                $log->entity_id,
                json_encode($log->new_values),
                $log->created_at->toIso8601String(),
            ]));
        });

        static::updating(function () {
            throw new \RuntimeException('Audit logs are immutable and cannot be updated.');
        });

        static::deleting(function () {
            throw new \RuntimeException('Audit logs are immutable and cannot be deleted.');
        });
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
