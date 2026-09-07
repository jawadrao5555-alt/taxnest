<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LiveOpsDiagnosticReport extends Model
{
    protected $table = 'live_ops_diagnostic_reports';

    protected $fillable = [
        'report_id',
        'operation',
        'company_id',
        'date_from',
        'date_to',
        'requester',
        'artifact_digest',
        'result_count',
        'summary',
        'payload',
    ];

    protected $casts = [
        'date_from' => 'date',
        'date_to' => 'date',
        'summary' => 'array',
        'payload' => 'array',
    ];
}
