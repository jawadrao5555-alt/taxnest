<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\PaymentProof;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * Reminds admins about STILL-PENDING payment proofs whose auto-granted
 * 10-day temporary access expires within 2 days. Without this, the
 * expired-grant reconciler silently locks a genuinely paying customer out
 * on day 10 just because nobody got to the review queue.
 *
 * Mirrors the SendTrialReminders pattern: runs SYNCHRONOUSLY from the
 * scheduler (no queue worker required on cPanel), swallows mail failures
 * with a log line, and de-duplicates via the notifications table so each
 * proof+expiry-date reminds at most once.
 */
class SendPaymentProofExpiryReminders extends Command
{
    protected $signature = 'payment-proofs:expiry-reminders';

    protected $description = 'Email admins about pending payment proofs whose auto-granted temporary access expires within 2 days.';

    public function handle(): int
    {
        if (!Schema::hasTable('payment_proofs') || !Schema::hasColumn('payment_proofs', 'auto_access_until')) {
            $this->info('payment_proofs table/column not present — nothing to do.');

            return self::SUCCESS;
        }

        // Include already-lapsed pending grants from the last day too: if the
        // scheduler was down over the boundary, the admin still hears about it
        // once instead of never.
        $proofs = PaymentProof::with(['company', 'pricingPlan'])
            ->where('status', 'pending')
            ->whereNotNull('auto_access_until')
            ->whereBetween('auto_access_until', [now()->subDay(), now()->addDays(2)->endOfDay()])
            ->orderBy('auto_access_until')
            ->get()
            // Never nag about internal test accounts.
            ->filter(fn ($p) => !($p->company?->is_internal_account));

        if ($proofs->isEmpty()) {
            $this->info('No soon-expiring pending payment proofs.');

            return self::SUCCESS;
        }

        $emails = \App\Models\AdminUser::whereNotNull('email')
            ->where('email', '!=', '')
            ->pluck('email')->unique()->values();

        $sent = 0;

        foreach ($proofs as $proof) {
            // Dedup per proof + expiry date: one reminder each, but if an admin
            // extends the access, the NEW date warns again.
            $dedupType = 'proof_expiry_admin_reminder_' . $proof->id . '_' . $proof->auto_access_until->toDateString();
            if (Notification::where('company_id', $proof->company_id)->where('type', $dedupType)->exists()) {
                continue;
            }

            $companyName = $proof->company->name ?? ('Company #' . $proof->company_id);
            $until = $proof->auto_access_until->format('d M Y');
            $daysLeft = (int) now()->startOfDay()->diffInDays($proof->auto_access_until->copy()->startOfDay(), false);
            $when = $daysLeft <= 0 ? 'TODAY' : ($daysLeft === 1 ? 'tomorrow' : "in {$daysLeft} days");

            if ($emails->isNotEmpty()) {
                try {
                    $plan = $proof->pricingPlan;
                    $body = "REMINDER: a payment receipt is still awaiting review and the customer's temporary access is about to run out.\n\n"
                        . "Company: {$companyName}\n"
                        . ($plan ? "Package: {$plan->name}\n" : '')
                        . ($proof->amount !== null ? 'Amount: PKR ' . number_format((float) $proof->amount) . "\n" : '')
                        . 'Submitted: ' . $proof->created_at->format('d M Y') . "\n"
                        . "Temporary access ends: {$until} ({$when})\n\n"
                        . "If this is not verified before then, the customer will be LOCKED OUT automatically even if they genuinely paid.\n\n"
                        . 'Review: ' . route('saas.admin.payment-proofs') . "\n\n"
                        . 'TaxNest';

                    Mail::raw($body, function ($m) use ($emails, $companyName, $when) {
                        $m->to($emails->all())->subject("Action needed: {$companyName}'s temporary access ends {$when} — payment proof still pending");
                    });

                    \App\Services\MailHealth::recordSuccess();
                } catch (\Throwable $e) {
                    Log::warning('Payment proof expiry reminder email failed', [
                        'proof_id' => $proof->id,
                        'error' => $e->getMessage(),
                    ]);

                    \App\Services\MailHealth::recordFailure('Payment proof expiry reminder', $e);
                }
            }

            // Dedup row is written even if mail failed — a misconfigured mailer
            // must not turn into a daily retry storm; the sidebar/dashboard
            // badge still flags the proof (mirrors SendTrialReminders).
            Notification::create([
                'company_id' => $proof->company_id,
                'type' => $dedupType,
                'title' => 'Payment proof expiring soon (admin reminder)',
                'message' => "Admin reminder sent: pending payment proof #{$proof->id} temporary access ends {$until}.",
                'read' => true,
            ]);

            $sent++;
        }

        $this->info("Payment proof expiry reminders processed. Reminders sent: {$sent}.");

        return self::SUCCESS;
    }
}
