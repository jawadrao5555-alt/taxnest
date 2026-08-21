<?php

namespace App\Http\Controllers;

use App\Models\PaymentProof;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * Company-facing payment-proof submission.
 *
 * Registered inside the web (DI), pos and fbrpos authenticated route groups
 * so a locked company on any panel can upload a receipt. Intentionally NOT
 * behind plan.limit — a locked / trial-ended company must still be able to POST.
 */
class PaymentProofController extends Controller
{
    public function store(Request $request)
    {
        $companyId = $this->resolveCompanyId();
        if (!$companyId) {
            return back()->with('error', 'Unable to identify your company. Please log in again.')
                ->with('payment_proof', 'error');
        }

        if (!Schema::hasTable('payment_proofs')) {
            return back()->with('error', 'Payment submission is temporarily unavailable. Please contact support.')
                ->with('payment_proof', 'error');
        }

        $company = \App\Models\Company::find($companyId);

        // Extra-branch add-on request (Rs 10,000/branch/year) — apna alag
        // raasta: koi package/cycle nahi, approve par sirf slots barhte hain.
        if ($request->input('request_type') === 'extra_branch') {
            return $this->storeExtraBranchRequest($request, $company);
        }

        $productType = $this->resolveProductType($company);
        $allowedCycles = match ($productType) {
            'di' => ['monthly', 'quarterly', 'semi_annual', 'annual'],
            'pos' => ['annual', 'quarterly'], // PRA POS: Annual + Quarterly (Aug 2026)
            default => ['annual'],            // standalone / fbrpos stay annual-only
        };

        $validated = $request->validate([
            'pricing_plan_id' => 'required|exists:pricing_plans,id',
            'billing_cycle' => 'required|in:' . implode(',', $allowedCycles),
            'amount' => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|in:bank,jazzcash,easypaisa,other',
            'reference' => 'nullable|string|max:120',
            'payment_date' => 'nullable|date',
            'notes' => 'nullable|string|max:500',
            'proof' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        // The selected package must be a real, non-trial plan for THIS account's
        // product line — a company can never request a plan from another panel.
        $plan = \App\Models\PricingPlan::find($validated['pricing_plan_id']);
        if (!$plan || $plan->is_trial || ($plan->product_type ?? 'di') !== $productType) {
            return back()
                ->withErrors(['pricing_plan_id' => 'Please select a valid package for your account.'])
                ->withInput()
                ->with('payment_proof', 'error');
        }

        // One pending PACKAGE proof at a time — avoid duplicate review queue
        // entries. Extra-branch requests live in their own lane (see the
        // subscriptionKind scope) so an add-on request never hides the
        // renewal form, and vice-versa.
        $existing = PaymentProof::subscriptionKind()
            ->where('company_id', $companyId)
            ->where('status', 'pending')
            ->exists();
        if ($existing) {
            return back()->with('success', 'Your payment proof is already under review. We will notify you once it is verified.')
                ->with('payment_proof', 'pending');
        }

        // Private (non-public) disk: receipts are downloadable by admins only.
        $path = $request->file('proof')->store('payment-proofs/' . $companyId, 'local');

        $proof = PaymentProof::create([
            'company_id' => $companyId,
            'pricing_plan_id' => $plan->id,
            // Store the cycle the APPROVAL will actually assign (computePrice
            // forces annual when a plan has no quarterly price) so the proof row
            // and the resulting subscription can never disagree.
            'billing_cycle' => \App\Services\SubscriptionAssignmentService::computePrice($plan, $validated['billing_cycle'])['cycle'],
            'amount' => $validated['amount'] ?? null,
            'payment_method' => Schema::hasColumn('payment_proofs', 'payment_method') ? ($validated['payment_method'] ?? null) : null,
            'reference' => $validated['reference'] ?? null,
            'payment_date' => $validated['payment_date'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'proof_path' => $path,
            'status' => 'pending',
        ]);

        // Alert admins right away — the company is blocked from billing until an
        // admin verifies, so review speed matters. Best-effort: a mail failure
        // must NEVER break the company's submission (mirrors trial-reminder pattern).
        // Instant temporary access (owner approved, Aug 2026): the moment a
        // locked company submits a proof it gets 10 days of temporary access
        // while an admin verifies. Safeguard: a company with ANY previously
        // rejected proof gets NO auto access on re-upload (admin must decide).
        $granted = $this->grantInstantAccess($company, $proof);

        $this->alertAdmins($company, $plan, $proof);

        if ($granted) {
            return back()->with('success', 'Payment proof submitted! Temporary access has been enabled for 10 days while our team verifies your payment.')
                ->with('payment_proof', 'submitted');
        }

        return back()->with('success', 'Payment proof submitted! Your account will be unlocked once our team verifies it.')
            ->with('payment_proof', 'submitted');
    }

    /**
     * Extra-branch add-on request (owner-approved, Aug 2026).
     *
     * Package se ooper har branch Rs 10,000 saalana. Yahan sirf REQUEST banti
     * hai — slots admin ke approve karne par barhte hain. Deliberately NO
     * instant access grant and NO subscription touch: ye package ki payment
     * nahi, sirf ek add-on hai.
     */
    private function storeExtraBranchRequest(Request $request, ?\App\Models\Company $company)
    {
        // PRA POS panel ka feature hai — baqi panels par raasta hi nahi.
        if (!$company || !auth('pos')->check() || $this->resolveProductType($company) !== 'pos') {
            return back()->with('error', __('pos.eb_not_available'))
                ->with('payment_proof', 'error');
        }

        $eligibility = \App\Services\BranchAddonService::purchaseEligibility($company);
        if (!$eligibility['allowed']) {
            return back()->with('error', __($eligibility['reason_key'] ?? 'pos.eb_not_available'))
                ->with('payment_proof', 'error');
        }

        $validated = $request->validate([
            'extra_branch_qty' => 'required|integer|min:1|max:' . \App\Services\BranchAddonService::MAX_QTY,
            'amount' => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|in:bank,jazzcash,easypaisa,other',
            'reference' => 'nullable|string|max:120',
            'payment_date' => 'nullable|date',
            'notes' => 'nullable|string|max:500',
            'proof' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        // One pending add-on request at a time (package proofs are a separate lane).
        $pending = PaymentProof::extraBranchKind()
            ->where('company_id', $company->id)
            ->where('status', 'pending')
            ->exists();
        if ($pending) {
            return back()->with('success', __('pos.eb_already_pending'))
                ->with('payment_proof', 'pending');
        }

        $qty = (int) $validated['extra_branch_qty'];
        $quote = \App\Services\BranchAddonService::quote($company, $qty);

        $path = $request->file('proof')->store('payment-proofs/' . $company->id, 'local');

        $sub = \App\Services\BranchAddonService::activeSubscription($company);

        $proof = PaymentProof::create([
            'company_id' => $company->id,
            // Context only — the approval path never reads it for pricing.
            'pricing_plan_id' => $sub?->pricing_plan_id,
            'billing_cycle' => null,
            'request_type' => 'extra_branch',
            'extra_branch_qty' => $quote['qty'],
            'amount' => $validated['amount'] ?? $quote['price'],
            'payment_method' => Schema::hasColumn('payment_proofs', 'payment_method') ? ($validated['payment_method'] ?? null) : null,
            'reference' => $validated['reference'] ?? null,
            'payment_date' => $validated['payment_date'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'proof_path' => $path,
            'status' => 'pending',
        ]);

        $this->alertAdminsExtraBranch($company, $proof, $quote);

        return back()->with('success', __('pos.eb_submitted'))
            ->with('payment_proof', 'submitted');
    }

    /** Admins ko extra-branch request ki ittila (best-effort, kabhi submission na toray). */
    private function alertAdminsExtraBranch(\App\Models\Company $company, PaymentProof $proof, array $quote): void
    {
        try {
            $emails = \App\Models\AdminUser::whereNotNull('email')
                ->where('email', '!=', '')
                ->pluck('email')->unique()->values();
            if ($emails->isEmpty()) {
                return;
            }

            $amount = $proof->amount !== null ? 'PKR ' . number_format((float) $proof->amount) : 'Not specified';
            $body = "An EXTRA BRANCH request is waiting for review.\n\n"
                . 'Company: ' . ($company->name ?? ('Company #' . $proof->company_id)) . "\n"
                . 'Panel: NestPOS (PRA)' . "\n"
                . 'Extra branches requested: ' . $proof->extra_branch_qty . "\n"
                . 'Quoted amount: PKR ' . number_format($quote['price'])
                . ($quote['prorated'] ? ' (pro-rata for ' . $quote['months'] . ' month(s) left on the package)' : ' (full year)') . "\n"
                . "Amount paid: {$amount}\n"
                . ($proof->reference ? 'Reference: ' . $proof->reference . "\n" : '')
                . "\nApproving adds ONLY the branch slots — the package, its expiry and the subscription row stay untouched.\n"
                . 'Review: ' . route('saas.admin.payment-proofs') . "\n\nTaxNest";

            Mail::raw($body, function ($m) use ($emails, $company) {
                $m->to($emails->all())->subject('Extra branch request — ' . ($company->name ?? 'Company'));
            });

            \App\Services\MailHealth::recordSuccess();
        } catch (\Throwable $e) {
            Log::warning('Extra branch request admin alert failed', [
                'proof_id' => $proof->id,
                'error' => $e->getMessage(),
            ]);

            \App\Services\MailHealth::recordFailure('Extra branch request admin alert', $e);
        }
    }

    /**
     * Auto-grant a 10-day temporary override on proof upload.
     *
     * Granted ONLY when ALL hold:
     *  - the company exists and is not internal / suspended / rejected;
     *  - the company has NO previously rejected proof (owner safeguard: after
     *    a rejection, re-uploads wait for manual admin review);
     *  - the current subscription has no other active override (never stomp an
     *    admin-granted lifetime/temporary/usage-free grant);
     *  - the company is currently locked (hasAccess false) — a still-active
     *    company needs no bridge access.
     *
     * The grant rides on the subscription row exactly like an admin temporary
     * grant, so the existing reconciler expires+demotes it automatically if the
     * admin does nothing within 10 days. override_by stays NULL and the reason
     * carries the proof id so reject() can find and revoke exactly this grant.
     */
    private function grantInstantAccess(?\App\Models\Company $company, PaymentProof $proof): bool
    {
        try {
            if (!$company || $company->is_internal_account) {
                return false;
            }
            if (in_array($company->status, ['suspended', 'rejected'], true)
                || in_array($company->company_status, ['suspended', 'rejected'], true)) {
                return false;
            }
            if (!Schema::hasColumn('payment_proofs', 'auto_access_until')) {
                return false;
            }

            // Owner safeguard: any prior rejection disables instant access.
            // Scoped to package proofs — a rejected extra-branch request has
            // nothing to do with whether a renewal payment is trustworthy.
            $wasRejected = PaymentProof::subscriptionKind()
                ->where('company_id', $company->id)
                ->where('status', 'rejected')
                ->where('id', '!=', $proof->id)
                ->exists();
            if ($wasRejected) {
                return false;
            }

            $sub = \Illuminate\Support\Facades\DB::transaction(function () use ($company) {
                $sub = \App\Models\Subscription::where('company_id', $company->id)
                    ->orderByDesc('active')
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();
                if (!$sub) {
                    return \App\Models\Subscription::create([
                        'company_id' => $company->id,
                        'pricing_plan_id' => null,
                        'billing_cycle' => 'monthly',
                        'discount_percent' => 0,
                        'final_price' => 0,
                        'start_date' => now()->toDateString(),
                        'end_date' => null,
                        'active' => true,
                    ]);
                }
                if (!$sub->active) {
                    $sub->update(['active' => true]);
                }
                return $sub;
            });

            // Never stomp an existing valid grant (lifetime / admin temporary / usage-free).
            if ($sub->hasActiveOverride()) {
                return false;
            }

            // Only bridge companies that are actually locked right now.
            if (\App\Services\SubscriptionAccessService::hasAccess($company)['allowed'] ?? false) {
                return false;
            }

            $until = now()->addDays(10);
            $sub->update([
                'override_type' => 'temporary',
                'override_until' => $until,
                'override_granted_at' => now(),
                'free_invoice_limit' => null,
                'override_reason' => 'Auto access: payment proof #' . $proof->id . ' pending verification',
                'override_by' => null,
            ]);
            $proof->update(['auto_access_until' => $until]);

            // Mirror the admin grant flow: a grant unlocks a pending company
            // (both status columns), never a suspended/rejected one (checked above).
            if ($company->status !== 'approved' || $company->company_status !== 'active') {
                $company->update(['status' => 'approved', 'company_status' => 'active']);
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('Payment proof instant access grant failed', [
                'proof_id' => $proof->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Email every admin account about the newly submitted receipt. Sent
     * synchronously (no queue-worker dependency on cPanel), swallowing failures
     * with a log line — the sidebar badge + dashboard tile still cover it.
     */
    private function alertAdmins(?\App\Models\Company $company, \App\Models\PricingPlan $plan, PaymentProof $proof): void
    {
        try {
            $emails = \App\Models\AdminUser::whereNotNull('email')
                ->where('email', '!=', '')
                ->pluck('email')->unique()->values();
            if ($emails->isEmpty()) {
                return;
            }

            $companyName = $company->name ?? ('Company #' . $proof->company_id);
            $panel = strtoupper($plan->product_type ?? 'di');
            $cycle = ucwords(str_replace('_', ' ', (string) $proof->billing_cycle));
            $amount = $proof->amount !== null ? 'PKR ' . number_format((float) $proof->amount) : 'Not specified';
            $methodLabels = ['bank' => 'Bank Transfer', 'jazzcash' => 'JazzCash', 'easypaisa' => 'EasyPaisa', 'other' => 'Other'];
            $method = $methodLabels[$proof->payment_method] ?? null;

            $body = "A new payment receipt is waiting for review.\n\n"
                . "Company: {$companyName}\n"
                . "Panel: {$panel}\n"
                . "Package: {$plan->name}\n"
                . "Billing cycle: {$cycle}\n"
                . "Amount: {$amount}\n"
                . ($method ? "Payment method: {$method}\n" : '')
                . ($proof->reference ? "Reference: {$proof->reference}\n" : '')
                . ($proof->payment_date ? 'Payment date: ' . $proof->payment_date->format('Y-m-d') . "\n" : '')
                . ($proof->auto_access_until
                    ? "\nTemporary access was AUTO-GRANTED until " . $proof->auto_access_until->format('Y-m-d') . " — please verify before it expires.\n"
                    : "\nThe company stays locked until this is verified.\n")
                . 'Review: ' . route('saas.admin.payment-proofs') . "\n\n"
                . 'TaxNest';

            Mail::raw($body, function ($m) use ($emails, $companyName) {
                $m->to($emails->all())->subject("New payment receipt — {$companyName}");
            });

            \App\Services\MailHealth::recordSuccess();
        } catch (\Throwable $e) {
            Log::warning('Payment proof admin alert email failed', [
                'proof_id' => $proof->id,
                'error' => $e->getMessage(),
            ]);

            \App\Services\MailHealth::recordFailure('Payment receipt admin alert', $e);
        }
    }

    /**
     * Resolve the acting company across the three isolated guards, mirroring
     * the trial-lock-modal resolution (CompanyIsolation binds 'n').
     */
    private function resolveCompanyId(): ?int
    {
        if (app()->bound('n') && app('n')) {
            return (int) app('n');
        }
        // Order mirrors the lock-modal's submitAction resolution (pos → fbrpos → web)
        // so the company a proof is attached to always matches the panel it was
        // submitted from, even in the rare dual-guard login case.
        foreach (['pos', 'fbrpos', 'web'] as $guard) {
            if (auth($guard)->check()) {
                $cid = (int) (auth($guard)->user()->company_id ?? 0);
                return $cid ?: null;
            }
        }
        return null;
    }

    /**
     * Map the acting panel/company to its pricing product line so a company can
     * only request (and be shown) plans from its own panel.
     *  - pos guard    → 'pos'
     *  - fbrpos guard → 'fbrpos'
     *  - web guard    → 'di'
     */
    private function resolveProductType(?\App\Models\Company $company): string
    {
        if (auth('pos')->check()) {
            // Mirror the trial-lock modal: standalone-mode POS companies are a
            // separate (annual-only) product line, never 'pos'.
            return (($company?->pos_integration_mode ?? 'pra') === 'standalone') ? 'standalone' : 'pos';
        }
        if (auth('fbrpos')->check()) {
            return 'fbrpos';
        }
        return 'di';
    }
}
