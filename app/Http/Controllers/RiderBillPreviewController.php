<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PosRider;
use App\Services\PosFeatureService;
use App\Services\RiderBillPreviewService;
use Illuminate\Http\Request;

class RiderBillPreviewController extends Controller
{
    public function update(Request $request, RiderBillPreviewService $service)
    {
        $user = auth('pos')->user() ?: auth('fbrpos')->user();
        // Both POS panels use the same User row; role is the canonical owner
        // authority (never trust a company id supplied in the form).
        abort_unless($user && (($user->pos_role ?? null) === 'company_admin' || ($user->role ?? null) === 'company_admin'), 403);
        abort_unless($request->input('rider_bill_preview_present') === '1', 422);
        $company = Company::findOrFail(app('currentCompanyId'));
        $service->save($company, $request->only([
            'enabled', 'quantity', 'prices', 'tax', 'ntn', 'qr',
            'customer_name', 'customer_phone', 'customer_address', 'customer_code',
            'business',
        ]));
        return back()->with('success', __('pos.rider_bill_preview_saved'));
    }

    public function web(Request $request, $id, RiderBillPreviewService $service)
    {
        $rider = $this->portalRider();
        abort_unless(PosFeatureService::planAllows(Company::find($rider->company_id), 'riders_enabled'), 403);
        $bill = $service->assigned($rider, (int) $id, $request->query('revision'));
        abort_unless($bill, 404);
        return response()->view('pos.rider-bill-preview', ['preview' => $service->dto(Company::findOrFail($rider->company_id), $bill)])
            ->header('Cache-Control', 'private, no-store, no-cache, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    public function app(Request $request, $id, RiderBillPreviewService $service)
    {
        // One canonical validation path for all rider-app calls. This includes
        // token proof, active linked user, app-version stamp and company plan /
        // suspension gate; do not reproduce any part of it here.
        $rider = app(\App\Http\Controllers\PosRiderTrackingController::class)->riderFromToken($request);
        $company = Company::findOrFail($rider->company_id);
        $bill = $service->assigned($rider, (int) $id, $request->query('revision'));
        if (!$bill) return response()->json(['ok' => false, 'error' => 'not_found'], 404)->header('Cache-Control', 'private, no-store');
        return response()->json(['ok' => true, 'preview' => $service->dto($company, $bill)])
            ->header('Cache-Control', 'private, no-store, no-cache, max-age=0')->header('Pragma', 'no-cache');
    }

    private function portalRider(): PosRider
    {
        $user = auth('pos')->user();
        abort_unless($user, 401);
        return PosRider::where('company_id', app('currentCompanyId'))->where('user_id', $user->id)->where('is_active', true)->firstOrFail();
    }
}