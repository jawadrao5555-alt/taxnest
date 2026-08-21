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
        $proof = PaymentProof::with('pricingPlan')->findOrFail($id);

        // Extra-branch add-on request: apna raasta — SIRF slots barhte hain.
        // Package, uski miyaad, uski qeemat aur subscription row jyun ki tyun
        // (normal approve subscription DOBARA banata hai — us se bachna zaroori
        // hai, warna chalte hue admin grants toot jate hain).
        if ($proof->isExtraBranch()) {
            return $this->approveExtraBranch($request, $proof);
        }

        $request->validate([
            'pricing_plan_id' => 'required|exists:pricing_plans,id',
            'billing_cycle' => 'required|in:monthly,quarterly,semi_annual,annual,yearly',
        ]);

        $plan = PricingPlan::findOrFail((int) $request->pricing_plan_id);
        if ($plan->is_trial) {
            return back()->with('error', 'Trial plans cannot be assigned from payment approval.');
        }

        // Product-line guard: the approved plan must stay on the same product
        // line the customer submitted for (operator-error protection).
        $proofPlanType = $proof->pricingPlan->product_type ?? null;
        if ($proofPlanType && ($plan->product_type ?? 'di') !== $proofPlanType) {
            return back()->with('error', 'Selected package belongs to a different product line than this payment proof.');
        }

        // Cycle guard: same per-product rules as customer submission
        // (di = 4 cycles, pos = annual+quarterly, others annual-only).
        $allowedCycles = match ($plan->product_type ?? 'di') {
            'di' => ['monthly', 'quarterly', 'semi_annual', 'annual'],
            'pos' => ['annual', 'quarterly'],
            default => ['annual'],
        };
        $requestedCycle = SubscriptionAssignmentService::normalizeCycle($request->billing_cycle);
        if (!in_array($requestedCycle, $allowedCycles, true)) {
            return back()->with('error', 'That billing cycle is not available for the selected package.');
        }

        // Single source of truth: the cycle actually enforced by pricing
        // (e.g. quarterly requested on a plan without a quarterly price
        // silently becomes annual) — the proof row, the subscription and the
        // customer notification must all carry THIS cycle, never raw input.
        $enforcedCycle = SubscriptionAssignmentService::computePrice($plan, $requestedCycle)['cycle'];

        // Race-safe: lock the row, bail out if another admin already processed it.
        $subscription = DB::transaction(function () use ($proof, $plan, $enforcedCycle) {
            $locked = PaymentProof::where('id', $proof->id)->lockForUpdate()->first();
            if (!$locked || $locked->status !== 'pending') {
                return null;
            }

            $sub = SubscriptionAssignmentService::assign(
                $locked->company_id,
                $plan->id,
                $enforcedCycle
            );

            $locked->update([
                'status' => 'verified',
                'pricing_plan_id' => $plan->id,
                'billing_cycle' => $enforcedCycle,
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

        // A verified payment must also unlock the company itself: mirror the
        // admin grant flow (BOTH status columns), never reversing a deliberate
        // suspension/rejection. Covers companies demoted to pending by the
        // expired-grant reconciler before the admin got to this proof.
        $company = Company::find($proof->company_id);
        if ($company
            && !in_array($company->status, ['suspended', 'rejected'], true)
            && !in_array($company->company_status, ['suspended', 'rejected'], true)
            && ($company->status !== 'approved' || $company->company_status !== 'active')) {
            $company->update(['status' => 'approved', 'company_status' => 'active']);
        }

        // Agent commission: a verified proof is the "cleared payment" that
        // earns the introducing agent their Schedule A cut. Never breaks approval.
        \App\Services\AgentCommissionService::recordForProof($proof->fresh());

        AdminAuditLog::log(auth('admin')->id(), 'Payment proof approved', 'PaymentProof', $proof->id, [
            'company_id' => $proof->company_id,
            'subscription_id' => $subscription->id,
        ]);

        $this->notifyCompany($proof->fresh(['company', 'pricingPlan']), 'approved');

        return back()->with('success', 'Payment approved & subscription activated — company is now unlocked.');
    }

    /**
     * Extra-branch add-on approval (Rs 10,000/branch/year).
     *
     * Sirf companies.extra_branch_slots barhta hai — subscription row, plan,
     * miyaad, qeemat aur company status ko bilkul haath nahi lagaya jata.
     * Admin approve karte waqt tadaad theek kar sakta hai (agar shop ne kam/
     * zyada paisa bheja ho).
     */
    private function approveExtraBranch(Request $request, PaymentProof $proof)
    {
        $request->validate([
            'extra_branch_qty' => 'nullable|integer|min:1|max:' . \App\Services\BranchAddonService::MAX_QTY,
        ]);

        if (!\App\Services\BranchAddonService::slotsColumnExists()) {
            return back()->with('error', 'The extra_branch_slots column is missing — run php artisan migrate --force first.');
        }

        $qty = (int) ($request->input('extra_branch_qty') ?: $proof->extra_branch_qty ?: 1);
        $qty = max(1, min(\App\Services\BranchAddonService::MAX_QTY, $qty));

        $result = DB::transaction(function () use ($proof, $qty) {
            $locked = PaymentProof::where('id', $proof->id)->lockForUpdate()->first();
            if (!$locked || $locked->status !== 'pending') {
                return null;
            }

            $company = Company::where('id', $locked->company_id)->lockForUpdate()->first();
            if (!$company) {
                return null;
            }

            $before = \App\Services\BranchAddonService::slots($company);
            $company->update(['extra_branch_slots' => $before + $qty]);

            $locked->update([
                'status' => 'verified',
                'extra_branch_qty' => $qty,
                'verified_by' => auth('admin')->id(),
                'verified_at' => now(),
                'reject_reason' => null,
            ]);

            return ['company' => $company, 'before' => $before, 'after' => $before + $qty];
        });

        if (!$result) {
            return back()->with('error', 'This payment proof was already processed.');
        }

        AdminAuditLog::log(auth('admin')->id(), 'Extra branch slots approved', 'PaymentProof', $proof->id, [
            'company_id' => $proof->company_id,
            'qty' => $qty,
            'slots_before' => $result['before'],
            'slots_after' => $result['after'],
        ]);

        $this->notifyCompany($proof->fresh(['company', 'pricingPlan']), 'approved');

        return back()->with('success', "Extra branch approved — {$result['company']->name} now has {$result['after']} paid branch slot(s). The package and its expiry were not changed.");
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

        // Reject = the auto-granted 10-day bridge access ends immediately.
        $revokedAccess = $this->revokeAutoAccess($proof->fresh());

        AdminAuditLog::log(auth('admin')->id(), 'Payment proof rejected', 'PaymentProof', $proof->id, [
            'company_id' => $proof->company_id,
            'reason' => $request->reject_reason,
            'auto_access_revoked' => $revokedAccess,
        ]);

        $this->notifyCompany($proof->fresh(['company', 'pricingPlan']), 'rejected');

        return back()->with('success', 'Payment proof rejected. The company can submit a new one.');
    }

    /**
     * Revoke the 10-day temporary override that this proof's upload
     * auto-granted (identified by override_by NULL + the proof id in the
     * reason — an ADMIN-granted override is never touched). If the company is
     * left without access, it demotes back to pending (BOTH status columns),
     * mirroring the expired-grant reconciler; deliberate suspensions/rejections
     * and companies with other valid access are left alone.
     */
    private function revokeAutoAccess(?PaymentProof $proof): bool
    {
        try {
            if (!$proof || !$proof->auto_access_until) {
                return false;
            }

            $sub = \App\Models\Subscription::where('company_id', $proof->company_id)
                ->where('active', true)
                ->orderByDesc('id')
                ->first();
            if (!$sub
                || $sub->override_type !== 'temporary'
                || $sub->override_by !== null
                || !str_contains((string) $sub->override_reason, 'payment proof #' . $proof->id)) {
                return false;
            }

            $sub->update([
                'override_type' => 'none',
                'override_until' => null,
                'override_granted_at' => null,
                'free_invoice_limit' => null,
                'override_reason' => null,
                'override_by' => null,
            ]);

            $company = Company::find($proof->company_id);
            if ($company
                && !in_array($company->status, ['suspended', 'rejected'], true)
                && !in_array($company->company_status, ['suspended', 'rejected'], true)
                && !(\App\Services\SubscriptionAccessService::hasAccess($company)['allowed'] ?? false)
                && ($company->status !== 'pending' || $company->company_status !== 'pending')) {
                $company->update(['status' => 'pending', 'company_status' => 'pending']);
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('Auto access revoke on reject failed', [
                'payment_proof_id' => $proof->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
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

            // Extra-branch add-on: package ki baat hi nahi — sirf branch slots.
            if ($proof->isExtraBranch()) {
                $qty = max(1, (int) ($proof->extra_branch_qty ?? 1));
                $total = \App\Services\BranchAddonService::slots($company);

                if ($decision === 'approved') {
                    $title = 'Extra branch approved';
                    $message = "Your extra branch request ({$qty}) has been approved. You can add the new branch now — your package and its expiry date have not changed.";
                    $subject = 'Extra branch approved — you can add it now';
                    $headline = 'Your extra branch request has been approved.';
                    $paragraphs = [
                        "We have verified your payment for {$qty} extra branch(es) for {$company->name}.",
                        "Paid extra branch slots: {$total}.",
                        'Open Branches in your NestPOS panel and add the new branch. Your package, its expiry date and its price stay exactly the same.',
                    ];
                    $ctaLabel = 'Add My Branch';
                } else {
                    $reason = trim((string) $proof->reject_reason);
                    $reasonLine = $reason !== '' ? $reason : 'No reason specified — please contact support.';
                    if (!str_ends_with($reasonLine, '.')) {
                        $reasonLine .= '.';
                    }
                    $title = 'Extra branch request rejected';
                    $message = 'Your extra branch request was rejected: ' . $reasonLine . ' No branch slots were added.';
                    $subject = 'Extra branch request rejected — action required';
                    $headline = 'Your extra branch request could not be verified.';
                    $paragraphs = [
                        "The extra branch payment you submitted for {$company->name} could not be verified.",
                        "Reason: {$reasonLine}",
                        'No branch slots were added. Please submit a new payment proof from the Branches page, or contact our support team on WhatsApp.',
                    ];
                    $ctaLabel = 'Log In & Resubmit';
                }

                Notification::create([
                    'company_id' => $company->id,
                    'type' => $decision === 'approved' ? 'extra_branch_approved' : 'extra_branch_rejected',
                    'title' => $title,
                    'message' => $message,
                    'read' => false,
                    'metadata' => [
                        'payment_proof_id' => $proof->id,
                        'product_type' => $plan?->product_type ?? 'pos',
                        'extra_branch_qty' => $qty,
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

                        \App\Services\MailHealth::recordSuccess();
                    } catch (\Throwable $e) {
                        Log::warning('Extra branch decision email failed', [
                            'payment_proof_id' => $proof->id,
                            'company_id' => $company->id,
                            'decision' => $decision,
                            'error' => $e->getMessage(),
                        ]);

                        \App\Services\MailHealth::recordFailure('Extra branch decision email', $e);
                    }
                }

                return;
            }

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
                $message = 'Payment rejected: ' . $reasonLine
                    . ($proof->auto_access_until ? ' Your temporary access has ended.' : '')
                    . ' Please submit a new payment proof.';
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

                    \App\Services\MailHealth::recordSuccess();
                } catch (\Throwable $e) {
                    Log::warning('Payment decision email failed', [
                        'payment_proof_id' => $proof->id,
                        'company_id' => $company->id,
                        'decision' => $decision,
                        'error' => $e->getMessage(),
                    ]);

                    \App\Services\MailHealth::recordFailure('Payment decision email', $e);
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
