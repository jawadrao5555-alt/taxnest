<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Immutable, tenant-scoped inbox for Local TaxNest Core events.
 *
 * The row is both the durable receipt and the exactly-once projection state.
 * Terminal results are immutable within the company/device namespace.
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
        'event_scope',
        'projection_status',
        'projection_result',
        'projection_error',
        'projection_dependency',
        'projection_attempts',
        'projected_at',
        'lease_id',
        'lease_sequence',
        'lease_chain_hash',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'payload' => 'array',
        'legacy_backfilled' => 'boolean',
        'event_scope' => 'array',
        'projection_result' => 'array',
        'projection_attempts' => 'integer',
        'projected_at' => 'datetime',
        'lease_sequence' => 'integer',
    ];
}