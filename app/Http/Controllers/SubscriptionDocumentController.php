<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\PaymentProof;
use App\Services\HealthAccessService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

final class SubscriptionDocumentController extends Controller
{
    public function companyInvoice(Request $request, int $invoice)
    {
        $companyId = (int) app('currentCompanyId');
        $this->authorizeCompanyUser($request);
        $record = SubscriptionInvoice::where('company_id', $companyId)
            ->whereNotNull('document_number')->whereNotNull('document_snapshot')->find($invoice);
        if (! $record) {
            return $this->notFound();
        }

        return $this->download('invoice', $record, null);
    }

    public function companyReceipt(Request $request, int $payment)
    {
        $companyId = (int) app('currentCompanyId');
        $this->authorizeCompanyUser($request);
        $record = SubscriptionPayment::downloadableReceipt()
            ->whereHas('invoice', fn ($query) => $query->where('company_id', $companyId))->find($payment);
        if (! $record) {
            return $this->notFound();
        }

        return $this->download('receipt', $record->invoice, $record);
    }

    public function adminInvoice(int $company, int $invoice)
    {
        abort_unless(auth('admin')->user()?->fresh(), 403);
        $record = SubscriptionInvoice::where('company_id', $company)
            ->whereNotNull('document_number')->whereNotNull('document_snapshot')->find($invoice);
        if (! $record) {
            return $this->notFound();
        }

        return $this->download('invoice', $record, null);
    }

    public function adminReceipt(int $company, int $payment)
    {
        abort_unless(auth('admin')->user()?->fresh(), 403);
        $record = SubscriptionPayment::downloadableReceipt()
            ->whereHas('invoice', fn ($query) => $query->where('company_id', $company))->find($payment);
        if (! $record) {
            return $this->notFound();
        }

        return $this->download('receipt', $record->invoice, $record);
    }

    private function authorizeCompanyUser(Request $request): void
    {
        $route = (string) $request->route()?->getName();
        $guard = str_starts_with($route, 'health.')
            ? 'health'
            : (str_starts_with($route, 'fbrpos.') ? 'fbrpos' : (str_starts_with($route, 'pos.') ? 'pos' : 'web'));
        $user = auth($guard)->user()?->fresh();
        abort_unless($user && (int) $user->company_id === (int) app('currentCompanyId'), 403);
        $owner = ($guard === 'health' && HealthAccessService::isOwner($user))
            || in_array((string) ($user->role ?? ''), ['super_admin', 'company_admin'], true)
            || (method_exists($user, 'isPosAdmin') && $user->isPosAdmin());
        abort_unless($owner, 403);
    }

    private function download(string $kind, SubscriptionInvoice $invoice, ?SubscriptionPayment $payment)
    {
        $invoice->loadMissing(['company', 'subscription' => fn ($query) => $query->where('company_id', $invoice->company_id)->with('pricingPlan')]);
        $historical = $payment && $payment->receipt_snapshot === null;
        if ($payment && ! $payment->hasDownloadableReceipt()) {
            return $this->notFound();
        }
        $snapshot = $payment ? ($payment->receipt_snapshot ?? []) : $invoice->document_snapshot;
        abort_unless(is_array($snapshot), 404);
        $attestation = null;
        if ($historical && (blank($payment->payment_method) || blank($payment->transaction_ref))
            && $invoice->subscription_id && Schema::hasColumn('payment_proofs', 'admin_allocated_payment_id')) {
            $attestation = PaymentProof::subscriptionKind()->where('company_id', $invoice->company_id)
                ->where('subscription_id', $invoice->subscription_id)->where('admin_settlement_invoice_id', $invoice->id)
                ->where('admin_allocated_payment_id', $payment->id)->where('admin_settlement_origin', 'allocated')
                ->where('status', 'verified')->whereNotNull('verified_by')->whereNotNull('verified_at')
                ->first(['payment_method', 'reference']);
        }
        $number = $payment ? $payment->receiptDisplayNumber() : $invoice->document_number;
        $pdf = Pdf::loadView('billing.subscription-document-pdf', compact('kind', 'invoice', 'payment', 'snapshot', 'number', 'historical', 'attestation'))
            ->setPaper('A4', 'portrait');

        $filename = strtolower((string) $number).'.pdf';
        $response = request()->boolean('print') ? $pdf->stream($filename) : $pdf->download($filename);
        return $response
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    private function notFound()
    {
        return response('Subscription document not available.', 404)
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}

