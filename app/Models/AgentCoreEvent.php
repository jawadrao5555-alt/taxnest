<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Immutable, tenant-scoped inbox for Local TaxNest Core events.
 *
 * These rows are deliberately not projected into sales, stock, or accounting.
 * They are the durable acknowledgement boundary for the first Core protocol.
 */
class AgentCoreEvent extends Model
{
    protected $table = 'agent_core_events';

    protected $fillable = [
        'company_id',
        'device_uid',
        'event_id',
        'idempotency_key',
        'event_type',
        'occurred_at',
        'payload',
        'legacy_backfilled',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'payload' => 'array',
        'legacy_backfilled' => 'boolean',
    ];
}