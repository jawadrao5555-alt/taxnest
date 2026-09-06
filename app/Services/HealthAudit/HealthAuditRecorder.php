<?php

namespace App\Services\HealthAudit;

use App\Models\Company;
use App\Models\HealthAuditEvent;
use App\Models\User;
use App\Services\HealthAccessService;
use App\Support\HealthPanel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The ONE way a healthcare audit event gets written (Task 1554).
 *
 * Everything the panel wants remembered — a charge reversed, a permission
 * granted, a confidential file opened, a report exported, somebody signing in —
 * lands here, in one shape, with the same six answers attached: who, when,
 * where, from what, why, and what changed. A second recorder would drift from
 * this one within a release, and an audit trail with two shapes is an audit
 * trail nobody can query.
 *
 * Recording NEVER breaks the act it is recording. A failure here is logged and
 * swallowed: refusing a patient's admission because the audit row would not
 * write is a worse outcome than a gap in the trail, and the gap is visible
 * (the chain verifier reports it) while a refused admission is just an outage.
 */
class HealthAuditRecorder
{
    /** Categories an event may belong to. Anything else is stored as 'clinical'. */
    public const CATEGORIES = [
        'clinical', 'billing', 'payment', 'stock', 'accounts',
        'hr', 'access', 'auth', 'export', 'record_view', 'audit',
    ];

    /** Values never written into old_values / new_values, whatever the caller passes. */
    private const REDACTED_KEYS = [
        'password', 'password_confirmation', 'remember_token', 'api_token',
        'secret', 'token', 'pin', 'otp',
    ];

    /**
     * Clinical narrative. Recorded as "changed / not changed", never as
     * content: the audit trail proves a note was rewritten without becoming a
     * second, unguarded copy of the note.
     */
    private const CLINICAL_TEXT_KEYS = [
        'clinical_notes', 'diagnosis', 'examination', 'history', 'chief_complaint',
        'advice', 'procedures', 'discharge_summary', 'discharge_advice',
        'operative_notes', 'findings', 'complications', 'post_op_instructions',
        'allergies', 'chronic_conditions', 'notes', 'final_diagnosis',
        'provisional_diagnosis', 'general_instructions', 'care_note',
    ];

    /**
     * Free text a person typed into an operational record — a cancellation
     * reason, a shift note, an expense description. Kept in the before/after
     * diff as "N characters" only: the words already ride on the event's own
     * `reason` field, where the reader's capability decides who sees them.
     * A diff that repeated them verbatim would hand every auditor the text
     * the reason gate withholds.
     */
    private const FREE_TEXT_KEYS = [
        'reason', 'note', 'memo', 'description', 'remarks', 'remark', 'comment',
        'comments', 'narration', 'body', 'message', 'instructions', 'purpose',
    ];

    /**
     * Personal identifiers. A change to any of these is recorded as "changed",
     * never as the value: the auditor needs to know a patient's phone number
     * was edited at 02:14 by the night cashier — not what the number is.
     * Matched as a substring of the column name, on every model.
     */
    private const IDENTITY_KEYS = [
        'cnic', 'nic_no', 'national_id', 'passport',
        'phone', 'mobile', 'whatsapp', 'email', 'address',
        'date_of_birth', 'dob', 'guardian', 'father', 'husband', 'mother',
        'emergency_contact', 'attendant', 'next_of_kin', 'kin_',
        'iban', 'account_no', 'account_number', 'card_no', 'policy_no',
    ];

    /** Set while a bulk/system routine runs, so a sweep does not write 10k rows. */
    protected static bool $suspended = false;

    public static function suspend(): void
    {
        self::$suspended = true;
    }

    public static function resume(): void
    {
        self::$suspended = false;
    }

    /** Run a callback without writing per-model events (bulk sweeps, seeders). */
    public static function withoutRecording(callable $callback)
    {
        $was = self::$suspended;
        self::$suspended = true;

        try {
            return $callback();
        } finally {
            self::$suspended = $was;
        }
    }

    public static function isSuspended(): bool
    {
        return self::$suspended;
    }

    /**
     * Write one event.
     *
     * @param  array{
     *   company_id?:int|null, branch_id?:int|null, health_department_id?:int|null,
     *   category?:string, action?:string, actor?:User|null, occurred_at?:mixed,
     *   entity_type?:string|null, entity_id?:int|null, entity_label?:string|null,
     *   health_patient_id?:int|null, health_doctor_id?:int|null,
     *   amount?:float|null, reason?:string|null, source?:string,
     *   old?:array|null, new?:array|null, meta?:array|null, sensitive?:bool
     * }  $attributes
     */
    public static function record(string $event, array $attributes = []): ?HealthAuditEvent
    {
        if (self::$suspended) {
            return null;
        }

        try {
            if (!Schema::hasTable('health_audit_events')) {
                return null;
            }

            $actor = $attributes['actor'] ?? self::currentActor();
            $companyId = (int) ($attributes['company_id']
                ?? $actor?->company_id
                ?? (app()->bound('currentCompanyId') ? app('currentCompanyId') : 0));

            if ($companyId <= 0) {
                return null;
            }

            $category = $attributes['category'] ?? self::categoryFor($event);
            if (!in_array($category, self::CATEGORIES, true)) {
                $category = 'clinical';
            }

            $occurredAt = $attributes['occurred_at'] ?? now();
            if (!$occurredAt instanceof \DateTimeInterface) {
                $occurredAt = \Illuminate\Support\Carbon::parse($occurredAt);
            }

            [$branchId, $departmentId] = self::postingFor($attributes, $actor);

            $row = new HealthAuditEvent([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'health_department_id' => $departmentId,
                'occurred_at' => $occurredAt,
                'category' => $category,
                'event' => mb_substr($event, 0, 64),
                'action' => mb_substr((string) ($attributes['action'] ?? self::actionFor($event)), 0, 16),
                'actor_user_id' => $actor?->id,
                'actor_name' => $actor ? mb_substr((string) ($actor->name ?: $actor->email), 0, 150) : null,
                'actor_role' => $actor ? mb_substr((string) (HealthAccessService::roleFor($actor) ?? $actor->role ?? ''), 0, 32) : null,
                'entity_type' => isset($attributes['entity_type']) ? mb_substr((string) $attributes['entity_type'], 0, 64) : null,
                'entity_id' => $attributes['entity_id'] ?? null,
                'entity_label' => isset($attributes['entity_label']) ? mb_substr((string) $attributes['entity_label'], 0, 190) : null,
                'health_patient_id' => $attributes['health_patient_id'] ?? null,
                'health_doctor_id' => $attributes['health_doctor_id'] ?? null,
                'amount' => $attributes['amount'] ?? null,
                'reason' => self::scrubText(isset($attributes['reason']) ? mb_substr((string) $attributes['reason'], 0, 500) : null),
                'source' => mb_substr((string) ($attributes['source'] ?? self::currentSource()), 0, 16),
                'ip_address' => self::currentIp(),
                'user_agent' => self::currentUserAgent(),
                'route' => self::currentRoute(),
                'old_values' => self::sanitize($attributes['old'] ?? null),
                'new_values' => self::sanitize($attributes['new'] ?? null),
                'meta' => self::sanitize($attributes['meta'] ?? null),
                'is_sensitive' => (bool) ($attributes['sensitive'] ?? false),
                'created_at' => now(),
            ]);

            // One writer per organisation at a time. The anchor row is the
            // lock: whoever holds it reads the tip, appends, seals and moves
            // the anchor, so the chain is a single line with no forks — which
            // is what lets the verifier insist that every row's predecessor is
            // the row written immediately before it.
            //
            // The seal covers the row's own id, so it can only be computed once
            // the insert has handed the id back; the placeholder lives for the
            // length of this transaction and is never visible committed. Event,
            // seal and anchor move together or not at all: an event without
            // its anchor would read as "written behind the anchor's back" on
            // the next verification — a false alarm the hospital would learn to
            // ignore, which is how the real one gets ignored too.
            DB::transaction(function () use ($row, $companyId) {
                $anchor = self::lockAnchor($companyId);

                $row->prev_hash = $anchor
                    ? ((string) $anchor->tip_hash ?: null)
                    : self::chainTip($companyId);
                $row->sha256_hash = str_repeat('0', 64);
                $row->save();

                $hash = $row->expectedHash();
                DB::table($row->getTable())
                    ->where('id', $row->id)
                    ->update(['sha256_hash' => $hash]);
                // Reflect the sealed value on the instance without going
                // through save() — the model refuses updates by design.
                $row->setRawAttributes(array_merge($row->getAttributes(), ['sha256_hash' => $hash]), true);

                self::advanceAnchor($row, $anchor);
            });

            return $row;
        } catch (\Throwable $e) {
            Log::warning('Healthcare audit event not recorded', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * A model write, recorded with its before/after.
     *
     * Only the columns that ACTUALLY changed are stored. A dump of every column
     * on every save turns the trail into noise and buries the one field that
     * moved — which is the field the auditor came for.
     */
    public static function recordModelChange(string $event, $model, ?array $original, array $attributes = []): ?HealthAuditEvent
    {
        try {
            $new = self::diffable($model->getAttributes());
            $old = $original === null ? null : self::diffable($original);

            if ($old !== null) {
                $changed = [];
                foreach ($new as $key => $value) {
                    if (!array_key_exists($key, $old) || self::comparable($old[$key]) !== self::comparable($value)) {
                        $changed[$key] = $value;
                    }
                }

                $new = $changed;
                $old = array_intersect_key($old, $changed);
            }

            // A model that carries a person's identity gets an allow-list, not
            // a deny-list: only the structural columns keep their values, every
            // other column is recorded as the field name alone. A new column
            // added to the patient later is therefore private by default.
            if (isset($attributes['fields']) && is_array($attributes['fields'])) {
                $new = self::allowOnly($new, $attributes['fields']);
                $old = $old === null ? null : self::allowOnly($old, $attributes['fields']);
            }
            unset($attributes['fields']);
        } catch (\Throwable $e) {
            // Same contract as record(): the trail never breaks the act.
            Log::warning('Healthcare audit diff not computed', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return self::record($event, $attributes + ['old' => $old, 'new' => $new]);
    }

    /**
     * One shape for "did this column move?".
     *
     * The after-image comes from getAttributes() (raw DB values); the
     * before-image may arrive already cast — a JSON column is a string on one
     * side and an array on the other. Comparing them as strings raises an
     * "Array to string conversion" and, in a test run, turns a warning into an
     * exception that fails the hospital's own save.
     */
    protected static function comparable($value): string
    {
        if (is_array($value) || is_object($value)) {
            return (string) json_encode($value);
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    /** Keep values only for the allow-listed keys; the rest keep the key and lose the value. */
    protected static function allowOnly(?array $values, array $allowed): ?array
    {
        if ($values === null) {
            return null;
        }

        $out = [];
        foreach ($values as $key => $value) {
            $out[$key] = in_array((string) $key, $allowed, true) ? $value : '[redacted]';
        }

        return $out;
    }

    /** Drop the columns nobody audits (timestamps, the row's own id). */
    protected static function diffable(array $attributes): array
    {
        unset($attributes['id'], $attributes['created_at'], $attributes['updated_at']);

        return $attributes;
    }

    /**
     * Strip secrets and collapse clinical narrative to a length marker.
     *
     * The audit trail must be readable by an auditor who is deliberately NOT
     * allowed to read a diagnosis. Recording "diagnosis: changed, 148 → 210
     * characters" proves the edit happened without handing the clinical content
     * to a second audience.
     */
    /**
     * Free text a person typed — a cancellation reason, a shift note — with
     * the identifiers people habitually type into such fields taken out: an
     * e-mail address, a CNIC, a phone number, any long run of digits. The
     * words stay (the owner needs to read WHY a charge was reversed); what
     * cannot stay is a field that quietly turns into a patient directory.
     *
     * Applied at write time, so the trail never holds the identifier in the
     * first place — a later reader cannot un-redact what was never stored.
     */
    public static function scrubText(?string $text, bool $numbers = true): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        $text = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/u', '[email]', $text);
        $text = preg_replace('/\b\d{5}-?\d{7}-?\d\b/u', '[cnic]', $text);

        // Long digit runs are phone numbers in a sentence but document numbers
        // in a "bill_no" column; callers handling structured values keep them.
        if ($numbers) {
            $text = preg_replace('/\+?\d[\d\s().-]{5,}\d/u', '[number]', $text);
        }

        return $text;
    }

    /**
     * Free text as THIS reader may see it — the event's reason, a finding's
     * decision note, an investigation note. People type patient names and
     * clinical detail into every free-text box the product has, so the words
     * go only to a reader who may open the clinical record anyway
     * (clinical.view, module-aware, same organisation); everybody else
     * learns that text was recorded and how long it is.
     */
    public static function wordsFor(?User $reader, int $companyId, ?string $text): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }

        $company = $reader?->company_id ? Company::find($reader->company_id) : null;

        if ($reader && $company && (int) $company->id === $companyId
            && HealthAccessService::can($reader, 'clinical.view', $company)) {
            return $text;
        }

        return self::withhold($text);
    }

    /** The form that travels: a length, never the words. */
    public static function withhold(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }

        return __('health.audit_reason_withheld', ['n' => mb_strlen($text)]);
    }

    /**
     * The same policy for a nested structure — a finding's params and
     * evidence, which carry lists (duplicate members, the shifts of a week)
     * as well as scalars. A rule may copy any column it queried into its
     * evidence; this is the one place that decides what of it may persist,
     * so a new rule cannot leak by forgetting to redact.
     */
    public static function sanitizeDeep(array $values, int $depth = 0): array
    {
        if ($depth > 6) {
            return [];
        }

        $clean = [];
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $lower = strtolower((string) $key);
                // A list under a private key is still private.
                $clean[$key] = self::isPrivateKey($lower)
                    ? '[redacted]'
                    : self::sanitizeDeep($value, $depth + 1);
                continue;
            }

            $clean[$key] = self::sanitize([$key => $value])[$key];
        }

        return $clean;
    }

    protected static function isPrivateKey(string $lower): bool
    {
        foreach (array_merge(self::REDACTED_KEYS, self::IDENTITY_KEYS) as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** reason, cancel_reason, review_note, deduction_reason, memo ... */
    protected static function isFreeTextKey(string $lower): bool
    {
        foreach (self::FREE_TEXT_KEYS as $needle) {
            if ($lower === $needle || str_ends_with($lower, '_' . $needle)) {
                return true;
            }
        }

        return false;
    }

    protected static function sanitize(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $clean = [];
        foreach ($values as $key => $value) {
            $lower = strtolower((string) $key);

            foreach (self::REDACTED_KEYS as $needle) {
                if (str_contains($lower, $needle)) {
                    $clean[$key] = '[redacted]';
                    continue 2;
                }
            }

            foreach (self::IDENTITY_KEYS as $needle) {
                if (str_contains($lower, $needle)) {
                    $clean[$key] = '[redacted]';
                    continue 2;
                }
            }

            if (in_array($lower, self::CLINICAL_TEXT_KEYS, true)) {
                $clean[$key] = $value === null || $value === ''
                    ? null
                    : '[clinical text, ' . mb_strlen((string) $value) . ' characters]';
                continue;
            }

            if (self::isFreeTextKey($lower)) {
                $clean[$key] = $value === null || $value === ''
                    ? null
                    : '[free text, ' . mb_strlen((string) $value) . ' characters]';
                continue;
            }

            if (is_string($value)) {
                // Structured values keep their digits (a bill number is not a
                // phone number); an e-mail or a CNIC never belongs in one.
                $value = (string) self::scrubText($value, false);
                $clean[$key] = mb_strlen($value) > 500 ? mb_substr($value, 0, 500) . '…' : $value;
                continue;
            }

            if (is_array($value)) {
                $encoded = json_encode($value);
                $clean[$key] = $encoded === false ? '[unencodable]' : mb_substr($encoded, 0, 500);
                continue;
            }

            if (is_object($value)) {
                $clean[$key] = '[object]';
                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    /**
     * Where an explicitly recorded act (a login, a permission change, an
     * export) is filed.
     *
     * The caller's own branch/department win. Failing those, an act ABOUT a
     * staff member is filed under that member's posting, and any other act
     * under the actor's — so a ward cashier's export or a ward clerk's login
     * is a ward event, not an organisation-wide one that every confined
     * auditor gets to read. Only an unposted (organisation-wide) account
     * produces an unfiled event.
     *
     * @return array{0:int|null,1:int|null}
     */
    protected static function postingFor(array $attributes, $actor): array
    {
        $branchId = (int) ($attributes['branch_id'] ?? 0) ?: null;
        $departmentId = (int) ($attributes['health_department_id'] ?? 0) ?: null;

        if ($branchId && $departmentId) {
            return [$branchId, $departmentId];
        }

        $subjects = [];
        if (($attributes['entity_type'] ?? null) === 'users' && !empty($attributes['entity_id'])) {
            $subjects[] = (int) $attributes['entity_id'];
        }
        if ($actor && $actor->id) {
            $subjects[] = (int) $actor->id;
        }

        foreach ($subjects as $userId) {
            $posting = self::userPosting($userId);
            if ($posting === null) {
                continue;
            }

            $branchId = $branchId ?: $posting[0];
            $departmentId = $departmentId ?: $posting[1];

            if ($posting[0] || $posting[1]) {
                break;
            }
        }

        return [$branchId, $departmentId];
    }

    /** @return array{0:int|null,1:int|null}|null */
    protected static function userPosting(int $userId): ?array
    {
        try {
            $columns = [];
            foreach (['default_branch_id', 'health_department_id'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $columns[] = $column;
                }
            }

            $row = $columns ? DB::table('users')->where('id', $userId)->first($columns) : null;

            return $row
                ? [(int) ($row->default_branch_id ?? 0) ?: null, (int) ($row->health_department_id ?? 0) ?: null]
                : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The hash of the most recent event for this organisation, read from the
     * table. Only used when there is no anchor row to read it from — the very
     * first event of an organisation, or an install without the anchor table.
     */
    protected static function chainTip(int $companyId): ?string
    {
        return DB::table('health_audit_events')
            ->where('company_id', $companyId)
            ->orderByDesc('id')
            ->value('sha256_hash');
    }

    /**
     * Kept for callers written when the tip was memoised per request; the tip
     * now always comes from the locked anchor, so there is nothing to forget.
     */
    public static function forgetChainTip(?int $companyId = null): void
    {
    }

    /**
     * Whoever is acting. The healthcare guard first, then the web guard (an
     * admin managing-as), then nobody — a scheduled sweep has no actor and must
     * not borrow the last person who logged in.
     */
    public static function currentActor(): ?User
    {
        try {
            return Auth::guard(HealthPanel::GUARD)->user() ?: Auth::guard('web')->user();
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected static function currentSource(): string
    {
        if (app()->runningInConsole()) {
            return 'console';
        }

        try {
            return request()->expectsJson() ? 'api' : 'web';
        } catch (\Throwable $e) {
            return 'system';
        }
    }

    protected static function currentIp(): ?string
    {
        try {
            return request()->ip();
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected static function currentUserAgent(): ?string
    {
        try {
            $agent = request()->userAgent();

            return $agent ? mb_substr($agent, 0, 255) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected static function currentRoute(): ?string
    {
        try {
            $path = request()->path();

            return $path ? mb_substr(request()->method() . ' /' . ltrim($path, '/'), 0, 190) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** category derived from the event's own prefix, so callers rarely pass it. */
    protected static function categoryFor(string $event): string
    {
        $prefix = strtok($event, '.');

        return match ($prefix) {
            'billing', 'charge', 'bill' => 'billing',
            'payment', 'cash', 'shift' => 'payment',
            'stock', 'pharmacy' => 'stock',
            'accounts', 'journal', 'expense', 'settlement', 'period' => 'accounts',
            'hr', 'attendance', 'leave', 'roster' => 'hr',
            'access', 'staff', 'permission' => 'access',
            'auth' => 'auth',
            'export' => 'export',
            'record' => 'record_view',
            'audit' => 'audit',
            default => 'clinical',
        };
    }

    protected static function actionFor(string $event): string
    {
        foreach ([
            'created', 'updated', 'deleted', 'approved', 'reversed', 'cancelled',
            'viewed', 'login', 'logout', 'exported', 'granted', 'revoked',
            'closed', 'reopened', 'paid', 'refunded',
        ] as $action) {
            if (str_ends_with($event, '.' . $action)) {
                return $action;
            }
        }

        return 'updated';
    }

    /**
     * Walk the chain for a period and report what does not add up.
     *
     * Two different failures, reported separately because they mean different
     * things: ALTERED means a stored row's content no longer matches its own
     * hash (somebody edited the table), MISSING means a row points at an
     * ancestor that is not there any more (somebody deleted one).
     *
     * A FORK — two rows sharing an ancestor — is normal, not a failure: two
     * requests writing at the same instant both read the same tip.
     *
     * The walk covers EVERY row of the window — read in id-ordered chunks so
     * a year of a busy hospital does not have to fit in memory — because an
     * "intact" verdict that quietly stopped part-way is worse than none: the
     * pack would certify a period nobody actually checked.
     *
     * @return array{checked:int,altered:int,missing:int,altered_ids:array,missing_ids:array}
     */
    public static function verifyChain(int $companyId, ?string $from = null, ?string $to = null, int $chunk = 2000): array
    {
        $result = [
            'checked' => 0, 'altered' => 0, 'missing' => 0,
            'altered_ids' => [], 'missing_ids' => [],
            'anchor' => ['status' => 'empty', 'expected_count' => 0, 'actual_count' => 0, 'last_event_id' => null],
            'intact' => true,
        ];

        if (!Schema::hasTable('health_audit_events')) {
            return $result;
        }

        $result['anchor'] = self::verifyAnchor($companyId);

        $query = HealthAuditEvent::query()->where('company_id', $companyId);
        if ($from) {
            $query->where('occurred_at', '>=', $from . ' 00:00:00');
        }
        if ($to) {
            $query->where('occurred_at', '<=', $to . ' 23:59:59');
        }

        // The chain is one straight line per organisation (writes are
        // serialised on the anchor), so inside the window every row's
        // predecessor must be the row written immediately before it. The
        // first row of the window is the one exception: its predecessor sits
        // outside the window and only has to exist, with a smaller id. A row
        // whose occurred_at was pushed out of the window therefore leaves a
        // hole the next row points into, rather than quietly vanishing.
        $previous = null;
        $lastId = 0;

        while (true) {
            $rows = (clone $query)->where('id', '>', $lastId)->orderBy('id')->limit(max(1, $chunk))->get();
            if ($rows->isEmpty()) {
                break;
            }
            $lastId = (int) $rows->last()->id;

            foreach ($rows as $row) {
                $result['checked']++;

                if (!hash_equals((string) $row->sha256_hash, $row->expectedHash())) {
                    $result['altered']++;
                    if (count($result['altered_ids']) < 50) {
                        $result['altered_ids'][] = (int) $row->id;
                    }
                }

                $linked = true;
                if ($previous === null) {
                    if ($row->prev_hash) {
                        $linked = DB::table('health_audit_events')
                            ->where('company_id', $companyId)
                            ->where('id', '<', $row->id)
                            ->where('sha256_hash', $row->prev_hash)
                            ->exists();
                    }
                } else {
                    $linked = hash_equals((string) $previous->sha256_hash, (string) $row->prev_hash);
                }

                if (!$linked) {
                    $result['missing']++;
                    if (count($result['missing_ids']) < 50) {
                        $result['missing_ids'][] = (int) $row->id;
                    }
                }

                $previous = $row;
            }
        }

        $result['intact'] = $result['altered'] === 0
            && $result['missing'] === 0
            && in_array($result['anchor']['status'], ['intact', 'empty'], true);

        return $result;
    }

    // ═══════════════════════════ chain anchor ═══════════════════════════

    /**
     * Point the organisation's anchor at the event just written.
     *
     * The chain already lets every event vouch for the one before it. The
     * anchor is the one thing that vouches for the LAST event — without it,
     * deleting the newest rows leaves a chain that still verifies perfectly,
     * just shorter. It also carries the row count, so a deletion anywhere is
     * a mismatch even before the per-row walk finds the hole.
     *
     * Signed under the application key. A database-only intruder can rewrite
     * an event and recompute every hash after it; what they cannot do is
     * produce a fresh signature for the new tip.
     */
    protected static function lockAnchor(int $companyId): ?object
    {
        if (!Schema::hasTable('health_audit_chain_anchors')) {
            return null;
        }

        return DB::table('health_audit_chain_anchors')
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->first();
    }

    protected static function advanceAnchor(HealthAuditEvent $row, ?object $anchor): void
    {
        if (!Schema::hasTable('health_audit_chain_anchors')) {
            return;
        }

        $companyId = (int) $row->company_id;

        // First anchor for an organisation that already has history: count what
        // is there, so the anchor vouches for the whole chain, not just for
        // rows written after today.
        $count = $anchor
            ? (int) $anchor->event_count + 1
            : (int) DB::table('health_audit_events')->where('company_id', $companyId)->count();

        $values = [
            'last_event_id' => (int) $row->id,
            'tip_hash' => (string) $row->sha256_hash,
            'event_count' => $count,
            'signature' => self::anchorSignature($companyId, (int) $row->id, (string) $row->sha256_hash, $count),
            'updated_at' => now(),
        ];

        if ($anchor) {
            DB::table('health_audit_chain_anchors')->where('id', $anchor->id)->update($values);
        } else {
            DB::table('health_audit_chain_anchors')->insert(['company_id' => $companyId] + $values);
        }
    }

    public static function anchorSignature(int $companyId, int $lastEventId, string $tipHash, int $count): string
    {
        return hash_hmac('sha256', implode('|', ['v1', $companyId, $lastEventId, $tipHash, $count]), (string) config('app.key'));
    }

    /**
     * Does the anchor still agree with the table?
     *
     *   empty           no events and no anchor — nothing to vouch for yet
     *   intact          signature valid, tip present, counts agree
     *   missing         events exist but no anchor row does
     *   forged          the anchor's signature does not match its own content
     *   tail_removed    the event the anchor points at is gone or altered
     *   unanchored      events exist beyond the anchor — written behind its back
     *   count_mismatch  the row count differs from what the anchor recorded
     */
    public static function verifyAnchor(int $companyId): array
    {
        $actualCount = (int) DB::table('health_audit_events')->where('company_id', $companyId)->count();
        $out = ['status' => 'empty', 'expected_count' => 0, 'actual_count' => $actualCount, 'last_event_id' => null];

        if (!Schema::hasTable('health_audit_chain_anchors')) {
            $out['status'] = $actualCount === 0 ? 'empty' : 'missing';

            return $out;
        }

        $anchor = DB::table('health_audit_chain_anchors')->where('company_id', $companyId)->first();

        if (!$anchor) {
            $out['status'] = $actualCount === 0 ? 'empty' : 'missing';

            return $out;
        }

        $out['expected_count'] = (int) $anchor->event_count;
        $out['last_event_id'] = (int) $anchor->last_event_id;

        $expectedSignature = self::anchorSignature(
            $companyId,
            (int) $anchor->last_event_id,
            (string) $anchor->tip_hash,
            (int) $anchor->event_count
        );

        if (!hash_equals($expectedSignature, (string) $anchor->signature)) {
            $out['status'] = 'forged';

            return $out;
        }

        // The tip must be there, carry the hash the anchor signed for, AND
        // still hash to it — the last row is the one no successor vouches
        // for, so the anchor has to do the recomputation itself.
        $tip = HealthAuditEvent::query()
            ->where('company_id', $companyId)
            ->where('id', (int) $anchor->last_event_id)
            ->first();

        if ($tip === null
            || !hash_equals((string) $anchor->tip_hash, (string) $tip->sha256_hash)
            || !hash_equals((string) $tip->sha256_hash, $tip->expectedHash())) {
            $out['status'] = 'tail_removed';

            return $out;
        }

        $newest = (int) DB::table('health_audit_events')->where('company_id', $companyId)->max('id');
        if ($newest > (int) $anchor->last_event_id) {
            $out['status'] = 'unanchored';

            return $out;
        }

        if ($actualCount !== (int) $anchor->event_count) {
            $out['status'] = 'count_mismatch';

            return $out;
        }

        $out['status'] = 'intact';

        return $out;
    }

    /** Convenience: the company the current actor belongs to. */
    public static function currentCompany(): ?Company
    {
        $id = app()->bound('currentCompanyId') ? app('currentCompanyId') : null;

        return $id ? Company::find($id) : null;
    }
}
