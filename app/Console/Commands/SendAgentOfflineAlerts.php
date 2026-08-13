<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Task 630 (Frost & Brew, Aug 2026): the shop PC was switched off overnight,
 * the Desktop Sync Agent went offline, and the cashier only found out the next
 * morning when bills started opening in the Chrome print preview. This command
 * emails the shop owner directly ("aap ka Desktop Agent 2+ ghante se offline
 * hai") so the shop fixes it BEFORE complaining.
 *
 * Rules:
 *  - Only silent-print companies (pos_printer_settings.silent_print_enabled)
 *    with agent_enabled — those are the shops that actually depend on the agent.
 *  - Only active POS-line companies with an ACTIVE subscription (no alerts to
 *    expired/suspended/internal accounts).
 *  - Offline threshold = agent_last_seen older than 2 hours (agentOnline()'s
 *    2-minute window is for print routing; short blips must not email owners).
 *  - ONE email per outage: agent_offline_notified_at is set on send and cleared
 *    the moment the agent is seen again, so a week-long outage never spams and
 *    a NEW outage after recovery notifies again.
 *  - Sent SYNCHRONOUSLY (no queue worker on cPanel), same as trial:reminders.
 *  - Task 634: WhatsApp FIRST from TaxNest's CENTRAL WhatsApp Business number
 *    (Meta-approved utility template, configured on SaaS Admin → Settings),
 *    to the company mobile normalized via PkPhone. Email is the FALLBACK —
 *    sent when central WhatsApp is unconfigured, the mobile is unroutable, or
 *    the WhatsApp send fails. Until the owner completes Meta setup, behaviour
 *    is identical to Task 630 (email only).
 */
class SendAgentOfflineAlerts extends Command
{
    protected $signature = 'pos:agent-offline-alerts';

    protected $description = 'Email shop owners once per outage when their Desktop Agent has been offline >2h (silent-print POS companies).';

    public function handle(): int
    {
        $threshold = now()->subHours(2);

        // Candidates: agent-enabled POS companies that have EVER heartbeat.
        // silent_print_enabled lives inside the pos_printer_settings JSON —
        // filtered in PHP via the normalized printerSettings() accessor
        // (portable across MySQL prod / sqlite tests, tiny candidate set).
        $companies = Company::where('product_type', 'pos')
            ->where('company_status', 'active')
            ->where('is_internal_account', false)
            ->where('agent_enabled', true)
            ->whereNotNull('agent_last_seen')
            ->whereNotNull('pos_printer_settings')
            ->whereHas('subscriptions', fn ($q) => $q->where('active', true))
            ->get();

        $sent = 0;
        $whatsapped = 0;

        foreach ($companies as $company) {
            if (!$company->printerSettings()['silent_print_enabled']) {
                continue;
            }

            $offline = $company->agent_last_seen->lt($threshold);

            if (!$offline) {
                // Agent is back — arm the flag for the NEXT outage.
                if ($company->agent_offline_notified_at !== null) {
                    $company->forceFill(['agent_offline_notified_at' => null])->save();
                }
                continue;
            }

            // Already notified for THIS outage (flag set after the last heartbeat).
            if ($company->agent_offline_notified_at !== null
                && $company->agent_offline_notified_at->gte($company->agent_last_seen)) {
                continue;
            }

            $email = $this->recipientEmail($company);

            $hours = (int) floor($company->agent_last_seen->diffInMinutes(now()) / 60);
            $lastSeen = $company->agent_last_seen->timezone(config('app.timezone'))->format('d M Y, h:i A');

            // WhatsApp first (TaxNest central number, Task 634). Email only as fallback.
            $waSent = false;
            if (\App\Services\WhatsAppBusinessApi::centralConfigured()) {
                $digits = \App\Services\PkPhone::normalize($company->mobile ?: $company->phone);
                if ($digits) {
                    $wa = \App\Services\WhatsAppBusinessApi::sendAgentOfflineAlert(
                        $digits,
                        (string) ($company->name ?? 'your shop'),
                        $hours,
                        $lastSeen,
                    );
                    if ($wa['ok']) {
                        $waSent = true;
                        $whatsapped++;
                    } else {
                        Log::warning('Agent offline alert WhatsApp failed — falling back to email', [
                            'company_id' => $company->id,
                            'error' => $wa['error'] ?? 'unknown',
                        ]);
                    }
                } else {
                    Log::warning('Agent offline alert: company mobile not routable for WhatsApp', ['company_id' => $company->id]);
                }
            }

            if (!$waSent && !$email) {
                // No reachable channel — mark anyway so we don't re-scan forever;
                // the in-app Notification row below still covers the panel bell.
                Log::warning('Agent offline alert: no recipient email', ['company_id' => $company->id]);
            }

            if (!$waSent && $email) {
                try {
                    Mail::to($email)->send(new \App\Mail\TrialReminderMail(
                        subjectLine: 'NestPOS Desktop Agent offline hai — printing ruk sakti hai',
                        companyName: $company->name ?? 'your shop',
                        headline: 'Aap ka Desktop Agent offline hai',
                        paragraphs: [
                            "Aap ki shop ({$company->name}) ka NestPOS Desktop Agent taqreeban {$hours} ghante se offline hai (aakhri raabta: {$lastSeen}).",
                            'Jab tak agent offline hai, bills silent-print nahi hon ge — cashier ko Chrome ka print popup nazar aaye ga.',
                            'Barah-e-karam counter wala PC on karein aur check karein ke NestPOS Desktop Agent chal raha hai. Agent dobara online hote hi sab khud theek ho jaye ga.',
                        ],
                        ctaUrl: url('/pos/login'),
                        ctaLabel: 'POS Panel Kholein',
                        panelName: 'NestPOS — PRA Point of Sale',
                    ));

                    \App\Services\MailHealth::recordSuccess();
                    $sent++;
                } catch (\Throwable $e) {
                    Log::warning('Agent offline alert email failed', [
                        'company_id' => $company->id,
                        'error' => $e->getMessage(),
                    ]);
                    \App\Services\MailHealth::recordFailure('Agent offline alert email', $e);
                }
            }

            // In-app notification for the panel bell (written even if mail
            // failed — same convention as trial:reminders).
            Notification::create([
                'company_id' => $company->id,
                'type' => 'agent_offline_' . now()->format('YmdHi'),
                'title' => 'Desktop Agent offline',
                'message' => "Aap ka NestPOS Desktop Agent {$hours}+ ghante se offline hai — PC/agent chalu karein.",
                'read' => false,
            ]);

            // Flag AFTER the attempt — set even on mail failure so a broken
            // mailer can't turn into a per-run retry storm for the same outage.
            $company->forceFill(['agent_offline_notified_at' => now()])->save();
        }

        $this->info("Agent offline alerts processed. WhatsApp sent: {$whatsapped}, emails sent: {$sent}.");

        return self::SUCCESS;
    }

    /** Same resolution order as trial:reminders — company_admin, company email, any user. */
    private function recipientEmail(Company $company): ?string
    {
        $admin = $company->users()->where('role', 'company_admin')->first();
        if ($admin && $admin->email) {
            return $admin->email;
        }
        if ($company->email) {
            return $company->email;
        }
        $any = $company->users()->whereNotNull('email')->first();

        return $any->email ?? null;
    }
}
