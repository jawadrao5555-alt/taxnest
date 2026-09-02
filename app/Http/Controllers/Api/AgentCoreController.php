<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AgentCoreEventBatchRequest;
use App\Services\AgentCoreEventConflictException;
use App\Services\AgentCoreEventInboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        try {
            $result = $inbox->accept(
                $request->attributes->get('agent_company'),
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