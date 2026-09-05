<?php

namespace App\Http\Controllers;

use App\Models\PaymentProof;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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

        // Paid feature add-on (PRA POS, Aug 2026) — teesri lane: approve par
        // sirf chune hue feature khulte hain, package bilkul waisa hi rehta hai.
        if ($request->input('request_type') === 'pos_addon') {
            return $this->storePosAddonRequest($request, $company);
        }

        // AI Reader page top-up (Digital Invoice, Sep 2026) — chauthi lane:
        // approve par sirf pages ka balance barhta hai, package ko haath nahi.
        if ($request->input('request_type') === 'ai_pages') {
            return $this->storeAiPagesRequest($request, $company);
        }

        $productType = $this->resolveProductType($company);
        // Owner, 23 Aug 2026: every product line is sold by the YEAR only, so
        // a stale form or a hand-crafted POST can no longer buy a short cycle.
        $allowedCycles = \App\Services\SubscriptionAssignmentService::SELLABLE_CYCLES;

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
        // A retired package must not enter the review queue on ANY product
        // line, or approving it would put a shop back on a dead plan.
        if (\App\Services\PlanSellabilityService::isRetired($plan)) {
            return back()
                ->withErrors(['pricing_plan_id' => \App\Services\PlanSellabilityService::pickCurrentMessage($plan)])
                ->withInput()
                ->with('payment_proof', 'error');
        }

        // Private (non-public) disk: receipts are downloadable by admins only.
        $path = $request->file('proof')->store('payment-proofs/' . $companyId, 'local');
        $result = DB::transaction(function () use ($companyId, $plan, $validated, $path) {
            $lockedCompany = \App\Models\Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
            if (PaymentProof::subscriptionKind()->where('company_id',$companyId)->where('status','pending')->exists()) {
                return null;
            }
            $hasSnapshotColumn = Schema::hasColumn('payment_proofs', 'distributor_quote_snapshot');
            $snapshot = \App\Services\DistributorDiscountService::quote(
                $lockedCompany,
                $plan,
                $validated['billing_cycle'],
                $hasSnapshotColumn
            );
            $proofData = [
            'company_id' => $companyId,
            'pricing_plan_id' => $plan->id,
            // Store the cycle the APPROVAL will actually assign (computePrice
            // always annual since 23 Aug 2026) so the proof row
            // and the resulting subscription can never disagree.
            'billing_cycle' => \App\Services\SubscriptionAssignmentService::computePrice($plan, $validated['billing_cycle'])['cycle'],
            // Preserve what the shop says it transferred for the admin's bank
            // reconciliation. The expected quote remains server-owned inside
            // the immutable snapshot and is never derived from this input.
            'amount' => $validated['amount'] ?? null,
            'payment_method' => Schema::hasColumn('payment_proofs', 'payment_method') ? ($validated['payment_method'] ?? null) : null,
            'reference' => $validated['reference'] ?? null,
            'payment_date' => $validated['payment_date'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'proof_path' => $path,
            'status' => 'pending',
            ];
            if ($hasSnapshotColumn) {
                $proofData['distributor_quote_snapshot'] = $snapshot;
            }
            if (Schema::hasColumn('payment_proofs', 'distributor_net_amount')) {
                // Frozen only when an admin independently verifies the bank amount.
                $proofData['distributor_net_amount'] = null;
            }
            return PaymentProof::create($proofData);
        });
        if (!$result) {
            Storage::disk('local')->delete($path);
            return back()->with('success', 'Your payment proof is already under review. We will notify you once it is verified.')
                ->with('payment_proof', 'pending');
        }
        $proof = $result;

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
        // Dono POS panels ka feature hai (FBR POS 23 Aug 2026 se) — DI par
        // raasta hi nahi. Guard aur company ka product line dono match karein.
        $type = $company ? $this->resolveProductType($company) : null;
        $guarded = ($type === 'pos' && auth('pos')->check())
            || ($type === 'fbrpos' && auth('fbrpos')->check());
        if (!$company || !$guarded) {
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

    /**
     * Paid feature add-on request (owner-approved, Aug 2026).
     *
     * Chhe optional features — Delivery Riders, QR Menu, WhatsApp Bill, Staff
     * Attendance, Rider Live Tracking, Caller ID — Business se ooper ke PRA POS
     * shops alag khareed sakte hain. Yahan sirf REQUEST banti hai; feature admin
     * ke approve karne par khulta hai. Extra-branch ki tarah: NO instant access
     * grant aur subscription row ko bilkul haath nahi lagta.
     */
    private function storePosAddonRequest(Request $request, ?\App\Models\Company $company)
    {
        // Sirf PRA POS panel ka feature hai — baqi panels par raasta hi nahi.
        if (!$company || !auth('pos')->check() || $this->resolveProductType($company) !== 'pos') {
            return back()->with('error', __('pos.addons_not_available'))
                ->with('payment_proof', 'error');
        }

        // Paisay ka faisla sirf malik/manager ka. A cashier must never be able
        // to file a purchase request for the shop — the billing page hides the
        // box from them, but the POST is the only guard that actually counts.
        if (auth('pos')->user()?->posCashierBlocked()) {
            abort(403);
        }

        if (!Schema::hasTable('pos_addons') || !PaymentProof::addonCodesColumnExists()) {
            return back()->with('error', __('pos.addons_not_available'))
                ->with('payment_proof', 'error');
        }

        $eligibility = \App\Services\PosAddonService::purchaseEligibility($company);
        if (!$eligibility['allowed']) {
            return back()->with('error', __($eligibility['reason_key'] ?? 'pos.addons_not_available'))
                ->with('payment_proof', 'error');
        }

        $validated = $request->validate([
            'addon_codes' => 'required|array|min:1',
            'addon_codes.*' => 'required|string|in:' . implode(',', array_keys(\App\Services\PosAddonPricingService::ADDONS)),
            'addon_cycle' => 'required|in:' . implode(',', \App\Services\PosAddonPricingService::CYCLES),
            'payment_method' => 'nullable|in:bank,jazzcash,easypaisa,other',
            'reference' => 'nullable|string|max:120',
            'payment_date' => 'nullable|date',
            'notes' => 'nullable|string|max:500',
            'proof' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        // Store the receipt before the lock so the transaction stays short —
        // an orphan file is harmless, a long-held row lock is not.
        $path = $request->file('proof')->store('payment-proofs/' . $company->id, 'local');

        // The "one pending request" rule and the purchasable list are BOTH
        // read-then-write. Two tabs (or a double-tap on a slow connection)
        // would otherwise file two requests for the same features and the shop
        // could be charged twice. Every writer in this lane locks the same
        // company row first, so the second one sees the first one's proof.
        $outcome = \Illuminate\Support\Facades\DB::transaction(function () use ($company, $validated, $path) {
            $lockedCompany = \App\Models\Company::where('id', $company->id)->lockForUpdate()->first();
            if (!$lockedCompany) {
                return ['status' => 'nothing_to_buy'];
            }

            // The package can lapse or be replaced while this request waits for
            // the company mutex. Re-check on the locked row so an expired or
            // newly ineligible package can never create a zero-month proof.
            $eligibility = \App\Services\PosAddonService::purchaseEligibility($lockedCompany);
            if (!$eligibility['allowed']) {
                return [
                    'status' => 'ineligible',
                    'reason_key' => $eligibility['reason_key'] ?? 'pos.addons_not_available',
                ];
            }

            $pending = PaymentProof::posAddonKind()
                ->where('company_id', $company->id)
                ->where('status', 'pending')
                ->exists();
            if ($pending) {
                return ['status' => 'pending'];
            }

            // Never sell what the shop already has: drop anything its package
            // (or an earlier purchase) already grants. Server-side — the form
            // is only a hint. Re-read INSIDE the lock so a purchase approved a
            // moment ago cannot be bought a second time.
            \App\Services\PosAddonService::flushCache();
            $purchasable = \App\Services\PosAddonService::purchasableCodes($lockedCompany);
            $codes = array_values(array_intersect($validated['addon_codes'], $purchasable));
            if (empty($codes)) {
                return ['status' => 'nothing_to_buy'];
            }

            $quote = \App\Services\PosAddonService::quote(
                $codes,
                $validated['addon_cycle'],
                $lockedCompany
            );
            $proofData = [
                'company_id' => $lockedCompany->id,
                // Context only — approval never reads it for pricing.
                'pricing_plan_id' => $eligibility['subscription']?->pricing_plan_id,
                // The add-on's OWN cycle (annual). Safe to reuse this
                // column: the subscriptionKind scope keeps pos_addon rows out of
                // every renewal query, so it can never be read as a package cycle.
                'billing_cycle' => $quote['cycle'],
                'request_type' => 'pos_addon',
                'addon_codes' => json_encode($quote['codes']),
                // Displayed browser totals are a convenience only. Never accept
                // an amount from the request for paid features.
                'amount' => $quote['total'],
                'payment_method' => Schema::hasColumn('payment_proofs', 'payment_method') ? ($validated['payment_method'] ?? null) : null,
                'reference' => $validated['reference'] ?? null,
                'payment_date' => $validated['payment_date'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'proof_path' => $path,
                'status' => 'pending',
            ];
            if (PaymentProof::addonQuoteSnapshotColumnExists()) {
                $proofData['addon_quote_snapshot'] = $quote;
            }

            return [
                'status' => 'ok',
                'codes' => $codes,
                'quote' => $quote,
                'proof' => PaymentProof::create($proofData),
            ];
        });

        if ($outcome['status'] === 'pending') {
            return back()->with('success', __('pos.addons_already_pending'))
                ->with('payment_proof', 'pending');
        }
        if ($outcome['status'] === 'nothing_to_buy') {
            return back()->with('error', __('pos.addons_nothing_to_buy'))
                ->with('payment_proof', 'error');
        }
        if ($outcome['status'] === 'ineligible') {
            return back()->with('error', __($outcome['reason_key']))
                ->with('payment_proof', 'error');
        }

        $quote = $outcome['quote'];
        $proof = $outcome['proof'];

        $this->alertAdminsPosAddon($company, $proof, $quote);
        $request->session()->forget(\App\Services\PosAddonService::SIGNUP_SESSION_KEY);

        return back()->with('success', __('pos.addons_submitted'))
            ->with('payment_proof', 'submitted');
    }

    /**
     * AI Reader page top-up request (Digital Invoice, Sep 2026).
     *
     * Package ke sath har mahine pages milte hain; khatam hon to shop yahan se
     * extra pages khareedti hai. Extra-branch ki tarah apni lane: approve par
     * SIRF pages ka balance barhta hai — package, uski miyaad aur uski qeemat
     * bilkul waise hi rehte hain. Khareede hue pages kabhi expire nahi hote.
     */
    private function storeAiPagesRequest(Request $request, ?\App\Models\Company $company)
    {
        // Sirf Digital Invoice panel — POS/FBR par AI Reader hai hi nahi.
        if (!$company || !auth('web')->check() || $this->resolveProductType($company) !== 'di') {
            return back()->with('error', 'AI page top-up is not available on this account.')
                ->with('payment_proof', 'error');
        }

        // Paisay ka faisla sirf malik ka — staff top-up request file na kar sakay.
        if (!in_array(auth('web')->user()->role ?? '', ['super_admin', 'company_admin'], true)) {
            abort(403);
        }

        // AI Reader jis package mein hai hi nahi, uske liye pages bechna bemani hai.
        if (!\App\Services\DiFeatureService::planAllows($company, 'ai_reader')) {
            return back()->with('error', 'AI Invoice Reader is not part of your current package. Upgrade to buy extra pages.')
                ->with('payment_proof', 'error');
        }

        if (!PaymentProof::addonQuoteSnapshotColumnExists() || !Schema::hasColumn('companies', 'ai_page_balance')) {
            return back()->with('error', 'AI page top-up is temporarily unavailable. Please contact support.')
                ->with('payment_proof', 'error');
        }

        $validated = $request->validate([
            'ai_pages' => 'required|integer|in:' . implode(',', array_keys(\App\Services\AiPageCreditService::PACKS)),
            'payment_method' => 'nullable|in:bank,jazzcash,easypaisa,other',
            'reference' => 'nullable|string|max:120',
            'payment_date' => 'nullable|date',
            'notes' => 'nullable|string|max:500',
            'proof' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        $pages = (int) $validated['ai_pages'];
        // Qeemat hamesha server se — browser ka bheja hua amount kabhi nahi.
        $price = \App\Services\AiPageCreditService::packPrice($pages);
        if ($price === null) {
            return back()->with('error', 'That top-up pack is no longer available.')
                ->with('payment_proof', 'error');
        }

        // Ek waqt mein ek hi top-up request — warna do tabs se do baar paisay.
        $pending = PaymentProof::aiPagesKind()
            ->where('company_id', $company->id)
            ->where('status', 'pending')
            ->exists();
        if ($pending) {
            return back()->with('success', 'Your AI page top-up is already under review. We will add the pages as soon as it is verified.')
                ->with('payment_proof', 'pending');
        }

        $path = $request->file('proof')->store('payment-proofs/' . $company->id, 'local');

        $sub = \App\Models\Subscription::where('company_id', $company->id)
            ->where('active', true)
            ->orderByDesc('id')
            ->first();

        $proof = PaymentProof::create([
            'company_id' => $company->id,
            // Context only — approval never reads it for pricing.
            'pricing_plan_id' => $sub?->pricing_plan_id,
            'billing_cycle' => null,
            'request_type' => 'ai_pages',
            'addon_quote_snapshot' => ['pages' => $pages, 'price' => $price],
            'amount' => $price,
            'payment_method' => Schema::hasColumn('payment_proofs', 'payment_method') ? ($validated['payment_method'] ?? null) : null,
            'reference' => $validated['reference'] ?? null,
            'payment_date' => $validated['payment_date'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'proof_path' => $path,
            'status' => 'pending',
        ]);

        $this->alertAdminsAiPages($company, $proof, $pages, $price);

        return back()->with('success', 'Top-up request submitted! Your ' . number_format($pages) . ' AI pages will be added once our team verifies the payment.')
            ->with('payment_proof', 'submitted');
    }

    /** Admins ko AI page top-up ki ittila (best-effort, kabhi submission na toray). */
    private function alertAdminsAiPages(\App\Models\Company $company, PaymentProof $proof, int $pages, int $price): void
    {
        try {
            $emails = \App\Models\AdminUser::whereNotNull('email')
                ->where('email', '!=', '')
                ->pluck('email')->unique()->values();
            if ($emails->isEmpty()) {
                return;
            }

            $body = "An AI READER PAGE TOP-UP request is waiting for review.\n\n"
                . 'Company: ' . ($company->name ?? ('Company #' . $proof->company_id)) . "\n"
                . 'Panel: Digital Invoicing' . "\n"
                . 'Pages requested: ' . number_format($pages) . "\n"
                . 'Quoted amount: PKR ' . number_format($price) . "\n"
                . ($proof->reference ? 'Reference: ' . $proof->reference . "\n" : '')
                . "\nApproving ONLY adds the pages to the company's purchased balance. The package, its expiry and its price stay untouched.\n"
                . 'Review: ' . route('saas.admin.payment-proofs') . "\n\nTaxNest";

            Mail::raw($body, function ($m) use ($emails, $company) {
                $m->to($emails->all())->subject('AI page top-up request — ' . ($company->name ?? 'Company'));
            });

            \App\Services\MailHealth::recordSuccess();
        } catch (\Throwable $e) {
            Log::warning('AI page top-up admin alert failed', [
                'proof_id' => $proof->id,
                'error' => $e->getMessage(),
            ]);

            \App\Services\MailHealth::recordFailure('AI page top-up admin alert', $e);
        }
    }

    /** Admins ko feature add-on request ki ittila (best-effort, kabhi submission na toray). */
    private function alertAdminsPosAddon(\App\Models\Company $company, PaymentProof $proof, array $quote): void
    {
        try {
            $emails = \App\Models\AdminUser::whereNotNull('email')
                ->where('email', '!=', '')
                ->pluck('email')->unique()->values();
            if ($emails->isEmpty()) {
                return;
            }

            $catalog = \App\Services\PosAddonPricingService::ADDONS;
            $lines = [];
            foreach ($quote['codes'] as $code) {
                $lines[] = '  - ' . ($catalog[$code]['label'] ?? $code)
                    . ': PKR ' . number_format($quote['lines'][$code] ?? 0);
            }

            $amount = $proof->amount !== null ? 'PKR ' . number_format((float) $proof->amount) : 'Not specified';
            $body = "A PAID FEATURE ADD-ON request is waiting for review.\n\n"
                . 'Company: ' . ($company->name ?? ('Company #' . $proof->company_id)) . "\n"
                . 'Panel: NestPOS (PRA)' . "\n"
                . 'Billing: Annual' . "\n"
                . "Features requested:\n" . implode("\n", $lines) . "\n"
                . 'Quoted total: PKR ' . number_format($quote['total']) . "\n"
                . "Amount paid: {$amount}\n"
                . ($proof->reference ? 'Reference: ' . $proof->reference . "\n" : '')
                . "\nApproving switches ON only the selected features until the current package expires. The package, its price and its expiry stay untouched.\n"
                . 'Review: ' . route('saas.admin.payment-proofs') . "\n\nTaxNest";

            Mail::raw($body, function ($m) use ($emails, $company) {
                $m->to($emails->all())->subject('Feature add-on request — ' . ($company->name ?? 'Company'));
            });

            \App\Services\MailHealth::recordSuccess();
        } catch (\Throwable $e) {
            Log::warning('POS add-on request admin alert failed', [
                'proof_id' => $proof->id,
                'error' => $e->getMessage(),
            ]);

            \App\Services\MailHealth::recordFailure('POS add-on request admin alert', $e);
        }
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
            $panel = \App\Support\ProductCatalog::shortLabel($plan->product_type ?? 'di');
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
