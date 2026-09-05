<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Notification;
use App\Models\Subscription;
use App\Services\SubscriptionAccessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends trial-ending reminder emails to companies that are close to their
 * day or invoice limit. Runs SYNCHRONOUSLY from the scheduler (no queue
 * worker required on cPanel). De-duplicated via the notifications table so
 * each threshold fires at most once per company.
 */
class SendTrialReminders extends Command
{
    protected $signature = 'trial:reminders';

    protected $description = 'Email trial-ending reminders (day/invoice thresholds) — sync, deduped via notifications.';

    public function handle(): int
    {
        $subs = Subscription::where('active', true)
            ->whereNotNull('trial_ends_at')
            ->whereHas('pricingPlan', fn ($q) => $q->where('is_trial', true))
            ->whereHas('company', fn ($q) => $q->where('is_internal_account', false))
            ->with('company')
            ->get();

        $sent = 0;

        foreach ($subs as $sub) {
            $company = $sub->company;
            if (!$company) {
                continue;
            }

            $status = SubscriptionAccessService::trialStatus($company);
            if (!$status || !$status['on_trial']) {
                continue;
            }

            $email = $this->recipientEmail($company);
            if (!$email) {
                continue;
            }

            $daysLeft = $status['days_left'];
            $invLeft = $status['invoices_left'];

            // 2-day early warning (owner, 1 Aug 2026). Dedup type kept so
            // companies already mailed at the old 1-day threshold get no repeat.
            if ($daysLeft !== null && $daysLeft <= 2) {
                $when = $daysLeft <= 0 ? 'today' : "in {$daysLeft} day(s)";
                if ($this->fire(
                    $company,
                    'trial_reminder_day_1',
                    $email,
                    'Your TaxNest free trial is ending soon',
                    'Trial ending soon',
                    "Your free trial for {$company->name} is ending {$when}.",
                    [
                        'To keep your invoicing running without interruption, please subscribe to a plan.',
                        'Log in to your account and open the Billing page to choose the package that fits your business.',
                    ]
                )) {
                    $sent++;
                }
            }

            if ($invLeft !== null && $invLeft <= 5) {
                if ($this->fire(
                    $company,
                    'trial_reminder_inv_5',
                    $email,
                    'Your TaxNest free trial invoices are almost used up',
                    'Trial invoices almost used',
                    "Your free trial for {$company->name} has only {$invLeft} free invoice(s) remaining.",
                    [
                        'To keep creating invoices without interruption, please subscribe to a plan.',
                        'Log in to your account and open the Billing page to choose the package that fits your business.',
                    ]
                )) {
                    $sent++;
                }
            }
        }

        // ---- Temporary/grace override ending soon (owner, 1 Aug 2026) ----
        // Default window = 2 days. FBR POS shops on a TEMPORARY grant get a
        // 7-day early warning (free-access expiry reminder, Aug 2026) so they
        // can decide on a Business/Pro upgrade in time.
        $ovSubs = Subscription::where('active', true)
            ->whereIn('override_type', ['temporary', 'grace'])
            ->whereNotNull('override_until')
            ->whereBetween('override_until', [now(), now()->addDays(7)->endOfDay()])
            ->whereHas('company', fn ($q) => $q->where('is_internal_account', false))
            ->with('company')
            ->get();

        foreach ($ovSubs as $sub) {
            $company = $sub->company;
            $window = ($company && $company->product_type === 'fbrpos' && $sub->override_type === 'temporary') ? 7 : 2;
            if ($sub->override_until->gt(now()->addDays($window)->endOfDay())) {
                continue;
            }
            $email = $company ? $this->recipientEmail($company) : null;
            if (!$email) {
                continue;
            }
            $until = $sub->override_until->format('d M Y');
            // Dedup per expiry date so an extended override warns again before the NEW date.
            if ($this->fire(
                $company,
                'override_reminder_' . $sub->override_until->toDateString(),
                $email,
                'Your TaxNest access is ending soon',
                'Access ending soon',
                "The free access granted to {$company->name} ends on {$until}.",
                [
                    'To keep your billing running without interruption, please subscribe to a plan before that date.',
                    'Log in to your account and open the Billing page to choose the package that fits your business.',
                ]
            )) {
                $sent++;
            }
        }

        // ---- Paid subscription ending within 2 days (owner, 1 Aug 2026) ----
        $paidSubs = Subscription::where('active', true)
            ->where('override_type', 'none')
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [now()->startOfDay(), now()->addDays(2)->endOfDay()])
            ->whereHas('pricingPlan', fn ($q) => $q->where('is_trial', false))
            ->whereHas('company', fn ($q) => $q->where('is_internal_account', false))
            ->with(['company', 'pricingPlan'])
            ->get();

        foreach ($paidSubs as $sub) {
            $company = $sub->company;
            $email = $company ? $this->recipientEmail($company) : null;
            if (!$email) {
                continue;
            }
            $until = \Carbon\Carbon::parse($sub->end_date)->format('d M Y');

            $paragraphs = [
                'Please renew before that date to keep your billing running without interruption.',
            ];
            // POS packages were repriced on 02 Aug 2026: existing subscriptions
            // stay on the old rate until end_date, renewals charge the NEW rate.
            // Quote the plan's CURRENT rate so the renewal price is no surprise.
            // POS product line only for now (DI/FBR POS rates unchanged).
            foreach ($this->renewalRateLines($company, $sub->pricingPlan) as $line) {
                $paragraphs[] = $line;
            }
            $paragraphs[] = 'Log in to your account and open the Billing page to renew your package.';

            // Dedup per end_date so each renewal period warns once.
            if ($this->fire(
                $company,
                'sub_renewal_reminder_' . \Carbon\Carbon::parse($sub->end_date)->toDateString(),
                $email,
                'Your TaxNest subscription is ending soon',
                'Subscription ending soon',
                "The subscription for {$company->name} ends on {$until}.",
                $paragraphs
            )) {
                $sent++;
            }
        }

        $this->info("Trial reminders processed. Emails sent: {$sent}.");

        return self::SUCCESS;
    }

    /**
     * Fire one threshold reminder. Deduped via notifications.type per company.
     * The notification row is written even if mail fails, so a misconfigured
     * mailer cannot turn into a daily retry storm — the in-app banner still covers it.
     * Sends the branded HTML mailable synchronously (no queue worker on cPanel).
     */
    private function fire(Company $company, string $type, string $email, string $subject, string $title, string $headline, array $paragraphs): bool
    {
        if (Notification::where('company_id', $company->id)->where('type', $type)->exists()) {
            return false;
        }

        [$panelName, $ctaUrl] = $this->panelCta($company);

        try {
            Mail::to($email)->send(new \App\Mail\TrialReminderMail(
                subjectLine: $subject,
                companyName: $company->name ?? 'your company',
                headline: $headline,
                paragraphs: $paragraphs,
                ctaUrl: $ctaUrl,
                ctaLabel: 'Subscribe Now',
                panelName: $panelName,
            ));

            \App\Services\MailHealth::recordSuccess();
        } catch (\Throwable $e) {
            Log::warning('Trial reminder email failed', [
                'company_id' => $company->id,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            \App\Services\MailHealth::recordFailure('Trial reminder email', $e);
        }

        Notification::create([
            'company_id' => $company->id,
            'type' => $type,
            'title' => $title,
            'message' => $subject,
            'read' => false,
        ]);

        return true;
    }

    /**
     * Renewal-rate notice lines for the subscription-ending email.
     *
     * POS packages were repriced on 02 Aug 2026 (existing subscriptions keep
     * the old rate until end_date; renewals charge the NEW rate), so the
     * reminder quotes the plan's CURRENT annual rate from pricing_plans
     * (sale-adjusted, like every charge path). Annual is the only cycle sold
     * since 23 Aug 2026. POS product line only.
     *
     * @return string[]
     */
    private function renewalRateLines(Company $company, ?\App\Models\PricingPlan $plan): array
    {
        if (($company->product_type ?? 'di') !== 'pos' || !$plan) {
            return [];
        }

        $annual = (float) $plan->sale_price;
        if ($annual <= 0) {
            return [];
        }

        return ["Renewal rate for your {$plan->name} plan: Rs " . number_format($annual) . ' per year.'];
    }

    /**
     * Map the company's product line to its panel name + login URL so the CTA
     * always lands the user on the RIGHT panel (guards are isolated — a DI
     * link would show "Invalid credentials" to a POS-only account).
     */
    private function panelCta(Company $company): array
    {
        // Nest ERPS (Task 1568) reaches its own vertical's login page through
        // the catalogue. Left to the old `default` an ERPS organisation was
        // mailed the Digital Invoice portal, where its guard-isolated account
        // can only ever answer "Invalid credentials".
        [, $panelName, $ctaUrl] = \App\Support\ProductCatalog::cta(
            $company->product_type ?? 'di',
            \App\Support\NestErps::verticalOf($company)
        );

        return [$panelName, $ctaUrl];
    }

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
