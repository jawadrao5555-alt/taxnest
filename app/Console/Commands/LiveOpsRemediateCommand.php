<?php

namespace App\Console\Commands;

use App\Services\LiveOps\LiveOpsRemediationService;
use Illuminate\Console\Command;

/**
 * Trusted-runner entrypoint for owner-approved Live Ops remediation.
 */
class LiveOpsRemediateCommand extends Command
{
    protected $signature = 'live-ops:remediate
        {--propose : Create a remediation proposal (does not execute)}
        {--approve= : Approve action_id with owner phrase}
        {--execute= : Execute approved action_id}
        {--reject= : Reject action_id}
        {--action= : Allow-listed action when proposing}
        {--company-id= : Company id when proposing}
        {--params= : JSON object of parameters}
        {--idempotency-key= : Idempotency key}
        {--proposal= : Human proposal text}
        {--requester=cloud-agent : Requester label}
        {--approved-by=owner : Approver label}
        {--owner-phrase= : Must equal OWNER_APPROVES_LIVE_OPS_FIX for approve}
        {--output= : Optional JSON output path}';

    protected $description = 'Propose / approve / execute allow-listed Live Ops remediation';

    public function handle(LiveOpsRemediationService $remediation): int
    {
        try {
            if ($this->option('propose')) {
                $params = $this->decodeParams();
                $row = $remediation->propose([
                    'action' => (string) $this->option('action'),
                    'company_id' => (int) $this->option('company-id'),
                    'parameters' => $params,
                    'idempotency_key' => $this->option('idempotency-key') ?: null,
                    'proposal' => $this->option('proposal'),
                    'requester' => $this->option('requester'),
                ]);
                return $this->emit($row->toArray());
            }

            if ($approve = $this->option('approve')) {
                $row = $remediation->approve((string) $approve, [
                    'owner_approval_phrase' => (string) ($this->option('owner-phrase') ?: ''),
                    'approved_by' => $this->option('approved-by'),
                ]);
                return $this->emit($row->toArray());
            }

            if ($reject = $this->option('reject')) {
                $row = $remediation->reject((string) $reject, [
                    'rejected_by' => $this->option('requester'),
                ]);
                return $this->emit($row->toArray());
            }

            if ($execute = $this->option('execute')) {
                $row = $remediation->execute((string) $execute, [
                    'executor' => $this->option('requester') ?: 'runner',
                ]);
                return $this->emit($row->toArray());
            }

            $this->error('Specify --propose, --approve=, --execute=, or --reject=');

            return self::FAILURE;
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('Remediation failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function decodeParams(): array
    {
        $raw = $this->option('params');
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('--params must be a JSON object');
        }

        return $decoded;
    }

    private function emit(array $data): int
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $out = $this->option('output');
        if ($out) {
            file_put_contents($out, $json."\n");
            $this->info('Wrote '.$out);
        } else {
            $this->line($json);
        }

        return self::SUCCESS;
    }
}
