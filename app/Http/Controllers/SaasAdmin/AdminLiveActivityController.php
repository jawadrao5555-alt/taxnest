<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Live Activity (Task: admin panel se dekho kaunsi dukan online hai aur aaj
 * kitni billing hui).
 *
 * - "Aaj" = business_date convention: COALESCE(business_date, DATE(created_at))
 *   with the default 06:00 cutoff — a bill at 01:00 counts in YESTERDAY's
 *   trading day (per-company custom cutoffs are approximated by the default;
 *   business_date on the row itself is always authoritative when set).
 * - Online = pos_user_sessions.last_activity_at within the last ~6 minutes
 *   (Staff Hazri heartbeat is throttled to 5 min, +1 min slack).
 * - Super admin only; @scaletest.pk companies excluded; capped at 50 rows.
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

        // NULL emails are legal — exclude only actual @scaletest.pk accounts.
        $companies = Company::where('product_type', 'pos')
            ->where(function ($q) {
                $q->whereNull('email')->orWhere('email', 'not like', '%@scaletest.pk');
            })
            ->select('id', 'name', 'status')
            ->get();

        $companyIds = $companies->pluck('id');

        // Each company's CURRENT open trading day (per-company cutoff +
        // already-day-closed rules — the same resolver the POS itself uses).
        $bizDates = [];
        foreach ($companyIds as $cid) {
            try {
                $bizDates[$cid] = \App\Services\PosBusinessDay::forMoment((int) $cid, $now);
            } catch (\Throwable $e) {
                $bizDates[$cid] = $bizDate;
            }
        }

        $hasBizDate = Schema::hasColumn('pos_transactions', 'business_date');
        $dateExpr = $hasBizDate
            ? 'COALESCE(business_date, DATE(created_at))'
            : 'DATE(created_at)';

        // Today's billing per company: count, total, last bill time, and a
        // PRA-submitted vs local/NULL breakdown. Grouped by (company, day)
        // over the union of possible trading days, then each company keeps
        // only its own open business day.
        $aggRows = DB::table('pos_transactions')
            ->whereIn('company_id', $companyIds)
            ->where('status', 'completed')
            ->whereIn(DB::raw($dateExpr), array_values(array_unique($bizDates)))
            ->select(
                'company_id',
                DB::raw("$dateExpr as biz_day"),
                DB::raw('COUNT(*) as bill_count'),
                DB::raw('COALESCE(SUM(total_amount),0) as total'),
                DB::raw('MAX(created_at) as last_bill_at'),
                DB::raw("SUM(CASE WHEN pra_status = 'submitted' THEN 1 ELSE 0 END) as pra_submitted"),
                DB::raw("SUM(CASE WHEN pra_status = 'local' OR pra_status IS NULL THEN 1 ELSE 0 END) as local_bills")
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
        // must not show online for another 6 minutes).
        $lastSeen = Schema::hasTable('pos_user_sessions')
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
            $seen = $seenRaw ? \Illuminate\Support\Carbon::parse($seenRaw) : null;

            return (object) [
                'id' => $c->id,
                'name' => $c->name,
                'status' => $c->status,
                'online' => $seen !== null && $seen->gte($onlineCutoff),
                'last_seen' => $seen,
                'bill_count' => (int) ($a->bill_count ?? 0),
                'total' => (float) ($a->total ?? 0),
                'last_bill_at' => isset($a->last_bill_at) && $a->last_bill_at
                    ? \Illuminate\Support\Carbon::parse($a->last_bill_at) : null,
                'pra_submitted' => (int) ($a->pra_submitted ?? 0),
                'local_bills' => (int) ($a->local_bills ?? 0),
                'other_bills' => max(0, (int) ($a->bill_count ?? 0) - (int) ($a->pra_submitted ?? 0) - (int) ($a->local_bills ?? 0)),
            ];
        });

        // Online shops first, then by today's billing; dashboard cap 50.
        $rows = $rows
            ->sortByDesc(fn ($r) => [$r->online ? 1 : 0, $r->total, $r->bill_count])
            ->values();
        $totalCompanies = $rows->count();
        $rows = $rows->take(50);

        $summary = [
            'online' => $rows->where('online', true)->count(),
            'bills' => (int) $agg->sum('bill_count'),
            'total' => (float) $agg->sum('total'),
            'active_shops' => $agg->count(),
        ];

        return view('saas-admin.live-activity', [
            'rows' => $rows,
            'summary' => $summary,
            'bizDate' => $bizDate,
            'totalCompanies' => $totalCompanies,
        ]);
    }
}
