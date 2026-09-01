<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Services\SupportMailService;
use Illuminate\Http\Request;

/**
 * Support Inbox — read/reply support@taxnest.pk inside the admin panel.
 * Super-admin only (guarded in each action; admin.auth handles login).
 */
class SupportInboxController extends Controller
{
    public function __construct(protected SupportMailService $mail)
    {
    }

    protected function guardSuperAdmin(): void
    {
        $u = auth('admin')->user();
        abort_unless($u && ($u->role ?? null) === 'super_admin', 403);
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

        return view('saas-admin.support-inbox.show', [
            'box' => $box,
            'message' => $message,
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
        $this->guardSuperAdmin();
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

        $backTo = ! empty($data['reply_uid'])
            ? route('saas.admin.support-inbox.show', ['box' => $data['reply_box'] ?? 'inbox', 'uid' => $data['reply_uid']])
            : route('saas.admin.support-inbox');

        return redirect($backTo)->with('success', 'Email bhej di gayi ('.$data['to'].').');
    }
}
