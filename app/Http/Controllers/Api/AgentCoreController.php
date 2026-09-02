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

    public function storeEvents(AgentCoreEventBatchRequest $request, AgentCoreEventInboxService $inbox): JsonResponse
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

        try {
            $result = $inbox->accept(
                $company,
                $data['device_uid'],
                $data['events']
            );
        } catch (AgentCoreEventConflictException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 409);
        }

        // Accepted means durably present, including an already-present retry.
        // No domain command is run from this endpoint.
        return response()->json(['ok' => true] + $result);
    }
}