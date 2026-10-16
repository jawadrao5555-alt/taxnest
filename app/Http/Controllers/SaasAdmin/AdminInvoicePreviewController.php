<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\{Company, Invoice, PosTransaction, FbrPosTransaction};
use App\Services\AdminInvoiceDocument;

class AdminInvoicePreviewController extends Controller
{
    public function show(string $product, int $company, int $record, string $action, AdminInvoiceDocument $documents)
    {
        abort_unless(auth('admin')->check(), 403);
        abort_unless(in_array($action, ['preview', 'download'], true), 404);
        $model = match ($product) {
            'di' => Invoice::class, 'pos' => PosTransaction::class,
            'fbrpos' => FbrPosTransaction::class, default => abort(404),
        };
        // The company, product and typed-table record must agree, including when
        // different products have colliding numeric IDs. Tenant sessions never
        // supply authority or currentCompanyId to this platform-admin route.
        $owner = Company::where('product_type', $product)->find($company);
        if (!$owner) return response()->json(['error' => 'Invoice document not available.'], 404)->header('Cache-Control', 'private, no-store');
        $invoice = $model::where('company_id', $owner->id)->find($record);
        if (!$invoice) return response()->json(['error' => 'Invoice document not available.'], 404)->header('Cache-Control', 'private, no-store');
        $pdf = $documents->pdf($invoice, $owner, $product);
        $filename = "taxnest-{$product}-{$company}-{$record}.pdf";
        $response = $action === 'download' ? $pdf->download($filename) : $pdf->stream($filename);
        return $response->header('Cache-Control', 'private, no-store')
            ->header('X-Frame-Options', 'SAMEORIGIN')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
