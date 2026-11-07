<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportCase extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_REOPENED = 'reopened';

    protected $fillable = [
        'company_id',
        'assigned_admin_id',
        'opened_by_admin_id',
        'resolved_by_admin_id',
        'reopened_by_admin_id',
        'source_box',
        'source_uid',
        'source_message_id',
        'subject',
        'status',
        'resolution_build',
        'opened_at',
        'resolved_at',
        'reopened_at',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'resolved_at' => 'datetime',
        'reopened_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class)->withTrashed();
    }

    public function assignedAdmin()
    {
        return $this->belongsTo(AdminUser::class, 'assigned_admin_id');
    }

    public function events()
    {
        return $this->hasMany(SupportCaseEvent::class)->orderBy('occurred_at')->orderBy('id');
    }
}
