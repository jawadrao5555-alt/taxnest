<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FbrPosTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 🎯 PHASE 1 — Analytics Core (super-admin platform-wide KPIs).
 * 🎯 PHASE 4 — Advanced Analytics (heatmap, top/worst, insights).
 *
 * Source: fbr_pos_transactions + fbr_pos_transaction_items.
 * Tenant isolation respected via optional ?company_id= filter (super admin sees all by default).
 * Performance target: < 300ms via single-aggregate queries on indexed columns (company_id, created_at, product_id).
 *
 * NEVER touches: FbrService, PRA POS, Digital Invoice (per scope).
 */
class AnalyticsController extends Controller
{
    /**
     * Phase 1 — Dashboard KPIs:
     *   today_sales, today_profit, invoice_count, avg_invoice_value, top_product_today
     */
    public function dashboard(Request $request)
    {
        $date = $request->input('date')
            ? Carbon::parse($request->input('date'))->toDateString()
            : Carbon::today()->toDateString();
        $companyId = $request->input('company_id'); // optional drill-down

        // KPIs 1-4 — single aggregate row (1 query)
        $aggQ = FbrPosTransaction::whereDate('created_at', $date)
            ->where('status', 'completed');
        if ($companyId) {
            $aggQ->where('company_id', $companyId);
        }
        $agg = $aggQ->selectRaw('
            COUNT(*)                        AS invoice_count,
            COALESCE(SUM(total_amount), 0)  AS today_sales,
            COALESCE(SUM(subtotal), 0)      AS today_subtotal,
            COALESCE(AVG(total_amount), 0)  AS avg_invoice_value
        ')->first();

        // KPI 5 — Top product(s) today (1 join query)
        // Group by item_name (always present) since legacy items may have null product_id
        $topProducts = DB::table('fbr_pos_transaction_items as i')
            ->join('fbr_pos_transactions as t', 't.id', '=', 'i.transaction_id')
            ->whereDate('t.created_at', $date)
            ->where('t.status', 'completed')
            ->when($companyId, fn ($q) => $q->where('t.company_id', $companyId))
            ->selectRaw('i.item_name, MAX(i.product_id) AS product_id,
                         SUM(i.quantity) AS units_sold, SUM(i.subtotal) AS revenue')
            ->groupBy('i.item_name')
            ->orderByDesc('units_sold')
            ->limit(5)
            ->get();

        // Profit proxy until Phase 3 cost_price exists
        $kpis = [
            'date' => $date,
            'today_sales' => round((float) $agg->today_sales, 2),
            'today_profit' => round((float) $agg->today_subtotal, 2),
            'profit_basis' => 'subtotal_proxy',
            'invoice_count' => (int) $agg->invoice_count,
            'avg_invoice_value' => round((float) $agg->avg_invoice_value, 2),
            'top_product_today' => $topProducts->first(),
            'top_5_products' => $topProducts,
        ];

        if ($request->wantsJson() || $request->input('format') === 'json') {
            return response()->json($kpis);
        }
        return view('admin.analytics.dashboard', compact('kpis', 'date', 'companyId'));
    }

    /**
     * Phase 4 — Advanced Analytics:
     *   - Sales heatmap by HOUR(created_at) → 24-key array
     *   - Top / Worst products (last 30 days)
     *   - Simple insights (drop/spike strings)
     */
    public function advanced(Request $request)
    {
        $date = $request->input('date')
            ? Carbon::parse($request->input('date'))->toDateString()
            : Carbon::today()->toDateString();
        $companyId = $request->input('company_id');

        // === Heatmap: 24 hours x sum(total_amount) for $date ===
        $heatmapQ = DB::table('fbr_pos_transactions')
            ->whereDate('created_at', $date)
            ->where('status', 'completed')
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->selectRaw('HOUR(created_at) AS hr, SUM(total_amount) AS sales')
            ->groupBy('hr')
            ->pluck('sales', 'hr');

        $heatmap = [];
        for ($h = 0; $h < 24; $h++) {
            $heatmap[$h] = round((float) ($heatmapQ[$h] ?? 0), 2);
        }

        // === Top / Worst products (last 30 days, by units sold) ===
        $since = Carbon::parse($date)->subDays(30)->toDateString();
        $productAgg = DB::table('fbr_pos_transaction_items as i')
            ->join('fbr_pos_transactions as t', 't.id', '=', 'i.transaction_id')
            ->whereDate('t.created_at', '>=', $since)
            ->whereDate('t.created_at', '<=', $date)
            ->where('t.status', 'completed')
            ->whereNotNull('i.product_id')
            ->when($companyId, fn ($q) => $q->where('t.company_id', $companyId))
            ->selectRaw('i.product_id, MAX(i.item_name) AS item_name,
                         SUM(i.quantity) AS units, SUM(i.subtotal) AS revenue')
            ->groupBy('i.product_id')
            ->get();

        $top = $productAgg->sortByDesc('units')->take(5)->values();
        $worst = $productAgg->where('units', '>', 0)->sortBy('units')->take(5)->values();

        // === Simple Insights ===
        $insights = $this->buildInsights($date, $companyId);

        $payload = [
            'date' => $date,
            'heatmap' => $heatmap,                   // 24-key map: 0-23 => sum
            'heatmap_peak_hour' => collect($heatmap)->sortDesc()->keys()->first(),
            'top_products_30d' => $top,
            'worst_products_30d' => $worst,
            'insights' => $insights,
        ];

        if ($request->wantsJson() || $request->input('format') === 'json') {
            return response()->json($payload);
        }
        return view('admin.analytics.advanced', compact('payload', 'date', 'companyId'));
    }

    /**
     * Generate plain-English insight strings (drop, spike, trending).
     */
    private function buildInsights(string $date, ?int $companyId): array
    {
        $today = Carbon::parse($date);
        $yesterday = $today->copy()->subDay()->toDateString();

        $sumOn = function (string $d) use ($companyId) {
            $q = FbrPosTransaction::whereDate('created_at', $d)->where('status', 'completed');
            if ($companyId) {
                $q->where('company_id', $companyId);
            }
            return (float) $q->sum('total_amount');
        };

        $todaySum = $sumOn($date);
        $yestSum = $sumOn($yesterday);

        $insights = [];

        // 📉 Sales drop / 📈 spike
        if ($yestSum > 0) {
            $changePct = round((($todaySum - $yestSum) / $yestSum) * 100, 1);
            if ($changePct <= -15) {
                $insights[] = "📉 Sales dropped {$changePct}% vs yesterday (Rs " . number_format($todaySum, 0) . " vs Rs " . number_format($yestSum, 0) . ")";
            } elseif ($changePct >= 15) {
                $insights[] = "📈 Sales spiked +{$changePct}% vs yesterday (Rs " . number_format($todaySum, 0) . " vs Rs " . number_format($yestSum, 0) . ")";
            }
        } elseif ($todaySum > 0 && $yestSum == 0) {
            $insights[] = "📈 Sales started today (yesterday was zero) — Rs " . number_format($todaySum, 0);
        }

        // 🔥 Trending product (today's qty > 2× last-7d daily avg)
        $weekAgo = $today->copy()->subDays(7)->toDateString();
        $trending = DB::table('fbr_pos_transaction_items as i')
            ->join('fbr_pos_transactions as t', 't.id', '=', 'i.transaction_id')
            ->whereDate('t.created_at', '>=', $weekAgo)
            ->whereDate('t.created_at', '<=', $date)
            ->where('t.status', 'completed')
            ->whereNotNull('i.product_id')
            ->when($companyId, fn ($q) => $q->where('t.company_id', $companyId))
            ->selectRaw('
                i.product_id,
                MAX(i.item_name) AS item_name,
                SUM(CASE WHEN DATE(t.created_at) = ? THEN i.quantity ELSE 0 END) AS today_qty,
                SUM(CASE WHEN DATE(t.created_at) < ? THEN i.quantity ELSE 0 END) / 7.0 AS avg_qty
            ', [$date, $date])
            ->groupBy('i.product_id')
            ->havingRaw('today_qty > 2 * avg_qty AND today_qty >= 3')
            ->orderByDesc('today_qty')
            ->limit(3)
            ->get();

        foreach ($trending as $t) {
            $insights[] = "🔥 Trending: {$t->item_name} sold {$t->today_qty} units today (vs " . round($t->avg_qty, 1) . "/day avg)";
        }

        if (empty($insights)) {
            $insights[] = "ℹ️ No significant signals on {$date}.";
        }
        return $insights;
    }
}
