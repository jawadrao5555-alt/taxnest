<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Barryvdh\DomPDF\Facade\Pdf;

/** Existing invoice layouts rendered without tenant impersonation or transport. */
class AdminInvoiceDocument
{
    public function pdf(Model $record, Company $company, string $product): \Barryvdh\DomPDF\PDF
    {
        abort_unless(in_array($product, ['di', 'pos', 'fbrpos'], true), 404);
        abort_unless((int)$record->company_id === (int)$company->id && $company->product_type === $product, 404);
        $record->setRelation('company', $company);
        if ($product === 'di') {
            $data = InvoicePdfService::buildData($record);
            if ($record->branch && (int)$record->branch->company_id !== (int)$company->id) $record->setRelation('branch', null);
            $view = 'invoice.pdf-bw';
        } elseif ($product === 'pos') {
            $record->loadMissing(['items', 'payments', 'terminal' => fn($q) => $q->where('company_id', $company->id), 'rider' => fn($q) => $q->where('company_id', $company->id), 'creator' => fn($q) => $q->where('company_id', $company->id)]);
            $view = ($company->receipt_printer_size ?? '80mm') === '58mm'
                ? 'pos.receipts.receipt_58mm' : 'pos.receipts.receipt_80mm';
            $data = ['transaction' => $record, 'company' => $company, 'pdfMode' => true, 'pdfPaper' => 'a4'];
        } else {
            $record->loadMissing(['items', 'creator' => fn($q) => $q->where('company_id', $company->id)]);
            $view = 'fbr-pos.invoice-pdf';
            $data = ['transaction' => $record, 'company' => $company];
        }

        $pdf = $product === 'di' ? InvoicePdfService::make($view, $data) : Pdf::loadView($view, $data);
        return $pdf->setPaper('a4', 'portrait')
            ->setOption('isRemoteEnabled', false)
            ->setOption('isPhpEnabled', false)
            ->setOption('isJavascriptEnabled', false);
    }
}
