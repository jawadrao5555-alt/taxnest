<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Commission agent/partner (Agency Agreement Model A — payments come straight
 * to TaxNest; the agent only introduces customers and earns Schedule A rates).
 */
class Agent extends Authenticatable
{
    protected $fillable = [
        'name',
        'cnic',
        'phone',
        'email',
        'password',
        'is_active',
        'referral_code',
        'territory',
        'rate_new',
        'rate_renewal',
        'status',
        'terminated_at',
        'reactivated_at',
        'termination_windows',
        'notes',
        'discount_percent',
    ];

    protected $casts = [
        'rate_new' => 'decimal:2',
        'rate_renewal' => 'decimal:2',
        'terminated_at' => 'datetime',
        'reactivated_at' => 'datetime',
        'termination_windows' => 'array',
        'is_active' => 'boolean',
        'discount_percent' => 'decimal:2',
        'password' => 'hashed',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected static function booted(): void
    {
        static::creating(function (Agent $agent) {
            if (Schema::hasColumn('agents', 'referral_code') && !$agent->referral_code) {
                do {
                    $code = 'AG-' . strtoupper(Str::random(8));
                } while (static::where('referral_code', $code)->exists());
                $agent->referral_code = $code;
            }
        });
    }

    public function companies()
    {
        return $this->hasMany(Company::class);
    }

    public function commissions()
    {
        return $this->hasMany(AgentCommission::class);
    }

    public function saleClaims()
    {
        return $this->hasMany(AgentSaleClaim::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Was the agent entitled to earn commission at the given moment?
     * Checks EVERY recorded termination window [from, to) — the full history
     * survives repeated terminate/reactivate cycles, so a payment cleared in
     * ANY past terminated period never earns commission, even years later.
     */
    public function wasActiveAt(\DateTimeInterface $moment): bool
    {
        foreach ($this->allTerminationWindows() as $win) {
            $from = $win['from'] ?? null;
            $to = $win['to'] ?? null;
            if (!$from) {
                continue;
            }
            $fromTs = \Illuminate\Support\Carbon::parse($from);
            if ($moment >= $fromTs && ($to === null || $moment < \Illuminate\Support\Carbon::parse($to))) {
                return false; // inside a terminated window (open-ended if to=null)
            }
        }

        // Outside every recorded window: currently-terminated agents with no
        // recorded window (legacy rows) still earn nothing "now".
        if (!$this->isActive() && $this->allTerminationWindows() === []) {
            return false;
        }

        return true;
    }

    /** Full termination-window history (JSON), with legacy-pair fallback. */
    public function allTerminationWindows(): array
    {
        $windows = $this->termination_windows;
        if (is_array($windows) && $windows !== []) {
            return $windows;
        }
        if ($this->terminated_at) {
            return [[
                'from' => $this->terminated_at->toDateTimeString(),
                'to' => ($this->isActive() && $this->reactivated_at) ? $this->reactivated_at->toDateTimeString() : null,
            ]];
        }

        return [];
    }
}
