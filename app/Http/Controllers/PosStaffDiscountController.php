<?php

namespace App\Http\Controllers;

use App\Models\{Company, User};
use App\Services\{BranchContextService, PosStaffDiscount};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PosStaffDiscountController extends Controller
{
    public function index(Request $request)
    {
        $company = Company::findOrFail(app('currentCompanyId'));
        $user = auth('pos')->user();
        $canReview = PosStaffDiscount::reviewer($user, (int) $company->id);
        $query = DB::table('pos_staff_discount_requests')->where('company_id', $company->id);
        if (!$canReview) $query->where('requester_id', $user->id);
        $requests = $query->orderByDesc('id')->limit(100)->get();
        $employees = User::where('company_id', $company->id)->where('is_active', true)->get(['id', 'name']);
        if ($request->expectsJson()) return response()->json(['enabled' => (bool) $company->pos_staff_discount_enabled, 'requests' => $requests, 'employees' => $employees])->header('Cache-Control', 'no-store, private');
        return view('pos.staff-discounts', compact('company', 'requests', 'employees', 'canReview'));
    }

    public function toggle(Request $request)
    {
        $company = Company::findOrFail(app('currentCompanyId'));
        abort_unless(PosStaffDiscount::reviewer(auth('pos')->user(), (int) $company->id), 403);
        $data = $request->validate(['enabled' => 'required|boolean']);
        // Updates this one optional field only; all existing settings survive.
        $company->forceFill(['pos_staff_discount_enabled' => (bool) $data['enabled']])->save();
        return back()->with('success', 'Staff/family discount preference saved.');
    }

    public function show(int $id)
    {
        $companyId=(int) app('currentCompanyId'); $user=auth('pos')->user();
        $row=DB::table('pos_staff_discount_requests')->where('company_id',$companyId)->where('id',$id)->first();
        abort_unless($row && (PosStaffDiscount::reviewer($user,$companyId) || (int)$row->requester_id === (int)$user->id),404);
        return response()->json(['request'=>$row,'employees'=>User::where('company_id',$companyId)->where('is_active',true)->get(['id','name'])])->header('Cache-Control','no-store, private');
    }

    public function review(Request $request, int $id)
    {
        $companyId = (int) app('currentCompanyId'); $user = auth('pos')->user();
        $data = $request->validate(['revision' => 'required|integer|min:1', 'action' => 'required|in:approve,reject,cancel',
            'discount_type' => 'required_if:action,approve|in:percentage,amount', 'discount_value' => 'required_if:action,approve|numeric']);
        DB::transaction(function () use ($request, $id, $companyId, $user, $data) {
            Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
            $row = DB::table('pos_staff_discount_requests')->where('company_id', $companyId)->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            $owner = PosStaffDiscount::reviewer($user, $companyId);
            abort_unless($owner || ($data['action'] === 'cancel' && (int) $row->requester_id === (int) $user->id), 403);
            abort_unless(in_array($row->status, ['pending', 'approved'], true) && (int) $row->revision === (int) $data['revision'], 409, 'This request changed. Reload before reviewing.');
            $changes = ['status' => ['approve' => 'approved', 'reject' => 'rejected', 'cancel' => 'cancelled'][$data['action']],
                'revision' => $row->revision + 1, 'reviewer_id' => $user->id, 'reviewed_at' => now(), 'updated_at' => now()];
            if ($data['action'] === 'approve') {
                $changes += ['approved_type' => $data['discount_type'], 'approved_value' => PosStaffDiscount::value($data['discount_type'], $data['discount_value'], (float) $row->eligible_subtotal)];
            }
            DB::table('pos_staff_discount_requests')->where('id', $id)->where('company_id', $companyId)->update($changes);
            PosStaffDiscount::event((object) array_merge((array) $row, $changes), $data['action'], $user->id);
        });
        return $request->expectsJson() ? response()->json(['success' => true]) : back()->with('success', 'Discount request updated.');
    }
}
