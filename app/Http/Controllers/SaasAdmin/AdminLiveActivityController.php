<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Live Activity (Task: admin panel se dekho kaunsi dukan online hai aur aaj
 * kitni billing hui). Task 558: FBR POS dukanein bhi shamil.
 *
 * - "Aaj" = business_date convention: COALESCE(business_date, DATE(created_at))
 *   with the default 06:00 cutoff — a bill at 01:00 counts in YESTERDAY's
 *   trading day (per-company custom cutoffs are approximated by the default;
 *   business_date on the row itself is always authoritative when set).
 *   PRA and FBR each resolve against their OWN day-close table
 *   (PosBusinessDay::forMoment vs forMomentFbr).
 * - Online = pos_user_sessions.last_activity_at within the last ~6 minutes
 *   (heartbeat is throttled to 5 min, +1 min slack). fbrpos-guard logins
 *   write into the same table (Task 558).
 * - Super admin only; @scaletest.pk companies excluded; capped at 50 rows
 *   per section.
 */
class AdminLiveActivityController extends Controller
{
    public function index()
    {
        if ((auth('admin')->user()->role ?? null) !== 'super_admin') {
            abort(403);
        }

        $now = now(); // app tz = Asia/Karachi
        // Reference trading day under the default 06:00 cutoff (header only —
        // each company is aggregated against ITS OWN open business day below).
        $bizDate = $now->format('H:i') < '06:00'
            ? $now->copy()->subDay()->toDateString()
            : $now->toDateString();

        $pra = $this->buildSection('pos', 'pos_transactions', $now, $bizDate);
        $fbr = $this->buildSection('fbrpos', 'fbr_pos_transactions', $now, $bizDate);

        return view('saas-admin.live-activity', [
            'rows' => $pra['rows'],
            'summary' => $pra['summary'],
            'totalCompanies' => $pra['total'],
            'fbrRows' => $fbr['rows'],
            'fbrSummary' => $fbr['summary'],
            'fbrTotalCompanies' => $fbr['total'],
            'bizDate' => $bizDate,
        ]);
    }

    /**
     * One product's section: rows (capped 50) + summary. $productType is
     * 'pos' (PRA, pra_status breakdown) or 'fbrpos' (FBR, fbr_status).
     */
    protected function buildSection(string $productType, string $table, $now, string $bizDate): array
    {
        $isFbr = $productType === 'fbrpos';

        // NULL emails are legal — exclude only actual @scaletest.pk accounts.
        $companies = Company::where('product_type', $productType)
            ->where(function ($q) {
                $q->whereNull('email')->orWhere('email', 'not like', '%@scaletest.pk');
            })
            ->select('id', 'name', 'status')
            ->get();

        $companyIds = $companies->pluck('id');

        // Each company's CURRENT open trading day (per-company cutoff +
        // already-day-closed rules — the same resolver the POS itself uses;
        // PRA and FBR day-close independently, so each uses its own).
        $bizDates = [];
        foreach ($companyIds as $cid) {
            try {
                $bizDates[$cid] = $isFbr
                    ? \App\Services\PosBusinessDay::forMomentFbr((int) $cid, $now)
                    : \App\Services\PosBusinessDay::forMoment((int) $cid, $now);
            } catch (\Throwable $e) {
                $bizDates[$cid] = $bizDate;
            }
        }

        $hasBizDate = Schema::hasColumn($table, 'business_date');
        $dateExpr = $hasBizDate
            ? 'COALESCE(business_date, DATE(created_at))'
            : 'DATE(created_at)';

        // Regulator-submitted vs local/NULL breakdown. FBR uses fbr_status
        // ('submitted'/'success' = accepted; 'local'/NULL = not reported);
        // PRA uses pra_status ('submitted' / 'local' or NULL).
        $submittedExpr = $isFbr
            ? "SUM(CASE WHEN fbr_status IN ('submitted','success') THEN 1 ELSE 0 END) as reg_submitted"
            : "SUM(CASE WHEN pra_status = 'submitted' THEN 1 ELSE 0 END) as reg_submitted";
        $localExpr = $isFbr
            ? "SUM(CASE WHEN fbr_status = 'local' OR fbr_status IS NULL THEN 1 ELSE 0 END) as local_bills"
            : "SUM(CASE WHEN pra_status = 'local' OR pra_status IS NULL THEN 1 ELSE 0 END) as local_bills";

        // Today's billing per company: count, total, last bill time, and the
        // breakdown. Grouped by (company, day) over the union of possible
        // trading days, then each company keeps only its own open business day.
        $aggRows = $companyIds->isEmpty() || !Schema::hasTable($table)
            ? collect()
            : DB::table($table)
                ->whereIn('company_id', $companyIds)
                ->where('status', 'completed')
                ->whereIn(DB::raw($dateExpr), array_values(array_unique($bizDates)))
                ->select(
                    'company_id',
                    DB::raw("$dateExpr as biz_day"),
                    DB::raw('COUNT(*) as bill_count'),
                    DB::raw('COALESCE(SUM(total_amount),0) as total'),
                    DB::raw('MAX(created_at) as last_bill_at'),
                    DB::raw($submittedExpr),
                    DB::raw($localExpr)
                )
                ->groupBy('company_id', DB::raw($dateExpr))
                ->get();

        // Keep only each company's own open business day.
        $agg = collect();
        foreach ($aggRows as $row) {
            if (($bizDates[$row->company_id] ?? $bizDate) === (string) $row->biz_day) {
                $agg[$row->company_id] = $row;
            }
        }

        // Latest heartbeat per company — OPEN sessions only (explicit logout
        // stamps logout_at + refreshes last_activity_at; a logged-out shop
        // must not show online for another 6 minutes). fbrpos logins share
        // this table (Task 558).
        $lastSeen = Schema::hasTable('pos_user_sessions') && $companyIds->isNotEmpty()
            ? DB::table('pos_user_sessions')
                ->whereIn('company_id', $companyIds)
                ->whereNull('logout_at')
                ->select('company_id', DB::raw('MAX(last_activity_at) as last_seen'))
                ->groupBy('company_id')
                ->get()->keyBy('company_id')
            : collect();

        $onlineCutoff = $now->copy()->subMinutes(6);

        $rows = $companies->map(function ($c) use ($agg, $lastSeen, $onlineCutoff) {
            $a = $agg[$c->id] ?? null;
            $seenRaw = $lastSeen[$c->id]->last_seen ?? null;
            $seen = $seenRaw ? Carbon::parse($seenRaw) : null;

            return (object) [
                'id' => $c->id,
                'name' => $c->name,
                'status' => $c->status,
                'online' => $seen !== null && $seen->gte($onlineCutoff),
                'last_seen' => $seen,
                'bill_count' => (int) ($a->bill_count ?? 0),
                'total' => (float) ($a->total ?? 0),
                'last_bill_at' => isset($a->last_bill_at) && $a->last_bill_at
                    ? Carbon::parse($a->last_bill_at) : null,
                'reg_submitted' => (int) ($a->reg_submitted ?? 0),
                'local_bills' => (int) ($a->local_bills ?? 0),
                'other_bills' => max(0, (int) ($a->bill_count ?? 0) - (int) ($a->reg_submitted ?? 0) - (int) ($a->local_bills ?? 0)),
            ];
        });

        // Online shops first, then by today's billing; dashboard cap 50.
        $rows = $rows
            ->sortByDesc(fn ($r) => [$r->online ? 1 : 0, $r->total, $r->bill_count])
            ->values();
        $total = $rows->count();
        $online = $rows->where('online', true)->count();
        $rows = $rows->take(50);

        $summary = [
            'online' => $online,
            'bills' => (int) $agg->sum('bill_count'),
            'total' => (float) $agg->sum('total'),
            'active_shops' => $agg->count(),
        ];

        return ['rows' => $rows, 'summary' => $summary, 'total' => $total];
    }
}
