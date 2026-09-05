<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\PaymentProof;
use App\Models\PricingPlan;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Paid extra-branch add-on — SINGLE source of truth (owner-approved, 21 Aug 2026).
 *
 * Owner ka faisla: har package apne card par likhi branches MUFT deta hai
 * (Starter 1, Business 1, Pro 3, Unlimited 5). Us se ooper jitni
 * branches shop chahe, har branch Rs 10,000 SAALANA.
 *
 * Har wo jagah jo ye paisa dikhati ya check karti hai — Branches page, POS
 * billing page, expiry popup, trial-lock modal, payment-proof screen, admin
 * panel aur renewal ka charge — YAHIN se hisaab leti hai, taake do alag number
 * kabhi na dikhen:
 *
 *   saal bhar ka add-on  = slots × 10,000
 *   kisi cycle ka add-on = slots × 10,000 × (us cycle ke mahine / 12)
 *   beech-saal kharidari = qty × 10,000 × (subscription khatam hone tak baqi mahine / 12)
 *
 * Scope: dono POS lines — PRA POS ('pos') aur FBR POS ('fbrpos'). FBR POS 23
 * Aug 2026 ko shamil hua jab uska Business package 2 branches par set hua; DI
 * par ye add-on ab bhi nahi.
 * Slots companies.extra_branch_slots par mehfooz hain aur SIRF admin approval
 * (payment proof) ya admin ke hath se badalte hain.
 */
class BranchAddonService
{
    /** Rs per extra branch, per YEAR. */
    public const PRICE_PER_YEAR = 10000;

    /**
     * Product lines jin par ye add-on chalta hai.
     *
     * Digital Invoice aur Nest ERPS (Task 1568) is list se BAHAR hain — donon
     * branch slots nahi bechte. Ye list hi wahid faisla hai; isEligible() se
     * poochein, kahin bhi product type ka apna match na likhein.
     */
    public const PRODUCT_TYPES = ['pos', 'fbrpos'];

    /**
     * Kya is product line par extra-branch add-on bikta hai?
     *
     * Nest ERPS ke liye jawab sochi-samjhi 'nahi' hai: uske panels branches
     * platform ke shared branch service se lete hain, per-branch slot bech kar
     * nahi. Ye sawal yahan explicit hai taake koi buying path unmatched value
     * ke zariye Digital Invoice ke default par na gir jaye.
     */
    public static function isEligible(?string $productType): bool
    {
        return $productType !== null && in_array($productType, self::PRODUCT_TYPES, true);
    }

    /** Ek request mein maximum slots (operator-error guard). */
    public const MAX_QTY = 20;

    /** hasColumn() per call = DB round trip; per-request memo. */
    private static ?bool $slotsColumn = null;

    public static function slotsColumnExists(): bool
    {
        if (self::$slotsColumn === null) {
            try {
                self::$slotsColumn = Schema::hasColumn('companies', 'extra_branch_slots');
            } catch (\Throwable $e) {
                self::$slotsColumn = false;
            }
        }

        return self::$slotsColumn;
    }

    /**
     * Company row nikalein — SIRF add-on ka hisaab lagane ke liye.
     *
     * Ye enrichment hai, core nahi: jahan companies table hi maujood na ho
     * (lightweight unit tests) ya slots column abhi migrate na hua ho, wahan
     * null lauta kar purana behaviour bilkul waisa hi rehta hai.
     */
    public static function findCompany(?int $companyId): ?Company
    {
        if (!$companyId || !self::slotsColumnExists()) {
            return null;
        }

        try {
            return Company::find($companyId);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Kitne extra branch slots is company ne khareed rakhe hain. */
    public static function slots(?Company $company): int
    {
        if (!$company || !self::slotsColumnExists()) {
            return 0;
        }

        return max(0, (int) ($company->extra_branch_slots ?? 0));
    }

    /**
     * Is this company on a product line / package the add-on is sold for?
     *
     * Looser than applicableSlots() on purpose: it ignores expiry and the admin
     * branch override, so an admin can legitimately pre-load slots just before
     * a renewal. It still refuses FBR POS / DI / standalone / trial companies —
     * the add-on does not exist for them, so storing a number there would be a
     * lie that the enforcement layer silently ignores.
     */
    public static function supportsCompany(?Company $company): bool
    {
        if (!$company) {
            return false;
        }

        $sub = self::activeSubscription($company);
        if (!$sub || !self::supportsPlan($sub->pricingPlan)) {
            return false;
        }

        $included = $sub->pricingPlan->branch_limit;

        return $included !== null && (int) $included !== -1;
    }

    /**
     * Slots that may actually WIDEN a branch limit right now.
     *
     * slots() is the raw stored number (admin display, receipts, audit).
     * This is the enforcement value, and it is deliberately stricter: the
     * add-on is a PRA POS product, so a stored count must never quietly hand
     * free branches to an FBR POS / DI / standalone company, to a trial, or to
     * a package that has already expired. A company that switches product line
     * (or whose plan is swapped by an admin) therefore loses the widened limit
     * on the spot, without anyone having to remember to zero the column.
     *
     * Unlimited-branch plans are excluded too — there is nothing to widen, and
     * counting slots there would report a nonsense limit.
     */
    public static function applicableSlots(?Company $company, ?Subscription $sub = null): int
    {
        $slots = self::slots($company);
        if ($slots < 1) {
            return 0;
        }

        $sub ??= self::activeSubscription($company);
        if (!$sub || !self::supportsPlan($sub->pricingPlan)) {
            return 0;
        }

        $included = $sub->pricingPlan->branch_limit;
        if ($included === null || (int) $included === -1) {
            return 0;
        }

        // Miyaad guzar chuki: package hi band hai, add-on ki gunjaish nahi.
        if (self::remainingMonths($sub) < 1) {
            return 0;
        }

        return $slots;
    }

    /** Shop ki abhi ki branches (gate bhi isi ginti par chalta hai). */
    public static function branchCount(?Company $company): int
    {
        if (!$company) {
            return 0;
        }

        try {
            return Branch::where('company_id', $company->id)->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Kam se kam kitne paid slots RAKHNE parenge, taake koi maujooda branch
     * hadd se bahar na reh jaye.
     *
     * Renewal approve karte waqt admin slots kam kar sakta hai (shop ne base
     * package ke paise bheje, add-on ke nahi). Ye us kami ki hadd hai: package
     * ki shamil branches + baqi slots shop ki maujooda branches se kam nahi ho
     * sakte — warna branch to bani rehti hai magar limit se bahar, aur koi gate
     * use hata bhi nahi sakta.
     *
     * Admin branch override ya internal account ki soorat mein branch limit ka
     * slots se koi taalluq hi nahi rehta, is liye wahan koi floor bhi nahi.
     *
     * Floor kabhi shop ke maujooda slots se ooper nahi jata: agar shop pehle hi
     * hadd se bahar hai (maslan admin ne hath se slots kam kar diye the), to
     * matlab sirf itna hai ke ab kami mumkin nahi — renewal khud block nahi hota.
     */
    public static function minimumSlotsForBranches(?Company $company, ?PricingPlan $plan): int
    {
        if (!$company || !self::supportsPlan($plan)) {
            return 0;
        }

        if ($company->is_internal_account || ($company->branch_limit_override ?? null) !== null) {
            return 0;
        }

        $included = $plan->branch_limit;
        if ($included === null || (int) $included === -1) {
            return 0;
        }

        $floor = max(0, self::branchCount($company) - (int) $included);

        return min($floor, self::slots($company));
    }

    /**
     * Renewal proof ka jaiza — mutawaqqa total bmuqabla jo raqam shop keh raha
     * hai ke usne bheji.
     *
     * Renewal ka quote pehle hi base package + (slots × 10,000) hota hai, magar
     * ab tak koi ye nahi dekhta tha ke shop ne wo BARHA HUA total bheja bhi hai
     * ya sirf package ke paise. Dono number — aur wo kam se kam slots jin se
     * neeche jana branches ko hadd se bahar chhod dega — YAHIN se nikalte hain,
     * taake admin ki screen aur approve wali validation kabhi alag baat na karen.
     *
     * @param  float|string|null  $paid  Jo raqam shop ne proof par likhi.
     * @return array{applies:bool,slots:int,min_slots:int,branches:int,included:?int,cycle:string,base_price:float,addon_price:float,expected_total:float,paid:?float,short:bool,shortfall:float}
     */
    public static function renewalReview(?Company $company, ?PricingPlan $plan, ?string $cycle, $paid = null): array
    {
        $slots = self::slots($company);
        // (Task 1441) "applies" tracks whether the add-on line is actually
        // charged, so it must key off BILLABLE slots — an unlimited package
        // has stored slots but bills nothing, and the review must say so
        // (addon_price 0, not a phantom line).
        $applies = $company !== null && self::billableSlots($company, $plan) > 0;

        $cycle = SubscriptionAssignmentService::normalizeCycle($cycle);
        $base = 0.0;
        $addon = 0.0;

        if ($plan) {
            // Wahi hisaab jo renewal par charge hota hai — dobara nahi banaya jata.
            $priced = SubscriptionAssignmentService::computePrice($plan, $cycle, $applies ? $company : null);
            $cycle = $priced['cycle'];
            $base = (float) $priced['base_price'];
            $addon = (float) $priced['extra_branch_price'];
        }

        $expected = $base + $addon;
        $paidAmount = ($paid === null || $paid === '') ? null : (float) $paid;
        $shortfall = $paidAmount === null ? 0.0 : max(0.0, round($expected - $paidAmount));

        $includedRaw = $plan?->branch_limit;

        return [
            'applies' => $applies,
            'slots' => $slots,
            'min_slots' => $applies ? self::minimumSlotsForBranches($company, $plan) : 0,
            'branches' => $applies ? self::branchCount($company) : 0,
            'included' => ($includedRaw === null || (int) $includedRaw === -1) ? null : (int) $includedRaw,
            'cycle' => $cycle,
            'base_price' => $base,
            'addon_price' => $addon,
            'expected_total' => $expected,
            'paid' => $paidAmount,
            'short' => $paidAmount !== null && $shortfall > 0,
            'shortfall' => $shortfall,
        ];
    }

    /** Add-on sirf PRA POS packages par. */
    public static function supportsPlan(?PricingPlan $plan): bool
    {
        return $plan !== null
            && !$plan->is_trial
            && in_array($plan->product_type ?? 'di', self::PRODUCT_TYPES, true);
    }

    /** Ek cycle ke mahine (annual 12, quarterly 3, …). */
    public static function monthsForCycle(?string $cycle): int
    {
        return max(1, (int) Subscription::getMonthsForCycle(
            SubscriptionAssignmentService::normalizeCycle($cycle)
        ));
    }

    /** THE formula: slots × 10,000 × months/12. */
    public static function priceForMonths(int $slots, int $months): float
    {
        return round(max(0, $slots) * self::PRICE_PER_YEAR * max(0, $months) / 12);
    }

    /**
     * Slots that may actually be BILLED for a given package.
     *
     * (Task 1441) The pricing half must agree with the enforcement half: an
     * unlimited-branch package (branch_limit null / -1) already grants every
     * branch for free, so applicableSlots() treats stored slots as inert there.
     * Charging slots × 10,000 on top of an unlimited plan bills a shop for
     * capacity it already has — the exact bug this closes. Unlike
     * applicableSlots(), this deliberately ignores expiry: a renewal quote for
     * a lapsed package must still price the slots the shop is renewing.
     *
     * The stored count is left DORMANT on purpose (never zeroed here): a shop
     * that later downgrades back to a limited package gets its bought capacity
     * — and its billing — restored automatically, with no admin cleanup step.
     */
    public static function billableSlots(?Company $company, ?PricingPlan $plan): int
    {
        if (!self::supportsPlan($plan)) {
            return 0;
        }

        $included = $plan->branch_limit;
        if ($included === null || (int) $included === -1) {
            return 0;
        }

        return self::slots($company);
    }

    /**
     * Renewal / naya subscription banate waqt add-on ka hissa.
     * Sirf tab jab package add-on support karta ho AUR company ke BILLABLE
     * slots hon (unlimited package par slots inert — dekhein billableSlots()).
     */
    public static function addonForCycle(?Company $company, ?PricingPlan $plan, ?string $cycle): float
    {
        $slots = self::billableSlots($company, $plan);
        if ($slots < 1) {
            return 0.0;
        }

        return self::priceForMonths($slots, self::monthsForCycle($cycle));
    }

    /** Company ka abhi ka active subscription (plan ke sath). */
    public static function activeSubscription(?Company $company): ?Subscription
    {
        if (!$company) {
            return null;
        }

        return Subscription::where('company_id', $company->id)
            ->where('active', true)
            ->with('pricingPlan')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Subscription khatam hone tak baqi mahine (pro-rata ki bunyad).
     * end_date na ho to poora saal; guzar chuka ho to 0 (pehle renewal).
     */
    public static function remainingMonths(?Subscription $sub): int
    {
        if (!$sub || empty($sub->end_date)) {
            return 12;
        }

        try {
            $end = Carbon::parse($sub->end_date)->endOfDay();
        } catch (\Throwable $e) {
            return 12;
        }

        if ($end->isPast()) {
            return 0;
        }

        // Adhoora mahina bhi poora charge hota hai (owner: "mahinon ke hisaab se").
        $months = (int) ceil(Carbon::now()->diffInDays($end) / 30.4375);

        return max(1, min(12, $months));
    }

    /**
     * Ek kharidari ka exact qabil-e-adayegi hisaab.
     *
     * @return array{qty:int,months:int,price:float,full_price:float,per_year:int,prorated:bool,until:?string}
     */
    public static function quote(?Company $company, int $qty): array
    {
        $qty = max(1, min(self::MAX_QTY, $qty));
        $sub = self::activeSubscription($company);
        $months = self::remainingMonths($sub);

        return [
            'qty' => $qty,
            'months' => $months,
            'price' => self::priceForMonths($qty, $months),
            'full_price' => (float) ($qty * self::PRICE_PER_YEAR),
            'per_year' => self::PRICE_PER_YEAR,
            'prorated' => $months > 0 && $months < 12,
            'until' => $sub && $sub->end_date ? Carbon::parse($sub->end_date)->toDateString() : null,
        ];
    }

    /**
     * Kya ye company abhi extra branch khareed sakti hai?
     *
     * @return array{allowed:bool, reason_key:?string}
     */
    public static function purchaseEligibility(?Company $company): array
    {
        if (!$company) {
            return ['allowed' => false, 'reason_key' => 'pos.eb_reason_no_plan'];
        }

        // Internal accounts har hadd se azad hain — inhen kuch kharidne ki zaroorat nahi.
        if ($company->is_internal_account) {
            return ['allowed' => false, 'reason_key' => 'pos.eb_reason_unlimited'];
        }

        // Admin ka branch override har cheez par bhaari hai: slots barhane se
        // gate par koi farq nahi padega, is liye kharidari ka raasta band.
        if (($company->branch_limit_override ?? null) !== null) {
            return ['allowed' => false, 'reason_key' => 'pos.eb_reason_admin_limit'];
        }

        $sub = self::activeSubscription($company);
        if (!$sub || !$sub->pricingPlan) {
            return ['allowed' => false, 'reason_key' => 'pos.eb_reason_no_plan'];
        }

        // Trial wali company pehle package le — add-on trial par nahi milta.
        if ($sub->pricingPlan->is_trial) {
            return ['allowed' => false, 'reason_key' => 'pos.eb_reason_trial'];
        }

        if (!self::supportsPlan($sub->pricingPlan)) {
            return ['allowed' => false, 'reason_key' => 'pos.eb_reason_unsupported'];
        }

        // Jis package ki branches pehle hi unlimited hain, us par add-on bemani hai.
        $included = $sub->pricingPlan->branch_limit;
        if ($included === null || (int) $included === -1) {
            return ['allowed' => false, 'reason_key' => 'pos.eb_reason_unlimited'];
        }

        // Miyaad guzar chuki — pehle renewal, phir add-on.
        if (self::remainingMonths($sub) < 1) {
            return ['allowed' => false, 'reason_key' => 'pos.eb_reason_expired'];
        }

        return ['allowed' => true, 'reason_key' => null];
    }

    /** Is company ki koi extra-branch request abhi review mein hai? */
    public static function hasPendingRequest(?Company $company): bool
    {
        if (!$company) {
            return false;
        }

        try {
            if (!Schema::hasTable('payment_proofs')) {
                return false;
            }

            return PaymentProof::extraBranchKind()
                ->where('company_id', $company->id)
                ->where('status', 'pending')
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Branches page ke liye poori tasveer — ek hi jagah se, taake page aur
     * gate kabhi alag baat na karen.
     */
    public static function status(?Company $company): array
    {
        $sub = self::activeSubscription($company);
        $plan = $sub?->pricingPlan;
        // Raw = what the shop OWNS (always shown, they paid for it);
        // applicable = what actually widens the limit today. The page must
        // quote the same ceiling the gate enforces, never a rosier one.
        $slots = self::slots($company);
        $liveSlots = self::applicableSlots($company, $sub);

        $includedRaw = $plan?->branch_limit;
        $unlimited = $includedRaw === null || (int) $includedRaw === -1;
        $included = $unlimited ? null : (int) $includedRaw;

        $override = $company?->branch_limit_override ?? null;
        $limit = match (true) {
            (bool) ($company?->is_internal_account) => null,
            $override !== null && (int) $override === -1 => null,
            $override !== null => (int) $override,
            $unlimited => null,
            default => $included + $liveSlots,
        };

        $eligibility = self::purchaseEligibility($company);

        // Server-side quotes 1..MAX_QTY — page par wahi raqam dikhti hai jo
        // approve hone par charge hoti hai (koi client-side hisaab nahi).
        $quotes = [];
        if ($eligibility['allowed']) {
            for ($i = 1; $i <= self::MAX_QTY; $i++) {
                $quotes[$i] = self::quote($company, $i);
            }
        }

        return [
            'plan_name' => $plan?->name,
            'included' => $included,
            'unlimited' => $unlimited,
            'slots' => $slots,
            'used' => $company ? Branch::where('company_id', $company->id)->count() : 0,
            'limit' => $limit,
            'override' => $override,
            'per_year' => self::PRICE_PER_YEAR,
            'eligibility' => $eligibility,
            'pending' => self::hasPendingRequest($company),
            'quotes' => $quotes,
            'renewal_addon' => self::addonForCycle($company, $plan, $sub->billing_cycle ?? 'annual'),
        ];
    }
}
