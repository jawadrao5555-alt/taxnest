<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Company;
use App\Models\Notification;
use App\Models\PaymentProof;
use App\Models\PricingPlan;
use App\Services\SubscriptionAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class AdminPaymentProofController extends Controller
{
    public function index(Request $request)
    {
        if (!Schema::hasTable('payment_proofs')) {
            return view('saas-admin.payment-proofs', [
                'proofs' => collect(),
                'plans' => collect(),
                'status' => 'pending',
                'tableMissing' => true,
            ]);
        }

        $status = $request->get('status', 'pending');

        $query = PaymentProof::with(['company', 'pricingPlan'])->orderByDesc('created_at');
        if (in_array($status, ['pending', 'verified', 'rejected'], true)) {
            $query->where('status', $status);
        }

        $proofs = $query->paginate(20)->appends($request->all());
        $plans = PricingPlan::where('is_trial', false)->orderBy('price')->get();

        return view('saas-admin.payment-proofs', [
            'proofs' => $proofs,
            'plans' => $plans,
            'status' => $status,
            'tableMissing' => false,
        ]);
    }

    public function approve(Request $request, $id)
    {
        $request->validate([
            'pricing_plan_id' => 'required|exists:pricing_plans,id',
            'billing_cycle' => 'required|in:monthly,quarterly,semi_annual,annual,yearly',
        ]);

        $proof = PaymentProof::findOrFail($id);

        // Race-safe: lock the row, bail out if another admin already processed it.
        $subscription = DB::transaction(function () use ($proof, $request) {
            $locked = PaymentProof::where('id', $proof->id)->lockForUpdate()->first();
            if (!$locked || $locked->status !== 'pending') {
                return null;
            }

            $sub = SubscriptionAssignmentService::assign(
                $locked->company_id,
                (int) $request->pricing_plan_id,
                $request->billing_cycle
            );

            $locked->update([
                'status' => 'verified',
                'pricing_plan_id' => $request->pricing_plan_id,
                'billing_cycle' => SubscriptionAssignmentService::normalizeCycle($request->billing_cycle),
                'subscription_id' => $sub->id,
                'verified_by' => auth('admin')->id(),
                'verified_at' => now(),
                'reject_reason' => null,
            ]);

            return $sub;
        });

        if (!$subscription) {
            return back()->with('error', 'This payment proof was already processed.');
        }

        AdminAuditLog::log(auth('admin')->id(), 'Payment proof approved', 'PaymentProof', $proof->id, [
            'company_id' => $proof->company_id,
            'subscription_id' => $subscription->id,
        ]);

        $this->notifyCompany($proof->fresh(['company', 'pricingPlan']), 'approved');

        return back()->with('success', 'Payment approved & subscription activated — company is now unlocked.');
    }

    public function reject(Request $request, $id)
    {
        $request->validate([
            'reject_reason' => 'nullable|string|max:255',
        ]);

        $proof = PaymentProof::findOrFail($id);

        $updated = PaymentProof::where('id', $proof->id)
            ->where('status', 'pending')
            ->update([
                'status' => 'rejected',
                'reject_reason' => $request->reject_reason,
                'verified_by' => auth('admin')->id(),
                'verified_at' => now(),
            ]);

        if (!$updated) {
            return back()->with('error', 'This payment proof was already processed.');
        }

        AdminAuditLog::log(auth('admin')->id(), 'Payment proof rejected', 'PaymentProof', $proof->id, [
            'company_id' => $proof->company_id,
            'reason' => $request->reject_reason,
        ]);

        $this->notifyCompany($proof->fresh(['company', 'pricingPlan']), 'rejected');

        return back()->with('success', 'Payment proof rejected. The company can submit a new one.');
    }

    public function download($id)
    {
        $proof = PaymentProof::findOrFail($id);

        // Retention cleanup removed the file — the row is audit-only now.
        if ($proof->file_pruned_at) {
            abort(410, 'This receipt file was removed by the retention cleanup. The record is kept for audit.');
        }

        if (!$proof->proof_path || !Storage::disk('local')->exists($proof->proof_path)) {
            abort(404, 'Proof file not found.');
        }

        return Storage::disk('local')->download($proof->proof_path);
    }

    /**
     * Close the loop with the company after an admin decision: write an in-app
     * notification row (shown on the DI dashboard + the cross-panel payment
     * banner) and email the company admin. Mirrors the SendTrialReminders
     * pattern — the notification row is written even if mail fails, and any
     * failure here can NEVER break the admin's approve/reject action.
     */
    private function notifyCompany(?PaymentProof $proof, string $decision): void
    {
        try {
            if (!$proof || !$proof->company) {
                return;
            }

            $company = $proof->company;
            $plan = $proof->pricingPlan;

            $productLabel = match ($plan?->product_type ?? 'di') {
                'pos' => 'NestPOS',
                'fbrpos' => 'FBR POS',
                default => 'TaxNest Digital Invoice',
            };
            $cycleLabels = [
                'monthly' => 'Monthly',
                'quarterly' => 'Quarterly',
                'semi_annual' => 'Semi-Annual',
                'annual' => 'Annual',
                'yearly' => 'Annual',
            ];
            $cycleLabel = $cycleLabels[$proof->billing_cycle] ?? ucfirst((string) $proof->billing_cycle);
            $planLine = $plan
                ? ($plan->name . ($cycleLabel ? ' (' . $cycleLabel . ')' : ''))
                : null;

            [$panelName, $ctaUrl] = match ($plan?->product_type ?? 'di') {
                'pos' => ['NestPOS — PRA Point of Sale', url('/pos/login')],
                'fbrpos' => ['Nest FBR POS', url('/fbr-pos/login')],
                default => ['Digital Invoicing', url('/login')],
            };

            if ($decision === 'approved') {
                $title = 'Payment verified — account unlocked';
                $message = 'Your payment has been verified'
                    . ($planLine ? ' for the ' . $planLine . ' package' : '')
                    . '. Your ' . $productLabel . ' account is now unlocked.';
                $subject = 'Payment verified — your TaxNest account is unlocked';
                $headline = 'Good news! Your payment has been verified.';
                $paragraphs = array_values(array_filter([
                    "We have verified the payment you submitted for {$company->name}.",
                    $planLine ? "Package: {$planLine}" : null,
                    "Your {$productLabel} account is now UNLOCKED — you can continue working right away.",
                    'Thank you for choosing TaxNest.',
                ]));
                $ctaLabel = 'Go to My Account';
            } else {
                $reason = trim((string) $proof->reject_reason);
                $reasonLine = $reason !== '' ? $reason : 'No reason specified — please contact support.';
                if (!str_ends_with($reasonLine, '.')) {
                    $reasonLine .= '.';
                }
                $title = 'Payment proof rejected';
                $message = 'Payment rejected: ' . $reasonLine . ' Please submit a new payment proof.';
                $subject = 'Payment proof rejected — action required';
                $headline = 'Your payment proof could not be verified.';
                $paragraphs = array_values(array_filter([
                    "Unfortunately the payment proof you submitted for {$company->name} could not be verified.",
                    "Reason: {$reasonLine}",
                    $planLine ? "Package: {$planLine}" : null,
                    "Please log in to your {$productLabel} account and submit a new payment proof. If you believe this is a mistake, contact our support team on WhatsApp.",
                ]));
                $ctaLabel = 'Log In & Resubmit';
            }

            Notification::create([
                'company_id' => $company->id,
                'type' => $decision === 'approved' ? 'payment_verified' : 'payment_rejected',
                'title' => $title,
                'message' => $message,
                'read' => false,
                'metadata' => [
                    'payment_proof_id' => $proof->id,
                    'product_type' => $plan?->product_type ?? 'di',
                ],
            ]);

            $email = $this->companyRecipientEmail($company);
            if ($email) {
                try {
                    Mail::to($email)->send(new \App\Mail\TrialReminderMail(
                        subjectLine: $subject,
                        companyName: $company->name ?? 'your company',
                        headline: $headline,
                        paragraphs: $paragraphs,
                        ctaUrl: $ctaUrl,
                        ctaLabel: $ctaLabel,
                        panelName: $panelName,
                    ));
                } catch (\Throwable $e) {
                    Log::warning('Payment decision email failed', [
                        'payment_proof_id' => $proof->id,
                        'company_id' => $company->id,
                        'decision' => $decision,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Payment decision notification failed', [
                'payment_proof_id' => $proof->id ?? null,
                'decision' => $decision,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Company admin first (all three panels register their owner with
     * role=company_admin), then the company email, then any user with one.
     */
    private function companyRecipientEmail(Company $company): ?string
    {
        $admin = $company->users()->where('role', 'company_admin')->orderBy('id')->first();
        if ($admin && $admin->email) {
            return $admin->email;
        }
        if ($company->email) {
            return $company->email;
        }
        $any = $company->users()->whereNotNull('email')->orderBy('id')->first();

        return $any->email ?? null;
    }
}
