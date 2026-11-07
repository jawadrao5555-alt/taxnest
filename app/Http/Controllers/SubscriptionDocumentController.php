<?php

namespace App\Http\Controllers;

use App\Models\{SubscriptionInvoice, SubscriptionPayment};
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

final class SubscriptionDocumentController extends Controller
{
    public function companyInvoice(Request $request, int $invoice)
    {
        $companyId = (int) app('currentCompanyId');
        $this->authorizeCompanyUser($request);
        $record = SubscriptionInvoice::where('company_id', $companyId)
            ->whereNotNull('document_number')->whereNotNull('document_snapshot')->find($invoice);
        if (!$record) return $this->notFound();

        return $this->download('invoice', $record, null);
    }

    public function companyReceipt(Request $request, int $payment)
    {
        $companyId = (int) app('currentCompanyId');
        $this->authorizeCompanyUser($request);
        $record = SubscriptionPayment::whereNotNull('receipt_number')->whereNotNull('receipt_snapshot')
            ->whereHas('invoice', fn ($query) => $query->where('company_id', $companyId))->find($payment);
        if (!$record) return $this->notFound();

        return $this->download('receipt', $record->invoice, $record);
    }

    public function adminInvoice(int $company, int $invoice)
    {
        abort_unless(auth('admin')->check(), 403);
        $record = SubscriptionInvoice::where('company_id', $company)
            ->whereNotNull('document_number')->whereNotNull('document_snapshot')->find($invoice);
        if (!$record) return $this->notFound();

        return $this->download('invoice', $record, null);
    }

    public function adminReceipt(int $company, int $payment)
    {
        abort_unless(auth('admin')->check(), 403);
        $record = SubscriptionPayment::whereNotNull('receipt_number')->whereNotNull('receipt_snapshot')
            ->whereHas('invoice', fn ($query) => $query->where('company_id', $company))->find($payment);
        if (!$record) return $this->notFound();

        return $this->download('receipt', $record->invoice, $record);
    }

    private function authorizeCompanyUser(Request $request): void
    {
        $route = (string) $request->route()?->getName();
        $guard = str_starts_with($route, 'fbrpos.') ? 'fbrpos' : (str_starts_with($route, 'pos.') ? 'pos' : 'web');
        $user = auth($guard)->user();
        abort_unless($user && (int) $user->company_id === (int) app('currentCompanyId'), 403);
        $owner = in_array((string) ($user->role ?? ''), ['super_admin', 'company_admin'], true)
            || (method_exists($user, 'isPosAdmin') && $user->isPosAdmin());
        abort_unless($owner, 403);
    }

    private function download(string $kind, SubscriptionInvoice $invoice, ?SubscriptionPayment $payment)
    {
        $invoice->loadMissing(['company', 'subscription.pricingPlan']);
        $snapshot = $kind === 'receipt' ? $payment?->receipt_snapshot : $invoice->document_snapshot;
        abort_unless(is_array($snapshot), 404);
        $number = $kind === 'receipt' ? $payment->receipt_number : $invoice->document_number;
        $pdf = Pdf::loadView('billing.subscription-document-pdf', compact('kind', 'invoice', 'payment', 'snapshot', 'number'))
            ->setPaper('A4', 'portrait');

        return $pdf->download(strtolower((string) $number).'.pdf')
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
