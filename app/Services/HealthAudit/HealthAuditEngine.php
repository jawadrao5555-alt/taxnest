<?php

namespace App\Services\HealthAudit;

use App\Models\HealthAuditFinding;
use App\Models\HealthAuditRun;
use App\Services\HealthAudit\Checks\BaseChecks;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The one press (Task 1554).
 *
 * Takes a scope, runs every rule against it, and writes down what each one
 * found together with the rows it found it in. Four properties matter more than
 * speed here, and the code is shaped around them:
 *
 *   REPRODUCIBLE   Same period, same filters, same data, same ruleset version →
 *                  byte-identical result_hash. An audit whose answer wobbles
 *                  between runs cannot be used to show that something changed.
 *
 *   HONEST         A rule that throws is COUNTED, not hidden. A run that
 *                  quietly skipped the cash checks and still shows a clean
 *                  summary is worse than no audit at all.
 *
 *   CONTINUOUS     An acknowledgement made last month survives this month's
 *                  rerun, because a finding is identified by a fingerprint over
 *                  what it is about — not by its row id.
 *
 *   BOUNDED        Every rule is capped. One broken import must not turn a
 *                  press of a button into a hundred thousand rows.
 */
class HealthAuditEngine
{
    /** Ordering rank so severity sorts hardest-first, deterministically. */
    protected const SEVERITY_RANK = ['critical' => 0, 'warning' => 1, 'info' => 2];

    /**
     * Run the whole ruleset and persist the result.
     *
     * The run row is written FIRST and marked running, so a press that dies
     * half way leaves a visible failed run rather than nothing at all.
     */
    public static function run(HealthAuditContext $ctx, array $actor = []): HealthAuditRun
    {
        $startedAt = microtime(true);

        $run = HealthAuditRun::create([
            'company_id' => $ctx->companyId,
            'user_id' => $actor['user_id'] ?? null,
            'actor_name' => $actor['actor_name'] ?? null,
            'actor_role' => $actor['actor_role'] ?? null,
            'date_from' => $ctx->from,
            'date_to' => $ctx->to,
            'preset' => $ctx->preset,
            'branch_id' => $ctx->branchId,
            'health_department_id' => $ctx->departmentId,
            'scope_branch_ids' => HealthAuditContext::sortedIds($ctx->branchBoundary),
            'scope_department_ids' => HealthAuditContext::sortedIds($ctx->departmentBoundary),
            'health_doctor_id' => $ctx->doctorId,
            'subject_user_id' => $ctx->subjectUserId,
            'ruleset_version' => HealthAuditRules::VERSION,
            'status' => 'running',
            'filters_hash' => $ctx->fingerprint(HealthAuditRules::VERSION),
            'started_at' => now(),
        ]);

        BaseChecks::flushCaches();

        $rules = HealthAuditRules::all();
        $rowsPerRule = [];
        $failed = [];
        $ran = 0;

        foreach ($rules as $ruleKey => $meta) {
            try {
                [$class, $method] = $meta['check'];
                $found = $class::$method($ctx);
                $rowsPerRule[$ruleKey] = is_array($found) ? $found : [];
                $ran++;
            } catch (\Throwable $e) {
                // One rule failing is a fact about the run, not a reason to
                // throw the other thirty-nine away. It is counted and named so
                // the summary can say the audit was incomplete.
                $failed[$ruleKey] = $e->getMessage();
                $rowsPerRule[$ruleKey] = [];

                Log::warning('[health-audit] rule failed', [
                    'company_id' => $ctx->companyId,
                    'run_id' => $run->id,
                    'rule' => $ruleKey,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $findings = self::normalise($ctx, $rules, $rowsPerRule);
        $findings = self::sortFindings($findings);
        $prior = self::priorStatuses($ctx->companyId, array_column($findings, 'fingerprint'));

        $counts = ['critical' => 0, 'warning' => 0, 'info' => 0];
        $now = now();
        $insert = [];

        foreach ($findings as $finding) {
            $counts[$finding['severity']] = ($counts[$finding['severity']] ?? 0) + 1;
            $carried = $prior[$finding['fingerprint']] ?? null;

            $insert[] = [
                'company_id' => $ctx->companyId,
                'health_audit_run_id' => $run->id,
                'rule_key' => $finding['rule_key'],
                'rule_version' => HealthAuditRules::VERSION,
                'category' => $finding['category'],
                'severity' => $finding['severity'],
                'occurred_on' => $finding['occurred_on'],
                'branch_id' => $finding['branch_id'],
                'health_department_id' => $finding['health_department_id'],
                'health_doctor_id' => $finding['health_doctor_id'],
                'subject_user_id' => $finding['subject_user_id'],
                'subject_name' => $finding['subject_name'],
                'entity_type' => $finding['entity_type'],
                'entity_id' => $finding['entity_id'],
                'entity_label' => $finding['entity_label'],
                'amount' => $finding['amount'],
                'variance' => $finding['variance'],
                'params' => self::encode($finding['params']),
                'evidence' => self::encode($finding['evidence']),
                'fingerprint' => $finding['fingerprint'],
                // A finding somebody already looked into does not come back as
                // "open" simply because the owner pressed the button again.
                'status' => $carried['status'] ?? 'open',
                'status_note' => $carried['status_note'] ?? null,
                'status_by' => $carried['status_by'] ?? null,
                'status_by_name' => $carried['status_by_name'] ?? null,
                'status_at' => $carried['status_at'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($insert, 250) as $chunk) {
            DB::table('health_audit_findings')->insert($chunk);
        }

        $run->update([
            'status' => 'ready',
            'progress' => 100,
            'rules_run' => $ran,
            'rules_failed' => count($failed),
            'findings_total' => count($findings),
            'findings_critical' => $counts['critical'],
            'findings_warning' => $counts['warning'],
            'findings_info' => $counts['info'],
            'events_scanned' => self::eventsScanned($ctx),
            'risk_score' => self::riskScore($counts),
            'result_hash' => hash('sha256', implode('|', array_column($findings, 'fingerprint'))),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'error_message' => empty($failed)
                ? null
                : mb_substr(implode('; ', array_map(
                    fn ($k, $m) => $k . ': ' . $m,
                    array_keys($failed),
                    $failed
                )), 0, 1000),
            'completed_at' => now(),
        ]);

        BaseChecks::flushCaches();

        return $run->fresh();
    }

    /**
     * Turn each check's loose array into a complete finding row.
     *
     * Every key is defaulted here rather than in thirty-nine checks, so a check
     * that forgets `variance` produces a null — not a run that dies at insert
     * time on a column it never heard of.
     */
    protected static function normalise(HealthAuditContext $ctx, array $rules, array $rowsPerRule): array
    {
        $out = [];

        foreach ($rowsPerRule as $ruleKey => $rows) {
            $meta = $rules[$ruleKey];

            foreach (array_slice($rows, 0, HealthAuditRules::PER_RULE_CAP) as $row) {
                $severity = $row['severity'] ?? $meta['severity'];
                if (!in_array($severity, HealthAuditFinding::SEVERITIES, true)) {
                    $severity = $meta['severity'];
                }

                $entityType = $row['entity_type'] ?? null;
                $entityId = isset($row['entity_id']) ? (int) $row['entity_id'] : null;

                $out[] = [
                    'rule_key' => $ruleKey,
                    'category' => $meta['category'],
                    'severity' => $severity,
                    'occurred_on' => $row['occurred_on'] ?? null,
                    'branch_id' => self::nullableInt($row['branch_id'] ?? null),
                    'health_department_id' => self::nullableInt($row['health_department_id'] ?? null),
                    'health_doctor_id' => self::nullableInt($row['health_doctor_id'] ?? null),
                    'subject_user_id' => self::nullableInt($row['subject_user_id'] ?? null),
                    'subject_name' => self::trimTo($row['subject_name'] ?? null, 150),
                    'entity_type' => self::trimTo($entityType, 64),
                    'entity_id' => $entityId ?: null,
                    'entity_label' => self::trimTo($row['entity_label'] ?? null, 190),
                    'amount' => isset($row['amount']) ? round((float) $row['amount'], 2) : null,
                    'variance' => isset($row['variance']) ? round((float) $row['variance'], 2) : null,
                    // Whatever a rule copied out of the source row passes
                    // through the ONE redaction policy before it persists:
                    // free-text reasons and notes become "N characters",
                    // identifiers are dropped, e-mails and CNICs scrubbed.
                    // The words of a reason live on the event trail, behind
                    // the reader's clinical capability — never in a finding
                    // that audit.view alone can open.
                    'params' => HealthAuditRecorder::sanitizeDeep(is_array($row['params'] ?? null) ? $row['params'] : []),
                    'evidence' => HealthAuditRecorder::sanitizeDeep(is_array($row['evidence'] ?? null) ? $row['evidence'] : []),
                    'fingerprint' => self::fingerprint($ctx, $ruleKey, $entityType, $entityId, $row),
                ];
            }
        }

        return $out;
    }

    /**
     * What makes two findings "the same finding".
     *
     * Deliberately built from WHAT the finding is about — the rule, the row it
     * points at, the day — and never from the run, the row id, or any amount.
     * A till that is still short next month is the same short till; a cashier
     * whose shortfall changed by ten rupees is not a brand-new problem, and an
     * acknowledgement should not evaporate because a number moved.
     *
     * When a finding points at no row at all (a grouped one, like a duplicate
     * set or a burst of sign-ins) its params carry the identity instead.
     */
    protected static function fingerprint(
        HealthAuditContext $ctx,
        string $ruleKey,
        ?string $entityType,
        ?int $entityId,
        array $row
    ): string {
        $identity = $entityType && $entityId
            ? $entityType . '#' . $entityId
            : 'group:' . self::encode($row['params'] ?? []);

        return hash('sha256', implode('|', [
            $ctx->companyId,
            $ruleKey,
            $identity,
            $row['occurred_on'] ?? '',
            $row['subject_user_id'] ?? '',
        ]));
    }

    /**
     * A total order over findings.
     *
     * Not cosmetic: result_hash is taken over this sequence, so two runs of an
     * unchanged period must produce the same list in the same order or the
     * owner would be told the hospital changed when only the sort did.
     */
    protected static function sortFindings(array $findings): array
    {
        usort($findings, function ($a, $b) {
            return [
                self::SEVERITY_RANK[$a['severity']] ?? 9,
                $a['category'],
                $a['rule_key'],
                $a['occurred_on'] ?? '',
                $a['entity_type'] ?? '',
                $a['entity_id'] ?? 0,
                $a['fingerprint'],
            ] <=> [
                self::SEVERITY_RANK[$b['severity']] ?? 9,
                $b['category'],
                $b['rule_key'],
                $b['occurred_on'] ?? '',
                $b['entity_type'] ?? '',
                $b['entity_id'] ?? 0,
                $b['fingerprint'],
            ];
        });

        return $findings;
    }

    /**
     * The status an earlier run left on each of these findings.
     *
     * Takes the LATEST decision per fingerprint. Only decisions that were
     * actually made are carried — a plain "open" is not a decision, and
     * carrying it would let a stale row overwrite nothing while still costing a
     * lookup.
     */
    protected static function priorStatuses(int $companyId, array $fingerprints): array
    {
        $fingerprints = array_values(array_unique(array_filter($fingerprints)));

        if (empty($fingerprints)) {
            return [];
        }

        $out = [];

        foreach (array_chunk($fingerprints, 500) as $chunk) {
            $rows = DB::table('health_audit_findings')
                ->where('company_id', $companyId)
                ->whereIn('fingerprint', $chunk)
                ->where('status', '!=', 'open')
                ->orderBy('status_at')
                ->orderBy('id')
                ->get(['fingerprint', 'status', 'status_note', 'status_by', 'status_by_name', 'status_at']);

            foreach ($rows as $row) {
                $out[$row->fingerprint] = [
                    'status' => $row->status,
                    'status_note' => $row->status_note,
                    'status_by' => $row->status_by,
                    'status_by_name' => $row->status_by_name,
                    'status_at' => $row->status_at,
                ];
            }
        }

        return $out;
    }

    /**
     * How much of the trail this run stood on.
     *
     * Shown beside the findings because "nothing found" means two very
     * different things depending on whether there were four hundred thousand
     * recorded acts in the period or none.
     */
    protected static function eventsScanned(HealthAuditContext $ctx): int
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('health_audit_events')) {
            return 0;
        }

        $query = DB::table('health_audit_events')
            ->where('company_id', $ctx->companyId)
            ->whereBetween('occurred_at', [$ctx->fromStart(), $ctx->toEnd()]);

        $ctx->applyBranch($query);

        return (int) $query->count();
    }

    /**
     * One number, 0–100, where 100 is a clean period.
     *
     * Weighted by severity and capped: a hundred informational rows must not
     * add up to the same alarm as one till that cannot be reconciled, and a
     * thousand of anything must not drive the score to zero and stay there
     * every month regardless of what the hospital does about it.
     */
    protected static function riskScore(array $counts): int
    {
        $weights = HealthAuditRules::WEIGHTS;

        $penalty = 0.0;
        foreach ($counts as $severity => $count) {
            $weight = $weights[$severity] ?? 0;
            // Diminishing: the tenth identical warning tells the owner much
            // less than the first one did.
            $penalty += $weight * sqrt(max(0, (int) $count));
        }

        return (int) max(0, min(100, round(100 - $penalty)));
    }

    /** UTF-8-safe JSON. A malformed byte must not silently empty the evidence. */
    protected static function encode($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        return $json === false ? '[]' : $json;
    }

    protected static function nullableInt($value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }

    protected static function trimTo(?string $value, int $length): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $length);
    }

    /**
     * The date windows behind the one-click presets.
     *
     * Kept here rather than in the controller so the workspace, a scheduled run
     * and a rerun all mean exactly the same thing by "last month".
     */
    public static function presetRange(string $preset, ?string $today = null): array
    {
        $now = $today ? Carbon::parse($today) : Carbon::today();

        return match ($preset) {
            'today' => [$now->toDateString(), $now->toDateString()],
            'yesterday' => [$now->copy()->subDay()->toDateString(), $now->copy()->subDay()->toDateString()],
            'this_week' => [$now->copy()->startOfWeek()->toDateString(), $now->toDateString()],
            'last_7' => [$now->copy()->subDays(6)->toDateString(), $now->toDateString()],
            'last_30' => [$now->copy()->subDays(29)->toDateString(), $now->toDateString()],
            'this_month' => [$now->copy()->startOfMonth()->toDateString(), $now->toDateString()],
            'last_month' => [
                $now->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                $now->copy()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            ],
            'this_quarter' => [$now->copy()->firstOfQuarter()->toDateString(), $now->toDateString()],
            'this_year' => [$now->copy()->startOfYear()->toDateString(), $now->toDateString()],
            default => [$now->copy()->subDays(29)->toDateString(), $now->toDateString()],
        };
    }

    /** Preset keys the workspace offers, in the order it offers them. */
    public static function presets(): array
    {
        return ['today', 'yesterday', 'this_week', 'last_7', 'last_30', 'this_month', 'last_month', 'this_quarter', 'this_year', 'custom'];
    }
}
