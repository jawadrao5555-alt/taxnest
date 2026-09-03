<?php

namespace App\Console\Commands;

use App\Models\AgentCoreEvent;
use App\Models\Company;
use App\Services\AgentCoreProjectionService;
use Illuminate\Console\Command;

class RetryAgentCoreProjections extends Command
{
    protected $signature = 'agent-core:retry {--company=} {--limit=100}';
    protected $description = 'Retry durable Local Core projections waiting on dependencies or transient failures';

    public function handle(AgentCoreProjectionService $projections): int
    {
        $query = AgentCoreEvent::query()
            ->whereIn('projection_status', ['received', 'blocked-dependency', 'pending-domain', 'retryable'])
            ->orderBy('id')
            ->limit(min(max((int) $this->option('limit'), 1), 1000));
        if ($this->option('company')) $query->where('company_id', (int) $this->option('company'));

        $processed = 0;
        foreach ($query->get() as $stored) {
            $company = Company::query()->find($stored->company_id);
            if (!$company) continue;
            $event = [
                'event_id' => $stored->event_id,
                'event_type' => $stored->event_type,
                'occurred_at' => optional($stored->occurred_at)->toIso8601String(),
                'idempotency_key' => $stored->idempotency_key,
                'payload' => (array) $stored->payload,
                'scope' => (array) $stored->event_scope,
            ];
            $outcome = $projections->project($company, $stored->device_uid, $event);
            $this->line("{$stored->event_id}: {$outcome->status}");
            $processed++;
        }
        $this->info("Retried {$processed} Local Core event(s).");
        return self::SUCCESS;
    }
}