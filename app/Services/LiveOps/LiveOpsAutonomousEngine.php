<?php

namespace App\Services\LiveOps;

use App\Exceptions\LiveOpsCompanyResolutionException;
use App\Models\LiveOpsAutonomousRequest;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Orchestrate owner NL request → resolve → diagnose → classify → optional low-risk
 * operational fix → owner report. Code deploys stay on the existing exact-SHA path.
 *
 * Does not SSH, does not hold production secrets, does not run arbitrary shell.
 */
class LiveOpsAutonomousEngine
{
    public const MAX_ITERATIONS = 3;

    public function __construct(
        private LiveOpsOwnerCommandParser $parser,
        private LiveOpsCompanyResolver $resolver,
        private LiveOpsDiagnosticsService $diagnostics,
        private LiveOpsOwnerReportFormatter $formatter,
        private LiveOpsChangeRiskClassifier $risk,
        private LiveOpsAuditService $audit,
        private LiveOpsRemediationService $remediation,
        private LiveOpsRedactor $redactor,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(string $ownerText, array $input = []): array
    {
        $requester = mb_substr((string) ($input['requester'] ?? 'owner-command'), 0, 120);
        $parsed = $this->parser->parse($ownerText);
        $row = $this->createRequest($parsed, $requester, $ownerText, $input);

        try {
            if (!$parsed['ok']) {
                return $this->finish($row, 'FAILED', [
                    'error' => $parsed['error'],
                    'owner_report' => [
                        'title' => 'Request rejected',
                        'overall' => 'FAILED',
                        'text' => $parsed['error'],
                        'companies' => [],
                        'unresolved' => [$parsed['error']],
                        'actions_taken' => [],
                    ],
                ]);
            }

            $this->transition($row, 'INVESTIGATING');

            $companyId = null;
            $companyName = $parsed['company_name'];
            $resolved = null;
            if ($companyName) {
                try {
                    $resolved = $this->resolver->resolve($companyName);
                    $companyId = $resolved['company_id'];
                    $companyName = $resolved['name'];
                    $this->persist($row, [
                        'company_id' => $companyId,
                        'company_name' => $companyName,
                        'metadata' => array_merge($row->metadata ?? [], ['match_type' => $resolved['match_type']]),
                    ]);
                } catch (LiveOpsCompanyResolutionException $e) {
                    $status = $e->reason === LiveOpsCompanyResolutionException::AMBIGUOUS ? 'BLOCKED' : 'FAILED';

                    return $this->finish($row, $status, [
                        'error' => $e->ownerMessage(),
                        'owner_report' => [
                            'title' => 'Company not resolved',
                            'overall' => $status,
                            'text' => $e->ownerMessage(),
                            'companies' => [],
                            'unresolved' => [$e->ownerMessage()],
                            'actions_taken' => [],
                            'matches' => $e->matches,
                        ],
                    ]);
                }
            }

            $operations = $this->operationsFor($parsed['intent'], $parsed['operation'], $parsed['focus'] ?? null);
            $reports = [];
            $primary = null;
            foreach ($operations as $operation) {
                $primary = $this->diagnostics->run($operation, [
                    'company_id' => $companyId,
                    'company_name' => $companyId ? null : $companyName,
                    'date_from' => $parsed['date_from'] ?? ($input['date_from'] ?? null),
                    'date_to' => $parsed['date_to'] ?? ($input['date_to'] ?? null),
                    'requester' => $requester,
                ]);
                $reports[] = $primary;
            }

            $this->transition($row, 'DIAGNOSED', [
                'diagnostic_report_id' => $primary['report_id'] ?? null,
                'root_cause' => $primary['data']['likely_root_cause'] ?? null,
            ]);

            $risk = $this->risk->classifyDiagnosis($primary['data'] ?? []);
            $this->persist($row, ['risk_class' => $risk['class']]);

            $actionsTaken = [];
            $status = 'DIAGNOSED';
            $wantsSolve = in_array($parsed['intent'], [
                LiveOpsOwnerCommandParser::INTENT_COMPANY_SOLVE,
                LiveOpsOwnerCommandParser::INTENT_FLEET_CHECK_AND_SOLVE,
            ], true);

            if ($wantsSolve) {
                $fix = $this->maybeOperationalFix($row, $risk, $companyId, $requester, $primary);
                $actionsTaken = $fix['actions_taken'];
                $status = $fix['status'];
                if ($fix['re_diagnose'] && $companyId) {
                    $this->transition($row, 'VERIFYING');
                    $primary = $this->diagnostics->run('COMPANY_DIAGNOSTIC', [
                        'company_id' => $companyId,
                        'date_from' => $parsed['date_from'] ?? ($input['date_from'] ?? null),
                        'date_to' => $parsed['date_to'] ?? ($input['date_to'] ?? null),
                        'requester' => $requester,
                    ]);
                    $reports[] = $primary;
                    $status = $this->postFixStatus($primary, $actionsTaken);
                }
            }

            $ownerReport = $this->formatter->format($primary, [
                'actions_taken' => $actionsTaken,
                'status' => $status,
            ]);
            if ($parsed['intent'] === LiveOpsOwnerCommandParser::INTENT_FLEET_CHECK_AND_SOLVE) {
                $ownerReport = $this->annotateFleetSolve($ownerReport, $risk);
            }

            return $this->finish($row, $status, [
                'owner_report' => $ownerReport,
                'root_cause' => $primary['data']['likely_root_cause'] ?? $row->root_cause,
                'diagnostic_report_id' => $primary['report_id'] ?? $row->diagnostic_report_id,
                'metadata' => array_merge($row->metadata ?? [], [
                    'operations' => $operations,
                    'risk' => $risk,
                    'reports' => count($reports),
                    'max_iterations' => self::MAX_ITERATIONS,
                ]),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->finish($row, 'FAILED', [
                'error' => $e->getMessage(),
                'owner_report' => [
                    'title' => 'Request failed',
                    'overall' => 'FAILED',
                    'text' => $e->getMessage(),
                    'companies' => [],
                    'unresolved' => [$e->getMessage()],
                    'actions_taken' => [],
                ],
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function operationsFor(string $intent, string $operation, ?string $focus): array
    {
        if ($intent === LiveOpsOwnerCommandParser::INTENT_DAILY_REPORT
            || $intent === LiveOpsOwnerCommandParser::INTENT_FLEET_CHECK_AND_SOLVE) {
            return ['DAILY_OPS'];
        }
        if ($intent === LiveOpsOwnerCommandParser::INTENT_COMPANY_CHECK
            || $intent === LiveOpsOwnerCommandParser::INTENT_COMPANY_SOLVE) {
            return ['COMPANY_DIAGNOSTIC'];
        }

        return [$operation];
    }

    /**
     * @param  array<string, mixed>  $risk
     * @param  array<string, mixed>  $primary
     * @return array{actions_taken:list<string>,status:string,re_diagnose:bool}
     */
    private function maybeOperationalFix(
        LiveOpsAutonomousRequest $row,
        array $risk,
        ?int $companyId,
        string $requester,
        array $primary,
    ): array {
        $iteration = (int) $row->iteration;
        if ($iteration >= self::MAX_ITERATIONS) {
            return [
                'actions_taken' => ['Stopped after '.self::MAX_ITERATIONS.' autonomous attempts.'],
                'status' => 'BLOCKED',
                're_diagnose' => false,
            ];
        }

        $cause = (string) ($primary['data']['likely_root_cause'] ?? '');
        if ($cause === '' || $cause === 'no_obvious_fault_from_available_signals') {
            return [
                'actions_taken' => ['Investigated — no proven defect to fix.'],
                'status' => 'RESOLVED',
                're_diagnose' => false,
            ];
        }

        if (!$companyId) {
            return [
                'actions_taken' => [],
                'status' => 'BLOCKED',
                're_diagnose' => false,
            ];
        }

        $low = $risk['operational_actions'] ?? [];
        $allowAuto = (bool) config('live_ops.autonomous.low_operational_from_owner_command', true);
        if ($risk['class'] !== LiveOpsChangeRiskClassifier::AUTO_DEPLOY || !$allowAuto || !$low) {
            $this->transition($row, 'BLOCKED', [
                'error' => $risk['reason'] ?? 'Automatic fix is not allowed for this class of issue.',
            ]);

            return [
                'actions_taken' => ['Diagnosis complete. Automatic deploy blocked: '.($risk['reason'] ?? 'high risk').'.'],
                'status' => 'BLOCKED',
                're_diagnose' => false,
            ];
        }

        $this->transition($row, 'FIXING', ['iteration' => $iteration + 1]);
        $taken = [];
        foreach (array_slice($low, 0, 2) as $spec) {
            $action = strtoupper(trim(explode(':', (string) $spec)[0]));
            try {
                $proposed = $this->remediation->propose([
                    'action' => $action,
                    'company_id' => $companyId,
                    'parameters' => $this->parametersFor($action, $primary),
                    'proposal' => 'Autonomous owner-command low-risk operational fix',
                    'idempotency_key' => mb_substr('auto-'.$row->request_id.'-'.$action, 0, 80),
                    'requester' => $requester,
                    'evidence' => ['report_id' => $primary['report_id'] ?? null, 'cause' => $cause],
                ]);
                $this->remediation->approve($proposed->action_id, [
                    'owner_approval_phrase' => LiveOpsRemediationService::APPROVAL_PHRASE,
                    'approved_by' => $requester,
                ]);
                $done = $this->remediation->execute($proposed->action_id, ['executor' => 'owner-command']);
                $ok = (bool) ($done->execution_result['ok'] ?? false);
                $taken[] = $action.' '.($ok ? 'applied' : 'attempted (did not confirm success)');
            } catch (\InvalidArgumentException $e) {
                $taken[] = $action.' skipped: '.$e->getMessage();
            }
        }

        return [
            'actions_taken' => $taken ?: ['No allow-listed low-risk action was executable.'],
            'status' => 'VERIFYING',
            're_diagnose' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $primary
     * @return array<string, mixed>
     */
    private function parametersFor(string $action, array $primary): array
    {
        if ($action === 'ENQUEUE_TEST_PRINT') {
            $name = is_string($primary['data']['printer']['assigned_receipt_printer'] ?? null)
                ? $primary['data']['printer']['assigned_receipt_printer']
                : 'XP-80';

            return ['printer' => $name];
        }
        if ($action === 'RETRY_ONE_PRA_INVOICE') {
            $id = $primary['data']['pra']['failed'][0]['id'] ?? null;

            return $id ? ['transaction_id' => $id] : [];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $primary
     * @param  list<string>  $actionsTaken
     */
    private function postFixStatus(array $primary, array $actionsTaken): string
    {
        $cause = (string) ($primary['data']['likely_root_cause'] ?? '');
        $printFailed = (int) ($primary['data']['printer']['failed_job_count'] ?? 0);
        if ($cause === 'no_obvious_fault_from_available_signals' && $printFailed === 0) {
            return 'RESOLVED';
        }

        return $actionsTaken ? 'DIAGNOSED' : 'BLOCKED';
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $risk
     * @return array<string, mixed>
     */
    private function annotateFleetSolve(array $report, array $risk): array
    {
        $unresolved = $report['unresolved'] ?? [];
        if ($risk['class'] === LiveOpsChangeRiskClassifier::HIGH_RISK_BLOCKED && $unresolved) {
            $report['text'] = rtrim((string) $report['text'])."\n\nAutomatic shop-by-shop code deploy was not started (risk gate).";
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function createRequest(array $parsed, string $requester, string $ownerText, array $input): LiveOpsAutonomousRequest
    {
        $payload = [
            'request_id' => (string) ($input['request_id'] ?? Str::ulid()),
            'requester' => $requester,
            'intent' => $parsed['intent'] ?? 'UNKNOWN',
            'owner_text' => mb_substr($ownerText, 0, 500),
            'company_name' => $parsed['company_name'] ?? null,
            'operation' => $parsed['operation'] ?? null,
            'status' => 'INVESTIGATING',
            'iteration' => 0,
            'diagnostic_run_id' => isset($input['diagnostic_run_id']) ? (string) $input['diagnostic_run_id'] : null,
            'metadata' => $this->redactor->redact([
                'source' => $input['source'] ?? 'runner',
            ]),
        ];

        if (!Schema::hasTable('live_ops_autonomous_requests')) {
            $row = new LiveOpsAutonomousRequest($payload);
            $row->exists = false;

            return $row;
        }

        return LiveOpsAutonomousRequest::create($payload);
    }

    private function transition(LiveOpsAutonomousRequest $row, string $status, array $attrs = []): void
    {
        $attrs['status'] = $status;
        $this->persist($row, $attrs);
        $this->audit->record(
            eventType: 'autonomous.'.$status,
            requester: $row->requester,
            companyId: $row->company_id,
            operation: $row->operation,
            actionId: $row->request_id,
            reportId: $row->diagnostic_report_id,
            resultStatus: $status,
            metadata: ['intent' => $row->intent, 'iteration' => $row->iteration],
        );
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>
     */
    private function finish(LiveOpsAutonomousRequest $row, string $status, array $attrs): array
    {
        $this->transition($row, $status, $attrs);
        $report = $row->owner_report ?? ($attrs['owner_report'] ?? []);

        return [
            'ok' => !in_array($status, ['FAILED'], true),
            // BLOCKED is a completed fail-closed outcome, not a transport failure.
            'request_id' => $row->request_id,
            'status' => $status,
            'intent' => $row->intent,
            'company_id' => $row->company_id,
            'company_name' => $row->company_name,
            'operation' => $row->operation,
            'root_cause' => $row->root_cause,
            'risk_class' => $row->risk_class,
            'diagnostic_report_id' => $row->diagnostic_report_id,
            'owner_report' => $report,
            'error' => $row->error,
            'metadata' => $row->metadata ?? ($attrs['metadata'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function persist(LiveOpsAutonomousRequest $row, array $attrs): void
    {
        foreach ($attrs as $key => $value) {
            $row->{$key} = $value;
        }
        if ($row->exists) {
            $row->save();
        }
    }
}
