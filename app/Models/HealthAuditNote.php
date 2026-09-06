<?php

namespace App\Models;

use App\Services\HealthAudit\HealthAuditRecorder;
use Illuminate\Database\Eloquent\Model;

/**
 * An investigation note against a finding (Task 1554).
 *
 * Append-only: there is no edit and no delete. "We asked the cashier and the
 * till was short because of a torn note" stays on the record even after
 * somebody changes their mind, because a note that can be rewritten is a note
 * nobody can rely on six months later.
 */
class HealthAuditNote extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id',
        'health_audit_finding_id',
        'user_id',
        'actor_name',
        'actor_role',
        'status_from',
        'status_to',
        'body',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::updating(function () {
            throw new \RuntimeException('Audit investigation notes are append-only and cannot be edited.');
        });

        static::deleting(function () {
            throw new \RuntimeException('Audit investigation notes are append-only and cannot be deleted.');
        });
    }

    /**
     * The note's words as THIS reader may see them. A note is written by the
     * person resolving the finding and read by whoever audits it; the same
     * gate as the trail's reasons decides who gets the text and who gets its
     * length, because people put patient detail in resolution notes too.
     */
    public function bodyFor(?User $reader): ?string
    {
        return HealthAuditRecorder::wordsFor($reader, (int) $this->company_id, $this->body);
    }

    public function finding()
    {
        return $this->belongsTo(HealthAuditFinding::class, 'health_audit_finding_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
