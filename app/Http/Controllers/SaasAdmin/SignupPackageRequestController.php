<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\Company;
use App\Models\SignupPackageRequest;
use App\Services\AdminPackageSettlement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SignupPackageRequestController extends Controller
{
    public function index(Request $request)
    {
        AdminUser::requireBillingAdministrator();
        $status = $request->validate(['status' => 'nullable|in:pending,approved,rejected,all'])['status'] ?? 'pending';
        $sales = SignupPackageRequest::with('company')
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->latest('id')->paginate(25)->withQueryString();

        return view('saas-admin.package-requests.index', compact('sales', 'status'));
    }

    public function show(SignupPackageRequest $sale)
    {
        AdminUser::requireBillingAdministrator();
        $sale->load('company', 'invoice.payments');

        return view('saas-admin.package-requests.show', compact('sale'));
    }

    public function confirm(Request $request, SignupPackageRequest $sale)
    {
        $actor = AdminUser::requireBillingAdministrator();
        $payment = $request->validate([
            'payment_method' => 'required|in:cash,card',
            'verified_received_amount' => 'required|numeric|decimal:0,2|gt:0',
            'payment_date' => 'required|date_format:Y-m-d|before_or_equal:today',
            'reference' => 'nullable|string|max:120',
            'idempotency_key' => 'required|uuid',
        ]);
        DB::transaction(function () use ($actor, $sale, $payment) {
            $company = Company::whereKey($sale->company_id)->lockForUpdate()->firstOrFail();
            $locked = SignupPackageRequest::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'approved') return;
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['package' => 'This package request was already rejected.']);
            }
            $notifyActivation = in_array('pending', [$company->status, $company->company_status], true);
            $invoice = AdminPackageSettlement::assign(
                $company->id, $locked->pricing_plan_id, $locked->billing_cycle, $payment, $actor->id, true
            );
            $locked->update([
                'status' => 'approved', 'reviewer_admin_id' => $actor->id,
                'reviewed_at' => now(), 'subscription_invoice_id' => $invoice->id,
            ]);
            AdminAuditLog::log($actor->id, 'Signup package approved', 'Company', $company->id, [
                'package_request_id' => $locked->id, 'subscription_invoice_id' => $invoice->id,
            ]);
            if ($notifyActivation) {
                DB::afterCommit(fn () => app(AdminCompanyController::class)->sendActivationNotification($company));
            }
        });

        return redirect()->route('saas.admin.package-requests.show', $sale)->with('success', 'Package payment confirmed. The receipt is available below.');
    }

    public function reject(Request $request, SignupPackageRequest $sale)
    {
        $actor = AdminUser::requireBillingAdministrator();
        $reason = $request->validate(['rejection_reason' => 'required|string|max:1000'])['rejection_reason'];
        DB::transaction(function () use ($actor, $sale, $reason) {
            Company::whereKey($sale->company_id)->lockForUpdate()->firstOrFail();
            $locked = SignupPackageRequest::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['package' => 'Only pending package requests can be rejected.']);
            }
            $locked->update(['status' => 'rejected', 'rejection_reason' => $reason, 'reviewed_at' => now(), 'reviewer_admin_id' => $actor->id]);
            AdminAuditLog::log($actor->id, 'Signup package rejected', 'Company', $locked->company_id, [
                'package_request_id' => $locked->id, 'reason' => $reason,
            ]);
        });

        return redirect()->route('saas.admin.package-requests.show', $sale)->with('success', 'Package request rejected.');
    }
}
