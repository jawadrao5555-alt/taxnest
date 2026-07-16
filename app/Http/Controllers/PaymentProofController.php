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
        $productType = $this->resolveProductType($company);
        $allowedCycles = $productType === 'di'
            ? ['monthly', 'quarterly', 'semi_annual', 'annual']
            : ['annual'];

        $validated = $request->validate([
            'pricing_plan_id' => 'required|exists:pricing_plans,id',
            'billing_cycle' => 'required|in:' . implode(',', $allowedCycles),
            'amount' => 'nullable|numeric|min:0',
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

        // One pending proof at a time — avoid duplicate review queue entries.
        $existing = PaymentProof::where('company_id', $companyId)
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
            'billing_cycle' => \App\Services\SubscriptionAssignmentService::normalizeCycle($validated['billing_cycle']),
            'amount' => $validated['amount'] ?? null,
            'reference' => $validated['reference'] ?? null,
            'payment_date' => $validated['payment_date'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'proof_path' => $path,
            'status' => 'pending',
        ]);

        // Alert admins right away — the company is blocked from billing until an
        // admin verifies, so review speed matters. Best-effort: a mail failure
        // must NEVER break the company's submission (mirrors trial-reminder pattern).
        $this->alertAdmins($company, $plan, $proof);

        return back()->with('success', 'Payment proof submitted! Your account will be unlocked once our team verifies it.')
            ->with('payment_proof', 'submitted');
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

            $body = "A new payment receipt is waiting for review.\n\n"
                . "Company: {$companyName}\n"
                . "Panel: {$panel}\n"
                . "Package: {$plan->name}\n"
                . "Billing cycle: {$cycle}\n"
                . "Amount: {$amount}\n"
                . ($proof->reference ? "Reference: {$proof->reference}\n" : '')
                . ($proof->payment_date ? 'Payment date: ' . $proof->payment_date->format('Y-m-d') . "\n" : '')
                . "\nThe company stays locked until this is verified.\n"
                . 'Review: ' . route('saas.admin.payment-proofs') . "\n\n"
                . 'TaxNest';

            Mail::raw($body, function ($m) use ($emails, $companyName) {
                $m->to($emails->all())->subject("New payment receipt — {$companyName}");
            });
        } catch (\Throwable $e) {
            Log::warning('Payment proof admin alert email failed', [
                'proof_id' => $proof->id,
                'error' => $e->getMessage(),
            ]);
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
            return 'pos';
        }
        if (auth('fbrpos')->check()) {
            return 'fbrpos';
        }
        return 'di';
    }
}
