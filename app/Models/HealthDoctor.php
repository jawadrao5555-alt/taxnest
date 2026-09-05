<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A practitioner the organisation books patients to.
 *
 * Deliberately NOT the same thing as a login. Visiting consultants sit two
 * mornings a week and never touch the system, yet their patients still have to
 * be booked, charged and prescribed for — so `user_id` is nullable and the
 * profile stands on its own. When a doctor DOES have an account, linking it is
 * what lets the panel show them their own queue.
 */
class HealthDoctor extends Model
{
    protected $fillable = [
        'company_id',
        'branch_id',
        'health_department_id',
        'user_id',
        'name',
        'specialty',
        'qualification',
        'registration_no',
        'phone',
        'email',
        'gender',
        'room',
        'consultation_fee',
        'follow_up_fee',
        'follow_up_days',
        'slot_minutes',
        'is_active',
    ];

    protected $casts = [
        'consultation_fee' => 'decimal:2',
        'follow_up_fee' => 'decimal:2',
        'follow_up_days' => 'integer',
        'slot_minutes' => 'integer',
        'is_active' => 'boolean',
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'health_department_id' => 'integer',
        'user_id' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function department()
    {
        return $this->belongsTo(HealthDepartment::class, 'health_department_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function slots()
    {
        return $this->hasMany(HealthDoctorSlot::class, 'health_doctor_id');
    }

    public function appointments()
    {
        return $this->hasMany(HealthAppointment::class, 'health_doctor_id');
    }

    public function visits()
    {
        return $this->hasMany(HealthVisit::class, 'health_doctor_id');
    }

    /**
     * The fee this doctor charges for a given visit type.
     *
     * A follow-up fee of zero is a real answer (many clinics see a return
     * patient free inside the window), so it is returned as-is rather than
     * falling back to the full consultation fee.
     */
    public function feeFor(string $visitType): float
    {
        return $visitType === 'follow_up'
            ? (float) $this->follow_up_fee
            : (float) $this->consultation_fee;
    }
}
