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

            if ($daysLeft !== null && $daysLeft <= 1) {
                $when = $daysLeft <= 0 ? 'today' : "in {$daysLeft} day(s)";
                $body = "Assalam-o-Alaikum,\n\n"
                    . "Your TaxNest free trial for {$company->name} is ending {$when}.\n\n"
                    . "To keep your invoicing running without interruption, please subscribe to a plan.\n\n"
                    . "Team TaxNest";
                if ($this->fire($company, 'trial_reminder_day_1', $email, 'Your TaxNest free trial is ending soon', $body, 'Trial ending soon')) {
                    $sent++;
                }
            }

            if ($invLeft !== null && $invLeft <= 5) {
                $body = "Assalam-o-Alaikum,\n\n"
                    . "Your TaxNest free trial for {$company->name} has only {$invLeft} free invoice(s) remaining.\n\n"
                    . "To keep creating invoices without interruption, please subscribe to a plan.\n\n"
                    . "Team TaxNest";
                if ($this->fire($company, 'trial_reminder_inv_5', $email, 'Your TaxNest free trial invoices are almost used up', $body, 'Trial invoices almost used')) {
                    $sent++;
                }
            }
        }

        $this->info("Trial reminders processed. Emails sent: {$sent}.");

        return self::SUCCESS;
    }

    /**
     * Fire one threshold reminder. Deduped via notifications.type per company.
     * The notification row is written even if mail fails, so a misconfigured
     * mailer cannot turn into a daily retry storm — the in-app banner still covers it.
     */
    private function fire(Company $company, string $type, string $email, string $subject, string $body, string $title): bool
    {
        if (Notification::where('company_id', $company->id)->where('type', $type)->exists()) {
            return false;
        }

        try {
            Mail::raw($body, function ($m) use ($email, $subject) {
                $m->to($email)->subject($subject);
            });
        } catch (\Throwable $e) {
            Log::warning('Trial reminder email failed', [
                'company_id' => $company->id,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
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
