<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One side of one journal (Task 1552).
 *
 * The dimensions — branch, department, doctor, patient, supplier — live HERE
 * rather than on the journal header, because a single bill legitimately serves
 * three departments and a single settlement covers one doctor's whole month.
 * Pushing them up to the header would force either a lie or five journals per
 * event, and departmental profitability would be a guess either way.
 */
class HealthJournalLine extends Model
{
    protected $fillable = [
        'company_id',
        'health_journal_id',
        'health_account_id',
        'line_no',
        'debit',
        'credit',
        'branch_id',
        'health_department_id',
        'health_doctor_id',
        'health_patient_id',
        'supplier_id',
        'source_type',
        'source_id',
        'entry_date',
        'memo',
    ];

    protected $casts = [
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
        'entry_date' => 'date',
        'line_no' => 'integer',
        'company_id' => 'integer',
        'health_journal_id' => 'integer',
        'health_account_id' => 'integer',
        'branch_id' => 'integer',
        'health_department_id' => 'integer',
        'health_doctor_id' => 'integer',
        'health_patient_id' => 'integer',
        'supplier_id' => 'integer',
        'source_id' => 'integer',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function journal()
    {
        return $this->belongsTo(HealthJournal::class, 'health_journal_id');
    }

    public function account()
    {
        return $this->belongsTo(HealthAccount::class, 'health_account_id');
    }

    public function doctor()
    {
        return $this->belongsTo(HealthDoctor::class, 'health_doctor_id');
    }

    public function department()
    {
        return $this->belongsTo(HealthDepartment::class, 'health_department_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }
}
