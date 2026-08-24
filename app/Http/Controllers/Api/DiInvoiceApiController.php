<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\InvoiceController;
use App\Jobs\ComplianceScoringJob;
use App\Models\FbrLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\AuditLogService;
use App\Services\GlobalHsService;
use App\Services\InvoiceActivityService;
use App\Services\InvoiceNumberingService;
use App\Services\PlanLimitService;
use App\Services\ScheduleEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Task 1231: DI invoice push API (v1) — third-party DMS/ERP software posts a
 * sale invoice, TaxNest creates it (same validation + numbering as the panel),
 * optionally submits it to FBR Digital Invoicing synchronously, and returns
 * the internal + FBR invoice numbers.
 *
 * Idempotency: client_reference is REQUIRED and unique per company — a retry
 * of the same reference always returns the original invoice (checked before
 * quota, plus a DB unique index for true concurrent races).
 */
class DiInvoiceApiController extends Controller
{
    /** POST /api/di/v1/invoices */
    public function store(Request $request)
    {
        $company = $request->attributes->get('di_api_company');
        $companyId = $company->id;

        $validator = Validator::make($request->all(), [
            'client_reference' => 'required|string|max:100',
            'mode' => 'nullable|string|in:draft,submit',
            'buyer_name' => 'required|string|max:255',
            'buyer_ntn' => 'nullable|string|max:50',
            'buyer_cnic' => 'nullable|string|max:15',
            'buyer_address' => 'required|string|max:500',
            'buyer_registration_type' => 'nullable|string|in:Registered,Unregistered',
            'branch_id' => 'nullable|integer',
            'document_type' => 'required|string|in:Sale Invoice,Credit Note,Debit Note',
            'reference_invoice_number' => 'nullable|string|max:255',
            'destination_province' => 'required|string|max:100',
            // The date of the actual sale. Omit it and today is used. An ERP
            // pushing back-dated sales must send it — this date goes to FBR,
            // and in submit mode that cannot be taken back.
            'invoice_date' => 'nullable|date|before_or_equal:today',
            'items' => 'required|array|min:1',
            'items.*.hs_code' => 'required|string|max:50',
            'items.*.description' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.price' => 'required|numeric|min:0.01',
            'items.*.tax' => 'required|numeric|min:0',
            'items.*.schedule_type' => 'nullable|string|in:standard,reduced,3rd_schedule,exempt,zero_rated,fed_services,services',
            'items.*.pct_code' => 'nullable|string|max:50',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.sro_schedule_no' => 'nullable|string|max:100',
            'items.*.serial_no' => 'nullable|string|max:100',
            'items.*.mrp' => 'nullable|numeric|min:0',
            'items.*.default_uom' => 'nullable|string|max:100',
            'items.*.st_withheld_at_source' => 'nullable',
            'items.*.petroleum_levy' => 'nullable|numeric|min:0',
            'items.*.further_tax' => 'nullable|numeric|min:0',
        ], [
            'items.*.price.min' => 'Item prices must be greater than Rs 0. FBR rejects free/bonus lines (error 0300). Note the free item in the description of a paid line or omit it.',
            'invoice_date.before_or_equal' => 'Invoice date cannot be in the future. Use the date the sale actually happened (YYYY-MM-DD).',
        ]);

        $documentType = (string) $request->input('document_type', 'Sale Invoice');
        $validator->after(function ($v) use ($request, $documentType) {
            if (in_array($documentType, ['Credit Note', 'Debit Note']) && !$request->filled('reference_invoice_number')) {
                $v->errors()->add('reference_invoice_number', 'Reference invoice number is required for Credit/Debit Notes.');
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'error' => 'validation_failed',
                'message' => 'The invoice payload failed validation.',
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $clientReference = (string) $request->input('client_reference');
        $mode = $request->input('mode', 'submit');

        // ── Idempotency (before anything that consumes quota) ──────────────
        $existing = Invoice::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('client_reference', $clientReference)
            ->first();
        if ($existing) {
            return response()->json($this->serialize($existing, true), 200);
        }

        // ── FBR schedule rules — same engine as the panel ───────────────────
        $standardTaxRate = $company->getStandardTaxRateValue();
        $itemsWithTaxRate = collect($request->input('items'))->map(function ($item) {
            $item['tax_rate'] = isset($item['tax_rate']) && is_numeric($item['tax_rate']) ? intval($item['tax_rate']) : null;
            return $item;
        })->toArray();

        $scheduleErrors = ScheduleEngine::validateItems($itemsWithTaxRate, $standardTaxRate);
        if (!empty($scheduleErrors)) {
            return response()->json([
                'status' => 'error',
                'error' => 'validation_failed',
                'message' => 'One or more items failed FBR schedule validation.',
                'errors' => ['items' => array_values($scheduleErrors)],
            ], 422);
        }

        $selectedBranch = null;
        if ($request->filled('branch_id')) {
            $selectedBranch = \App\Models\Branch::where('id', (int) $request->input('branch_id'))
                ->where('company_id', $companyId)->first();
            if (!$selectedBranch) {
                return response()->json([
                    'status' => 'error',
                    'error' => 'validation_failed',
                    'message' => 'The invoice payload failed validation.',
                    'errors' => ['branch_id' => ['Branch not found for this company.']],
                ], 422);
            }
        }

        // ── Monthly plan quota — exactly the panel's check ──────────────────
        $limitCheck = PlanLimitService::canCreateInvoice($companyId);
        if (!$limitCheck['allowed']) {
            return response()->json([
                'status' => 'error',
                'error' => 'quota_exceeded',
                'message' => $limitCheck['reason'] ?? 'Invoice quota exceeded for your plan.',
            ], 429);
        }

        $buyerRegType = $request->input('buyer_registration_type');
        if (!$buyerRegType || !in_array($buyerRegType, ['Registered', 'Unregistered'])) {
            $buyerRegType = InvoiceController::detectBuyerRegistrationType(
                $request->input('buyer_ntn'), $request->input('buyer_cnic')
            );
        }

        // ── Create invoice + items (mirrors InvoiceController::store) ───────
        try {
            $invoice = DB::transaction(function () use ($request, $company, $companyId, $selectedBranch, $documentType, $buyerRegType, $clientReference, $standardTaxRate) {
                $totalValueExcludingST = 0;
                $totalSalesTax = 0;
                foreach ($request->input('items') as $item) {
                    $totalValueExcludingST += floatval($item['price']) * floatval($item['quantity']);
                    $totalSalesTax += floatval($item['tax']);
                }
                $totalAmount = round($totalValueExcludingST + $totalSalesTax, 2);

                $invoiceNumber = InvoiceNumberingService::generateNextNumber($companyId);
                $supplierProvince = $selectedBranch?->province ?? $company->province ?? null;

                $invoice = Invoice::create([
                    'company_id' => $companyId,
                    'invoice_number' => $invoiceNumber,
                    'internal_invoice_number' => $invoiceNumber,
                    'buyer_name' => $request->input('buyer_name'),
                    'buyer_ntn' => $request->input('buyer_ntn'),
                    'buyer_cnic' => $request->input('buyer_cnic'),
                    'buyer_address' => $request->input('buyer_address'),
                    'buyer_registration_type' => $buyerRegType,
                    'total_amount' => $totalAmount,
                    'total_value_excluding_st' => round($totalValueExcludingST, 2),
                    'total_sales_tax' => round($totalSalesTax, 2),
                    'wht_rate' => 0,
                    'wht_amount' => 0,
                    'net_receivable' => $totalAmount,
                    'status' => 'draft',
                    'fbr_status' => null,
                    'branch_id' => $selectedBranch?->id,
                    'document_type' => $documentType,
                    'reference_invoice_number' => $request->input('reference_invoice_number'),
                    'supplier_province' => $supplierProvince,
                    'destination_province' => $request->input('destination_province'),
                    'invoice_date' => $request->filled('invoice_date')
                        ? \Illuminate\Support\Carbon::parse($request->input('invoice_date'))->toDateString()
                        : now()->toDateString(),
                    'source' => 'api',
                    'client_reference' => $clientReference,
                ]);

                foreach ($request->input('items') as $item) {
                    $scheduleType = $item['schedule_type'] ?? 'standard';
                    $hsResolved = GlobalHsService::resolveForInvoiceItem(
                        $item['hs_code'], $standardTaxRate, $companyId, $invoice->id
                    );

                    InvoiceItem::create([
                        'invoice_id' => $invoice->id,
                        'hs_code' => $item['hs_code'],
                        'schedule_type' => $scheduleType,
                        'pct_code' => $item['pct_code'] ?? ($hsResolved['pct_code'] ?? null),
                        'tax_rate' => app(InvoiceController::class)->extractTaxRate($item, $invoice->supplier_province),
                        'sro_schedule_no' => $item['sro_schedule_no'] ?? null,
                        'serial_no' => $item['serial_no'] ?? null,
                        'mrp' => !empty($item['mrp']) ? $item['mrp'] : null,
                        'default_uom' => $item['default_uom'] ?? ($hsResolved['default_uom'] ?? 'Numbers, pieces, units'),
                        'sale_type' => ScheduleEngine::mapSaleType($scheduleType),
                        'st_withheld_at_source' => !empty($item['st_withheld_at_source']),
                        'petroleum_levy' => !empty($item['petroleum_levy']) ? floatval($item['petroleum_levy']) : null,
                        'further_tax' => !empty($item['further_tax']) ? floatval($item['further_tax']) : 0,
                        'description' => $item['description'],
                        'quantity' => $item['quantity'],
                        'price' => $item['price'],
                        'tax' => $item['tax'],
                    ]);
                }

                return $invoice;
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // Unique (company_id, client_reference) race — a concurrent retry
            // won; return the winner's invoice instead of an error.
            if (str_contains($e->getMessage(), 'invoices_company_client_ref_unique')
                || (string) ($e->errorInfo[1] ?? '') === '1062') {
                $winner = Invoice::withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->where('client_reference', $clientReference)
                    ->first();
                if ($winner) {
                    return response()->json($this->serialize($winner, true), 200);
                }
            }
            Log::error("DI API invoice create failed (company {$companyId}): " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'error' => 'server_error',
                'message' => 'Failed to create invoice. Please retry with the same client_reference.',
            ], 500);
        } catch (\Throwable $e) {
            Log::error("DI API invoice create failed (company {$companyId}): " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'error' => 'server_error',
                'message' => 'Failed to create invoice. Please retry with the same client_reference.',
            ], 500);
        }

        InvoiceActivityService::log($invoice->id, $companyId, 'created', [
            'buyer_name' => $invoice->buyer_name,
            'total_amount' => $invoice->total_amount,
            'items_count' => count($request->input('items')),
            'document_type' => $documentType,
            'via' => 'api',
            'client_reference' => $clientReference,
        ]);
        AuditLogService::log('invoice_created', 'Invoice', $invoice->id, null, [
            'invoice_number' => $invoice->invoice_number,
            'buyer_name' => $invoice->buyer_name,
            'total_amount' => $invoice->total_amount,
            'document_type' => $documentType,
            'via' => 'api',
        ], $companyId);
        ComplianceScoringJob::dispatch($invoice->id);

        try {
            \App\Services\HsUsagePatternService::recordFromInvoiceCreation($request->input('items'));
        } catch (\Throwable $e) {
            // usage analytics only — never block the API response
        }

        // ── Draft-only mode stops here ───────────────────────────────────────
        if ($mode === 'draft') {
            return response()->json($this->serialize($invoice->fresh()), 201);
        }

        // ── Create-and-submit: same lock discipline as the panel submit ────
        $locked = DB::transaction(function () use ($invoice) {
            $lockedInvoice = Invoice::withoutGlobalScopes()->where('id', $invoice->id)->lockForUpdate()->first();
            if (!$lockedInvoice || !in_array($lockedInvoice->status, ['draft', 'failed']) || $lockedInvoice->is_fbr_processing) {
                return false;
            }
            $lockedInvoice->is_fbr_processing = true;
            $lockedInvoice->submitted_at = now();
            $lockedInvoice->submission_mode = 'api';
            $lockedInvoice->save();
            return true;
        });

        if (!$locked) {
            // Extremely unlikely straight after create — report the current state.
            return response()->json($this->serialize($invoice->fresh()), 201);
        }

        InvoiceActivityService::log($invoice->id, $companyId, 'submitted', [
            'mode' => 'api',
            'client_reference' => $clientReference,
        ]);

        $invoice = $invoice->fresh();
        $result = app(InvoiceController::class)->submitToFbrSync($invoice);

        $payload = $this->serialize($invoice->fresh());
        if ($result['status'] === 'success' || !empty($result['fbr_invoice_number'])) {
            // covered by serialize() — fbr_invoice_number comes from the row
        } else {
            $errors = !empty($result['errors'])
                ? array_values($result['errors'])
                : $this->latestFbrErrors($invoice);
            if (!empty($errors)) {
                $payload['fbr_errors'] = $errors;
            }
        }
        return response()->json($payload, 201);
    }

    /** GET /api/di/v1/invoices/status?client_reference=…|invoice_number=… */
    public function status(Request $request)
    {
        $company = $request->attributes->get('di_api_company');

        $clientReference = trim((string) $request->query('client_reference', ''));
        $invoiceNumber = trim((string) $request->query('invoice_number', ''));

        if ($clientReference === '' && $invoiceNumber === '') {
            return response()->json([
                'status' => 'error',
                'error' => 'missing_parameter',
                'message' => 'Provide client_reference or invoice_number as a query parameter.',
            ], 422);
        }

        $query = Invoice::withoutGlobalScopes()->where('company_id', $company->id);
        if ($clientReference !== '') {
            $query->where('client_reference', $clientReference);
        } else {
            $query->where(function ($q) use ($invoiceNumber) {
                $q->where('invoice_number', $invoiceNumber)
                  ->orWhere('internal_invoice_number', $invoiceNumber)
                  ->orWhere('fbr_invoice_number', $invoiceNumber);
            });
        }

        $invoice = $query->orderByDesc('id')->first();
        if (!$invoice) {
            return response()->json([
                'status' => 'error',
                'error' => 'not_found',
                'message' => 'No invoice matches the given reference.',
            ], 404);
        }

        $payload = $this->serialize($invoice);
        if ($invoice->status === 'failed') {
            $fbrErrors = $this->latestFbrErrors($invoice);
            if (!empty($fbrErrors)) {
                $payload['fbr_errors'] = $fbrErrors;
            }
        }
        return response()->json($payload, 200);
    }

    /** Uniform invoice representation. No lazy relations — items via count query. */
    private function serialize(Invoice $invoice, bool $duplicate = false): array
    {
        return [
            'status' => 'ok',
            'duplicate' => $duplicate,
            'invoice' => [
                'id' => (int) $invoice->id,
                'client_reference' => $invoice->client_reference,
                'invoice_number' => $invoice->internal_invoice_number ?? $invoice->invoice_number,
                'document_type' => $invoice->document_type,
                'invoice_date' => (string) $invoice->invoice_date,
                'buyer_name' => $invoice->buyer_name,
                'total_amount' => (float) $invoice->total_amount,
                'total_sales_tax' => (float) $invoice->total_sales_tax,
                'items_count' => (int) InvoiceItem::where('invoice_id', $invoice->id)->count(),
                'invoice_status' => $invoice->status,
                'fbr_status' => $invoice->fbr_status,
                'fbr_invoice_number' => $invoice->fbr_invoice_number,
                'fbr_submission_date' => optional($invoice->fbr_submission_date)->toIso8601String(),
                'source' => $invoice->source,
            ],
        ];
    }

    /** Pull the latest FBR error messages for a failed invoice (for polling callers). */
    private function latestFbrErrors(Invoice $invoice): array
    {
        try {
            $log = FbrLog::where('invoice_id', $invoice->id)->orderByDesc('created_at')->first();
            if (!$log || !$log->response_payload) return [];
            $resp = json_decode($log->response_payload, true);
            if (!is_array($resp)) return [];
            if (!empty($resp['errors'])) {
                return array_values(is_array($resp['errors']) ? $resp['errors'] : [$resp['errors']]);
            }
            if (!empty($resp['error'])) {
                return [is_string($resp['error']) ? $resp['error'] : json_encode($resp['error'])];
            }
        } catch (\Throwable $e) {
            // best-effort only
        }
        return [];
    }
}
