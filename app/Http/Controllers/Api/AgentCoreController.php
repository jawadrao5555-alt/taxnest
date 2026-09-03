<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AgentCoreEventBatchRequest;
use App\Models\PosAgentDevice;
use App\Models\Company;
use App\Services\AgentCoreEventConflictException;
use App\Services\AgentCoreEventInboxService;
use App\Services\AgentCoreProjectionService;
use App\Services\AgentCoreReconciliationService;
use App\Services\AgentCoreScopeLeaseService;
use App\Services\AgentCoreSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
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
                'event_version' => 1,
                'status_endpoint' => '/api/agent/v2/status',
                'command_types' => AgentCoreScopeLeaseService::SUPPORTED_ACTIONS,
            ],
            'snapshot' => ['endpoint' => '/api/agent/v2/snapshot', 'method' => 'POST', 'schema' => 'local-core.snapshot.v1',
                'mode' => 'full-refresh-merge', 'hash_algorithm' => 'sha256'],
        ]);
    }

    public function snapshot(Request $request, AgentCoreSnapshotService $snapshots): JsonResponse
    {
        $data = $request->validate([
            'device_uid' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'branch_id' => ['required', 'integer', 'min:1'],
            'lease_id' => ['required', 'integer', 'min:1'],
            'lease_token' => ['required', 'string', 'max:160'],
        ]);
        try {
            $snapshot = $snapshots->build($request->attributes->get('agent_company'), (int) $data['branch_id'],
                $data['device_uid'], (int) $data['lease_id'], $data['lease_token']);
            return response()->json($snapshot)->header('Cache-Control', 'no-store');
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'error' => 'snapshot_scope_denied'], 403)
                ->header('Cache-Control', 'no-store');
        }
    }

    public function storeEvents(
        AgentCoreEventBatchRequest $request,
        AgentCoreEventInboxService $inbox,
        AgentCoreProjectionService $projections,
        AgentCoreScopeLeaseService $leases
    ): JsonResponse
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
        foreach ($data['events'] as &$event) {
            $scope = (array) ($event['scope'] ?? []);
            if ((string) ($scope['company_id'] ?? '') !== (string) $company->id ||
                (string) ($scope['device_id'] ?? '') !== (string) $data['device_uid']) {
                return response()->json(['ok' => false, 'error' => 'event_scope_mismatch'], 422);
            }
            if (str_starts_with((string) ($event['payload']['schema'] ?? ''), 'local-core.')) {
                // Cryptographic chain verification and lease-state advancement
                // happen atomically with inbox persistence below.
            }
        }
        unset($event);

        try {
            $result = $leases->acceptBatch($company, $data['device_uid'], $data['events'], $inbox);
        } catch (AgentCoreEventConflictException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 409);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'error' => 'scope_lease_invalid',
                'message' => collect($e->errors())->flatten()->first()], 403);
        }

        $acknowledged = [];
        $results = [];
        $rejected = [];
        $hasProjectionState = Schema::hasColumn('agent_core_events', 'projection_status');
        foreach ($data['events'] as $event) {
            if (!$hasProjectionState) {
                // Compatibility for installations/tests still between the
                // inbox and projection-state migrations.
                $mapping = ['event_id' => $event['event_id'], 'status' => 'accepted'];
                $acknowledged[] = $event['event_id'];
                $results[] = $mapping;
                continue;
            }

            $outcome = $projections->project($company, $data['device_uid'], $event);
            $results[] = $outcome->result;
            if ($outcome->isAcknowledged()) {
                $acknowledged[] = $event['event_id'];
            } elseif ($outcome->status === 'rejected') {
                $rejected[] = [
                    'event_id' => $event['event_id'],
                    'error' => 'projection_rejected',
                    'message' => $outcome->error,
                ];
            }
        }
        return response()->json(array_merge(['ok' => true], $result, [
            'acknowledged_ids' => $acknowledged,
            'results' => $results,
            'rejected' => $rejected,
        ]));
    }

    public function issueLease(Request $request, AgentCoreScopeLeaseService $leases): JsonResponse
    {
        $data = $request->validate(['device_uid' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/']]);
        $user = auth('pos')->user();
        $company = Company::query()->findOrFail((int) app('currentCompanyId'));
        $branchId = (int) app(\App\Services\BranchContextService::class)->stampBranchId();
        if (!$branchId) return response()->json(['ok' => false, 'error' => 'branch_unavailable'], 422);
        try {
            return response()->json(['ok' => true] + $leases->issue($company, $user, $branchId, $data['device_uid']))
                ->header('Cache-Control', 'no-store');
        } catch (ValidationException $exception) {
            return response()->json(['ok' => false, 'error' => 'scope_lease_denied'], 403);
        }
    }

    /**
     * Route-ready reconciliation query. Device and company filters are always
     * mandatory so an eventual transport route cannot accidentally broaden it.
     */
    public function status(Request $request, AgentCoreReconciliationService $reconciliation): JsonResponse
    {
        $data = $request->validate([
            'device_uid' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'event_ids' => ['sometimes', 'array', 'max:100'],
            'event_ids.*' => ['string', 'max:64'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $company = $request->attributes->get('agent_company');
        $registered = Schema::hasTable('pos_agent_devices') && PosAgentDevice::query()
            ->where('company_id', $company->id)
            ->where('device_uid', $data['device_uid'])
            ->exists();
        if (!$registered) {
            return response()->json(['ok' => false, 'error' => 'device_not_registered'], 403);
        }

        return response()->json($reconciliation->status(
            $company,
            $data['device_uid'],
            $data['event_ids'] ?? [],
            (int) ($data['per_page'] ?? 100),
        ));
    }
}
