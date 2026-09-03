<?php

namespace App\Services;

use App\Models\AgentCoreEvent;
use App\Models\Company;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AgentCoreReconciliationService
{
    public function status(Company $company, string $deviceUid, array $eventIds = [], int $perPage = 100): LengthAwarePaginator
    {
        return AgentCoreEvent::query()
            ->where('company_id', $company->id)
            ->where('device_uid', $deviceUid)
            ->when($eventIds, fn ($query) => $query->whereIn('event_id', $eventIds))
            ->select([
                'event_id', 'event_type', 'occurred_at', 'projection_status',
                'projection_result', 'projection_error', 'projection_dependency',
                'projection_attempts', 'projected_at',
            ])
            ->orderBy('id')
            // Reconciliation is a snapshot query. Do not inherit the current
            // ingestion request's page resolver (which can carry stale input
            // when queried in-process by a worker).
            ->paginate(min(max($perPage, 1), 100), ['*'], 'page', 1);
    }
}