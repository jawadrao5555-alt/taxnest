<?php

namespace App\Services;

use App\Models\AgentCoreAggregateMapping;
use App\Models\AgentCoreEvent;
use Illuminate\Support\Facades\Schema;

/**
 * Maps opaque LocalCore aggregate identities onto server-generated keys.
 * Local identities are strings by contract and are never cast to database ids.
 */
final class AgentCoreAggregateMap
{
    public function bind(
        int $companyId,
        int $branchId,
        string $localType,
        string $localId,
        string $cloudType,
        int $cloudId,
        array $metadata = [],
    ): AgentCoreAggregateMapping {
        return AgentCoreAggregateMapping::updateOrCreate(
            [
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'local_type' => $localType,
                'local_aggregate_id' => $localId,
            ],
            ['cloud_type' => $cloudType, 'cloud_id' => $cloudId, 'metadata' => $metadata],
        );
    }

    public function resolve(
        int $companyId,
        int $branchId,
        string $localType,
        string $localId,
        ?string $cloudType = null,
    ): ?AgentCoreAggregateMapping {
        if ($localId === '' || !Schema::hasTable('agent_core_aggregate_mappings')) {
            return null;
        }
        $query = AgentCoreAggregateMapping::where('company_id', $companyId)
            ->where('branch_id', $branchId)->where('local_type', $localType)
            ->where('local_aggregate_id', $localId);
        if ($cloudType !== null) {
            $query->where('cloud_type', $cloudType);
        }
        return $query->first();
    }

    /**
     * Compatibility for orders/sales projected before the mapping table landed.
     * Projection results are authoritative; payload data is never treated as a
     * cloud primary key.
     */
    public function resolveProjectedResult(
        int $companyId,
        int $branchId,
        string $aggregate,
        string $resultKey,
    ): ?int {
        if (!Schema::hasTable('agent_core_events')) {
            return null;
        }
        $events = AgentCoreEvent::where('company_id', $companyId)
            ->whereIn('projection_status', ['projected', 'accepted'])
            ->get(['payload', 'event_scope', 'projection_result']);
        foreach ($events as $event) {
            $scope = (array) $event->event_scope;
            $payload = (array) $event->payload;
            $result = (array) $event->projection_result;
            if ((string) ($scope['branch_id'] ?? '') !== (string) $branchId
                || (string) ($payload['aggregate_id'] ?? '') !== $aggregate
                || !is_numeric($result[$resultKey] ?? null)) {
                continue;
            }
            return (int) $result[$resultKey];
        }
        return null;
    }
}