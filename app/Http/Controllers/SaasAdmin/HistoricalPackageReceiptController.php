<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\Company;
use App\Models\PaymentProof;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Services\AdminPackageSettlement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class HistoricalPackageReceiptController extends Controller
{
    public function index(int $companyId): View
    {
        AdminUser::requireBillingAdministrator();
        $company = Company::withTrashed()->findOrFail($companyId);
        $terms = Subscription::where('company_id', $companyId)->with('pricingPlan')
            ->orderByDesc('id')->paginate(15);
        $ids = $terms->getCollection()->modelKeys();
        $charges = SubscriptionInvoice::packageCharge()->where('company_id', $companyId)
            ->whereIn('subscription_id', $ids)->with('payments')->orderByDesc('id')
            ->get()->groupBy('subscription_id');
        $proofs = PaymentProof::subscriptionKind()->where('company_id', $companyId)
            ->whereIn('subscription_id', $ids)->where('status', 'verified')
            ->orderByDesc('id')->get()->groupBy('subscription_id');

        return view('saas-admin.package-receipts', compact('company', 'terms', 'charges', 'proofs'));
    }

    public function record(Request $request, int $companyId, int $subscriptionId, AdminPackageSettlement $settlement): RedirectResponse
    {
        $admin = AdminUser::requireBillingAdministrator();
        $this->requireTerm($companyId, $subscriptionId);
        $details = $request->validate([
            'payment_method' => ['required', 'in:cash,card'],
            'verified_received_amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:1000000000'],
            'payment_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:100'],
            'idempotency_key' => ['required', 'uuid'],
            'subscription_invoice_id' => ['nullable', 'integer', 'min:1'],
        ]);
        if (isset($details['subscription_invoice_id'])) {
            SubscriptionInvoice::packageCharge()->where('company_id', $companyId)
                ->where('subscription_id', $subscriptionId)->findOrFail($details['subscription_invoice_id']);
        }
        $settlement->historical($companyId, $subscriptionId, $details, (int) $admin->id);

        return back()->with('success', 'Payment recorded for the selected package term.');
    }

    public function recover(int $companyId, int $subscriptionId, int $proofId, AdminPackageSettlement $settlement): RedirectResponse
    {
        $admin = AdminUser::requireBillingAdministrator();
        $this->requireTerm($companyId, $subscriptionId);
        PaymentProof::subscriptionKind()->where('company_id', $companyId)->where('subscription_id', $subscriptionId)
            ->where('status', 'verified')->findOrFail($proofId);
        $settlement->historicalVerifiedProof($companyId, $subscriptionId, $proofId, (int) $admin->id);

        return back()->with('success', 'Receipt is available for the verified proof.');
    }

    private function requireTerm(int $companyId, int $subscriptionId): void
    {
        Company::findOrFail($companyId);
        Subscription::where('company_id', $companyId)->findOrFail($subscriptionId);
    }
}
