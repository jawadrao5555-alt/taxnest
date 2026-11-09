<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\Company;
use App\Models\SupportCase;
use App\Models\SupportCaseEvent;
use App\Services\SupportMailService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Support Inbox — read/reply support@taxnest.pk inside the admin panel.
 * Super-admin only (guarded in each action; admin.auth handles login).
 */
class SupportInboxController extends Controller
{
    public function __construct(protected SupportMailService $mail) {}

    protected function guardSuperAdmin(): AdminUser
    {
        $u = auth('admin')->user();
        $current = $u instanceof AdminUser ? $u->fresh() : null;
        abort_unless($current && $current->isSuperAdmin(), 403);

        return $current;
    }

    /** Lightweight JSON badge count — cached only, never hits IMAP. */
    public function unread()
    {
        $this->guardSuperAdmin();

        return response()->json(['unread' => SupportMailService::cachedUnreadCount()]);
    }

    public function index(Request $request)
    {
        $this->guardSuperAdmin();
        $tab = $request->query('tab') === 'sent' ? 'sent' : 'inbox';
        $page = max(1, (int) $request->query('page', 1));

        $error = null;
        $list = ['messages' => [], 'total' => 0, 'page' => 1, 'last_page' => 1];
        if (! $this->mail->isConfigured()) {
            $error = 'Support mailbox abhi configure nahi hui — SUPPORT_MAIL_PASSWORD set karein.';
        } else {
            try {
                $list = $this->mail->listMessages($tab, $page);
            } catch (\RuntimeException $e) {
                $error = $e->getMessage();
            }
        }

        return view('saas-admin.support-inbox.index', [
            'tab' => $tab,
            'list' => $list,
            'error' => $error,
            'unread' => SupportMailService::cachedUnreadCount(),
        ]);
    }

    /** Durable case queue is independent from transient IMAP availability. */
    public function caseIndex(Request $request)
    {
        $this->guardSuperAdmin();
        $status = in_array($request->query('status'), [SupportCase::STATUS_OPEN, SupportCase::STATUS_RESOLVED, SupportCase::STATUS_REOPENED], true)
            ? $request->query('status')
            : null;
        $query = SupportCase::query()->with(['company', 'assignedAdmin'])->latest('updated_at');
        if ($status) {
            $query->where('status', $status);
        }

        return view('saas-admin.support-inbox.cases-index', [
            'cases' => $query->paginate(50)->withQueryString(),
            'status' => $status,
        ]);
    }

    public function caseShow(SupportCase $supportCase)
    {
        $this->guardSuperAdmin();

        return view('saas-admin.support-inbox.case-show', [
            'supportCase' => $supportCase->load(['company', 'assignedAdmin', 'events.actor']),
            'caseLinks' => $this->caseLinks($supportCase),
            'supportOwners' => AdminUser::query()
                ->whereIn('role', ['admin', 'super_admin'])
                ->orderBy('name')
                ->get(['id', 'name', 'role']),
        ]);
    }

    /**
     * Auto-refresh poll: returns the rendered message list + a fingerprint so
     * the page only swaps the DOM when something actually changed. Backed by
     * the service's short list cache — polls do NOT hammer IMAP.
     */
    public function poll(Request $request)
    {
        $this->guardSuperAdmin();
        $tab = $request->query('tab') === 'sent' ? 'sent' : 'inbox';
        $page = max(1, (int) $request->query('page', 1));

        if (! $this->mail->isConfigured()) {
            return response()->json(['ok' => false], 503);
        }
        try {
            $list = $this->mail->listMessagesCached($tab, $page);
        } catch (\RuntimeException $e) {
            // Transient IMAP hiccup — keep the current list on screen.
            return response()->json(['ok' => false], 503);
        }

        $fingerprint = md5(json_encode(array_map(
            fn ($m) => [$m['uid'], (bool) $m['seen']],
            $list['messages']
        )).'|'.$list['total']);

        return response()->json([
            'ok' => true,
            'fingerprint' => $fingerprint,
            'unread' => SupportMailService::cachedUnreadCount(),
            'html' => view('saas-admin.support-inbox._list', [
                'tab' => $tab,
                'list' => $list,
                'error' => null,
            ])->render(),
        ]);
    }

    public function show(Request $request, string $box, int $uid)
    {
        $this->guardSuperAdmin();
        abort_unless(in_array($box, ['inbox', 'sent']), 404);
        try {
            $message = $this->mail->getMessage($box, $uid);
        } catch (\RuntimeException $e) {
            return redirect()->route('saas.admin.support-inbox', ['tab' => $box])->with('error', $e->getMessage());
        }

        $supportCase = SupportCase::query()
            ->with(['company', 'assignedAdmin', 'events.actor'])
            ->where('source_box', $box)
            ->where('source_uid', $uid)
            ->first();

        return view('saas-admin.support-inbox.show', [
            'box' => $box,
            'message' => $message,
            'supportCase' => $supportCase,
            'supportCompanies' => $box === 'inbox' && ! $supportCase
                ? Company::query()->select(['id', 'name', 'account_code', 'product_type'])->orderBy('name')->limit(500)->get()
                : collect(),
            'supportOwners' => AdminUser::query()
                ->whereIn('role', ['admin', 'super_admin'])
                ->orderBy('name')
                ->get(['id', 'name', 'role']),
        ]);
    }

    /** Link one inbound mailbox message to exactly one company support case. */
    public function storeCase(Request $request, string $box, int $uid)
    {
        $actor = $this->guardSuperAdmin();
        abort_unless($box === 'inbox', 404);
        $data = $request->validate([
            'company_id' => ['required', 'integer'],
            'priority' => ['nullable', 'in:'.implode(',', SupportCase::PRIORITIES)],
            'sla_due_at' => ['nullable', 'date'],
        ]);

        $company = Company::query()->findOrFail((int) $data['company_id']);
        try {
            $message = $this->mail->getMessage($box, $uid);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        try {
            $case = DB::transaction(function () use ($actor, $box, $uid, $company, $message, $data) {
                Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
                $case = SupportCase::query()->create([
                    'company_id' => $company->id,
                    'assigned_admin_id' => $actor->id,
                    'opened_by_admin_id' => $actor->id,
                    'source_box' => $box,
                    'source_uid' => $uid,
                    'source_message_id' => isset($message['message_id'])
                        ? mb_substr((string) $message['message_id'], 0, 512)
                        : null,
                    'subject' => mb_substr((string) ($message['subject'] ?? '(no subject)'), 0, 255),
                    'status' => SupportCase::STATUS_OPEN,
                    'priority' => $data['priority'] ?? SupportCase::PRIORITY_NORMAL,
                    'sla_due_at' => ! empty($data['sla_due_at']) ? Carbon::parse($data['sla_due_at']) : null,
                    'opened_at' => now(),
                ]);
                $this->recordCaseEvent(
                    $case,
                    $actor,
                    'opened',
                    null,
                    SupportCase::STATUS_OPEN,
                    null,
                    'Linked to company by administrator with '.strtoupper($case->priority).' priority.'
                        .($case->sla_due_at ? ' SLA due '.$case->sla_due_at->toIso8601String().'.' : '')
                );

                return $case;
            }, 3);
        } catch (QueryException $e) {
            $case = SupportCase::query()->where('source_box', $box)->where('source_uid', $uid)->first();
            if (! $case) {
                throw $e;
            }
            abort_unless((int) $case->company_id === (int) $company->id, 409, 'This mailbox message is already linked to another company.');
        }

        return redirect()->route('saas.admin.support-inbox.show', ['box' => $box, 'uid' => $uid])
            ->with('success', "Support case #{$case->id} linked to {$company->name}.");
    }

    public function assignCase(Request $request, SupportCase $supportCase)
    {
        $actor = $this->guardSuperAdmin();
        $data = $request->validate([
            'assigned_admin_id' => ['required', 'integer'],
            'priority' => ['nullable', 'in:'.implode(',', SupportCase::PRIORITIES)],
            'sla_due_at' => ['nullable', 'date'],
        ]);
        $owner = AdminUser::query()
            ->whereKey((int) $data['assigned_admin_id'])
            ->whereIn('role', ['admin', 'super_admin'])
            ->firstOrFail();

        DB::transaction(function () use ($supportCase, $actor, $owner, $data) {
            $case = SupportCase::query()->lockForUpdate()->findOrFail($supportCase->id);
            $priority = $data['priority'] ?? $case->priority ?? SupportCase::PRIORITY_NORMAL;
            $nextSla = array_key_exists('sla_due_at', $data)
                ? (! empty($data['sla_due_at']) ? Carbon::parse($data['sla_due_at']) : null)
                : $case->sla_due_at;
            $ownerChanged = (int) $case->assigned_admin_id !== (int) $owner->id;
            $priorityChanged = $case->priority !== $priority;
            $slaChanged = $case->sla_due_at?->getTimestamp() !== $nextSla?->getTimestamp();
            if (! $ownerChanged && ! $priorityChanged && ! $slaChanged) {
                return;
            }
            $previousPriority = $case->priority;
            $previousSla = $case->sla_due_at;
            $case->assigned_admin_id = $owner->id;
            $case->priority = $priority;
            $case->sla_due_at = $nextSla;
            $case->save();
            if ($ownerChanged) {
                $this->recordCaseEvent($case, $actor, 'assigned', $case->status, $case->status, null, "Assigned to {$owner->name}.");
            }
            if ($priorityChanged) {
                $this->recordCaseEvent(
                    $case,
                    $actor,
                    'priority_changed',
                    $case->status,
                    $case->status,
                    null,
                    'Priority changed from '.strtoupper($previousPriority).' to '.strtoupper($priority).'.'
                );
            }
            if ($slaChanged) {
                $this->recordCaseEvent(
                    $case,
                    $actor,
                    'sla_changed',
                    $case->status,
                    $case->status,
                    null,
                    'SLA due changed from '.($previousSla?->toIso8601String() ?? 'UNSET')
                        .' to '.($nextSla?->toIso8601String() ?? 'UNSET').'.'
                );
            }
        }, 3);

        return back()->with('success', 'Support case owner, priority and SLA updated.');
    }

    public function resolveCase(Request $request, SupportCase $supportCase)
    {
        $actor = $this->guardSuperAdmin();
        $data = $request->validate([
            'resolution_build' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._-]+$/'],
            'resolution_note' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        DB::transaction(function () use ($supportCase, $actor, $data) {
            $case = SupportCase::query()->lockForUpdate()->findOrFail($supportCase->id);
            abort_if($case->status === SupportCase::STATUS_RESOLVED, 409, 'Support case is already resolved.');
            abort_unless(in_array($case->status, [SupportCase::STATUS_OPEN, SupportCase::STATUS_REOPENED], true), 409);
            $from = $case->status;
            $case->forceFill([
                'status' => SupportCase::STATUS_RESOLVED,
                'resolution_build' => $data['resolution_build'],
                'resolved_by_admin_id' => $actor->id,
                'resolved_at' => now(),
            ])->save();
            $this->recordCaseEvent(
                $case,
                $actor,
                'resolved',
                $from,
                SupportCase::STATUS_RESOLVED,
                $data['resolution_build'],
                $data['resolution_note']
            );
        }, 3);

        return back()->with('success', 'Support case explicitly resolved. Email reply alone did not change its status.');
    }

    public function reopenCase(Request $request, SupportCase $supportCase)
    {
        $actor = $this->guardSuperAdmin();
        $data = $request->validate([
            'reopen_note' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        DB::transaction(function () use ($supportCase, $actor, $data) {
            $case = SupportCase::query()->lockForUpdate()->findOrFail($supportCase->id);
            abort_unless($case->status === SupportCase::STATUS_RESOLVED, 409, 'Only a resolved case can be reopened.');
            $case->forceFill([
                'status' => SupportCase::STATUS_REOPENED,
                'reopened_by_admin_id' => $actor->id,
                'reopened_at' => now(),
            ])->save();
            $this->recordCaseEvent(
                $case,
                $actor,
                'reopened',
                SupportCase::STATUS_RESOLVED,
                SupportCase::STATUS_REOPENED,
                $case->resolution_build,
                $data['reopen_note']
            );
        }, 3);

        return back()->with('success', 'Support case reopened; its earlier resolution remains in the timeline.');
    }

    /** Immutable related/duplicate evidence; never merge or resolve a case automatically. */
    public function linkCase(Request $request, SupportCase $supportCase)
    {
        $actor = $this->guardSuperAdmin();
        abort_unless(\Illuminate\Support\Facades\Schema::hasTable('support_case_links'), 503);
        abort_unless($supportCase->company_id, 404);
        $data = $request->validate([
            'related_case_id' => ['required', 'integer', 'min:1'],
            'kind' => ['required', 'in:related,duplicate'],
            'note' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        abort_if((int) $data['related_case_id'] === (int) $supportCase->id, 422, 'Choose a different case.');
        DB::transaction(function () use ($supportCase, $actor, $data) {
            // Lock in canonical order to serialize reversed concurrent requests.
            $ids = [(int) $supportCase->id, (int) $data['related_case_id']];
            sort($ids);
            $cases = SupportCase::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            abort_unless($cases->count() === 2 && $cases->every(fn ($c) => (int) $c->company_id === (int) $supportCase->company_id), 404);
            $created = DB::table('support_case_links')->insertOrIgnore([
                'company_id' => $supportCase->company_id, 'case_id' => $ids[0], 'related_case_id' => $ids[1],
                'kind' => $data['kind'], 'note' => $data['note'], 'actor_admin_id' => $actor->id, 'created_at' => now(),
            ]);
            if ($created) {
                foreach ($cases as $case) {
                    $other = $ids[0] === (int) $case->id ? $ids[1] : $ids[0];
                    $this->recordCaseEvent($case, $actor, 'case_linked', $case->status, $case->status, null,
                        ucfirst($data['kind']).' case #'.$other.'. '.$data['note']);
                }
            }
        }, 3);

        return back()->with('success', 'Case link retained. Both case statuses and resolution evidence are unchanged.');
    }

    private function caseLinks(SupportCase $case): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('support_case_links')) {
            return [];
        }

        $companyId = $case->company_id ?? $case->company_id_snapshot;
        if (! $companyId) {
            return [];
        }
        $snapshot = \Illuminate\Support\Facades\Schema::hasColumn('support_cases', 'company_id_snapshot');

        return DB::table('support_case_links as link')
            ->join('support_cases as left_case', 'left_case.id', '=', 'link.case_id')
            ->join('support_cases as right_case', 'right_case.id', '=', 'link.related_case_id')
            ->where('link.company_id', $companyId)
            ->whereRaw(($snapshot ? 'COALESCE(left_case.company_id, left_case.company_id_snapshot)' : 'left_case.company_id').' = ?', [$companyId])
            ->whereRaw(($snapshot ? 'COALESCE(right_case.company_id, right_case.company_id_snapshot)' : 'right_case.company_id').' = ?', [$companyId])
            ->where(fn ($q) => $q->where('link.case_id', $case->id)->orWhere('link.related_case_id', $case->id))
            ->orderByDesc('link.id')->limit(50)->get(['link.case_id', 'link.related_case_id', 'link.kind', 'link.note', 'link.created_at'])
            ->map(fn ($link) => ['case_id' => (int) ((int) $link->case_id === (int) $case->id ? $link->related_case_id : $link->case_id),
                'kind' => $link->kind, 'note' => $link->note, 'created_at' => $link->created_at])->all();
    }

    private function recordCaseEvent(
        SupportCase $case,
        AdminUser $actor,
        string $event,
        ?string $from,
        ?string $to,
        ?string $build,
        ?string $note
    ): void {
        SupportCaseEvent::query()->create([
            'support_case_id' => $case->id,
            'actor_admin_id' => $actor->id,
            'event_type' => $event,
            'from_status' => $from,
            'to_status' => $to,
            'resolution_build' => $build,
            'note' => $note,
            'occurred_at' => now(),
        ]);
    }

    /** MIME types that are safe to render inline (image preview / PDF viewer). */
    protected const INLINE_SAFE_MIMES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf',
    ];

    public function attachment(Request $request, string $box, int $uid, int $index)
    {
        $this->guardSuperAdmin();
        abort_unless(in_array($box, ['inbox', 'sent']), 404);
        try {
            $att = $this->mail->getAttachment($box, $uid, $index);
        } catch (\RuntimeException $e) {
            abort(404, $e->getMessage());
        }

        // inline=1 → preview in browser, but ONLY for whitelisted safe types
        // (never inline HTML/SVG etc. — XSS via attachment).
        $inline = $request->query('inline') && in_array(strtolower($att['mime']), self::INLINE_SAFE_MIMES, true);
        $disposition = ($inline ? 'inline' : 'attachment').'; filename="'.str_replace('"', '', $att['name']).'"';

        return response($att['content'], 200, [
            'Content-Type' => $att['mime'],
            'Content-Disposition' => $disposition,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Reply to an inbox message OR compose a new email (no uid). */
    public function send(Request $request)
    {
        $actor = $this->guardSuperAdmin();
        $data = $request->validate([
            'to' => ['required', 'email'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:50000'],
            'in_reply_to' => ['nullable', 'string', 'max:1000'],
            'references' => ['nullable', 'string', 'max:5000'],
            'reply_box' => ['nullable', 'in:inbox,sent'],
            'reply_uid' => ['nullable', 'integer'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:10240'],
        ], [
            'attachments.max' => 'Ek waqt mein zyada se zyada 10 attachments bhej sakte hain.',
            'attachments.*.max' => 'Har attachment ki size 10 MB se kam honi chahiye.',
            'attachments.*.file' => 'Attachment upload theek se nahi hui — dobara koshish karein.',
        ]);

        $payload = [
            'to' => $data['to'],
            'subject' => $data['subject'],
            'body' => $data['body'],
            'in_reply_to' => $data['in_reply_to'] ?? null,
            'references' => $data['references'] ?? null,
        ];
        foreach ($request->file('attachments', []) as $file) {
            $payload['attachments'][] = [
                'name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType() ?: 'application/octet-stream',
                'content' => file_get_contents($file->getRealPath()),
            ];
        }

        try {
            $this->mail->send($payload);
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $timelineRecorded = true;
        if (($data['reply_box'] ?? null) === 'inbox' && ! empty($data['reply_uid']) && ! empty($data['in_reply_to'])) {
            try {
                DB::transaction(function () use ($data, $actor) {
                    $case = SupportCase::query()->where('source_box', 'inbox')->where('source_uid', $data['reply_uid'])
                        ->where('source_message_id', $data['in_reply_to'])->lockForUpdate()->first();
                    if ($case) {
                        $this->recordCaseEvent($case, $actor, 'reply_submitted', $case->status, $case->status, null,
                            'Reply accepted by mail transport. Customer delivery, acknowledgement and resolution are not confirmed.');
                    }
                });
            } catch (QueryException) {
                // SMTP already accepted the message: never report a failed send
                // or invite an automatic retry because the evidence write failed.
                $timelineRecorded = false;
                \Illuminate\Support\Facades\Log::warning('Support reply accepted but case timeline unavailable.');
            }
        }

        $backTo = ! empty($data['reply_uid'])
            ? route('saas.admin.support-inbox.show', ['box' => $data['reply_box'] ?? 'inbox', 'uid' => $data['reply_uid']])
            : route('saas.admin.support-inbox');

        return redirect($backTo)->with('success', 'Email bhej di gayi ('.$data['to'].').'.($timelineRecorded ? '' : ' Case timeline could not be saved; do not resend the email.'));
    }
}
