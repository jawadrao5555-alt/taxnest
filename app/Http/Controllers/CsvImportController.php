<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Company;
use App\Services\InvoiceNumberingService;
use App\Services\AuditLogService;
use App\Services\InvoiceActivityService;
use App\Services\ScheduleEngine;
use App\Services\GlobalHsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CsvImportController extends Controller
{
    private const TEMPLATE_COLUMNS = [
        'buyer_name',
        'buyer_ntn',
        'buyer_cnic',
        'buyer_address',
        'destination_province',
        'document_type',
        'hs_code',
        'description',
        'quantity',
        'price',
        'tax',
        'schedule_type',
        'tax_rate',
    ];

    public function template(): StreamedResponse
    {
        $callback = function () {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, self::TEMPLATE_COLUMNS);
            fputcsv($handle, [
                'ABC Trading Co',
                '1234567',
                '42201-1234567-1',
                '123 Main St, Lahore',
                'Punjab',
                'Sale Invoice',
                '15179090',
                'Cooking Oil 1L',
                '10',
                '250.00',
                '450.00',
                'standard',
                '18',
            ]);
            fclose($handle);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="invoice_import_template.csv"',
        ]);
    }

    public function upload(Request $request)
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        $file = $request->file('csv_file');
        $handle = fopen($file->getRealPath(), 'r');

        if (!$handle) {
            return response()->json(['error' => 'Unable to read CSV file.'], 422);
        }

        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            return response()->json(['error' => 'CSV file is empty.'], 422);
        }

        $header = array_map(fn($h) => strtolower(trim($h)), $header);
        $missingCols = array_diff(self::TEMPLATE_COLUMNS, $header);
        if (!empty($missingCols)) {
            fclose($handle);
            return response()->json([
                'error' => 'Missing required columns: ' . implode(', ', $missingCols),
            ], 422);
        }

        $rows = [];
        $rowNum = 1;
        while (($data = fgetcsv($handle)) !== false) {
            $rowNum++;
            if (count($data) !== count($header)) {
                $rows[] = [
                    'row' => $rowNum,
                    'data' => [],
                    'valid' => false,
                    'errors' => ["Column count mismatch. Expected " . count($header) . ", got " . count($data)],
                ];
                continue;
            }

            $mapped = array_combine($header, array_map('trim', $data));

            if (empty(array_filter($mapped))) {
                continue;
            }

            $errors = $this->validateRow($mapped, $rowNum);

            $rows[] = [
                'row' => $rowNum,
                'data' => $mapped,
                'valid' => empty($errors),
                'errors' => $errors,
            ];
        }

        fclose($handle);

        if (empty($rows)) {
            return response()->json(['error' => 'No data rows found in CSV.'], 422);
        }

        $validCount = count(array_filter($rows, fn($r) => $r['valid']));
        $errorCount = count($rows) - $validCount;

        return response()->json([
            'rows' => $rows,
            'total' => count($rows),
            'valid_count' => $validCount,
            'error_count' => $errorCount,
        ]);
    }

    public function process(Request $request)
    {
        $request->validate([
            'rows' => 'required|array|min:1',
            'rows.*.buyer_name' => 'required|string|max:255',
            'rows.*.buyer_ntn' => 'nullable|string|max:50',
            'rows.*.buyer_cnic' => 'nullable|string|max:15',
            'rows.*.buyer_address' => 'required|string|max:500',
            'rows.*.destination_province' => 'required|string|max:100',
            'rows.*.document_type' => 'required|string|in:Sale Invoice,Credit Note,Debit Note',
            'rows.*.hs_code' => 'required|string|max:50',
            'rows.*.description' => 'required|string|max:255',
            'rows.*.quantity' => 'required|numeric|min:0.01',
            'rows.*.price' => 'required|numeric|min:0',
            'rows.*.tax' => 'required|numeric|min:0',
            'rows.*.schedule_type' => 'nullable|string|in:standard,reduced,3rd_schedule,exempt,zero_rated',
            'rows.*.tax_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $standardTaxRate = $company ? $company->getStandardTaxRateValue() : 18.0;

        $grouped = [];
        foreach ($request->rows as $row) {
            $buyerKey = ($row['buyer_name'] ?? '') . '|' . ($row['buyer_ntn'] ?? '');
            $grouped[$buyerKey][] = $row;
        }

        DB::beginTransaction();
        try {
            $createdInvoices = [];

            foreach ($grouped as $buyerKey => $items) {
                $first = $items[0];
                $buyerNtn = $first['buyer_ntn'] ?? null;
                $buyerRegType = InvoiceController::detectBuyerRegistrationType($buyerNtn);

                $totalValueExcludingST = 0;
                $totalSalesTax = 0;
                foreach ($items as $item) {
                    $itemValue = floatval($item['price']) * floatval($item['quantity']);
                    $totalValueExcludingST += $itemValue;
                    $totalSalesTax += floatval($item['tax']);
                }
                $totalAmount = round($totalValueExcludingST + $totalSalesTax, 2);

                $invoiceNumber = InvoiceNumberingService::generateNextNumber($companyId);

                $invoice = Invoice::create([
                    'company_id' => $companyId,
                    'invoice_number' => $invoiceNumber,
                    'internal_invoice_number' => $invoiceNumber,
                    'buyer_name' => $first['buyer_name'],
                    'buyer_ntn' => $buyerNtn,
                    'buyer_cnic' => $first['buyer_cnic'] ?? null,
                    'buyer_address' => $first['buyer_address'],
                    'buyer_registration_type' => $buyerRegType,
                    'total_amount' => $totalAmount,
                    'total_value_excluding_st' => round($totalValueExcludingST, 2),
                    'total_sales_tax' => round($totalSalesTax, 2),
                    'wht_rate' => 0,
                    'wht_amount' => 0,
                    'net_receivable' => $totalAmount,
                    'status' => 'draft',
                    'fbr_status' => null,
                    'document_type' => $first['document_type'] ?? 'Sale Invoice',
                    'destination_province' => $first['destination_province'],
                    'supplier_province' => $company->province ?? null,
                    'invoice_date' => now()->toDateString(),
                ]);

                foreach ($items as $item) {
                    $scheduleType = $item['schedule_type'] ?? 'standard';
                    $saleType = ScheduleEngine::mapSaleType($scheduleType);
                    $taxRate = isset($item['tax_rate']) && is_numeric($item['tax_rate'])
                        ? floatval($item['tax_rate'])
                        : $this->computeTaxRate($item, $scheduleType);

                    $hsResolved = GlobalHsService::resolveForInvoiceItem(
                        $item['hs_code'], $standardTaxRate, $companyId, $invoice->id
                    );

                    InvoiceItem::create([
                        'invoice_id' => $invoice->id,
                        'hs_code' => $item['hs_code'],
                        'schedule_type' => $scheduleType,
                        'pct_code' => $hsResolved['pct_code'] ?? null,
                        'tax_rate' => $taxRate,
                        'sro_schedule_no' => null,
                        'serial_no' => null,
                        'mrp' => null,
                        'default_uom' => $hsResolved['default_uom'] ?? 'Numbers, pieces, units',
                        'sale_type' => $saleType,
                        'st_withheld_at_source' => false,
                        'petroleum_levy' => null,
                        'description' => $item['description'],
                        'quantity' => $item['quantity'],
                        'price' => $item['price'],
                        'tax' => $item['tax'],
                    ]);
                }

                InvoiceActivityService::log($invoice->id, $companyId, 'created', [
                    'buyer_name' => $first['buyer_name'],
                    'total_amount' => $totalAmount,
                    'items_count' => count($items),
                    'document_type' => $first['document_type'] ?? 'Sale Invoice',
                    'source' => 'csv_import',
                ]);

                AuditLogService::log('invoice_created', 'Invoice', $invoice->id, null, [
                    'invoice_number' => $invoiceNumber,
                    'buyer_name' => $first['buyer_name'],
                    'total_amount' => $totalAmount,
                    'document_type' => $first['document_type'] ?? 'Sale Invoice',
                    'source' => 'csv_import',
                ]);

                $createdInvoices[] = [
                    'id' => $invoice->id,
                    'invoice_number' => $invoiceNumber,
                    'buyer_name' => $first['buyer_name'],
                    'total_amount' => $totalAmount,
                    'items_count' => count($items),
                ];
            }

            AuditLogService::log('csv_bulk_import', 'Invoice', null, null, [
                'invoices_created' => count($createdInvoices),
                'total_items' => count($request->rows),
                'user' => auth()->user()->name,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => count($createdInvoices) . ' draft invoice(s) created successfully.',
                'invoices' => $createdInvoices,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to create invoices: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function validateRow(array $row, int $rowNum): array
    {
        $errors = [];

        if (empty($row['buyer_name'])) {
            $errors[] = "buyer_name is required";
        }
        if (empty($row['buyer_address'])) {
            $errors[] = "buyer_address is required";
        }
        if (empty($row['destination_province'])) {
            $errors[] = "destination_province is required";
        }

        $validProvinces = ['Punjab', 'Sindh', 'Khyber Pakhtunkhwa', 'Balochistan', 'Islamabad', 'Azad Kashmir', 'Gilgit-Baltistan', 'FATA'];
        if (!empty($row['destination_province']) && !in_array($row['destination_province'], $validProvinces)) {
            $errors[] = "Invalid destination_province. Must be one of: " . implode(', ', $validProvinces);
        }

        $validDocTypes = ['Sale Invoice', 'Credit Note', 'Debit Note'];
        if (empty($row['document_type'])) {
            $errors[] = "document_type is required";
        } elseif (!in_array($row['document_type'], $validDocTypes)) {
            $errors[] = "Invalid document_type. Must be one of: " . implode(', ', $validDocTypes);
        }

        if (empty($row['hs_code'])) {
            $errors[] = "hs_code is required";
        }
        if (empty($row['description'])) {
            $errors[] = "description is required";
        }

        if (!is_numeric($row['quantity'] ?? '') || floatval($row['quantity'] ?? 0) <= 0) {
            $errors[] = "quantity must be a positive number";
        }
        if (!is_numeric($row['price'] ?? '')) {
            $errors[] = "price must be a number";
        }
        if (!is_numeric($row['tax'] ?? '')) {
            $errors[] = "tax must be a number";
        }

        $validScheduleTypes = ['standard', 'reduced', '3rd_schedule', 'exempt', 'zero_rated'];
        if (!empty($row['schedule_type']) && !in_array($row['schedule_type'], $validScheduleTypes)) {
            $errors[] = "Invalid schedule_type. Must be one of: " . implode(', ', $validScheduleTypes);
        }

        if (!empty($row['tax_rate']) && !is_numeric($row['tax_rate'])) {
            $errors[] = "tax_rate must be a number";
        }

        return $errors;
    }

    private function computeTaxRate(array $item, string $scheduleType): float
    {
        if (isset($item['tax'], $item['price'], $item['quantity'])) {
            $subtotal = floatval($item['price']) * floatval($item['quantity']);
            if ($subtotal > 0) {
                return round((floatval($item['tax']) / $subtotal) * 100, 2);
            }
        }
        return ScheduleEngine::getTaxRate($scheduleType);
    }
}
