<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Notification;
use App\Models\Subscription;
use App\Services\PlanLimitService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * Sep 2026 DI restructure — fair-use watch on the "Unlimited" package.
 *
 * Unlimited genuinely means unlimited: this command NEVER blocks, throttles or
 * warns the shop. Its only job is to tell the office when one account is
 * running far past what the package assumes (fair_use_limit on the plan row),
 * so somebody can pick up the phone and talk about a custom arrangement
 * instead of discovering it months later in the invoice counts.
 *
 * Mirrors SendPaymentProofExpiryReminders: runs SYNCHRONOUSLY from the
 * scheduler (cPanel has no queue worker), swallows mail failures with a log
 * line, and de-duplicates through the notifications table so each company
 * raises at most ONE alert per calendar month. Crossing again next month
 * alerts again.
 */
class SendDiFairUseAlerts extends Command
{
    protected $signature = 'di:fair-use-alerts';

    protected $description = 'Email the office when a Digital Invoice shop on an unlimited package passes its fair-use ceiling this month.';

    public function handle(): int
    {
        if (!Schema::hasColumn('pricing_plans', 'fair_use_limit')) {
            $this->info('fair_use_limit column not present — nothing to do.');

            return self::SUCCESS;
        }

        // Only live, paid-for packages that are BOTH unlimited and carry a
        // ceiling. A package with a numeric invoice_limit already stops itself.
        $subscriptions = Subscription::with(['pricingPlan', 'company'])
            ->where('active', true)
            ->whereHas('pricingPlan', function ($q) {
                $q->where('product_type', 'di')
                    ->where('invoice_limit', -1)
                    ->whereNotNull('fair_use_limit')
                    ->where('fair_use_limit', '>', 0);
            })
            ->get();

        if ($subscriptions->isEmpty()) {
            $this->info('No unlimited Digital Invoice packages with a fair-use ceiling.');

            return self::SUCCESS;
        }

        $emails = \App\Models\AdminUser::whereNotNull('email')
            ->where('email', '!=', '')
            ->pluck('email')->unique()->values();

        $month = now()->format('Y-m');
        $sent = 0;

        foreach ($subscriptions as $sub) {
            $company = $sub->company;
            $plan = $sub->pricingPlan;

            if (!$company || !$plan) {
                continue;
            }

            // Internal / demo accounts are ours, not customers to call.
            if ($company->is_internal_account) {
                continue;
            }

            // active=true and "still valid" are two different things: an
            // expired row keeps the flag until the reconcile job demotes it.
            // Invoicing is already gated on expiry, so alerting on one would
            // send the office after a shop that is not even billing.
            if ($sub->isExpired()) {
                continue;
            }

            $limit = (int) $plan->fair_use_limit;
            $count = PlanLimitService::monthlyInvoiceCount($company->id);

            if ($count < $limit) {
                continue;
            }

            $dedupType = 'di_fair_use_admin_alert_' . $month;
            if (Notification::where('company_id', $company->id)->where('type', $dedupType)->exists()) {
                continue;
            }

            $companyName = $company->name ?: ('Company #' . $company->id);
            $monthLabel = now()->format('F Y');

            if ($emails->isNotEmpty()) {
                try {
                    $body = "FAIR-USE NOTICE — a Digital Invoice customer on an unlimited package is running well past the fair-use figure.\n\n"
                        . "Company: {$companyName} (ID {$company->id})\n"
                        . "Package: {$plan->name}\n"
                        . "Month: {$monthLabel}\n"
                        . 'FBR-submitted invoices so far: ' . number_format($count) . "\n"
                        . 'Fair-use figure on the package: ' . number_format($limit) . "\n\n"
                        . "NOTHING HAS BEEN BLOCKED. The shop is invoicing normally and will keep doing so.\n"
                        . "This is only a heads-up so the office can decide whether to discuss a custom arrangement.\n\n"
                        . 'Company: ' . route('saas.admin.companies.show', $company->id) . "\n\n"
                        . 'TaxNest';

                    Mail::raw($body, function ($m) use ($emails, $companyName, $monthLabel) {
                        $m->to($emails->all())
                            ->subject("Fair use: {$companyName} is past its unlimited-package figure ({$monthLabel})");
                    });

                    \App\Services\MailHealth::recordSuccess();
                } catch (\Throwable $e) {
                    Log::warning('DI fair-use alert email failed', [
                        'company_id' => $company->id,
                        'error' => $e->getMessage(),
                    ]);

                    \App\Services\MailHealth::recordFailure('DI fair-use alert', $e);
                }
            }

            // Written even when mail failed — a broken mailer must not turn
            // into a daily retry storm. The row itself is the record that this
            // month's crossing was seen.
            Notification::create([
                'company_id' => $company->id,
                'type' => $dedupType,
                'title' => 'Fair-use notice (office alert)',
                'message' => "{$companyName} passed the fair-use figure for {$plan->name} in {$monthLabel}: "
                    . number_format($count) . ' of ' . number_format($limit) . ' invoices. Nothing was blocked.',
                'read' => true,
            ]);

            $sent++;
        }

        $this->info("Fair-use alerts raised: {$sent}");

        return self::SUCCESS;
    }
}
