<?php

namespace App\Services;

use App\Models\Company;
use App\Models\ConsultantClientLink;
use App\Models\ConsultantCommission;
use App\Models\ConsultantInvite;
use App\Models\ConsultantProfile;
use App\Models\Invoice;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

/**
 * Tax Consultant Console + affiliate program — single source of truth for
 * linking rules, health signals and commission recording.
 *
 * Consent invariant: a link may ONLY become 'active' through a client-side
 * action — the client admin approving a pending request, or a consultant
 * redeeming an invite code that the client admin generated. There is no
 * other activation path (not even for SaaS admin).
 *
 * Scope invariant: this is a DI web-guard feature. Only product_type='di'
 * companies can be linked; POS / FBR-POS panels are untouched.
 */
class ConsultantService
{
    public const SESSION_KEY = 'consultant_console';

    // ── Codes ───────────────────────────────────────────────────────────

    public static function generateReferralCode(): string
    {
        do {
            $code = 'TC-' . strtoupper(Str::random(6));
        } while (ConsultantProfile::where('referral_code', $code)->exists());

        return $code;
    }

    public static function generateInviteCode(): string
    {
        do {
            $code = 'CI-' . strtoupper(Str::random(8));
        } while (ConsultantInvite::where('code', $code)->exists());

        return $code;
    }

    // ── Profile ─────────────────────────────────────────────────────────

    public static function profileFor(?int $userId): ?ConsultantProfile
    {
        return $userId ? ConsultantProfile::where('user_id', $userId)->first() : null;
    }

    public static function activeProfileFor(?int $userId): ?ConsultantProfile
    {
        $profile = self::profileFor($userId);
        return ($profile && $profile->isActive()) ? $profile : null;
    }

    /** Opt a user in as consultant (idempotent — re-joining returns the existing profile). */
    public static function activateProfile(User $user): ConsultantProfile
    {
        $profile = self::profileFor($user->id);
        if ($profile) {
            return $profile;
        }

        $profile = ConsultantProfile::create([
            'user_id' => $user->id,
            'referral_code' => self::generateReferralCode(),
            'status' => 'active',
            'commission_rate' => 10.00,
        ]);

        AuditLogService::log('consultant_profile_activated', 'ConsultantProfile', $profile->id, null, [
            'referral_code' => $profile->referral_code,
        ], $user->company_id, $user->id);

        return $profile;
    }

    // ── Linking (consent-based) ─────────────────────────────────────────

    /**
     * Consultant asks a DI company (by NTN) for access. Creates/reuses the
     * pair row as 'pending' — NEVER activates. Returns void: the caller must
     * show a non-enumerating generic message whether or not the NTN matched.
     */
    public static function requestLink(User $consultant, string $ntn): void
    {
        $company = Company::where('ntn', trim($ntn))
            ->where('product_type', 'di')
            ->first();

        if (!$company || $company->id === $consultant->company_id) {
            return; // generic message shown either way — no NTN enumeration
        }

        $link = ConsultantClientLink::firstOrNew([
            'consultant_user_id' => $consultant->id,
            'company_id' => $company->id,
        ]);

        if ($link->exists && in_array($link->status, ['active', 'pending'], true)) {
            return; // already linked or already asked — nothing to do
        }

        $link->fill([
            'status' => 'pending',
            'initiated_by' => 'consultant',
            'approved_by_user_id' => null,
            'approved_at' => null,
            'revoked_by' => null,
            'revoked_at' => null,
        ])->save();

        AuditLogService::log('consultant_link_requested', 'ConsultantClientLink', $link->id, null, [
            'consultant_user_id' => $consultant->id,
            'consultant_name' => $consultant->name,
        ], $company->id, $consultant->id);

        ConsultantMailer::linkRequested($link, $consultant, $company);
    }

    /**
     * Client-side consent: approve a pending request. $approver must be a
     * company_admin of the link's company (checked by the caller's route
     * scoping AND re-checked here).
     */
    public static function approveLink(ConsultantClientLink $link, User $approver): bool
    {
        if ($link->status !== 'pending' || $approver->company_id !== $link->company_id) {
            return false;
        }

        $link->update([
            'status' => 'active',
            'approved_by_user_id' => $approver->id,
            'approved_at' => now(),
            'revoked_by' => null,
            'revoked_at' => null,
        ]);

        AuditLogService::log('consultant_link_activated', 'ConsultantClientLink', $link->id, null, [
            'via' => 'client_approval',
            'consultant_user_id' => $link->consultant_user_id,
            'approved_by' => $approver->id,
        ], $link->company_id, $approver->id);

        ConsultantMailer::linkApproved($link);

        return true;
    }

    /**
     * Client-side consent: redeem a single-use invite code the client admin
     * generated. Activates (or re-activates) the pair link.
     * Returns the linked company, or null when the code is invalid.
     */
    public static function redeemInvite(User $consultant, string $code): ?Company
    {
        return DB::transaction(function () use ($consultant, $code) {
            $invite = ConsultantInvite::where('code', strtoupper(trim($code)))->lockForUpdate()->first();

            if (!$invite || !$invite->isRedeemable()) {
                return null;
            }

            $company = Company::find($invite->company_id);
            if (!$company || $company->product_type !== 'di' || $company->id === $consultant->company_id) {
                return null;
            }

            $link = ConsultantClientLink::firstOrNew([
                'consultant_user_id' => $consultant->id,
                'company_id' => $company->id,
            ]);

            $invite->update(['used_by_user_id' => $consultant->id, 'used_at' => now()]);
            $link->fill([
                'status' => 'active', 'initiated_by' => 'client',
                'approved_by_user_id' => $invite->created_by_user_id, 'approved_at' => now(),
                'revoked_by' => null, 'revoked_at' => null,
            ])->save();

            AuditLogService::log('consultant_link_activated', 'ConsultantClientLink', $link->id, null, [
                'via' => 'invite_code', 'invite_id' => $invite->id,
                'consultant_user_id' => $consultant->id, 'consultant_name' => $consultant->name,
            ], $company->id, $consultant->id);

            return $company;
        });
    }

    /** Revoke (or reject a pending) link — either side or admin can do this. */
    public static function revokeLink(ConsultantClientLink $link, string $by, ?int $actorUserId = null): void
    {
        if ($link->status === 'revoked') {
            return;
        }

        $wasPending = $link->status === 'pending';

        $link->update([
            'status' => 'revoked',
            'revoked_by' => $by,
            'revoked_at' => now(),
        ]);

        AuditLogService::log(
            $wasPending ? 'consultant_link_rejected' : 'consultant_link_revoked',
            'ConsultantClientLink',
            $link->id,
            null,
            ['by' => $by, 'consultant_user_id' => $link->consultant_user_id],
            $link->company_id,
            $actorUserId
        );

        // Email the consultant when the client or SaaS admin ended the link —
        // never when the consultant cancelled/revoked it themself.
        if ($by !== 'consultant') {
            ConsultantMailer::linkRejectedOrRevoked($link, $wasPending);
        }
    }

    /** The active link for a consultant/company pair, or null. */
    public static function activeLink(int $consultantUserId, int $companyId): ?ConsultantClientLink
    {
        return ConsultantClientLink::where('consultant_user_id', $consultantUserId)
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->first();
    }

    // ── Console health signals ──────────────────────────────────────────

    /**
     * Health chips for every ACTIVE client of a consultant, computed without
     * N+1 explosions. Cross-company reads here are consented (active link)
     * and aggregate-only (counts / plan info — never invoice contents).
     *
     * @return array<int, array{link: ConsultantClientLink, company: Company, health: array}>
     */
    public static function clientsWithHealth(int $consultantUserId): array
    {
        $links = ConsultantClientLink::with(['company.activeSubscription.pricingPlan'])
            ->where('consultant_user_id', $consultantUserId)
            ->where('status', 'active')
            ->orderBy('id')
            ->get()
            ->filter(fn ($l) => $l->company !== null);

        $companyIds = $links->pluck('company_id')->all();
        if (!$companyIds) {
            return [];
        }

        $failed30 = Invoice::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)
            ->whereIn('company_id', $companyIds)
            ->where('status', 'failed')
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('company_id, COUNT(*) as c')
            ->groupBy('company_id')
            ->pluck('c', 'company_id');

        $totalInvoices = Invoice::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)
            ->whereIn('company_id', $companyIds)
            ->selectRaw('company_id, COUNT(*) as c')
            ->groupBy('company_id')
            ->pluck('c', 'company_id');

        $out = [];
        foreach ($links as $link) {
            $company = $link->company;
            $sub = $company->activeSubscription;
            $plan = $sub?->pricingPlan;

            // Quota: DI plans cap TOTAL invoices (company override wins; -1 = unlimited).
            $limit = $company->invoice_limit_override ?? $plan?->invoice_limit ?? null;
            $used = (int) ($totalInvoices[$company->id] ?? 0);
            $quotaPct = ($limit !== null && (int) $limit > 0)
                ? min(100, (int) round($used * 100 / (int) $limit))
                : null;

            // Expiry: paid end_date, or trial end for trial plans.
            $expiry = null;
            if ($sub) {
                $expiry = ($plan && $plan->is_trial && $sub->trial_ends_at)
                    ? Carbon::parse($sub->trial_ends_at)
                    : ($sub->end_date ? Carbon::parse($sub->end_date) : null);
            }
            $daysLeft = $expiry ? (int) now()->startOfDay()->diffInDays($expiry->copy()->startOfDay(), false) : null;

            $access = SubscriptionAccessService::hasAccess($company);

            $out[] = [
                'link' => $link,
                'company' => $company,
                'health' => [
                    'plan_name' => $plan?->name,
                    'is_trial' => (bool) ($plan->is_trial ?? false),
                    'quota_used' => $used,
                    'quota_limit' => ($limit === null || (int) $limit === -1) ? null : (int) $limit,
                    'quota_pct' => $quotaPct,
                    'expiry' => $expiry,
                    'days_left' => $daysLeft,
                    'failed_30d' => (int) ($failed30[$company->id] ?? 0),
                    'access_allowed' => (bool) ($access['allowed'] ?? false),
                    'access_reason' => $access['reason'] ?? '',
                    'approval_pending' => $company->status === 'pending',
                ],
            ];
        }

        return $out;
    }

    // ── Referral attribution & commissions ──────────────────────────────

    /** Find the active consultant profile matching a referral code (case-insensitive). */
    public static function profileForReferralCode(?string $code): ?ConsultantProfile
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '') {
            return null;
        }
        return ConsultantProfile::where('referral_code', $code)
            ->where('status', 'active')
            ->first();
    }

    /**
     * Commission hook — called from SubscriptionAssignmentService::assign()
     * (the single funnel for admin-recorded payments: manual assignment,
     * payment-proof approval, approval-time requested-plan activation).
     *
     * Attribution rule: companies carry referred_by_user_id set ONCE at
     * signup; every recorded payment of that company earns the consultant
     * their profile's current rate. Duplicate-safe per subscription row.
     */
    public static function recordCommissionForSubscription(Subscription $sub): ?ConsultantCommission
    {
        try {
            $company = Company::find($sub->company_id);
            if (!$company || !$company->referred_by_user_id) {
                return null;
            }

            $profile = ConsultantProfile::where('user_id', $company->referred_by_user_id)
                ->where('status', 'active')
                ->first();
            if (!$profile) {
                return null; // disabled/removed consultants earn nothing new
            }

            $plan = PricingPlan::find($sub->pricing_plan_id);
            if (!$plan || $plan->is_trial) {
                return null;
            }

            $base = (float) $sub->final_price;
            if ($base <= 0) {
                return null;
            }

            // One commission per recorded payment (= per subscription row).
            if (ConsultantCommission::where('subscription_id', $sub->id)->exists()) {
                return null;
            }

            $rate = (float) $profile->commission_rate;

            $commission = ConsultantCommission::create([
                'consultant_user_id' => $profile->user_id,
                'company_id' => $company->id,
                'company_name' => $company->name,
                'subscription_id' => $sub->id,
                'description' => trim(($plan->name ?? 'Plan') . ' · ' . ($sub->billing_cycle ?? 'monthly')),
                'base_amount' => round($base, 2),
                'rate_percent' => $rate,
                'amount' => round($base * $rate / 100, 2),
                'status' => 'pending',
                'source' => 'subscription',
            ]);

            ConsultantMailer::commissionRecorded($commission);

            return $commission;
        } catch (\Throwable $e) {
            // A commission failure must NEVER block recording the payment itself
            // (e.g. deploy-before-migrate window where the table doesn't exist yet).
            \Log::warning('Consultant commission not recorded: ' . $e->getMessage(), [
                'subscription_id' => $sub->id ?? null,
            ]);
            return null;
        }
    }
}
