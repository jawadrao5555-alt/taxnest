<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AgentCoreEventBatchRequest;
use App\Models\PosAgentDevice;
use App\Services\AgentCoreEventConflictException;
use App\Services\AgentCoreEventInboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use App\Services\AgentCoreSaleProjector;
use Illuminate\Validation\ValidationException;

class AgentCoreController extends Controller
{
    public function capabilities(): JsonResponse
    {
        return response()->json([
            'version' => 1,
            'events' => [
                'endpoint' => '/api/agent/v2/events',
                'max_events' => AgentCoreEventBatchRequest::MAX_EVENTS,
                'max_payload_bytes' => AgentCoreEventBatchRequest::MAX_PAYLOAD_BYTES,
                'types' => AgentCoreEventInboxService::V1_EVENT_TYPES,
            ],
        ]);
    }

    public function storeEvents(AgentCoreEventBatchRequest $request, AgentCoreEventInboxService $inbox, AgentCoreSaleProjector $sales): JsonResponse
    {
        $data = $request->validated();
        $company = $request->attributes->get('agent_company');

        // The shared company key authenticates the shop, while the registry
        // proves this device UID has completed a successful legacy heartbeat.
        // Never let a caller manufacture an arbitrary device namespace to
        // bypass per-device idempotency or impersonate another counter.
        try {
            if (!Schema::hasTable('pos_agent_devices')) {
                return response()->json(['ok' => false, 'error' => 'device_registry_unavailable'], 503);
            }
            $registered = PosAgentDevice::query()
                ->where('company_id', $company->id)
                ->where('device_uid', $data['device_uid'])
                ->exists();
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'device_registry_unavailable'], 503);
        }
        if (!$registered) {
            return response()->json(['ok' => false, 'error' => 'device_not_registered'], 403);
        }
        foreach ($data['events'] as $event) {
            $scope = (array) ($event['scope'] ?? []);
            if ((string) ($scope['company_id'] ?? '') !== (string) $company->id ||
                (string) ($scope['device_id'] ?? '') !== (string) $data['device_uid']) {
                return response()->json(['ok' => false, 'error' => 'event_scope_mismatch'], 422);
            }
        }

        try {
            $result = $inbox->accept(
                $company,
                $data['device_uid'],
                $data['events']
            );
        } catch (AgentCoreEventConflictException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 409);
        }

        $acknowledged = [];
        $results = [];
        $rejected = [];
        $hasProjectionState = Schema::hasColumn('agent_core_events', 'projection_status');
        foreach ($data['events'] as $event) {
            try {
                $isLocalSale = ($event['event_type'] ?? null) === 'sale.created'
                    && (($event['payload']['schema'] ?? null) === 'pra.manual-immediate.v1');
                $mapping = $isLocalSale
                    ? $sales->project($company, $data['device_uid'], $event)
                    : ['event_id' => $event['event_id'], 'status' => 'accepted'];
                $acknowledged[] = $event['event_id'];
                $results[] = $mapping;
                if ($hasProjectionState) {
                    \App\Models\AgentCoreEvent::where('company_id', $company->id)
                        ->where('device_uid', $data['device_uid'])->where('event_id', $event['event_id'])
                        ->update(['projection_status' => $mapping['status'], 'projection_result' => json_encode($mapping),
                            'projection_error' => null]);
                }
            } catch (ValidationException $e) {
                $rejection = [
                    'event_id' => $event['event_id'],
                    'error' => 'projection_rejected',
                    'message' => collect($e->errors())->flatten()->first() ?: 'Sale projection rejected.',
                ];
                $rejected[] = $rejection;
                if ($hasProjectionState) {
                    \App\Models\AgentCoreEvent::where('company_id', $company->id)
                        ->where('device_uid', $data['device_uid'])->where('event_id', $event['event_id'])
                        ->update(['projection_status' => 'rejected', 'projection_error' => $rejection['message']]);
                }
            } catch (\Throwable $e) {
                report($e);
                $rejected[] = ['event_id' => $event['event_id'], 'error' => 'projection_failed',
                    'message' => 'Projection failed; event remains pending.'];
            }
        }
        return response()->json(array_merge(['ok' => true], $result, [
            'acknowledged_ids' => $acknowledged,
            'results' => $results,
            'rejected' => $rejected,
        ]));
    }
}