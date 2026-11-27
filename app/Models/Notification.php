<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    public const AGENT_OFFLINE_TYPE_PREFIX = 'agent_offline_';

    protected $fillable = [
        'company_id',
        'user_id',
        'type',
        'title',
        'message',
        'read',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'read' => 'boolean',
    ];

    /** @return Collection<int, self> */
    public static function forPosDashboard(Company $company): Collection
    {
        $since = now()->subDays(30);
        $agentPrefix = [strlen(self::AGENT_OFFLINE_TYPE_PREFIX), self::AGENT_OFFLINE_TYPE_PREFIX];
        $latestAgentAlert = null;

        if ($company->agent_last_seen && $company->agentLongOffline()) {
            // Select the latest alert for this outage even if it was dismissed.
            // Older alerts must never reappear after a dismissal or a heartbeat.
            $latestAgentAlert = $company->notifications()
                ->whereRaw('SUBSTR(type, 1, ?) = ?', $agentPrefix)
                ->where('created_at', '>', $company->agent_last_seen)
                ->where('created_at', '>=', $since)
                ->latest()
                ->orderByDesc('id')
                ->value('id');
        }

        return $company->notifications()
            ->where('read', false)
            ->where('created_at', '>=', $since)
            ->where(function ($query) use ($agentPrefix, $latestAgentAlert) {
                $query->whereRaw('SUBSTR(type, 1, ?) <> ?', $agentPrefix);
                if ($latestAgentAlert !== null) {
                    $query->orWhere('id', $latestAgentAlert);
                }
            })
            ->latest()
            ->orderByDesc('id')
            ->limit(5)
            ->get();
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
