<?php

namespace App\Http\Controllers\Health;

use App\Models\Branch;
use App\Models\HealthAuditEvent;
use App\Models\HealthAuditFinding;
use App\Models\HealthAuditNote;
use App\Models\HealthAuditRun;
use App\Models\HealthDepartment;
use App\Models\HealthDoctor;
use App\Models\User;
use App\Services\HealthAccessService;
use App\Services\HealthAudit\HealthAuditContext;
use App\Services\HealthAudit\HealthAuditEngine;
use App\Services\HealthAudit\HealthAuditPackService;
use App\Services\HealthAudit\HealthAuditRecorder;
use App\Services\HealthAudit\HealthAuditRules;
use App\Services\HealthScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * The owner's one-click audit workspace (Task 1554).
 *
 * Three capabilities, and the split between them is the control itself:
 *
 *   audit.view    run an audit and read what it found
 *   audit.export  produce a signed evidence pack
 *   audit.manage  record a decision against a finding
 *
 * The dedicated auditor role holds the first two and NOT the third. An auditor
 * who can close their own findings is not an auditor — the point of the role is
 * that somebody outside the line of management looks, and somebody inside it
 * answers. Everything on this screen is read-only towards the rest of the ERP:
 * no operational record can be edited from here, by anyone, ever. If a charge
 * is wrong it is corrected on the billing screen by the person accountable for
 * it, and that correction produces its own audited event.
 */
class HealthAuditController extends HealthPanelController
{
    public function index(Request $request)
    {
        $this->require('audit.view');
        $this->requireSchema();

        $company = $this->company();

        // Only runs this account may open. A branch accountant does not get to
        // see that the owner ran an organisation-wide audit, let alone its
        // totals — the list is filtered by the same rule as the run itself.
        $runs = HealthAuditRun::where('company_id', $company?->id)
            ->orderByDesc('id')
            ->limit(60)
            ->get()
            ->filter(fn (HealthAuditRun $run) => $this->canReadRun($run))
            ->take(15)
            ->values();

        return view('health.audit.index', [
            'runs' => $runs,
            'latest' => $runs->firstWhere('status', 'ready'),
            'presets' => HealthAuditEngine::presets(),
            'defaultRange' => HealthAuditEngine::presetRange('last_30'),
            'canManage' => $this->can('audit.manage'),
            'canExport' => $this->can('audit.export'),
        ] + $this->filterOptions());
    }

    /**
     * One press.
     *
     * Runs inline rather than on the queue: the whole point of the feature is
     * that an owner presses a button and reads the answer, and a healthcare
     * month is tens of thousands of rows, not millions. A run that outgrows the
     * request is visible as a failed run rather than a silent nothing.
     */
    public function run(Request $request)
    {
        $this->require('audit.view');
        $this->requireSchema();

        $request->validate([
            'preset' => 'nullable|string|max:24',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'branch_id' => 'nullable|integer',
            'health_department_id' => 'nullable|integer',
            'health_doctor_id' => 'nullable|integer',
            'subject_user_id' => 'nullable|integer',
        ]);

        $company = $this->company();
        $user = $this->user();

        $preset = (string) $request->input('preset', 'last_30');

        if ($preset === 'custom') {
            $from = (string) $request->input('date_from', '');
            $to = (string) $request->input('date_to', '');

            if ($from === '' || $to === '') {
                return back()->with('error', __('health.audit_need_dates'));
            }
        } else {
            [$from, $to] = HealthAuditEngine::presetRange($preset);
        }

        if (strtotime($from) > strtotime($to)) {
            [$from, $to] = [$to, $from];
        }

        // A window nobody asked for is a window nobody will wait for. The cap
        // is a year, and it refuses rather than silently trimming — an audit
        // that quietly changed the period it examined is worse than one that
        // says no.
        if ((strtotime($to) - strtotime($from)) > 366 * 86400) {
            return back()->with('error', __('health.audit_range_too_long'));
        }

        // The FILTER the owner chose, then the BOUNDARY their account carries.
        // Applied together, never one instead of the other.
        $ctx = new HealthAuditContext(
            companyId: (int) $company?->id,
            from: date('Y-m-d', strtotime($from)),
            to: date('Y-m-d', strtotime($to)),
            branchId: $this->scopedId($request->input('branch_id'), $this->readerBranchScope()),
            departmentId: $this->scopedId($request->input('health_department_id'), $this->readerDepartmentScope()),
            doctorId: $this->scopedDoctorId($request->input('health_doctor_id')),
            subjectUserId: $this->scopedStaffId($request->input('subject_user_id')),
            branchBoundary: $this->readerBranchScope(),
            departmentBoundary: $this->readerDepartmentScope(),
            preset: $preset,
        );

        $run = HealthAuditEngine::run($ctx, [
            'user_id' => $user?->id,
            'actor_name' => $user?->name,
            'actor_role' => HealthAccessService::roleFor($user),
        ]);

        HealthAuditRecorder::record('audit.run', [
            'company_id' => $company?->id,
            'category' => 'audit',
            'action' => 'created',
            'entity_type' => 'health_audit_runs',
            'entity_id' => $run->id,
            'entity_label' => $run->date_from->toDateString() . ' → ' . $run->date_to->toDateString(),
            'meta' => [
                'findings' => $run->findings_total,
                'critical' => $run->findings_critical,
                'ruleset' => $run->ruleset_version,
            ],
        ]);

        return redirect()->route('health.audit.show', $run->id);
    }

    /** The result of one run: the summary, then the findings behind it. */
    public function show(Request $request, $id)
    {
        $this->require('audit.view');
        $this->requireSchema();

        $run = $this->findRun($id);

        // findRun() already refused a run wider than this reader; the scope on
        // every findings query below is the second lock on the same door.
        $query = $this->scopedFindings($run);

        if ($severity = $request->query('severity')) {
            $query->where('severity', $severity);
        }
        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }
        if ($status = $request->query('status')) {
            $status === 'open_only'
                ? $query->whereNotIn('status', HealthAuditFinding::CLOSED_STATUSES)
                : $query->where('status', $status);
        }
        if ($rule = $request->query('rule')) {
            $query->where('rule_key', $rule);
        }

        $findings = $query
            ->orderByRaw($this->severityOrder())
            ->orderBy('category')
            ->orderBy('rule_key')
            ->orderBy('occurred_on')
            ->orderBy('id')
            ->paginate(40)
            ->withQueryString();

        // Category and rule tallies come from the whole run, not the filtered
        // page — a filter must narrow what you read, never what you are told
        // exists.
        $byCategory = $this->scopedFindings($run)
            ->selectRaw('category, severity, COUNT(*) as c')
            ->groupBy('category', 'severity')
            ->get()
            ->groupBy('category');

        $byRule = $this->scopedFindings($run)
            ->selectRaw('rule_key, severity, COUNT(*) as c')
            ->groupBy('rule_key', 'severity')
            ->orderByRaw($this->severityOrder())
            ->orderByDesc('c')
            ->get();

        // The run this one should be compared against: the previous run of the
        // SAME question. Different scope, different question — putting the two
        // side by side would invent a trend that does not exist.
        $previous = HealthAuditRun::where('company_id', $run->company_id)
            ->where('filters_hash', $run->filters_hash)
            ->where('status', 'ready')
            ->where('id', '<', $run->id)
            ->orderByDesc('id')
            ->first();

        return view('health.audit.show', [
            'run' => $run,
            'findings' => $findings,
            'byCategory' => $byCategory,
            'byRule' => $byRule,
            'previous' => $previous,
            'categories' => HealthAuditRules::categories(),
            'canManage' => $this->can('audit.manage'),
            'canExport' => $this->can('audit.export'),
            'packVerified' => $run->pack_status === 'ready' ? HealthAuditPackService::verify($run) : null,
        ]);
    }

    /** One finding, its evidence, and everything the trail says around it. */
    public function finding(Request $request, $id)
    {
        $this->require('audit.view');
        $this->requireSchema();

        $finding = $this->findFinding($id, ['run', 'notes']);

        return view('health.audit.finding', [
            'finding' => $finding,
            'run' => $finding->run,
            'links' => $this->resolveLinks($finding),
            'timeline' => $this->timelineFor($finding),
            'history' => $this->historyFor($finding),
            'canManage' => $this->can('audit.manage'),
        ]);
    }

    /**
     * Record a decision against a finding.
     *
     * The decision is written onto the finding AND appended to its note
     * history. The note history is append-only, so "resolved, then reopened,
     * then resolved again" stays readable — which is exactly the sequence
     * somebody reviewing the review needs to see.
     */
    public function updateStatus(Request $request, $id)
    {
        $this->require('audit.manage');
        $this->requireSchema();

        $request->validate([
            'status' => 'required|in:' . implode(',', HealthAuditFinding::STATUSES),
            'note' => 'nullable|string|max:2000',
        ]);

        $finding = $this->findFinding($id);

        $user = $this->user();
        $from = $finding->status;
        $to = (string) $request->input('status');
        // Identifiers never persist — same policy as every other free text
        // the audit stores; the words themselves are gated at read time.
        $note = trim((string) HealthAuditRecorder::scrubText(trim((string) $request->input('note', ''))));

        // Closing a finding without saying why is how an audit becomes
        // paperwork. Acknowledging is allowed bare — it only means "seen".
        if (in_array($to, HealthAuditFinding::CLOSED_STATUSES, true) && $note === '') {
            return back()->with('error', __('health.audit_close_needs_note'));
        }

        $finding->update([
            'status' => $to,
            'status_note' => $note !== '' ? mb_substr($note, 0, 500) : $finding->status_note,
            'status_by' => $user?->id,
            'status_by_name' => $user?->name,
            'status_at' => now(),
        ]);

        HealthAuditNote::create([
            'company_id' => $finding->company_id,
            'health_audit_finding_id' => $finding->id,
            'user_id' => $user?->id,
            'actor_name' => $user?->name,
            'actor_role' => HealthAccessService::roleFor($user),
            'status_from' => $from,
            'status_to' => $to,
            'body' => $note !== '' ? $note : null,
            'created_at' => now(),
        ]);

        HealthAuditRecorder::record('audit.finding.' . $to, [
            'company_id' => $finding->company_id,
            'branch_id' => $finding->branch_id,
            'category' => 'audit',
            'action' => 'updated',
            'entity_type' => 'health_audit_findings',
            'entity_id' => $finding->id,
            'entity_label' => $finding->rule_key,
            'reason' => $note !== '' ? $note : null,
            'old' => ['status' => $from],
            'new' => ['status' => $to],
        ]);

        return back()->with('success', __('health.audit_status_saved'));
    }

    /** Add an investigation note without changing the finding's status. */
    public function addNote(Request $request, $id)
    {
        $this->require('audit.manage');
        $this->requireSchema();

        $request->validate(['body' => 'required|string|max:2000']);

        $finding = $this->findFinding($id);

        $user = $this->user();

        HealthAuditNote::create([
            'company_id' => $finding->company_id,
            'health_audit_finding_id' => $finding->id,
            'user_id' => $user?->id,
            'actor_name' => $user?->name,
            'actor_role' => HealthAccessService::roleFor($user),
            'status_from' => $finding->status,
            'status_to' => $finding->status,
            'body' => (string) HealthAuditRecorder::scrubText(trim((string) $request->input('body'))),
            'created_at' => now(),
        ]);

        return back()->with('success', __('health.audit_note_added'));
    }

    /**
     * The raw trail, browsable.
     *
     * The findings answer "what looks wrong". This answers "what happened",
     * which is the question an owner actually asks once they stop trusting a
     * summary — and it is also where the chain verification lives, because the
     * honest place to say "rows are missing" is on the page showing the rows.
     */
    public function trail(Request $request)
    {
        $this->require('audit.view');
        $this->requireSchema();

        $company = $this->company();
        $user = $this->user();

        [$from, $to] = $request->filled('date_from') && $request->filled('date_to')
            ? [(string) $request->query('date_from'), (string) $request->query('date_to')]
            : HealthAuditEngine::presetRange('last_7');

        $query = HealthAuditEvent::where('company_id', $company?->id)
            ->whereBetween('occurred_at', [$from . ' 00:00:00', $to . ' 23:59:59']);

        // Branch AND department. The accountant posted to Radiology shares a
        // building with everybody else, so the branch fence alone would hand
        // them every other ward's activity.
        HealthScopeService::apply($query, $user);

        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }
        if ($actor = $request->query('actor_user_id')) {
            $query->where('actor_user_id', (int) $actor);
        }
        if ($search = trim((string) $request->query('q', ''))) {
            // Structured columns only. The free-text reason is never searched:
            // a "does any reason mention X?" box would let a reader who is
            // shown the reason's length guess its words one name at a time.
            $query->where(function ($q) use ($search) {
                $q->where('entity_label', 'like', '%' . $search . '%')
                    ->orWhere('event', 'like', '%' . $search . '%');
            });
        }

        $events = $query->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate(60)->withQueryString();

        // Verified over the visible window only. Verifying a whole year on
        // every page load would make the page the slowest thing in the panel
        // and would still answer a question nobody asked.
        $chain = HealthAuditRecorder::verifyChain((int) $company?->id, $from, $to);

        return view('health.audit.trail', [
            'events' => $events,
            'chain' => $chain,
            'from' => $from,
            'to' => $to,
            'categories' => HealthAuditRecorder::CATEGORIES,
        ] + $this->filterOptions());
    }

    /** Build the signed evidence pack for a run. */
    public function pack(Request $request, $id)
    {
        $this->require('audit.export');
        $this->requireSchema();

        $run = $this->findRun($id);

        if ($run->status !== 'ready') {
            return back()->with('error', __('health.audit_pack_run_not_ready'));
        }

        if (!HealthAuditPackService::claim($run)) {
            return back()->with('error', __('health.audit_pack_busy'));
        }

        $user = $this->user();

        $run = HealthAuditPackService::build($run->fresh(), $this->company(), [
            'user_id' => $user?->id,
            'actor_name' => $user?->name,
            'actor_role' => HealthAccessService::roleFor($user),
        ]);

        return $run->pack_status === 'ready'
            ? back()->with('success', __('health.audit_pack_ready'))
            : back()->with('error', __('health.audit_pack_failed', ['error' => $run->pack_error ?: '—']));
    }

    /**
     * Hand the pack over — but only if it is still the pack that was signed.
     *
     * A file on disk that no longer matches its signature is refused rather
     * than served with a warning. Nobody reads the warning; everybody keeps the
     * file.
     */
    public function packDownload($id)
    {
        $this->require('audit.export');
        $this->requireSchema();

        $run = $this->findRun($id);

        if ($run->pack_status !== 'ready' || !$run->pack_path) {
            abort(404);
        }

        if (!HealthAuditPackService::verify($run)) {
            return back()->with('error', __('health.audit_pack_integrity_failed'));
        }

        HealthAuditRecorder::record('export.audit_pack.downloaded', [
            'company_id' => $run->company_id,
            'category' => 'export',
            'action' => 'exported',
            'entity_type' => 'health_audit_runs',
            'entity_id' => $run->id,
            'entity_label' => HealthAuditPackService::filename($run),
        ]);

        return Storage::disk('local')->download($run->pack_path, HealthAuditPackService::filename($run));
    }

    // ═══════════════════════════ helpers ═══════════════════════════

    /**
     * Critical first, then warning, then info — expressed portably.
     *
     * MySQL's FIELD() would say this in one word, but the test suite runs on
     * SQLite and an audit screen that only sorts on the production driver is an
     * audit screen nothing can test.
     */
    protected function severityOrder(): string
    {
        return "CASE severity WHEN 'critical' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END";
    }

    /** The audit tables ship with the module; without them there is no screen. */
    protected function requireSchema(): void
    {
        abort_unless(
            Schema::hasTable('health_audit_runs') && Schema::hasTable('health_audit_findings'),
            404
        );
    }

    protected function findRun($id): HealthAuditRun
    {
        $run = HealthAuditRun::where('company_id', $this->company()?->id)->findOrFail($id);

        if (!$this->canReadRun($run)) {
            abort(403, __('health.denied_no_permission'));
        }

        return $run;
    }

    /**
     * May this account open this run?
     *
     * Two fences, both checked. The FILTER the run was given (one branch, one
     * department) must be reachable — and the BOUNDARY it was computed inside
     * must sit within the reader's own. The second is the one that matters:
     * an owner's unfiltered run has no branch filter at all, and without this
     * check a branch-confined reader would open it as if it were their own.
     */
    protected function canReadRun(HealthAuditRun $run): bool
    {
        $user = $this->user();

        return HealthScopeService::canAccessBranch($user, $run->branch_id)
            && HealthScopeService::canAccessDepartment($user, $run->health_department_id)
            && $run->readableWithin(
                $this->readerBranchScope(),
                $this->readerDepartmentScope()
            );
    }

    /**
     * The reader's branch fence, as the audit understands it.
     *
     * The platform hands back an empty list for an unposted account in a
     * hospital that has not created any branches yet. Every row in such a
     * hospital carries a NULL branch, so that reader already sees everything
     * on every list screen; for the audit it is the same as no fence, and
     * a run "computed across every branch" is theirs to read. Once a single
     * branch exists the fence is real again.
     */
    protected function readerBranchScope(): ?array
    {
        $ids = HealthScopeService::branchIdsFor($this->user());
        if (is_array($ids) && $ids === [] && !$this->companyHasAny(Branch::class)) {
            return null;
        }

        return $ids;
    }

    /** Same rule for departments: no department anywhere = no department fence. */
    protected function readerDepartmentScope(): ?array
    {
        $ids = HealthScopeService::departmentIdsFor($this->user());
        if (is_array($ids) && $ids === []
            && (!Schema::hasTable('health_departments') || !$this->companyHasAny(HealthDepartment::class))) {
            return null;
        }

        return $ids;
    }

    protected function companyHasAny(string $model): bool
    {
        return $model::withoutGlobalScopes()
            ->where('company_id', $this->company()?->id)
            ->exists();
    }

    /** Findings of a run, kept inside the reader's own branch and department. */
    protected function scopedFindings(HealthAuditRun $run)
    {
        $query = HealthAuditFinding::where('health_audit_run_id', $run->id);

        HealthScopeService::apply($query, $this->user());

        return $query;
    }

    /**
     * One finding, or a refusal. The run it belongs to has to be readable, and
     * the finding's own branch and department have to be reachable — a finding
     * is evidence about a ward, and the ward fence applies to evidence too.
     */
    protected function findFinding($id, array $with = []): HealthAuditFinding
    {
        $finding = HealthAuditFinding::where('company_id', $this->company()?->id)
            ->with(array_values(array_unique(array_merge(['run'], $with))))
            ->findOrFail($id);

        if (!$finding->run || !$this->canReadRun($finding->run)) {
            abort(403, __('health.denied_no_permission'));
        }

        $this->requireBranch($finding->branch_id);
        $this->requireDepartment($finding->health_department_id);

        return $finding;
    }

    /**
     * Keep a posted filter inside the account's own boundary.
     *
     * The picker already hides what this account cannot reach; this is what
     * stops somebody typing another branch's id straight into the form.
     */
    protected function scopedId($value, ?array $boundary): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = (int) $value;

        if (is_array($boundary) && !in_array($id, $boundary, true)) {
            abort(403, __('health.denied_no_permission'));
        }

        return $id ?: null;
    }

    /** Branches, departments, doctors and staff this account may filter by. */
    protected function filterOptions(): array
    {
        $company = $this->company();
        $user = $this->user();

        $branchIds = $this->readerBranchScope();
        $branches = Branch::where('company_id', $company?->id)
            ->when(is_array($branchIds), fn ($q) => $q->whereIn('id', $branchIds ?: [0]))
            ->orderBy('name')->get(['id', 'name']);

        $departmentIds = $this->readerDepartmentScope();
        $departments = Schema::hasTable('health_departments')
            ? HealthDepartment::where('company_id', $company?->id)
                ->when(is_array($departmentIds), fn ($q) => $q->whereIn('id', $departmentIds ?: [0]))
                ->orderBy('name')->get(['id', 'name'])
            : collect();

        return [
            'branches' => $branches,
            'departments' => $departments,
            'doctors' => $this->selectableDoctors(),
            'staff' => $this->selectableStaff(),
        ];
    }

    /**
     * Practitioners this reader may pick. The picker is fenced like the
     * findings are: a ward auditor does not get the whole medical roster as
     * a drop-down before the first run.
     */
    protected function selectableDoctors()
    {
        $company = $this->company();

        if (!$company || !Schema::hasTable('health_doctors')) {
            return collect();
        }

        return HealthScopeService::apply(HealthDoctor::where('company_id', $company->id), $this->user())
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Staff this reader may pick, fenced by each member's posting. Unposted
     * (organisation-wide) accounts stay listed for everyone, the same way an
     * unfiled record does.
     */
    protected function selectableStaff()
    {
        $company = $this->company();

        if (!$company) {
            return collect();
        }

        $query = User::where('company_id', $company->id);
        if (Schema::hasColumn('users', 'health_role')) {
            $query->where(function ($q) {
                $q->whereNotNull('health_role')->orWhere('role', 'company_admin');
            });
        }

        $branchColumn = Schema::hasColumn('users', 'default_branch_id') ? 'default_branch_id' : null;
        $departmentColumn = Schema::hasColumn('users', 'health_department_id') ? 'health_department_id' : null;

        if ($branchColumn && is_array($this->readerBranchScope())) {
            $query = HealthScopeService::applyBranchScope($query, $this->user(), $branchColumn);
        }
        if ($departmentColumn && is_array($this->readerDepartmentScope())) {
            $query = HealthScopeService::applyDepartmentScope($query, $this->user(), $departmentColumn);
        }

        return $query->orderByRaw("CASE WHEN role = 'company_admin' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get();
    }

    /** A posted doctor id, refused unless it is one this reader may pick. */
    protected function scopedDoctorId($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = (int) $value;
        if (!$id) {
            return null;
        }

        if (!$this->selectableDoctors()->contains(fn ($doctor) => (int) $doctor->id === $id)) {
            abort(403, __('health.denied_no_permission'));
        }

        return $id;
    }

    /** A posted staff id, refused unless it is one this reader may pick. */
    protected function scopedStaffId($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = (int) $value;
        if (!$id) {
            return null;
        }

        if (!$this->selectableStaff()->contains(fn ($member) => (int) $member->id === $id)) {
            abort(403, __('health.denied_no_permission'));
        }

        return $id;
    }

    /**
     * Turn each evidence link descriptor into a URL this reader may follow.
     *
     * The capability travels WITH the link because the person reading a finding
     * is often not the person who produced it. An auditor with no pharmacy
     * access sees the stock finding and its numbers, and simply gets no link
     * through to the batch — which is the correct outcome, not a bug.
     */
    protected function resolveLinks(HealthAuditFinding $finding): array
    {
        $evidence = (array) $finding->evidence;

        // A check may attach one link or several; both spellings are accepted
        // so a new check cannot lose its drill-down over a plural.
        $descriptors = [];
        if (isset($evidence['link']['route'])) {
            $descriptors[] = $evidence['link'];
        }
        foreach ((array) ($evidence['links'] ?? []) as $link) {
            if (isset($link['route'])) {
                $descriptors[] = $link;
            }
        }

        $out = [];

        foreach ($descriptors as $link) {
            $route = $link['route'];

            // Route::has() first: a check may point at a module this
            // installation does not have, and a dead link on an audit screen
            // reads as a broken audit.
            if (!\Illuminate\Support\Facades\Route::has($route)) {
                continue;
            }

            if (!empty($link['cap']) && !$this->can($link['cap'])) {
                continue;
            }

            try {
                $key = 'health.audit_link_' . str_replace(['health.', '.'], ['', '_'], $route);

                $out[] = [
                    'label' => __($key) === $key ? __('health.audit_open_record') : __($key),
                    'url' => route($route, $link['params'] ?? [], false),
                ];
            } catch (\Throwable $e) {
                // A link that cannot be built is a link the reader never needed.
            }
        }

        return $out;
    }

    /**
     * Everything the trail recorded about the record this finding points at.
     *
     * This is the drill-down that makes a finding answerable: not "this charge
     * was reversed" but "this charge was created at 11:04 by reception, edited
     * at 11:40, and reversed at 18:12 by the same person with no reason given".
     */
    protected function timelineFor(HealthAuditFinding $finding)
    {
        if (!Schema::hasTable('health_audit_events') || !$finding->entity_type || !$finding->entity_id) {
            return collect();
        }

        $query = HealthAuditEvent::where('company_id', $finding->company_id)
            ->where('entity_type', $finding->entity_type)
            ->where('entity_id', $finding->entity_id);

        HealthScopeService::apply($query, $this->user());

        return $query->orderBy('occurred_at')->orderBy('id')
            ->limit(200)
            ->get();
    }

    /**
     * The same finding in earlier runs.
     *
     * A finding that has been raised every month for six months and closed as a
     * false positive every time is telling the owner about their rules, not
     * their hospital.
     */
    protected function historyFor(HealthAuditFinding $finding)
    {
        $query = HealthAuditFinding::where('company_id', $finding->company_id)
            ->where('fingerprint', $finding->fingerprint)
            ->where('id', '!=', $finding->id);

        HealthScopeService::apply($query, $this->user());

        return $query->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'health_audit_run_id', 'status', 'status_by_name', 'status_at', 'created_at']);
    }
}
