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
        $plans = PricingPlan::where('is_trial', false)->orderBy('price')->get()
            ->reject(fn (PricingPlan $plan) => \App\Services\PlanSellabilityService::isRetired($plan))
            ->values();

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

        // Paid feature add-on: sirf chune hue feature khulte hain. Package,
        // uski miyaad aur subscription row bilkul waise hi rehte hain.
        if ($proof->isPosAddon()) {
            return $this->approvePosAddon($request, $proof);
        }

        // AI Reader page top-up: sirf khareede hue pages ka balance barhta hai.
        if ($proof->isAiPages()) {
            return $this->approveAiPages($request, $proof);
        }

        $request->validate([
            'pricing_plan_id' => 'required|exists:pricing_plans,id',
            'billing_cycle' => 'required|in:monthly,quarterly,semi_annual,annual,yearly',
            // Paid extra-branch slots ka faisla, isi renewal ke sath (rakhein
            // ya kam karein). Ghair-mojood = purana behaviour, kuch na badle.
            'extra_branch_slots' => 'nullable|integer|min:0|max:' . \App\Services\BranchAddonService::MAX_QTY * 10,
        ]);

        $plan = PricingPlan::findOrFail((int) $request->pricing_plan_id);
        if ($plan->is_trial) {
            return back()->with('error', 'Trial plans cannot be assigned from payment approval.');
        }
        if (\App\Services\PlanSellabilityService::isRetired($plan)) {
            return back()->with('error', \App\Services\PlanSellabilityService::retiredMessage($plan));
        }

        // Product-line guard: the approved plan must stay on the same product
        // line the customer submitted for (operator-error protection).
        $proofPlanType = $proof->pricingPlan->product_type ?? null;
        if ($proofPlanType && ($plan->product_type ?? 'di') !== $proofPlanType) {
            return back()->with('error', 'Selected package belongs to a different product line than this payment proof.');
        }

        // Cycle guard: same per-product rules as customer submission
        // (di = 4 cycles, both POS lines = annual/quarterly/monthly, others annual-only).
        $allowedCycles = match ($plan->product_type ?? 'di') {
            'di' => ['monthly', 'quarterly', 'semi_annual', 'annual'],
            'pos', 'fbrpos' => ['annual', 'quarterly', 'monthly'],
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

        // Renewal ka jaiza: mutawaqqa total (base + paid slots) bmuqabla jo
        // raqam shop ne likhi. Agar shop ne sirf package ke paise bheje to ab
        // tak slots khamoshi se qaim rehte the — admin unhen isi qadam mein
        // rakh ya kam kar sakta hai (koi refund, koi khud-kar katauti nahi).
        $company = Company::find($proof->company_id);
        $review = \App\Services\BranchAddonService::renewalReview($company, $plan, $enforcedCycle, $proof->amount);

        $slotsChosen = null;
        if ($review['applies'] && \App\Services\BranchAddonService::slotsColumnExists()) {
            $raw = $request->input('extra_branch_slots');
            if ($raw !== null && $raw !== '') {
                $slotsChosen = (int) $raw;

                // Renewal par slots sirf rakhe ya kam kiye ja sakte hain —
                // barhane ka raasta shop ki apni extra-branch request hai
                // (wahan qeemat pro-rata bhi hoti hai).
                if ($slotsChosen > $review['slots']) {
                    return back()->with('error', 'Approving a renewal can only keep or reduce paid branch slots (currently ' . $review['slots'] . '). To sell more, approve the shop\'s extra-branch request instead.');
                }

                // Hadd se neeche jana branch ko limit se bahar chhod dega.
                if ($slotsChosen < $review['min_slots']) {
                    return back()->with('error', 'Cannot drop below ' . $review['min_slots'] . ' paid slot(s): this shop already has ' . $review['branches'] . ' branch(es) and the package includes ' . ($review['included'] ?? 0) . '. Delete the extra branch(es) first, then reduce the slots.');
                }
            }
        }

        // Race-safe: lock the row, bail out if another admin already processed it.
        $result = DB::transaction(function () use ($proof, $plan, $enforcedCycle, $slotsChosen) {
            $locked = PaymentProof::where('id', $proof->id)->lockForUpdate()->first();
            if (!$locked || $locked->status !== 'pending') {
                return ['outcome' => 'already_processed'];
            }

            $slotsBefore = null;
            $slotsAfter = null;

            if ($slotsChosen !== null) {
                $lockedCompany = Company::where('id', $locked->company_id)->lockForUpdate()->first();
                $slotsBefore = \App\Services\BranchAddonService::slots($lockedCompany);
                $slotsAfter = $slotsBefore;

                // Ho sakta hai isi darmiyan koi add-on request approve ho gayi
                // ho — admin ka number ab barhotri banega. Khamoshi se clamp
                // karne se behtar hai saaf mana kar dena.
                if ($slotsChosen > $slotsBefore) {
                    return ['outcome' => 'slots_changed'];
                }

                // Slots PEHLE likhein: naye subscription ki final_price isi
                // ginti se banti hai (assign → computePrice → addonForCycle),
                // warna record us add-on ka paisa dikhata rahega jo admin ne
                // abhi hata diya.
                if ($lockedCompany && $slotsChosen !== $slotsBefore) {
                    $lockedCompany->update(['extra_branch_slots' => $slotsChosen]);
                    $slotsAfter = $slotsChosen;
                }
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

            return [
                'outcome' => 'ok',
                'subscription' => $sub,
                'slots_before' => $slotsBefore,
                'slots_after' => $slotsAfter,
            ];
        });

        if (($result['outcome'] ?? null) === 'slots_changed') {
            return back()->with('error', 'This company\'s paid branch slots changed while you were reviewing. Reload the page and approve again.');
        }

        if (($result['outcome'] ?? null) !== 'ok') {
            return back()->with('error', 'This payment proof was already processed.');
        }

        $subscription = $result['subscription'];

        // A verified payment must also unlock the company itself: mirror the
        // admin grant flow (BOTH status columns), never reversing a deliberate
        // suspension/rejection. Covers companies demoted to pending by the
        // expired-grant reconciler before the admin got to this proof.
        $company = $company?->fresh();
        if ($company
            && !in_array($company->status, ['suspended', 'rejected'], true)
            && !in_array($company->company_status, ['suspended', 'rejected'], true)
            && ($company->status !== 'approved' || $company->company_status !== 'active')) {
            $company->update(['status' => 'approved', 'company_status' => 'active']);
        }

        // Agent commission: a verified proof is the "cleared payment" that
        // earns the introducing agent their Schedule A cut. Never breaks approval.
        \App\Services\AgentCommissionService::recordForProof($proof->fresh());

        AdminAuditLog::log(auth('admin')->id(), 'Payment proof approved', 'PaymentProof', $proof->id, array_filter([
            'company_id' => $proof->company_id,
            'subscription_id' => $subscription->id,
            'expected_total' => $review['applies'] ? $review['expected_total'] : null,
            'amount_claimed' => $review['applies'] ? $review['paid'] : null,
            'extra_branch_slots_before' => $result['slots_before'],
            'extra_branch_slots_after' => $result['slots_after'],
        ], fn ($v) => $v !== null));

        // Slots ka faisla company ke audit trail par bhi — bilkul usi shakl
        // mein jaise admin ke hath se ki gayi tabdeeli (before/after), plus wo
        // renewal period jiske liye ye slots ke paise mile.
        if ($result['slots_before'] !== null) {
            AdminAuditLog::log(auth('admin')->id(), 'Extra branch slots set at renewal', 'Company', $proof->company_id, [
                'name' => $company->name ?? null,
                'payment_proof_id' => $proof->id,
                'extra_branch_slots_before' => $result['slots_before'],
                'extra_branch_slots_after' => $result['slots_after'],
                'expected_total' => $review['expected_total'],
                'amount_claimed' => $review['paid'],
                'billing_cycle' => $enforcedCycle,
                'period_start' => (string) $subscription->start_date,
                'period_end' => (string) $subscription->end_date,
            ]);
        }

        $this->notifyCompany($proof->fresh(['company', 'pricingPlan']), 'approved');

        $message = 'Payment approved & subscription activated — company is now unlocked.';
        if ($result['slots_before'] !== null) {
            $message .= $result['slots_after'] === $result['slots_before']
                ? " Paid extra branch slots kept at {$result['slots_after']}."
                : " Paid extra branch slots reduced from {$result['slots_before']} to {$result['slots_after']}.";
        }

        return back()->with('success', $message);
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

            // A proof can sit in the queue for days. Whatever was true when the
            // shop paid, what matters is whether a paid slot would DO anything
            // now: an expired term, an admin branch override, a switch to a
            // trial / unlimited-branch / unsupported package all make the slot
            // dead weight. Refuse instead of selling a branch that cannot open —
            // the proof stays pending so it can be refunded or approved after a
            // renewal.
            $eligibility = \App\Services\BranchAddonService::purchaseEligibility($company);
            if (!$eligibility['allowed']) {
                return ['blocked' => $eligibility['reason_key']];
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

        if (isset($result['blocked'])) {
            $reason = $result['blocked'] ? __($result['blocked']) : '';
            $reason = ($reason && $reason !== $result['blocked']) ? ' (' . $reason . ')' : '';
            return back()->with('error',
                'This company can no longer use a paid extra branch' . $reason
                . ' — renew or fix the package first, or reject this proof and refund it.');
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

    /**
     * AI Reader page top-up approval (Digital Invoice, Sep 2026).
     *
     * Sirf khareede hue pages ka balance barhta hai — subscription row, plan,
     * miyaad aur qeemat ko haath nahi lagta. Pages kabhi expire nahi hote, is
     * liye package ki halat (chalu/khatam) yahan maani nahi rakhti: paisay aa
     * chuke hain, pages account mein jama ho jate hain.
     */
    private function approveAiPages(Request $request, PaymentProof $proof)
    {
        $pages = $proof->aiPagesRequested();
        if ($pages <= 0) {
            return back()->with('error', 'This top-up request has no valid pack on it — reject it and ask the company to resubmit.');
        }

        if (!Schema::hasTable('ai_page_ledgers') || !Schema::hasColumn('companies', 'ai_page_balance')) {
            return back()->with('error', 'The AI page credit tables are missing — run php artisan migrate --force first.');
        }

        $result = DB::transaction(function () use ($proof, $pages) {
            $locked = PaymentProof::where('id', $proof->id)->lockForUpdate()->first();
            if (!$locked || $locked->status !== 'pending') {
                return null;
            }

            $company = Company::where('id', $locked->company_id)->lockForUpdate()->first();
            if (!$company) {
                return null;
            }

            $before = \App\Services\AiPageCreditService::purchasedBalance($company);

            \App\Services\AiPageCreditService::credit($company, $pages, \App\Models\AiPageLedger::KIND_TOPUP, [
                // Admin panel ka guard alag hai — company ka user id yahan nahi.
                'user_id' => null,
                'source' => 'topup',
                'ref_type' => 'payment_proof',
                'ref_id' => $locked->id,
                'note' => 'Top-up approved by admin',
            ]);

            $locked->update([
                'status' => 'verified',
                'verified_by' => auth('admin')->id(),
                'verified_at' => now(),
                'reject_reason' => null,
            ]);

            return ['company' => $company, 'before' => $before, 'after' => $before + $pages];
        });

        if (!$result) {
            return back()->with('error', 'This payment proof was already processed.');
        }

        AdminAuditLog::log(auth('admin')->id(), 'AI page top-up approved', 'PaymentProof', $proof->id, [
            'company_id' => $proof->company_id,
            'pages' => $pages,
            'balance_before' => $result['before'],
            'balance_after' => $result['after'],
            'amount_claimed' => $proof->amount,
        ]);

        $this->notifyCompany($proof->fresh(['company', 'pricingPlan']), 'approved');

        return back()->with('success', number_format($pages) . ' AI Reader pages added — ' . $result['company']->name
            . ' now has ' . number_format($result['after']) . ' purchased pages. The package and its expiry were not changed.');
    }

    /**
     * Paid feature add-on approval (PRA POS, Aug 2026).
     *
     * Sirf pos_addons rows bante hain — subscription row, plan, miyaad, qeemat
     * aur company status ko haath nahi lagaya jata. Admin approve karte waqt
     * features ki list theek kar sakta hai (agar shop ne kam/zyada paisa bheja
     * ho), lekin sirf usi list mein se jo shop ne maangi thi.
     */
    private function approvePosAddon(Request $request, PaymentProof $proof)
    {
        $proofSnapshot = $proof->addonQuoteSnapshot();
        $requested = $proofSnapshot['codes'] ?? $proof->addonCodeList();
        if (empty($requested)) {
            return back()->with('error', 'This add-on request has no valid features on it — reject it and ask the shop to resubmit.');
        }

        $request->validate([
            'addon_codes' => 'nullable|array',
            'addon_codes.*' => 'string',
        ]);

        if (!Schema::hasTable('pos_addons')) {
            return back()->with('error', 'The pos_addons table is missing — run php artisan migrate --force first.');
        }

        // Admin sirf ghata sakta hai: jo shop ne maanga tha usi se intikhab.
        $chosen = $request->input('addon_codes');
        $codes = is_array($chosen) && !empty($chosen)
            ? array_values(array_intersect($requested, $chosen))
            : $requested;
        if (empty($codes)) {
            return back()->with('error', 'Select at least one feature to approve, or reject the request instead.');
        }

        // The shop has already paid for its selected billing cycle. An approver
        // may narrow features, but changing the cycle here would make the
        // displayed/payment-proof quote disagree with what is activated.
        $cycle = $proofSnapshot['cycle'] ?? (\App\Services\PosAddonService::cycleForProof($proof) ?? 'annual');
        $result = DB::transaction(function () use ($proof, $codes, $cycle) {
            $locked = PaymentProof::where('id', $proof->id)->lockForUpdate()->first();
            if (!$locked || $locked->status !== 'pending') {
                return ['outcome' => 'already_processed'];
            }
            $snapshot = $locked->addonQuoteSnapshot();
            $requested = $snapshot['codes'] ?? $locked->addonCodeList();
            $codes = array_values(array_intersect($requested, $codes));
            if (empty($codes)) {
                return ['outcome' => 'no_codes'];
            }

            $company = Company::where('id', $locked->company_id)->lockForUpdate()->first();
            if (!$company) {
                return ['outcome' => 'already_processed'];
            }

            // Re-check eligibility HERE, not just at submission. A request can
            // sit in the queue for days: in that window the shop may have
            // downgraded to Starter, switched to FBR, or let the package lapse.
            // Approving any of those would mint an entitlement the shop is not
            // entitled to — and, with no live package to date it against, one
            // with an invented expiry.
            \App\Services\PosAddonService::flushCache();
            $eligibility = \App\Services\PosAddonService::purchaseEligibility($company);
            if (!($eligibility['allowed'] ?? false)) {
                return ['outcome' => 'not_eligible'];
            }

            // Miyaad chalte hue package ke sath: add-on package se aage nahi jata.
            $sub = $eligibility['subscription'] ?? null;
            if (!$sub || !$sub->end_date) {
                return ['outcome' => 'no_expiry'];
            }

            if ($snapshot) {
                $currentUntil = $sub->end_date instanceof \DateTimeInterface
                    ? $sub->end_date->format('Y-m-d')
                    : (string) $sub->end_date;
                if ($snapshot['until'] !== $currentUntil) {
                    return ['outcome' => 'subscription_changed'];
                }

                $quote = \App\Services\PosAddonService::narrowSnapshotQuote($snapshot, $codes);
                if (!$quote) {
                    return ['outcome' => 'no_codes'];
                }
            } else {
                // Pre-snapshot proof: preserve the legacy review path.
                $quote = \App\Services\PosAddonService::quote($codes, $cycle, $company, $sub);
            }

            \App\Services\PosAddonService::activate(
                $company,
                $quote['codes'],
                $quote['cycle'],
                $quote['total'],
                $locked->id,
                $sub,
                $quote
            );

            $locked->update([
                'status' => 'verified',
                'addon_codes' => json_encode($quote['codes']),
                'subscription_id' => $sub?->id,
                'verified_by' => auth('admin')->id(),
                'verified_at' => now(),
                'reject_reason' => null,
            ]);

            return [
                'outcome' => 'approved',
                'company' => $company,
                'subscription' => $sub,
                'quote' => $quote,
            ];
        });

        if (($result['outcome'] ?? null) !== 'approved') {
            return back()->with('error', match ($result['outcome'] ?? '') {
                'not_eligible' => 'This shop is no longer eligible for paid add-ons (it must be on a live, paid Business-or-higher PRA POS package). Sort the package out first, or reject this request and refund the shop.',
                'no_expiry' => 'This shop has no dated active package to attach the add-on to. Renew or fix the package first — an add-on must always expire with it.',
                'subscription_changed' => 'This shop’s package changed after it submitted this add-on request. Reject this proof and ask the shop to submit a fresh request against its current package.',
                'no_codes' => 'This payment proof no longer contains a valid feature selection. Reject it and ask the shop to submit a fresh request.',
                default => 'This payment proof was already processed.',
            });
        }

        $quote = $result['quote'];

        // A freshly bought feature must be visible on the very next request.
        \App\Services\PosFeatureService::flushGateCaches();

        AdminAuditLog::log(auth('admin')->id(), 'POS feature add-on approved', 'PaymentProof', $proof->id, [
            'company_id' => $proof->company_id,
            'addon_codes' => $quote['codes'],
            'billing_cycle' => $quote['cycle'],
            'quoted_total' => $quote['total'],
            'amount_claimed' => $proof->amount,
            'ends_at' => (string) ($result['subscription']->end_date ?? ''),
        ]);

        $this->notifyCompany($proof->fresh(['company', 'pricingPlan']), 'approved');

        $labels = array_map(
            fn ($code) => \App\Services\PosAddonPricingService::ADDONS[$code]['label'] ?? $code,
            $quote['codes']
        );

        return back()->with('success', 'Feature add-on approved — ' . ($result['company']->name ?? 'the shop')
            . ' now has: ' . implode(', ', $labels) . '. The package and its expiry were not changed.');
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

            // AI Reader page top-up: package ki baat hi nahi — sirf pages.
            if ($proof->isAiPages()) {
                $pages = $proof->aiPagesRequested();
                $pagesLabel = $pages > 0 ? number_format($pages) . ' AI Reader pages' : 'your AI Reader pages';

                if ($decision === 'approved') {
                    $balance = \App\Services\AiPageCreditService::purchasedBalance($company);

                    $title = 'AI pages added';
                    $message = "{$pagesLabel} have been added to your account. Purchased pages never expire — they are used after your monthly allowance runs out.";
                    $subject = 'AI Reader pages added to your account';
                    $headline = 'Your AI Reader pages are ready.';
                    $paragraphs = array_values(array_filter([
                        "We have verified your top-up payment for {$company->name}.",
                        "Pages added: {$pagesLabel}.",
                        'Purchased balance: ' . number_format($balance) . ' pages. These never expire and are only used once your monthly package allowance is finished.',
                        'Your package, its expiry date and its price stay exactly the same.',
                    ]));
                    $ctaLabel = 'Open AI Invoice Reader';
                } else {
                    $reason = trim((string) $proof->reject_reason);
                    $reasonLine = $reason !== '' ? $reason : 'No reason specified — please contact support.';
                    if (!str_ends_with($reasonLine, '.')) {
                        $reasonLine .= '.';
                    }
                    $title = 'AI page top-up rejected';
                    $message = 'Your AI page top-up was rejected: ' . $reasonLine . ' No pages were added.';
                    $subject = 'AI page top-up rejected — action required';
                    $headline = 'Your AI page top-up could not be verified.';
                    $paragraphs = [
                        "The top-up payment you submitted for {$company->name} could not be verified.",
                        "Reason: {$reasonLine}",
                        'No pages were added. Please submit a new payment proof from the Billing page, or contact our support team on WhatsApp.',
                    ];
                    $ctaLabel = 'Log In & Resubmit';
                }

                Notification::create([
                    'company_id' => $company->id,
                    'type' => $decision === 'approved' ? 'ai_pages_approved' : 'ai_pages_rejected',
                    'title' => $title,
                    'message' => $message,
                    'read' => false,
                    'metadata' => [
                        'payment_proof_id' => $proof->id,
                        'product_type' => 'di',
                        'pages' => $pages,
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
                        Log::warning('AI page top-up decision email failed', [
                            'payment_proof_id' => $proof->id,
                            'company_id' => $company->id,
                            'decision' => $decision,
                            'error' => $e->getMessage(),
                        ]);

                        \App\Services\MailHealth::recordFailure('AI page top-up decision email', $e);
                    }
                }

                return;
            }

            // Paid feature add-on: package ki baat hi nahi — sirf chune hue features.
            if ($proof->isPosAddon()) {
                $codes = $proof->addonCodeList();
                $labels = array_map(
                    fn ($code) => \App\Services\PosAddonPricingService::ADDONS[$code]['label'] ?? $code,
                    $codes
                );
                $featureList = $labels ? implode(', ', $labels) : 'the requested features';

                if ($decision === 'approved') {
                    $endsAt = \App\Models\PosAddon::where('company_id', $company->id)
                        ->whereIn('addon_code', $codes ?: ['__none__'])
                        ->orderByDesc('ends_at')
                        ->value('ends_at');

                    $title = 'Feature add-on activated';
                    $message = "Your feature add-on is active: {$featureList}. Your package and its expiry date have not changed.";
                    $subject = 'Feature add-on activated — you can use it now';
                    $headline = 'Your new features are switched on.';
                    $paragraphs = array_values(array_filter([
                        "We have verified your payment for {$company->name}.",
                        "Features activated: {$featureList}.",
                        $endsAt ? 'Active until ' . \Carbon\Carbon::parse($endsAt)->format('d M Y') . ' — they renew with your package.' : null,
                        'Log in to your NestPOS panel to start using them. Your package, its expiry date and its price stay exactly the same.',
                    ]));
                    $ctaLabel = 'Open My Panel';
                } else {
                    $reason = trim((string) $proof->reject_reason);
                    $reasonLine = $reason !== '' ? $reason : 'No reason specified — please contact support.';
                    if (!str_ends_with($reasonLine, '.')) {
                        $reasonLine .= '.';
                    }
                    $title = 'Feature add-on request rejected';
                    $message = 'Your feature add-on request was rejected: ' . $reasonLine . ' No features were switched on.';
                    $subject = 'Feature add-on request rejected — action required';
                    $headline = 'Your feature add-on request could not be verified.';
                    $paragraphs = [
                        "The add-on payment you submitted for {$company->name} could not be verified.",
                        "Reason: {$reasonLine}",
                        'No features were switched on. Please submit a new payment proof from the Billing page, or contact our support team on WhatsApp.',
                    ];
                    $ctaLabel = 'Log In & Resubmit';
                }

                Notification::create([
                    'company_id' => $company->id,
                    'type' => $decision === 'approved' ? 'pos_addon_approved' : 'pos_addon_rejected',
                    'title' => $title,
                    'message' => $message,
                    'read' => false,
                    'metadata' => [
                        'payment_proof_id' => $proof->id,
                        'product_type' => $plan?->product_type ?? 'pos',
                        'addon_codes' => $codes,
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
                        Log::warning('POS add-on decision email failed', [
                            'payment_proof_id' => $proof->id,
                            'company_id' => $company->id,
                            'decision' => $decision,
                            'error' => $e->getMessage(),
                        ]);

                        \App\Services\MailHealth::recordFailure('POS add-on decision email', $e);
                    }
                }

                return;
            }

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
