<?php

namespace App\Services;

use App\Models\AgentCoreEvent;
use App\Models\AgentCoreScopeLease;
use App\Models\Company;
use App\Models\PosAgentDevice;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AgentCoreScopeLeaseService
{
    public const TTL_HOURS = 12;
    public const SUPPORTED_ACTIONS = [
        'order.hold', 'order.open', 'order.line.add', 'order.line.consume', 'order.claim', 'order.cancel', 'order.settle',
        'table.claim', 'table.shift', 'table.release', 'stock.set', 'stock.adjust', 'customer.upsert', 'khata.debit',
        'wasooli.record', 'refund.record', 'cash.open', 'cash.expense', 'cash.close', 'staff.start',
        'staff.end', 'print.enqueue', 'print.claim', 'print.complete', 'print.fail',
    ];

    public function issue(Company $company, User $user, int $branchId, string $deviceUid): array
    {
        $registered = PosAgentDevice::query()->where('company_id', $company->id)
            ->where('device_uid', $deviceUid)->exists();
        if (!$registered || (int) $user->company_id !== (int) $company->id || !$user->is_active) {
            throw ValidationException::withMessages(['device_uid' => ['Device/user scope is not authorized.']]);
        }
        $role = (string) ($user->pos_role ?: $user->role);
        $actions = in_array($role, ['company_admin', 'pos_admin', 'pos_manager'], true)
            ? self::SUPPORTED_ACTIONS
            : ['order.hold', 'order.open', 'order.line.add', 'order.line.consume', 'order.claim', 'order.cancel',
                'order.settle', 'table.claim', 'table.shift', 'table.release', 'customer.upsert', 'khata.debit',
                'wasooli.record', 'refund.record', 'cash.open', 'cash.expense', 'cash.close',
                'staff.start', 'staff.end', 'print.enqueue', 'print.claim', 'print.complete', 'print.fail'];
        $token = Str::random(80);
        $signingSecret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $version = hash('sha256', implode('|', [$user->updated_at?->getTimestamp(), $user->is_active, $role]));
        $lease = AgentCoreScopeLease::create([
            'company_id' => $company->id, 'device_uid' => $deviceUid, 'branch_id' => $branchId,
            'user_id' => $user->id, 'token_hash' => hash('sha256', $token),
            'nonce' => (string) Str::uuid(), 'allowed_actions' => $actions,
            'permission_version' => $version, 'expires_at' => now()->addHours(self::TTL_HOURS),
            'signing_secret' => Crypt::encryptString($signingSecret),
            'last_sequence' => 0,
        ]);
        return ['lease_id' => $lease->id, 'token' => $token, 'expires_at' => $lease->expires_at->toIso8601String(),
            'scope' => ['company_id' => (string) $company->id, 'branch_id' => (string) $branchId,
                'device_id' => $deviceUid, 'user_id' => (string) $user->id], 'allowed_actions' => $actions,
            'chain' => ['algorithm' => 'HMAC-SHA256', 'signing_secret' => $signingSecret,
                'next_sequence' => 1, 'prev_hash' => str_repeat('0', 64)]];
    }

    /**
     * Verify and advance every lease chain in the same transaction as inbox
     * persistence. Expiry does not discard offline-created chain entries;
     * revocation/permission drift marks them for explicit reconciliation.
     */
    public function acceptBatch(
        Company $company,
        string $deviceUid,
        array &$events,
        AgentCoreEventInboxService $inbox
    ): array {
        if (!Schema::hasColumn('agent_core_scope_leases', 'signing_secret')) {
            foreach ($events as &$event) {
                if (str_starts_with((string) ($event['payload']['schema'] ?? ''), 'local-core.')) {
                    $lease = $this->assertEvent($company, $deviceUid, $event);
                    $event['scope']['_lease_id'] = $lease->id;
                }
            }
            unset($event);
            return $inbox->accept($company, $deviceUid, $events);
        }

        return DB::transaction(function () use ($company, $deviceUid, &$events, $inbox): array {
            $groups = [];
            foreach ($events as $index => $event) {
                if (!str_starts_with((string) ($event['payload']['schema'] ?? ''), 'local-core.')) continue;
                $chain = (array) ($event['lease_chain'] ?? []);
                $leaseId = (int) ($chain['lease_id'] ?? $event['scope_lease_id'] ?? 0);
                if ($leaseId < 1) $this->invalidChain();
                $groups[$leaseId][] = $index;
            }

            $leaseIds = array_keys($groups);
            sort($leaseIds, SORT_NUMERIC);
            $leases = AgentCoreScopeLease::query()->where('company_id', $company->id)
                ->where('device_uid', $deviceUid)->whereIn('id', $leaseIds)
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($leases->count() !== count($leaseIds)) $this->invalidChain();

            $existing = AgentCoreEvent::query()->whereIn('lease_id', $leaseIds)
                ->where(function ($query) use ($events): void {
                    foreach ($events as $event) {
                        $chain = (array) ($event['lease_chain'] ?? []);
                        if (!empty($chain['lease_id']) && !empty($chain['sequence'])) {
                            $query->orWhere(fn ($q) => $q->where('lease_id', (int) $chain['lease_id'])
                                ->where('lease_sequence', (int) $chain['sequence']));
                        }
                    }
                })->get()->keyBy(fn (AgentCoreEvent $row) => $row->lease_id . ':' . $row->lease_sequence);

            $advance = [];
            foreach ($leaseIds as $leaseId) {
                /** @var AgentCoreScopeLease $lease */
                $lease = $leases->get($leaseId);
                $lastInputSequence = 0;
                foreach ($groups[$leaseId] as $index) {
                    $event = &$events[$index];
                    $chain = (array) ($event['lease_chain'] ?? []);
                $sequence = (int) ($chain['sequence'] ?? 0);
                $prev = strtolower((string) ($chain['prev_hash'] ?? ''));
                $signature = strtolower((string) ($chain['signature'] ?? ''));
                    // Preserve per-lease wire order. Sorting here would silently
                    // accept a reordered upload and weaken gap detection.
                    if ($sequence <= $lastInputSequence) $this->invalidChain();
                    $lastInputSequence = $sequence;
                if ($sequence < 1 || !preg_match('/^[a-f0-9]{64}$/', $prev)
                    || !preg_match('/^[a-f0-9]{64}$/', $signature)) $this->invalidChain();
                $scope = (array) ($event['scope'] ?? []);
                $action = (string) ($event['payload']['command_type'] ?? '');
                if ((string) $lease->branch_id !== (string) ($scope['branch_id'] ?? '')
                    || (string) $lease->user_id !== (string) ($scope['user_id'] ?? '')
                    || (string) $lease->company_id !== (string) ($scope['company_id'] ?? '')
                    || (string) $lease->device_uid !== (string) ($scope['device_id'] ?? '')
                    || (!in_array('*', $lease->allowed_actions, true)
                        && !in_array($action, $lease->allowed_actions, true))) {
                    $this->invalidChain();
                }

                $canonical = $this->canonicalEvent($event, $leaseId, $sequence, $prev);
                $expected = hash_hmac('sha256', $canonical, Crypt::decryptString($lease->signing_secret));
                if (!hash_equals($expected, $signature)) $this->invalidChain();
                $chainHash = hash('sha256', $canonical . ':' . $signature);

                $duplicate = $existing->get($leaseId . ':' . $sequence);
                if ($duplicate) {
                    if (!hash_equals((string) $duplicate->lease_chain_hash, $chainHash)
                        || $duplicate->event_id !== $event['event_id']) $this->invalidChain();
                } else {
                    $expectedSequence = ((int) $lease->last_sequence) + 1;
                    $expectedPrev = $lease->last_chain_hash ?: str_repeat('0', 64);
                    if ($sequence !== $expectedSequence || !hash_equals($expectedPrev, $prev)) $this->invalidChain();
                    $advance[$leaseId] = [$lease, $sequence, $chainHash];
                    $lease->forceFill(['last_sequence' => $sequence, 'last_chain_hash' => $chainHash]);
                }
                $event['scope']['_lease_id'] = $leaseId;
                $event['scope']['_lease_sequence'] = $sequence;
                $event['scope']['_lease_chain_hash'] = $chainHash;
                if ($this->needsReconciliation($lease)) $event['scope']['_lease_reconciliation'] = true;
                    unset($event);
                }
            }

            $result = $inbox->accept($company, $deviceUid, $events);
            foreach ($events as $event) {
                if (empty($event['scope']['_lease_id'])) continue;
                AgentCoreEvent::query()->where('company_id', $company->id)->where('device_uid', $deviceUid)
                    ->where('event_id', $event['event_id'])->update([
                        'lease_id' => $event['scope']['_lease_id'],
                        'lease_sequence' => $event['scope']['_lease_sequence'],
                        'lease_chain_hash' => $event['scope']['_lease_chain_hash'],
                    ]);
            }
            foreach ($advance as [$lease]) {
                // The in-memory lease was advanced after every new event above;
                // persist only its final state.
                $lease->save();
            }
            return $result;
        });
    }

    private function needsReconciliation(AgentCoreScopeLease $lease): bool
    {
        if ($lease->revoked_at) return true;
        $user = User::query()->whereKey($lease->user_id)->where('company_id', $lease->company_id)->first();
        if (!$user || !$user->is_active) return true;
        $version = hash('sha256', implode('|', [$user->updated_at?->getTimestamp(), $user->is_active,
            (string) ($user->pos_role ?: $user->role)]));
        return !hash_equals($lease->permission_version, $version);
    }

    private function canonicalEvent(array $event, int $leaseId, int $sequence, string $prev): string
    {
        return AgentCoreEventInboxService::canonicalJson([
            'event_id' => (string) $event['event_id'], 'event_type' => (string) $event['event_type'],
            'occurred_at' => $event['occurred_at'] ?? null, 'idempotency_key' => (string) $event['idempotency_key'],
            'scope' => (array) $event['scope'], 'payload' => (array) $event['payload'],
            'lease_id' => $leaseId, 'sequence' => $sequence, 'prev_hash' => $prev,
        ]);
    }

    private function invalidChain(): never
    {
        throw ValidationException::withMessages(['lease_chain' => ['Lease chain is invalid, replayed, or out of order.']]);
    }

    public function assertEvent(Company $company, string $deviceUid, array $event): AgentCoreScopeLease
    {
        $scope = (array) ($event['scope'] ?? []);
        $token = (string) ($event['scope_lease'] ?? $scope['lease_token'] ?? '');
        $leaseId = (int) ($event['scope_lease_id'] ?? $scope['lease_id'] ?? 0);
        $lease = AgentCoreScopeLease::query()->whereKey($leaseId)->where('company_id', $company->id)
            ->where('device_uid', $deviceUid)->first();
        $user = $lease ? User::query()->whereKey($lease->user_id)->where('company_id', $company->id)->first() : null;
        $version = $user ? hash('sha256', implode('|', [$user->updated_at?->getTimestamp(), $user->is_active, (string) ($user->pos_role ?: $user->role)])) : '';
        $action = (string) ($event['payload']['command_type'] ?? '');
        if (!$lease || !$token || !hash_equals($lease->token_hash, hash('sha256', $token))
            || $lease->revoked_at || $lease->expires_at->isPast() || !$user || !$user->is_active
            || !hash_equals($lease->permission_version, $version)
            || (string) $lease->branch_id !== (string) ($scope['branch_id'] ?? '')
            || (string) $lease->user_id !== (string) ($scope['user_id'] ?? '')
            || (!in_array('*', $lease->allowed_actions, true) && !in_array($action, $lease->allowed_actions, true))) {
            throw ValidationException::withMessages(['scope_lease' => ['Scope lease is invalid, expired, revoked, or denies this action.']]);
        }
        return $lease;
    }

    public function assertStored(Company $company, AgentCoreEvent $event): void
    {
        $scope = (array) $event->event_scope;
        $lease = AgentCoreScopeLease::query()->whereKey((int) ($scope['_lease_id'] ?? 0))
            ->where('company_id', $company->id)->where('device_uid', $event->device_uid)->first();
        $user = $lease ? User::query()->whereKey($lease->user_id)->where('company_id', $company->id)->first() : null;
        $version = $user ? hash('sha256', implode('|', [$user->updated_at?->getTimestamp(), $user->is_active,
            (string) ($user->pos_role ?: $user->role)])) : '';
        $command = (string) ($event->payload['command_type'] ?? '');
        if (!$lease || $lease->revoked_at || !$user || !$user->is_active
            || !hash_equals($lease->permission_version, $version)
            || (string) $lease->branch_id !== (string) ($scope['branch_id'] ?? '')
            || (string) $lease->user_id !== (string) ($scope['user_id'] ?? '')
            || (!in_array('*', $lease->allowed_actions, true) && !in_array($command, $lease->allowed_actions, true))) {
            throw ValidationException::withMessages(['scope_lease' => ['Stored scope lease is no longer authorized.']]);
        }
    }
}