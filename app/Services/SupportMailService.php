<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\IMAP;

/**
 * Support Inbox service: reads support@ mailbox over IMAP (pure-PHP webklex
 * library — no ext-imap needed on live cPanel) and sends replies over SMTP,
 * appending sent mail to the IMAP Sent folder so webmail stays in sync.
 *
 * All methods that touch the network throw \RuntimeException with a
 * human-friendly message when the mailbox is not configured/reachable.
 */
class SupportMailService
{
    public const UNREAD_CACHE_KEY = 'support_inbox_unread_count';

    protected ?Client $client = null;

    public function isConfigured(): bool
    {
        return (bool) config('support_mail.password');
    }

    protected function client(): Client
    {
        if ($this->client && $this->client->isConnected()) {
            return $this->client;
        }
        if (! $this->isConfigured()) {
            throw new \RuntimeException('Support mailbox password is not configured (SUPPORT_MAIL_PASSWORD).');
        }
        $cfg = config('support_mail');
        $cm = new ClientManager();
        $this->client = $cm->make([
            'host' => $cfg['host'],
            'port' => $cfg['imap_port'],
            'encryption' => 'ssl',
            'validate_cert' => $cfg['validate_cert'],
            'username' => $cfg['username'],
            'password' => $cfg['password'],
            'protocol' => 'imap',
            'timeout' => 20,
            'options' => [
                // PEEK: never mark messages read implicitly; we mark explicitly.
                'fetch' => IMAP::FT_PEEK,
                'fetch_body' => true,
                'soft_fail' => false,
            ],
        ]);
        try {
            $this->client->connect();
        } catch (\Throwable $e) {
            $this->client = null;
            Log::warning('Support inbox IMAP connect failed: '.$e->getMessage());
            throw new \RuntimeException('Mail server se rabta nahi ho saka (IMAP). Thori dair baad koshish karein.');
        }

        return $this->client;
    }

    /** Resolve the real IMAP folder for a logical box: 'inbox' or 'sent'. */
    protected function folder(string $box)
    {
        $client = $this->client();
        if ($box === 'inbox') {
            return $client->getFolderByPath('INBOX');
        }
        // Sent folder name varies (INBOX.Sent on cPanel/Dovecot, "Sent" elsewhere)
        foreach (['INBOX.Sent', 'Sent', 'INBOX.Sent Items', 'Sent Items'] as $name) {
            try {
                $f = $client->getFolderByPath($name);
                if ($f) {
                    return $f;
                }
            } catch (\Throwable $e) {
                // try next candidate
            }
        }
        // Last resort: scan the folder tree for something sent-like.
        foreach ($client->getFolders(false) as $f) {
            if (preg_match('/sent/i', $f->full_name)) {
                return $f;
            }
        }
        throw new \RuntimeException('Sent folder not found on the mail server.');
    }

    /**
     * List messages for a box. Returns:
     * ['messages' => array<mixed>, 'total' => int, 'page' => int, 'last_page' => int]
     */
    public function listMessages(string $box = 'inbox', int $page = 1): array
    {
        $perPage = (int) config('support_mail.per_page', 20);
        $folder = $this->folder($box);
        $query = $folder->messages()->all()->setFetchBody(false)->setFetchOrderDesc();
        $paginator = $query->paginate($perPage, $page, 'page');
        $rows = [];
        foreach ($paginator as $msg) {
            $rows[] = $this->summarize($msg);
        }
        $total = $paginator->total();

        if ($box === 'inbox') {
            // Refresh the cached unread badge while we're connected anyway.
            try {
                $unread = $folder->messages()->unseen()->setFetchBody(false)->count();
                Cache::put(self::UNREAD_CACHE_KEY, (int) $unread, now()->addMinutes(10));
            } catch (\Throwable $e) {
                // badge is best-effort
            }
        }

        return [
            'messages' => $rows,
            'total' => $total,
            'page' => $page,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    protected function summarize($msg): array
    {
        $from = $msg->getFrom()[0] ?? null;
        $to = $msg->getTo()[0] ?? null;
        $date = null;
        try {
            $d = $msg->getDate()->toDate();
            $date = $d ? $d->setTimezone(config('app.timezone')) : null;
        } catch (\Throwable $e) {
        }

        return [
            'uid' => $msg->getUid(),
            'subject' => (string) ($msg->getSubject() ?? '(no subject)'),
            'from_name' => $from->personal ?? '',
            'from_email' => $from->mail ?? '',
            'to_email' => $to->mail ?? '',
            'date' => $date,
            'seen' => $msg->hasFlag('Seen'),
            'has_attachments' => (bool) $msg->hasAttachments(),
        ];
    }

    /** Fetch a message by UID or throw a friendly RuntimeException. */
    protected function findByUid($folder, int $uid)
    {
        try {
            return $folder->messages()->getMessageByUid($uid);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Email nahi mili (shayad delete ho chuki hai).');
        }
    }

    /** Fetch one full message; marks it read in the inbox. */
    public function getMessage(string $box, int $uid): array
    {
        $folder = $this->folder($box);
        $msg = $this->findByUid($folder, $uid);
        $summary = $this->summarize($msg);

        $attachments = [];
        $i = 0;
        foreach ($msg->getAttachments() as $att) {
            $attachments[] = [
                'index' => $i++,
                'name' => $att->getName() ?: ('attachment-'.$i),
                'size' => (int) $att->getSize(),
                'mime' => $att->getMimeType() ?: 'application/octet-stream',
            ];
        }

        $html = $msg->hasHTMLBody() ? (string) $msg->getHTMLBody() : null;
        $text = $msg->hasTextBody() ? (string) $msg->getTextBody() : null;

        if ($box === 'inbox' && ! $summary['seen']) {
            try {
                $msg->setFlag('Seen');
                Cache::forget(self::UNREAD_CACHE_KEY);
            } catch (\Throwable $e) {
            }
        }

        $messageId = trim((string) $msg->getMessageId(), " \t");
        if ($messageId !== '' && ! str_starts_with($messageId, '<')) {
            $messageId = '<'.$messageId.'>';
        }
        $references = '';
        try {
            $refAttr = $msg->getReferences();
            $refs = method_exists($refAttr, 'all') ? $refAttr->all() : (array) $refAttr;
            $references = implode(' ', array_map(
                fn ($r) => str_starts_with((string) $r, '<') ? (string) $r : '<'.$r.'>',
                array_filter(array_map('trim', $refs))
            ));
        } catch (\Throwable $e) {
        }

        return $summary + [
            'html' => $html,
            'text' => $text,
            'message_id' => $messageId,
            'references' => $references,
            'attachments' => $attachments,
        ];
    }

    /** Returns ['name','mime','content'] for streaming a download. */
    public function getAttachment(string $box, int $uid, int $index): array
    {
        $folder = $this->folder($box);
        $msg = $this->findByUid($folder, $uid);
        $i = 0;
        foreach ($msg->getAttachments() as $att) {
            if ($i++ === $index) {
                return [
                    'name' => $att->getName() ?: 'attachment',
                    'mime' => $att->getMimeType() ?: 'application/octet-stream',
                    'content' => (string) $att->getContent(),
                ];
            }
        }
        throw new \RuntimeException('Attachment nahi mili.');
    }

    /**
     * Send an email from support@ via SMTP, then append the raw message to
     * the IMAP Sent folder so it shows in webmail too.
     *
     * $data: to, subject, body (plain text), in_reply_to?, references?,
     *        attachments? (array of ['name','mime','content'])
     */
    public function send(array $data): void
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('Support mailbox password is not configured (SUPPORT_MAIL_PASSWORD).');
        }
        $cfg = config('support_mail');

        $email = (new Email())
            ->from(new Address($cfg['username'], $cfg['from_name']))
            ->to($data['to'])
            ->subject($data['subject'])
            ->text($data['body'])
            ->html(nl2br(e($data['body'])));

        if (! empty($data['in_reply_to'])) {
            $ref = trim(($data['references'] ?? '').' '.$data['in_reply_to']);
            $email->getHeaders()->addTextHeader('In-Reply-To', $data['in_reply_to']);
            $email->getHeaders()->addTextHeader('References', $ref);
        }
        foreach ($data['attachments'] ?? [] as $att) {
            $email->attach($att['content'], $att['name'], $att['mime']);
        }

        // Implicit TLS (smtps) on 465 — same transport style as the working noreply block.
        $transport = new EsmtpTransport($cfg['host'], $cfg['smtp_port'], true);
        $transport->setUsername($cfg['username']);
        $transport->setPassword($cfg['password']);

        try {
            $sent = $transport->send($email);
        } catch (\Throwable $e) {
            Log::warning('Support inbox SMTP send failed: '.$e->getMessage());
            throw new \RuntimeException('Email bhejne mein masla aya (SMTP). Password/connection check karein.');
        }

        // Best-effort append to Sent — failure here must not "unsend" the mail.
        try {
            $raw = $sent ? $sent->toString() : $email->toString();
            $this->folder('sent')->appendMessage($raw, ['\\Seen']);
        } catch (\Throwable $e) {
            Log::warning('Support inbox: could not append to IMAP Sent folder: '.$e->getMessage());
        }
    }

    /** Cached unread badge (never hits IMAP from layout/sidebar). */
    public static function cachedUnreadCount(): int
    {
        try {
            return (int) Cache::get(self::UNREAD_CACHE_KEY, 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
