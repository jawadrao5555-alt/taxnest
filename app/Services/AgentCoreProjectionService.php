<?php

namespace App\Services;

use App\Models\AgentCoreEvent;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AgentCoreProjectionService
{
    public function __construct(private AgentCoreProjectorRegistry $registry, private AgentCoreScopeLeaseService $leases)
    {
    }

    /**
     * Locks the inbox row and commits its state/result with the domain write.
     * Successful and terminal rows are immutable and returned on every retry.
     */
    public function project(Company $company, string $deviceUid, array $event): AgentCoreProjectionOutcome
    {
        return DB::transaction(function () use ($company, $deviceUid, $event): AgentCoreProjectionOutcome {
            $stored = AgentCoreEvent::query()
                ->where('company_id', $company->id)
                ->where('device_uid', $deviceUid)
                ->where('event_id', $event['event_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($stored->projection_status, ['accepted', 'projected', 'rejected'], true)) {
                return new AgentCoreProjectionOutcome(
                    $stored->projection_status,
                    (array) ($stored->projection_result ?: [
                        'event_id' => $stored->event_id,
                        'status' => $stored->projection_status,
                    ]),
                    $stored->projection_error,
                    $stored->projection_dependency,
                );
            }

            if (str_starts_with((string) ($stored->payload['schema'] ?? ''), 'local-core.')) {
                if (!empty($stored->event_scope['_lease_reconciliation'])) {
                    $outcome = new AgentCoreProjectionOutcome('reconciliation-required',
                        ['event_id' => $stored->event_id, 'status' => 'reconciliation-required',
                            'dependency' => 'scope-lease-review'],
                        'The signed offline event is durable but its lease was revoked or permissions changed.',
                        'scope-lease-review');
                    $stored->forceFill(['projection_status' => $outcome->status,
                        'projection_result' => $outcome->result, 'projection_error' => $outcome->error,
                        'projection_dependency' => $outcome->dependency,
                        'projection_attempts' => ((int) $stored->projection_attempts) + 1])->save();
                    return $outcome;
                }
                try {
                    $this->leases->assertStored($company, $stored);
                } catch (ValidationException $exception) {
                    $outcome = new AgentCoreProjectionOutcome('rejected',
                        ['event_id' => $stored->event_id, 'status' => 'rejected'],
                        collect($exception->errors())->flatten()->first());
                    $stored->forceFill(['projection_status' => 'rejected',
                        'projection_result' => $outcome->result, 'projection_error' => $outcome->error,
                        'projection_attempts' => ((int) $stored->projection_attempts) + 1])->save();
                    return $outcome;
                }
            }

            $missing = $this->missingDependency($company, $deviceUid, $event);
            if ($missing !== null) {
                $outcome = new AgentCoreProjectionOutcome(
                    'blocked-dependency',
                    ['event_id' => $stored->event_id, 'status' => 'blocked-dependency', 'dependency' => $missing],
                    'A prerequisite event has not completed.',
                    $missing,
                );
            } else {
                try {
                    $outcome = $this->registry->project($company, $deviceUid, $stored, $event);
                } catch (ValidationException $exception) {
                    $message = collect($exception->errors())->flatten()->first() ?: 'Projection rejected.';
                    $outcome = new AgentCoreProjectionOutcome(
                        'rejected',
                        ['event_id' => $stored->event_id, 'status' => 'rejected'],
                        $message,
                    );
                } catch (\Throwable $exception) {
                    report($exception);
                    $outcome = new AgentCoreProjectionOutcome(
                        'retryable',
                        ['event_id' => $stored->event_id, 'status' => 'retryable'],
                        'Projection failed transiently and may be retried.',
                    );
                }
            }

            $stored->forceFill([
                'projection_status' => $outcome->status,
                'projection_result' => $outcome->result,
                'projection_error' => $outcome->error,
                'projection_dependency' => $outcome->dependency,
                'projection_attempts' => ((int) $stored->projection_attempts) + 1,
                'projected_at' => $outcome->isAcknowledged() ? now() : null,
            ])->save();

            return $outcome;
        });
    }

    private function missingDependency(Company $company, string $deviceUid, array $event): ?string
    {
        foreach ((array) ($event['payload']['depends_on'] ?? []) as $dependency) {
            $complete = AgentCoreEvent::query()
                ->where('company_id', $company->id)
                ->where('device_uid', $deviceUid)
                ->where('event_id', (string) $dependency)
                ->whereIn('projection_status', ['accepted', 'projected'])
                ->exists();
            if (!$complete) {
                return (string) $dependency;
            }
        }
        return null;
    }
}