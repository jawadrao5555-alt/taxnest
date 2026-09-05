<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A person the organisation treats.
 *
 * Registered ONCE and found again on every later visit — that is the whole
 * point of the medical record number. The row is never deleted (records are
 * filed under it); `is_active` archives a file instead.
 *
 * `branch_id` records where the file was opened and is nullable for
 * organisation-wide registration. It does not fence the patient off: the scope
 * service always includes NULL-branch rows, and a patient registered at one
 * branch is still the same human being at the next.
 */
class HealthPatient extends Model
{
    public const GENDERS = ['male', 'female', 'other'];

    public const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    public const MARITAL_STATUSES = ['single', 'married', 'widowed', 'divorced'];

    protected $fillable = [
        'company_id',
        'branch_id',
        'mrn',
        'name',
        'guardian_name',
        'gender',
        'date_of_birth',
        'age_years',
        'age_months',
        'phone',
        'phone_digits',
        'alt_phone',
        'email',
        'cnic',
        'address',
        'city',
        'blood_group',
        'marital_status',
        'allergies',
        'chronic_conditions',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relation',
        'consent_treatment',
        'consent_share_reports',
        'consent_contact',
        'consent_recorded_at',
        'consent_recorded_by',
        'is_confidential',
        'notes',
        'registered_by',
        'is_active',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'consent_recorded_at' => 'datetime',
        'consent_treatment' => 'boolean',
        'consent_share_reports' => 'boolean',
        'consent_contact' => 'boolean',
        'is_confidential' => 'boolean',
        'is_active' => 'boolean',
        // Live PDO hands back non-cast integer columns as STRINGS; anything the
        // views compare or hand to JS must be a real int on both boxes.
        'age_years' => 'integer',
        'age_months' => 'integer',
        'branch_id' => 'integer',
        'company_id' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function appointments()
    {
        return $this->hasMany(HealthAppointment::class, 'health_patient_id');
    }

    public function visits()
    {
        return $this->hasMany(HealthVisit::class, 'health_patient_id');
    }

    public function prescriptions()
    {
        return $this->hasMany(HealthPrescription::class, 'health_patient_id');
    }

    /**
     * Digits-only phone, the form duplicate detection matches on.
     *
     * Leading zero and a +92 country prefix are the same number to a human, so
     * they have to be the same number to the search too — otherwise reception
     * registers the same patient twice and the history splits in half.
     */
    public static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return null;
        }

        // 0092xxxxxxxxxx / 92xxxxxxxxxx / 03xxxxxxxxx all reduce to 3xxxxxxxxx.
        $digits = preg_replace('/^0092/', '', $digits);
        $digits = preg_replace('/^92(?=3\d{9}$)/', '', $digits);
        $digits = preg_replace('/^0(?=\d)/', '', $digits);

        return substr($digits, 0, 20) ?: null;
    }

    /** CNIC without dashes, so 12345-1234567-1 and 1234512345671 match. */
    public static function normalizeCnic(?string $cnic): ?string
    {
        if ($cnic === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $cnic) ?? '';

        return $digits === '' ? null : substr($digits, 0, 20);
    }

    /**
     * Age shown on screen. Prefers the real birthday and falls back to the
     * years/months reception typed, because most walk-ins never know the date.
     */
    public function getAgeLabelAttribute(): ?string
    {
        if ($this->date_of_birth) {
            $years = Carbon::parse($this->date_of_birth)->age;
            if ($years > 0) {
                return $years . 'y';
            }
            $months = Carbon::parse($this->date_of_birth)->diffInMonths(now());

            return max(0, (int) $months) . 'm';
        }

        $parts = [];
        if ($this->age_years) {
            $parts[] = $this->age_years . 'y';
        }
        if ($this->age_months) {
            $parts[] = $this->age_months . 'm';
        }

        return $parts ? implode(' ', $parts) : null;
    }

    public static function genderLabelKey(?string $gender): string
    {
        return 'health.gender_' . (in_array($gender, self::GENDERS, true) ? $gender : 'other');
    }
}
