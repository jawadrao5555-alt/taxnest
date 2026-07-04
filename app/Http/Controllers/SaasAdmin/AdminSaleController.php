<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\SaleCampaign;
use App\Models\AdminAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class AdminSaleController extends Controller
{
    public function index()
    {
        $campaigns = collect();
        if (Schema::hasTable('sale_campaigns')) {
            $campaigns = SaleCampaign::orderByDesc('is_active')->orderByDesc('id')->get();
        }

        return view('saas-admin.sales', compact('campaigns'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:100',
            'scope' => 'required|in:all,di,pos,fbrpos',
            'discount_percent' => 'required|numeric|min:1|max:100',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date',
        ]);

        // A blank start means "live now"; a date-only end is treated as inclusive
        // through the end of that day so the sale runs the full final day.
        $starts = $request->filled('starts_at') ? Carbon::parse($request->starts_at)->startOfDay() : Carbon::now();
        $ends = $request->filled('ends_at') ? Carbon::parse($request->ends_at)->endOfDay() : null;

        if ($ends && $ends->lt($starts)) {
            return back()->with('error', 'End date cannot be before the start date.');
        }

        $campaign = SaleCampaign::create([
            'name' => $data['name'] ?? null,
            'scope' => $data['scope'],
            'discount_percent' => $data['discount_percent'],
            'starts_at' => $starts,
            'ends_at' => $ends,
            'is_active' => true,
        ]);

        SaleCampaign::clearActiveCache();
        AdminAuditLog::log(auth('admin')->id(), 'Sale created', 'SaleCampaign', $campaign->id, [
            'scope' => $campaign->scope,
            'percent' => (string) $campaign->discount_percent,
        ]);

        return back()->with('success', 'Sale launched — it is live now.');
    }

    public function toggle($id)
    {
        $campaign = SaleCampaign::findOrFail($id);
        $campaign->is_active = !$campaign->is_active;
        $campaign->save();

        SaleCampaign::clearActiveCache();
        AdminAuditLog::log(auth('admin')->id(), 'Sale toggled', 'SaleCampaign', $campaign->id, [
            'is_active' => $campaign->is_active,
        ]);

        return back()->with('success', $campaign->is_active ? 'Sale resumed.' : 'Sale paused.');
    }

    public function destroy($id)
    {
        $campaign = SaleCampaign::findOrFail($id);
        $campaign->delete();

        SaleCampaign::clearActiveCache();
        AdminAuditLog::log(auth('admin')->id(), 'Sale deleted', 'SaleCampaign', $id, []);

        return back()->with('success', 'Sale deleted.');
    }
}
