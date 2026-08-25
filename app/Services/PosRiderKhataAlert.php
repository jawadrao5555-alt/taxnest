<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PosRider;
use App\Models\PosTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Rider settlement pending — the dashboard's cash-out-with-a-rider reminder.
 *
 * Pas-manzar (ZFC): day-close ke waqt ek bill isliye reh gaya ke rider ka cash
 * abhi wasool nahi hua tha — khata guard aisa bill ARCHIVE karta hai, delete
 * nahi, aur baad mein koi usay dobara nahi sameta. Shop ko pata tab chala jab
 * bill agle din tak para raha. Owner: "jis tarah baqi tamam issues dashboard
 * par aa jate hain, is ka bhi alert ho — kis kis rider ki settlement pari hai —
 * aur click par seedha rider settlement khul jaye."
 *
 * Yeh hisaab ek hi jagah rehta hai kyunke shop ek se zyada dashboard par utar
 * sakti hai: retail wala /pos/dashboard aur restaurant wala
 * /pos/restaurant/dashboard. Owner (25 Aug 2026, voice note) ne restaurant
 * dashboard par yeh alert na paya — wahan sirf pending-bills tile lagi thi —
 * to reminder us shop ke liye maujood hi nahi tha jise iski sab se zyada
 * zaroorat hai (delivery restaurant hi karte hain). Ab dono dashboard isi
 * service se poochte hain: naya dashboard banega to sirf ek call darkar hai.
 *
 * Predicate PosRider::openCashBills() ka hu-ba-hu aaina hai (archived bills bhi
 * shamil). Dashboard company ki liability ka reminder hai, reporting-stream
 * report nahi: cash tab tak dikhna chahiye jab tak waqai settle na ho.
 */
class PosRiderKhataAlert
{
    /**
     * @return Collection<int, array{id:int,name:string,bills:int,owed:float,days:int}>
     */
    public static function pending(int $companyId, ?Company $company = null): Collection
    {
        // PROD schema drift: rider columns purane shops par ho sakta hai na hon.
        if (!Schema::hasColumn('pos_transactions', 'rider_id')
            || !Schema::hasColumn('pos_transactions', 'rider_settlement_id')) {
            return collect();
        }

        // Yeh reminder company ki LIABILITY hai — rider ke naam ke saath kitna
        // cash bahar phansa hua hai. Settle sirf admin/manager kar sakta hai, is
        // liye cashier ko poori dukan ka bahar para cash dikhana bhi nahi
        // chahiye. Gate yahan (call site par nahi) taake koi naya caller ise
        // ghalti se bypass na kar sake.
        $viewer = auth('pos')->user();
        if ($viewer && $viewer->posCashierBlocked()) {
            return collect();
        }
        // Feature/plan gate jaan boojh kar YAHAN nahi lagaya: gate band hone par
        // bhi rider ke paas para cash phansna nahi chahiye (deliveries board bhi
        // isi wajah se khula rehta hai — PosRiderController::hasOpenRiderCash).
        // Khata khali hoga to neeche khud hi khali collection wapas jayegi.

        $q = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->whereNotNull('rider_id')
            ->where('payment_method', 'cash')
            ->whereNull('rider_settlement_id')
            ->where(function ($s) {
                $s->whereNull('delivery_status')->orWhere('delivery_status', '!=', 'returned');
            });

        // Multi-branch: baqi har dashboard figure ki tarah yeh reminder bhi
        // active branch ka hi hona chahiye. Warna ek branch ka manager doosri
        // branch ka rider cash dekhta hai — jis par wo kuch kar bhi nahi sakta,
        // aur do screenon par ek hi shop ke do alag hindsay aate hain.
        // Schema guard: purani DB par branch column/table ho hi na (PROD drift).
        if (Schema::hasColumn('pos_transactions', 'branch_id') && Schema::hasTable('branches')) {
            app(BranchContextService::class)->applyToQuery($q, 'branch_id');
        }

        // Ek hi grouped query — rider ke hisaab se ginti, raqam aur sab se
        // purane bill ki tareekh (N+1 se bachne ke liye).
        $rows = $q->groupBy('rider_id')
            ->selectRaw('rider_id, COUNT(*) as bills, COALESCE(SUM('
                . PosRider::remainingExpr('pos_transactions')
                . '), 0) as owed, MIN(created_at) as oldest')
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        $names = PosRider::where('company_id', $companyId)
            ->whereIn('id', $rows->pluck('rider_id')->all())
            ->pluck('name', 'id');

        return $rows->map(function ($r) use ($names) {
            $oldest = $r->oldest ? \Carbon\Carbon::parse($r->oldest) : null;
            return [
                'id'    => (int) $r->rider_id,
                'name'  => $names[$r->rider_id] ?? ('#' . $r->rider_id),
                'bills' => (int) $r->bills,
                'owed'  => (float) $r->owed,
                'days'  => $oldest ? (int) $oldest->copy()->startOfDay()->diffInDays(now()->startOfDay()) : 0,
            ];
        })->filter(fn ($r) => $r['owed'] > 0)->sortByDesc('owed')->values();
    }

    /**
     * Delivery bills that went out WITHOUT a rider and whose cash is still not
     * accounted for.
     *
     * Owner (25 Aug 2026, form answer): "Rok na lagayein, magar bina rider wale
     * delivery bills ka cash bhi dashboard par dikhe." Rider-less delivery is
     * the shop's own hand-over, so it deliberately does NOT block the day close
     * (undispatchedDeliverySummary ka usool waisa hi rehta hai) — but the cash
     * is out of the drawer all the same, and till now no dashboard figure said
     * so: rider khata alert sirf un bills ko ginta hai jin par rider laga ho.
     *
     * Predicate PosRiderController::index ke "fresh unassigned" pending bills ka
     * aaina hai (order_type delivery, koi rider nahi, koi delivery_status nahi,
     * 7 din ki khirki) — us list par "Delivered (no rider)" dabate hi bill yahan
     * se bhi nikal jata hai, is liye yeh reminder khud saaf hota hai.
     *
     * @return array{count:int,amount:float,days:int}
     */
    public static function unassigned(int $companyId, ?Company $company = null): array
    {
        $none = ['count' => 0, 'amount' => 0.0, 'days' => 0];

        if (!Schema::hasColumn('pos_transactions', 'delivery_status')
            || !Schema::hasColumn('pos_transactions', 'rider_id')
            || !Schema::hasColumn('pos_transactions', 'rider_settlement_id')) {
            return $none;
        }

        // pending() jaisa hi gate: bahar para cash poori dukan ki liability hai,
        // cashier ka manzar nahi.
        $viewer = auth('pos')->user();
        if ($viewer && $viewer->posCashierBlocked()) {
            return $none;
        }

        $q = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('order_type', 'delivery')
            ->where('payment_method', 'cash')
            ->whereNull('rider_id')
            ->whereNull('delivery_status')
            ->whereNull('rider_settlement_id')
            ->where('created_at', '>=', now()->subDays(7));

        // Provisional (local+local triple) abhi bikra hi nahi — uska cash bahar
        // nahi gaya, wo pending-bills tile ka mamla hai.
        if (Schema::hasColumn('pos_transactions', 'invoice_mode') && Schema::hasColumn('pos_transactions', 'pra_status')) {
            $q->whereNot(function ($w) {
                $w->where('invoice_mode', 'local')->where('pra_status', 'local');
            });
        }
        if (Schema::hasColumn('pos_transactions', 'transaction_type')) {
            $q->where(function ($w) {
                $w->whereNull('transaction_type')->orWhere('transaction_type', '!=', 'return');
            });
        }

        if (Schema::hasColumn('pos_transactions', 'branch_id') && Schema::hasTable('branches')) {
            app(BranchContextService::class)->applyToQuery($q, 'branch_id');
        }

        $row = $q->selectRaw('COUNT(*) as c, COALESCE(SUM(total_amount), 0) as amt, MIN(created_at) as oldest')->first();

        $count = (int) ($row->c ?? 0);
        if ($count < 1) {
            return $none;
        }

        $oldest = ($row->oldest ?? null) ? \Carbon\Carbon::parse($row->oldest) : null;

        return [
            'count'  => $count,
            'amount' => (float) ($row->amt ?? 0),
            'days'   => $oldest ? (int) $oldest->copy()->startOfDay()->diffInDays(now()->startOfDay()) : 0,
        ];
    }

    /**
     * Everything the dashboard needs about cash that is out on delivery — the
     * per-rider khata (banner) plus the rider-less bills, rolled into one chip.
     *
     * Owner (25 Aug 2026, voice note): "Main wo FIX button dhoond raha tha —
     * jaise provisional bill, open orders, cancel orders fix hain, waise hi
     * rider settlement bhi fix chahiye." The banner only appears when something
     * is pending; the chip must hold its place in the row even at zero, so the
     * eye always lands on the same spot. `enabled` decides whether the shop gets
     * that permanent chip at all — a shop that never delivers must not carry a
     * dead figure on its dashboard forever.
     *
     * @return array{enabled:bool,riders:Collection,bills:int,amount:float,unassigned:array{count:int,amount:float,days:int}}
     */
    public static function summary(int $companyId, ?Company $company = null): array
    {
        $riders = self::pending($companyId, $company);
        $unassigned = self::unassigned($companyId, $company);

        $pendingSomething = $riders->isNotEmpty() || $unassigned['count'] > 0;

        // Chip sirf un dukanon par jo waqai delivery karti hain. Feature band ho
        // magar cash phir bhi bahar para ho to chip lazmi dikhega (paisa gate se
        // bara hai) — wahi usool pending() aur deliveries board ka hai.
        $enabled = $pendingSomething;
        try {
            $company = $company ?: Company::find($companyId);
            $viewer = auth('pos')->user();
            if ($company && !($viewer && $viewer->posCashierBlocked())) {
                $enabled = $enabled || (bool) (PosFeatureService::forCompany($company)->delivery ?? false);
            }
        } catch (\Throwable $e) {
            // Feature service ka koi bhi masla chip ko chhupa de — figure khud
            // theek hai, sirf "hamesha dikhao" wali sahulat nahi milegi.
        }

        return [
            'enabled'    => $enabled,
            'riders'     => $riders,
            'bills'      => (int) $riders->sum('bills') + $unassigned['count'],
            'amount'     => (float) $riders->sum('owed') + $unassigned['amount'],
            'unassigned' => $unassigned,
        ];
    }
}
