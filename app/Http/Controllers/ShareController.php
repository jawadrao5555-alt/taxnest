<?php

namespace App\Http\Controllers;

use App\Models\Invoice;

class ShareController extends Controller
{
    public function show(string $uuid)
    {
        // Bypass tenant scope: share links are explicitly designed to be cross-tenant readable
        // (the share_uuid itself acts as the capability token).
        $invoice = Invoice::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)
            ->where('share_uuid', $uuid)
            ->with('items', 'company')
            ->firstOrFail();

        return response()
            ->view('share.invoice', compact('invoice'))
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function pdf(string $uuid)
    {
        // Bypass tenant scope: share links are intentionally cross-tenant readable via the UUID capability.
        $invoice = Invoice::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)
            ->where('share_uuid', $uuid)
            ->with('items', 'company')
            ->firstOrFail();

        // PDF build logic lives in InvoicePdfService (shared with the
        // in-panel pdf routes and the email-send attachment).
        $pdf = \App\Services\InvoicePdfService::renderBw($invoice);

        return $pdf->stream(\App\Services\InvoicePdfService::filename($invoice));
    }
}
