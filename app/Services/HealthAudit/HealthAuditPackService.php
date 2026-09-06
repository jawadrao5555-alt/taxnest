<?php

namespace App\Services\HealthAudit;

use App\Models\Company;
use App\Models\HealthAuditRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The signed evidence pack (Task 1554).
 *
 * A pack is what the owner hands to an accountant, a board member or an
 * external reviewer. Three properties decide whether it is worth handing over:
 *
 *   IT SAYS WHAT IT IS      organisation, scope, who generated it, when, and
 *                           under which ruleset version. A findings list with
 *                           no scope on it is unreadable a month later.
 *
 *   IT CAN BE CHECKED       every file inside is hashed, the archive itself is
 *                           hashed, and that hash is signed with the
 *                           installation's own key. Somebody who edits a CSV
 *                           and re-zips it cannot reproduce the signature.
 *
 *   IT WITHHOLDS THE NOTES  a pack carries what happened and who did it, never
 *                           the diagnosis. That is what allows it to leave the
 *                           building at all: the recorder collapsed clinical
 *                           narrative to a length marker on the way IN, so no
 *                           export path can leak it back out by accident.
 *
 * ONE RUN, ONE PACK. The pack is built from the findings already stored against
 * a run — never re-computed at export time. A pack whose contents could differ
 * from the screen the owner approved is not evidence of anything.
 */
class HealthAuditPackService
{
    /** Another process is considered to have abandoned its claim after this. */
    public const STALE_LOCK_SECONDS = 300;

    /** Rows an evidence CSV will carry before it says so and stops. */
    public const ROW_CAP = 50000;

    /** Built packs are cleaned up after this many days. */
    public const RETENTION_DAYS = HealthAuditRun::PACK_RETENTION_DAYS;

    /**
     * Atomically claim this run's pack build.
     *
     * The update is the claim: two presses of Export land on the same row and
     * only one of them changes it. Never read ownership from the affected-row
     * COUNT alone — MySQL counts changed rows — so the guard is the status
     * transition itself, which no second caller can repeat.
     */
    public static function claim(HealthAuditRun $run): bool
    {
        $stale = now()->subSeconds(self::STALE_LOCK_SECONDS);

        return HealthAuditRun::where('id', $run->id)
            ->where(function ($q) use ($stale) {
                $q->whereNull('pack_locked_at')->orWhere('pack_locked_at', '<', $stale);
            })
            ->update([
                'pack_locked_at' => now(),
                'pack_status' => 'building',
                'pack_progress' => 5,
                'pack_error' => null,
            ]) === 1;
    }

    /** Build the pack, start to finish. Returns the refreshed run. */
    public static function build(HealthAuditRun $run, ?Company $company, array $actor = []): HealthAuditRun
    {
        try {
            if (!class_exists(\ZipArchive::class)) {
                throw new \RuntimeException('The PHP zip extension is not available on this server.');
            }

            $path = self::pathFor($run);
            Storage::disk('local')->makeDirectory(dirname($path));
            $abs = Storage::disk('local')->path($path);

            if (is_file($abs)) {
                @unlink($abs);
            }

            $files = [];
            $files['README.txt'] = self::readme($run, $company, $actor);
            $files['findings.csv'] = self::findingsCsv($run);
            $files['audit-trail.csv'] = self::trailCsv($run);
            $files['summary.csv'] = self::summaryCsv($run);

            self::progress($run, 55);

            // The manifest covers the member files, so tampering with any one
            // of them is detectable from inside the archive alone.
            $manifest = self::manifest($run, $company, $actor, $files);
            $files['integrity.json'] = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $zip = new \ZipArchive();
            $opened = $zip->open($abs, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            if ($opened !== true) {
                throw new \RuntimeException('Could not open the audit pack archive (code ' . $opened . ').');
            }

            foreach ($files as $name => $content) {
                $zip->addFromString($name, $content);
            }

            if (!$zip->close()) {
                throw new \RuntimeException('Could not write the audit pack archive to disk.');
            }

            self::progress($run, 85);

            // The archive's own hash cannot live inside the archive, so it is
            // stored on the run and signed with the installation key. Re-zipped
            // contents produce a different hash and the signature stops
            // matching.
            $sha = hash_file('sha256', $abs);

            $run->update([
                'pack_status' => 'ready',
                'pack_progress' => 100,
                'pack_path' => $path,
                'pack_size' => (int) (@filesize($abs) ?: 0),
                'pack_sha256' => $sha,
                'pack_signature' => self::sign($run, $sha),
                'pack_generated_at' => now(),
                'pack_locked_at' => null,
                'pack_error' => null,
            ]);

            HealthAuditRecorder::record('export.audit_pack', [
                'company_id' => $run->company_id,
                'category' => 'export',
                'action' => 'exported',
                'entity_type' => 'health_audit_runs',
                'entity_id' => $run->id,
                'entity_label' => self::filename($run),
                'meta' => [
                    'period' => $run->date_from->toDateString() . ' → ' . $run->date_to->toDateString(),
                    'findings' => $run->findings_total,
                    'sha256' => $sha,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('[health-audit] pack build failed', [
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);

            $run->update([
                'pack_status' => 'failed',
                'pack_progress' => 0,
                'pack_locked_at' => null,
                'pack_error' => mb_substr($e->getMessage(), 0, 500),
            ]);
        }

        return $run->fresh();
    }

    /**
     * Does this pack still match its signature?
     *
     * Checked on the way OUT as well as claimed on the way in: a pack that was
     * edited on disk after it was built must not download as if it were the one
     * the owner signed.
     */
    public static function verify(HealthAuditRun $run): bool
    {
        if (!$run->pack_path || !$run->pack_sha256 || !$run->pack_signature) {
            return false;
        }

        if (!Storage::disk('local')->exists($run->pack_path)) {
            return false;
        }

        $abs = Storage::disk('local')->path($run->pack_path);

        return hash_equals($run->pack_sha256, (string) hash_file('sha256', $abs))
            && hash_equals($run->pack_signature, self::sign($run, $run->pack_sha256));
    }

    public static function filename(HealthAuditRun $run): string
    {
        return sprintf(
            'audit-pack-%s-to-%s-run%d.zip',
            $run->date_from->format('Y-m-d'),
            $run->date_to->format('Y-m-d'),
            $run->id
        );
    }

    protected static function pathFor(HealthAuditRun $run): string
    {
        return 'health-audit-packs/' . $run->company_id . '/' . self::filename($run);
    }

    /**
     * The signature.
     *
     * Over the archive hash AND the run's identity, so a valid pack from one
     * period cannot be presented as the pack for another by renaming the file.
     */
    protected static function sign(HealthAuditRun $run, string $sha): string
    {
        return hash_hmac('sha256', implode('|', [
            $run->company_id,
            $run->id,
            $run->date_from->toDateString(),
            $run->date_to->toDateString(),
            $run->ruleset_version,
            $sha,
        ]), (string) config('app.key'));
    }

    protected static function progress(HealthAuditRun $run, int $percent): void
    {
        HealthAuditRun::where('id', $run->id)->update([
            'pack_progress' => $percent,
            'pack_locked_at' => now(),
        ]);
    }

    /**
     * What the reader is holding, in plain words.
     *
     * Written for somebody who was not in the room. The last paragraph is not
     * decoration: a severity label is a request to look, and a pack that lets
     * itself be read as an accusation will eventually be used as one.
     */
    protected static function readme(HealthAuditRun $run, ?Company $company, array $actor): string
    {
        $lines = [];
        $lines[] = 'HEALTHCARE AUDIT PACK';
        $lines[] = str_repeat('=', 60);
        $lines[] = '';
        $lines[] = 'Organisation      : ' . ($company->company_name ?? $company->name ?? ('#' . $run->company_id));
        $lines[] = 'Period            : ' . $run->date_from->toDateString() . '  to  ' . $run->date_to->toDateString();
        $lines[] = 'Scope             : ' . self::scopeLine($run);
        $lines[] = 'Ruleset version   : ' . $run->ruleset_version;
        $lines[] = 'Audit run         : #' . $run->id;
        $lines[] = 'Generated by      : ' . ($actor['actor_name'] ?? $run->actor_name ?? '—')
            . ($actor['actor_role'] ?? $run->actor_role ? ' (' . ($actor['actor_role'] ?? $run->actor_role) . ')' : '');
        $lines[] = 'Generated at      : ' . now()->format('Y-m-d H:i:s');
        $lines[] = '';
        $lines[] = 'TOTALS';
        $lines[] = str_repeat('-', 60);
        $lines[] = 'Rules run         : ' . $run->rules_run . ($run->rules_failed ? ('  (' . $run->rules_failed . ' could not complete)') : '');
        $lines[] = 'Recorded acts     : ' . number_format((int) $run->events_scanned);
        $lines[] = 'Findings          : ' . number_format((int) $run->findings_total);
        $lines[] = '  critical        : ' . number_format((int) $run->findings_critical);
        $lines[] = '  warning         : ' . number_format((int) $run->findings_warning);
        $lines[] = '  informational   : ' . number_format((int) $run->findings_info);
        $lines[] = 'Risk score        : ' . $run->risk_score . ' / 100';
        $lines[] = '';
        $lines[] = 'CONTENTS';
        $lines[] = str_repeat('-', 60);
        $lines[] = 'findings.csv      Every finding, its severity, the record it points at,';
        $lines[] = '                  and the decision recorded against it.';
        $lines[] = 'audit-trail.csv   The recorded acts inside this period: who, when, what,';
        $lines[] = '                  from where, and the reason given.';
        $lines[] = 'summary.csv       Findings totalled by category and severity.';
        $lines[] = 'integrity.json    A SHA-256 for every file above, the archive signature,';
        $lines[] = '                  and the result of verifying the trail\'s hash chain.';
        $lines[] = '';
        $lines[] = 'WHAT THIS PACK DOES NOT CONTAIN';
        $lines[] = str_repeat('-', 60);
        $lines[] = 'No clinical narrative. Diagnoses, examination notes and prescriptions are';
        $lines[] = 'recorded only as "changed, N characters" in the trail, so this pack proves';
        $lines[] = 'that a clinical record was created or edited without disclosing what it';
        $lines[] = 'says. Patients are identified by their registration number, never by name.';
        $lines[] = '';
        $lines[] = 'HOW TO READ A FINDING';
        $lines[] = str_repeat('-', 60);
        $lines[] = 'A finding is a request to look at a record — not a conclusion about anybody.';
        $lines[] = 'Each one names the exact rows it was derived from so the reader checks the';
        $lines[] = 'source and decides for themselves. "Critical" means look first, not proven.';
        $lines[] = '';
        $lines[] = 'HOW TO CHECK THIS PACK HAS NOT BEEN ALTERED';
        $lines[] = str_repeat('-', 60);
        $lines[] = 'Each file inside is listed in integrity.json with its SHA-256. Recompute';
        $lines[] = 'them and compare. The archive as a whole is hashed and that hash is signed';
        $lines[] = 'with this installation\'s key, held on the server and not in this file — so';
        $lines[] = 'an edited-and-rezipped pack cannot reproduce the signature.';
        $lines[] = '';

        if ($run->rules_failed) {
            $lines[] = 'INCOMPLETE RUN';
            $lines[] = str_repeat('-', 60);
            $lines[] = $run->rules_failed . ' rule(s) could not complete for this period. The findings below';
            $lines[] = 'are therefore not the whole picture:';
            $lines[] = '  ' . ($run->error_message ?: '—');
            $lines[] = '';
        }

        return implode("\n", $lines) . "\n";
    }

    protected static function scopeLine(HealthAuditRun $run): string
    {
        $parts = [];

        if ($run->branch_id) {
            $parts[] = 'branch ' . (DB::table('branches')->where('id', $run->branch_id)->value('name') ?: ('#' . $run->branch_id));
        }
        if ($run->health_department_id) {
            $parts[] = 'department ' . (DB::table('health_departments')->where('id', $run->health_department_id)->value('name') ?: ('#' . $run->health_department_id));
        }
        if ($run->health_doctor_id) {
            $parts[] = 'doctor ' . (DB::table('health_doctors')->where('id', $run->health_doctor_id)->value('name') ?: ('#' . $run->health_doctor_id));
        }
        if ($run->subject_user_id) {
            $parts[] = 'staff member ' . (DB::table('users')->where('id', $run->subject_user_id)->value('name') ?: ('#' . $run->subject_user_id));
        }

        // The boundary the person who ran it was confined to. A pack that says
        // "whole organisation" when it was computed by a one-branch accountant
        // is a pack that lies about what it did not look at.
        if (is_array($run->scope_branch_ids)) {
            $names = DB::table('branches')->whereIn('id', $run->scope_branch_ids)->pluck('name')->all();
            $parts[] = 'confined to ' . ($names ? 'branch(es) ' . implode(' / ', $names) : 'unbranched records only');
        }
        if (is_array($run->scope_department_ids)) {
            $names = DB::table('health_departments')->whereIn('id', $run->scope_department_ids)->pluck('name')->all();
            $parts[] = 'confined to ' . ($names ? 'department(s) ' . implode(' / ', $names) : 'organisation-wide records only');
        }

        return $parts ? implode(', ', $parts) : 'whole organisation';
    }

    protected static function findingsCsv(HealthAuditRun $run): string
    {
        $rows = [[
            'Severity', 'Category', 'Rule', 'Rule version', 'Date', 'Record type', 'Record',
            'Reference', 'Staff member', 'Amount', 'Variance', 'Status', 'Decision by',
            'Decision at', 'Decision note', 'Detail', 'Fingerprint',
        ]];

        $count = 0;

        DB::table('health_audit_findings')
            ->where('health_audit_run_id', $run->id)
            ->orderBy('id')
            ->chunk(500, function ($chunk) use (&$rows, &$count) {
                foreach ($chunk as $f) {
                    if ($count >= self::ROW_CAP) {
                        return false;
                    }
                    $count++;

                    $rows[] = [
                        $f->severity,
                        $f->category,
                        $f->rule_key,
                        $f->rule_version,
                        $f->occurred_on ? substr((string) $f->occurred_on, 0, 10) : '',
                        (string) $f->entity_type,
                        (string) $f->entity_id,
                        (string) $f->entity_label,
                        (string) $f->subject_name,
                        $f->amount === null ? '' : (string) $f->amount,
                        $f->variance === null ? '' : (string) $f->variance,
                        $f->status,
                        (string) $f->status_by_name,
                        (string) $f->status_at,
                        // Decision notes travel in their withheld form, like
                        // every other free text in the pack.
                        (string) HealthAuditRecorder::withhold($f->status_note),
                        self::flatten($f->params),
                        $f->fingerprint,
                    ];
                }
            });

        if ($count >= self::ROW_CAP) {
            $rows[] = ['NOTE', 'output capped at ' . number_format(self::ROW_CAP) . ' findings'];
        }

        return self::toCsv($rows);
    }

    /**
     * The recorded acts for the period.
     *
     * The trail is exported as it was stored — clinical narrative was already
     * collapsed on the way in, so there is nothing to strip here and no path by
     * which a future column could leak into the pack unnoticed.
     */
    protected static function confineTo($query, string $column, ?array $ids): void
    {
        if ($ids === null) {
            return;
        }

        if (empty($ids)) {
            $query->whereNull($column);

            return;
        }

        $query->where(function ($q) use ($column, $ids) {
            $q->whereIn($column, array_map('intval', $ids))->orWhereNull($column);
        });
    }

    protected static function trailCsv(HealthAuditRun $run): string
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('health_audit_events')) {
            return self::toCsv([['The audit trail table is not present on this installation.']]);
        }

        $rows = [[
            'When', 'Category', 'Event', 'Action', 'Staff member', 'Role', 'Record type',
            'Record', 'Reference', 'Amount', 'Reason', 'Source', 'IP address',
            'Changed from', 'Changed to', 'Hash',
        ]];

        $count = 0;

        $query = DB::table('health_audit_events')
            ->where('company_id', $run->company_id)
            ->whereBetween('occurred_at', [
                $run->date_from->toDateString() . ' 00:00:00',
                $run->date_to->toDateString() . ' 23:59:59',
            ]);

        if ($run->branch_id) {
            $query->where('branch_id', $run->branch_id);
        }
        if ($run->health_department_id) {
            $query->where('health_department_id', $run->health_department_id);
        }
        if ($run->subject_user_id) {
            $query->where('actor_user_id', $run->subject_user_id);
        }

        // The BOUNDARY the run was computed inside, not only its filter. A pack
        // exported by a branch-confined auditor must not ship the other
        // branches' trail just because they left the branch filter blank.
        // Unfiled rows (NULL) stay in, the same rule every list screen applies.
        self::confineTo($query, 'branch_id', $run->scope_branch_ids);
        self::confineTo($query, 'health_department_id', $run->scope_department_ids);

        $query->orderBy('id')->chunk(500, function ($chunk) use (&$rows, &$count) {
            foreach ($chunk as $e) {
                if ($count >= self::ROW_CAP) {
                    return false;
                }
                $count++;

                $rows[] = [
                    (string) $e->occurred_at,
                    (string) $e->category,
                    (string) $e->event,
                    (string) $e->action,
                    (string) $e->actor_name,
                    (string) $e->actor_role,
                    (string) $e->entity_type,
                    (string) $e->entity_id,
                    (string) $e->entity_label,
                    $e->amount === null ? '' : (string) $e->amount,
                    // The pack travels to people who may not open the record;
                    // it carries the withheld form whoever built it.
                    (string) \App\Models\HealthAuditEvent::withholdReason($e->reason === null ? null : (string) $e->reason),
                    (string) $e->source,
                    (string) $e->ip_address,
                    self::flatten($e->old_values),
                    self::flatten($e->new_values),
                    (string) $e->sha256_hash,
                ];
            }
        });

        if ($count >= self::ROW_CAP) {
            $rows[] = ['NOTE', 'output capped at ' . number_format(self::ROW_CAP) . ' recorded acts'];
        }

        return self::toCsv($rows);
    }

    protected static function summaryCsv(HealthAuditRun $run): string
    {
        $rows = [['Category', 'Critical', 'Warning', 'Informational', 'Total', 'Open', 'Closed']];

        $grouped = DB::table('health_audit_findings')
            ->where('health_audit_run_id', $run->id)
            ->selectRaw('category, severity, status, COUNT(*) as c')
            ->groupBy('category', 'severity', 'status')
            ->get();

        $table = [];
        foreach ($grouped as $g) {
            $table[$g->category] ??= ['critical' => 0, 'warning' => 0, 'info' => 0, 'total' => 0, 'open' => 0, 'closed' => 0];
            $table[$g->category][$g->severity] = ($table[$g->category][$g->severity] ?? 0) + $g->c;
            $table[$g->category]['total'] += $g->c;
            $key = in_array($g->status, ['resolved', 'false_positive'], true) ? 'closed' : 'open';
            $table[$g->category][$key] += $g->c;
        }

        ksort($table);

        foreach ($table as $category => $counts) {
            $rows[] = [
                $category,
                $counts['critical'],
                $counts['warning'],
                $counts['info'],
                $counts['total'],
                $counts['open'],
                $counts['closed'],
            ];
        }

        return self::toCsv($rows);
    }

    /**
     * The integrity block.
     *
     * Includes the trail's hash-chain verification, because a pack that only
     * hashes its own CSVs proves the CSVs were not edited AFTER export — it
     * says nothing about whether rows went missing before it. The chain check
     * is what covers that, and its result travels with the pack whether it
     * passed or not.
     */
    protected static function manifest(HealthAuditRun $run, ?Company $company, array $actor, array $files): array
    {
        $chain = ['checked' => false];

        try {
            // verifyChain() takes plain dates and widens them to the day itself.
            $chain = HealthAuditRecorder::verifyChain(
                (int) $run->company_id,
                $run->date_from->toDateString(),
                $run->date_to->toDateString()
            );
        } catch (\Throwable $e) {
            $chain = ['checked' => false, 'error' => $e->getMessage()];
        }

        $hashes = [];
        foreach ($files as $name => $content) {
            $hashes[$name] = [
                'sha256' => hash('sha256', $content),
                'bytes' => strlen($content),
            ];
        }

        return [
            'pack_format' => 1,
            'organisation' => [
                'id' => $run->company_id,
                'name' => $company->company_name ?? $company->name ?? null,
            ],
            'scope' => [
                'date_from' => $run->date_from->toDateString(),
                'date_to' => $run->date_to->toDateString(),
                'preset' => $run->preset,
                'branch_id' => $run->branch_id,
                'health_department_id' => $run->health_department_id,
                'health_doctor_id' => $run->health_doctor_id,
                'subject_user_id' => $run->subject_user_id,
                'described' => self::scopeLine($run),
            ],
            'run' => [
                'id' => $run->id,
                'ruleset_version' => $run->ruleset_version,
                'rules_run' => $run->rules_run,
                'rules_failed' => $run->rules_failed,
                'filters_hash' => $run->filters_hash,
                'result_hash' => $run->result_hash,
                'started_at' => optional($run->started_at)->toIso8601String(),
                'completed_at' => optional($run->completed_at)->toIso8601String(),
                'duration_ms' => $run->duration_ms,
            ],
            'totals' => [
                'events_scanned' => (int) $run->events_scanned,
                'findings_total' => (int) $run->findings_total,
                'findings_critical' => (int) $run->findings_critical,
                'findings_warning' => (int) $run->findings_warning,
                'findings_info' => (int) $run->findings_info,
                'risk_score' => (int) $run->risk_score,
            ],
            'generated_by' => [
                'user_id' => $actor['user_id'] ?? $run->user_id,
                'name' => $actor['actor_name'] ?? $run->actor_name,
                'role' => $actor['actor_role'] ?? $run->actor_role,
            ],
            'generated_at' => now()->toIso8601String(),
            'trail_chain' => $chain,
            'files' => $hashes,
            'confidentiality' => 'Clinical narrative is excluded by design; patients appear by registration number only.',
            'disclaimer' => 'Findings identify records worth examining. They are not, on their own, evidence that anybody acted improperly.',
        ];
    }

    /** Excel-safe CSV: BOM for UTF-8, and no cell that a spreadsheet will execute. */
    protected static function toCsv(array $rows): string
    {
        $out = "\xEF\xBB\xBF";
        $handle = fopen('php://temp', 'r+');

        foreach ($rows as $row) {
            fputcsv($handle, array_map([self::class, 'safeCell'], $row));
        }

        rewind($handle);
        $out .= stream_get_contents($handle);
        fclose($handle);

        return $out;
    }

    /**
     * Neutralise a cell a spreadsheet would treat as a formula.
     *
     * An audit pack is opened in Excel by definition, and a patient name or a
     * reason field beginning with = would otherwise run on the auditor's
     * machine.
     */
    protected static function safeCell($value): string
    {
        $value = (string) $value;

        return $value !== '' && str_contains("=+-@\t\r", $value[0]) ? "'" . $value : $value;
    }

    /** JSON payload → "key: value" so a spreadsheet cell stays readable. */
    protected static function flatten($json): string
    {
        if ($json === null || $json === '') {
            return '';
        }

        $decoded = is_array($json) ? $json : json_decode((string) $json, true);

        if (!is_array($decoded)) {
            return (string) $json;
        }

        $parts = [];
        foreach ($decoded as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            $parts[] = $key . ': ' . $value;
        }

        return mb_substr(implode('; ', $parts), 0, 2000);
    }

    /** Drop packs older than the retention window. */
    public static function cleanup(): int
    {
        $removed = 0;

        HealthAuditRun::whereNotNull('pack_path')
            ->where('pack_generated_at', '<', now()->subDays(self::RETENTION_DAYS))
            ->chunkById(100, function ($runs) use (&$removed) {
                foreach ($runs as $run) {
                    try {
                        Storage::disk('local')->delete($run->pack_path);
                    } catch (\Throwable $e) {
                        // A file already gone is the outcome we wanted anyway.
                    }

                    $run->update([
                        'pack_status' => 'expired',
                        'pack_path' => null,
                        'pack_progress' => 0,
                    ]);
                    $removed++;
                }
            });

        return $removed;
    }
}
