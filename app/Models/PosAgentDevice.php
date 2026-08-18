<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One Desktop Agent install (counter PC) of a multi-counter shop.
 * Registered automatically the first time an agent that sends a device_uid
 * heartbeats / reports printers. Same company agent_api_key for all counters
 * (Option A) — the UID is what tells them apart.
 */
class PosAgentDevice extends Model
{
    protected $table = 'pos_agent_devices';

    protected $fillable = [
        'company_id',
        'device_uid',
        'hostname',
        'name',
        'agent_version',
        'last_seen_at',
        'printers',
        'printers_reported_at',
        'receipt_printer',
    ];

    protected $casts = [
        'printers' => 'array',
        'last_seen_at' => 'datetime',
        'printers_reported_at' => 'datetime',
    ];

    /**
     * Same 2-minute freshness window as Company::agentOnline() — routing to
     * an offline counter would strand the bill, so both checks must agree.
     */
    public function isOnline(): bool
    {
        return $this->last_seen_at && $this->last_seen_at->gt(now()->subMinutes(2));
    }

    /** Friendly label for UI: admin name, else hostname, else short UID. */
    public function label(): string
    {
        return $this->name ?: ($this->hostname ?: substr($this->device_uid, 0, 12));
    }
}
