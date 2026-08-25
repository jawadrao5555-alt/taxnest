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
}
