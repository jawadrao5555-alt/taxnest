<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Who stood at the table, and in which role.
 *
 * `name` is frozen at write time rather than joined from the practitioner
 * profile: a register printed two years later must still say who was actually
 * in the room, even if that person has since been renamed, retired or removed.
 */
class HealthOperationTeamMember extends Model
{
    protected $table = 'health_operation_team';

    public const ROLE_SURGEON = 'surgeon';
    public const ROLE_ASSISTANT = 'assistant';
    public const ROLE_ANAESTHETIST = 'anaesthetist';

    public const ROLES = [
        self::ROLE_SURGEON,
        self::ROLE_ASSISTANT,
        self::ROLE_ANAESTHETIST,
        'scrub_nurse',
        'circulating_nurse',
        'technician',
        'other',
    ];

    protected $fillable = [
        'company_id',
        'health_operation_id',
        'health_doctor_id',
        'user_id',
        'name',
        'role',
        'fee_amount',
        'note',
    ];

    protected $casts = [
        'fee_amount' => 'decimal:2',
        'company_id' => 'integer',
        'health_operation_id' => 'integer',
        'health_doctor_id' => 'integer',
        'user_id' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function operation()
    {
        return $this->belongsTo(HealthOperation::class, 'health_operation_id');
    }

    public static function roleLabelKey(?string $role): string
    {
        return 'health.op_role_' . (in_array($role, self::ROLES, true) ? $role : 'other');
    }
}
