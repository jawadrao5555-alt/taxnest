<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class HrPayrollLine extends Model
{
    public const INPUTS = [
        'basic' => 'Basic salary',
        'allowances' => 'Allowances',
        'overtime' => 'Overtime',
        'deductions' => 'Approved deductions',
        'bonus' => 'Approved bonus',
    ];

    protected $fillable = [
        'employee_id', 'employee_user_id', 'employee_number', 'employee_name', 'basic_minor', 'allowances_minor',
        'overtime_minor', 'deductions_minor', 'bonus_minor', 'net_minor', 'prepared_by',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(HrPayrollPeriod::class, 'period_id');
    }

    public static function amount(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
