<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminAuditLog extends Model
{
    protected $fillable = ['admin_id', 'action', 'target_type', 'target_id', 'metadata'];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function admin()
    {
        return $this->belongsTo(AdminUser::class, 'admin_id');
    }

    public static function log(int $adminId, string $action, ?string $targetType = null, ?int $targetId = null, ?array $metadata = null): self
    {
        return self::create([
            'admin_id' => $adminId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'metadata' => $metadata,
        ]);
    }
}
