<?php

namespace App\Services;

use App\Models\BulkAiImageBatch;
use App\Models\BulkAiImageItem;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceImportBatch;
use App\Models\InvoiceItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Batch review: after a bulk upload (Excel/CSV or AI photos) the user gets a
 * single screen showing every draft the batch produced, WHY a draft would be
 * rejected by FBR, inline fixes, and a "fix this everywhere" action.
 *
 * Design rules this service exists to hold:
 *  - The verdict shown here must be the SAME verdict submission uses. It runs
 *    FbrService::buildPayload + validatePayloadPreSubmission (exactly what
 *    submitToFbrSync runs) plus ScheduleEngine::validateItems — never a
 *    re-implementation, or the screen would say "OK" and FBR would say no.
 *  - Nothing that already reached FBR may be edited. Locked / processing /
 *    numbered invoices are read-only, and invoice numbers are never reissued.
 *  - Column names match InvoiceImportService's canonical set so the template,
 *    the error report and this export stay one single vocabulary.
 */
class BulkDraftReviewService
{
    public const TYPE_IMPORT = 'import';
    public const TYPE_AI = 'ai';

    /** Hard cap on invoices reviewed in one screen (DOM + validation cost). */
    public const MAX_REVIEW_INVOICES = 1000;

    /** Header fields editable from the review grid. */
    public const HEADER_FIELDS = [
        'invoice_date',
        'branch',
        'buyer_name',
        'buyer_ntn',
        'buyer_cnic',
        'buyer_address',
        'destination_province',
        'document_type',
        'reference_invoice_number',
    ];

    /** Item fields editable from the review grid. */
    public const ITEM_FIELDS = [
        'hs_code',
        'description',
        'quantity',
        'price',
        'tax',
        'tax_rate',
        'schedule_type',
        'mrp',
        'sro_schedule_no',
        'serial_no',
    ];

    /** Values that must stay literal strings in the export (codes and dates). */
    private const CODE_FIELDS = ['buyer_ntn', 'buyer_cnic', 'hs_code', 'sro_serial_no', 'serial_no', 'reference_invoice_number', 'invoice_date'];

    private ?FbrService $fbr = null;
    private ?BranchResolver $branchResolverInstance = null;
    private ?bool $branchStorageAvailable = null;

    private function fbr(): FbrService
    {
        return $this->fbr ??= new FbrService();
    }

    // ------------------------------------------------------------------
    // Batch resolution
    // ------------------------------------------------------------------

    /**
     * Resolve a batch of either kind into a company-scoped descriptor.
     *
     * @return array{type:string,ref:string,id:int,label:string,created_at:?string,source_label:string}|null
     */
    public function resolveBatch(string $type, string $ref, int $companyId): ?array
    {
        if ($type === self::TYPE_IMPORT) {
            $batch = InvoiceImportBatch::where('company_id', $companyId)->find((int) $ref);
            if (!$batch) {
                return null;
            }

            return [
                'type' => self::TYPE_IMPORT,
                'ref' => (string) $batch->id,
                'id' => (int) $batch->id,
                'label' => $batch->original_filename ?: ('Import #' . $batch->id),
                'created_at' => optional($batch->created_at)->toDateTimeString(),
                'source_label' => 'Excel / CSV import',
            ];
        }

        if ($type === self::TYPE_AI) {
            $batch = BulkAiImageBatch::where('company_id', $companyId)
                ->where(function ($q) use ($ref) {
                    $q->where('batch_uuid', $ref);
                    if (ctype_digit($ref)) {
                        $q->orWhere('id', (int) $ref);
                    }
                })
                ->first();
            if (!$batch) {
                return null;
            }

            return [
                'type' => self::TYPE_AI,
                'ref' => (string) $batch->batch_uuid,
                'id' => (int) $batch->id,
                'label' => 'AI photo batch (' . (int) $batch->total_images . ' image' . ((int) $batch->total_images === 1 ? '' : 's') . ')',
                'created_at' => optional($batch->created_at)->toDateTimeString(),
                'source_label' => 'AI photo upload',
            ];
        }

        return null;
    }

    /**
     * Every invoice id this batch produced, oldest first.
     *
     * @return array<int,int>
     */
    public function invoiceIdsForBatch(array $batch, int $companyId): array
    {
        if ($batch['type'] === self::TYPE_AI) {
            return BulkAiImageItem::where('batch_id', $batch['id'])
                ->where('company_id', $companyId)
                ->whereNotNull('invoice_id')
                ->orderBy('position')
                ->pluck('invoice_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        // Excel/CSV: the stamped column is the only complete link. Batches
        // created before the column existed fall back to result_json, which
        // the job caps at the first 300 created invoices.
        if (Schema::hasColumn('invoices', 'import_batch_id')) {
            $ids = Invoice::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('import_batch_id', $batch['id'])
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            if (!empty($ids)) {
                return $ids;
            }
        }

        $model = InvoiceImportBatch::where('company_id', $companyId)->find($batch['id']);
        $created = $model ? ($model->resultArray()['created'] ?? []) : [];
        $ids = [];
        foreach ($created as $entry) {
            if (!empty($entry['id'])) {
                $ids[] = (int) $entry['id'];
            }
        }

        if (empty($ids)) {
            return [];
        }

        return Invoice::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    // ------------------------------------------------------------------
    // Review
    // ------------------------------------------------------------------

    /**
     * Build the full review payload for a batch.
     *
     * @return array{rows:array,summary:array,truncated:bool,total_invoices:int}
     */
    public function buildReview(array $batch, Company $company): array
    {
        $allIds = $this->invoiceIdsForBatch($batch, $company->id);
        $total = count($allIds);
        $ids = array_slice($allIds, 0, self::MAX_REVIEW_INVOICES);

        $rows = $this->rowsForInvoiceIds($ids, $company);

        $summary = ['total' => count($rows), 'ok' => 0, 'error' => 0, 'submitted' => 0];
        foreach ($rows as $row) {
            if ($row['status'] === 'submitted') {
                $summary['submitted']++;
            } elseif ($row['status'] === 'error') {
                $summary['error']++;
            } else {
                $summary['ok']++;
            }
        }

        return [
            'rows' => $rows,
            'summary' => $summary,
            'truncated' => $total > count($ids),
            'total_invoices' => $total,
        ];
    }

    /**
     * Reviewed rows for a set of invoice ids (chunked so a big batch does not
     * hold every model in memory at once).
     *
     * @return array<int,array>
     */
    public function rowsForInvoiceIds(array $ids, Company $company): array
    {
        if (empty($ids)) {
            return [];
        }

        $rows = [];
        foreach (array_chunk($ids, 100) as $chunk) {
            $invoices = Invoice::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->whereIn('id', $chunk)
                ->with(['items' => fn ($q) => $q->orderBy('id'), 'company'])
                ->orderBy('id')
                ->get();

            foreach ($invoices as $invoice) {
                $rows[] = $this->rowFor($invoice, $company);
            }
        }

        return $rows;
    }

    /** One reviewed invoice, ready for the grid and the export. */
    public function rowFor(Invoice $invoice, Company $company): array
    {
        $review = $this->reviewInvoice($invoice, $company);
        $editable = $this->isEditable($invoice);

        $items = [];
        foreach ($invoice->items as $idx => $item) {
            $items[] = [
                'id' => (int) $item->id,
                'hs_code' => (string) ($item->hs_code ?? ''),
                'description' => (string) ($item->description ?? ''),
                'quantity' => $this->num($item->quantity),
                'price' => $this->num($item->price),
                'tax' => $this->num($item->tax),
                'tax_rate' => $item->tax_rate === null ? '' : $this->num($item->tax_rate),
                'schedule_type' => (string) ($item->schedule_type ?? 'standard'),
                'mrp' => $item->mrp === null ? '' : $this->num($item->mrp),
                'sro_schedule_no' => (string) ($item->sro_schedule_no ?? ''),
                'serial_no' => (string) ($item->serial_no ?? ''),
                'issues' => $review['item_issues'][$idx] ?? [],
            ];
        }

        return [
            'id' => (int) $invoice->id,
            'number' => (string) ($invoice->internal_invoice_number ?: $invoice->invoice_number ?: ('INV-' . $invoice->id)),
            'status' => $review['status'],
            'editable' => $editable,
            'lock_reason' => $editable ? null : $this->lockReason($invoice),
            'invoice_status' => (string) ($invoice->status ?? ''),
            'total_amount' => $this->num($invoice->total_amount),
            'issues' => $review['issues'],
            'header' => [
                'invoice_date' => $invoice->invoice_date
                    ? \Illuminate\Support\Carbon::parse($invoice->invoice_date)->toDateString()
                    : '',
                'branch' => $this->branchName($invoice),
                'buyer_name' => (string) ($invoice->buyer_name ?? ''),
                'buyer_ntn' => (string) ($invoice->buyer_ntn ?? ''),
                'buyer_cnic' => (string) ($invoice->buyer_cnic ?? ''),
                'buyer_address' => (string) ($invoice->buyer_address ?? ''),
                'destination_province' => (string) ($invoice->destination_province ?? ''),
                'document_type' => (string) ($invoice->document_type ?? 'Sale Invoice'),
                'reference_invoice_number' => (string) ($invoice->reference_invoice_number ?? ''),
            ],
            'header_issues' => $review['header_issues'],
            'items' => $items,
        ];
    }

    /**
     * The verdict. Same validators submission runs — no second rulebook.
     *
     * @return array{status:string,issues:array<int,string>,header_issues:array<string,string>,item_issues:array<int,array<string,string>>}
     */
    public function reviewInvoice(Invoice $invoice, ?Company $company = null): array
    {
        if (!$this->isEditable($invoice)) {
            return ['status' => 'submitted', 'issues' => [], 'header_issues' => [], 'item_issues' => []];
        }

        $messages = [];

        try {
            $payload = $this->fbr()->buildPayload($invoice);
            foreach ($this->fbr()->validatePayloadPreSubmission($payload, $company ?? $invoice->company) as $err) {
                $code = trim((string) ($err['code'] ?? ''));
                $msg = trim((string) ($err['message'] ?? ''));
                if ($msg !== '') {
                    $messages[] = ($code !== '' ? '[' . $code . '] ' : '') . $msg;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Batch review payload build failed for invoice ' . $invoice->id . ': ' . $e->getMessage());
            $messages[] = 'This invoice could not be prepared for FBR: ' . $e->getMessage();
        }

        try {
            $rate = $company?->getStandardTaxRateValue() ?? $invoice->company?->getStandardTaxRateValue() ?? 18.0;
            foreach (ScheduleEngine::validateItems($this->itemsAsArrays($invoice), (float) $rate) as $msg) {
                $msg = trim((string) $msg);
                if ($msg !== '') {
                    $messages[] = $msg;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Batch review schedule check failed for invoice ' . $invoice->id . ': ' . $e->getMessage());
        }

        $messages = array_values(array_unique($messages));

        $headerIssues = [];
        $itemIssues = [];
        foreach ($messages as $msg) {
            [$itemIndex, $field] = $this->locateIssue($msg, $invoice);
            if ($field === null) {
                continue;
            }
            if ($itemIndex === null) {
                $headerIssues[$field] = $headerIssues[$field] ?? $msg;
            } else {
                $itemIssues[$itemIndex][$field] = $itemIssues[$itemIndex][$field] ?? $msg;
            }
        }

        return [
            'status' => empty($messages) ? 'ok' : 'error',
            'issues' => $messages,
            'header_issues' => $headerIssues,
            'item_issues' => $itemIssues,
        ];
    }

    /** Raw item arrays in the shape ScheduleEngine expects. */
    private function itemsAsArrays(Invoice $invoice): array
    {
        $out = [];
        foreach ($invoice->items as $item) {
            $out[] = [
                'hs_code' => $item->hs_code,
                'description' => $item->description,
                'schedule_type' => $item->schedule_type ?? 'standard',
                'tax_rate' => $item->tax_rate,
                'price' => $item->price,
                'quantity' => $item->quantity,
                'tax' => $item->tax,
                'sro_schedule_no' => $item->sro_schedule_no,
                'serial_no' => $item->serial_no,
                'mrp' => $item->mrp,
            ];
        }

        return $out;
    }

    /**
     * Best-effort "which cell does this complaint belong to".
     *
     * Highlighting only — the full message is always shown on the row, so a
     * miss here degrades to "invoice-level issue", never to a hidden error.
     *
     * @return array{0:?int,1:?string} [item index (0-based) or null, field or null]
     */
    private function locateIssue(string $message, Invoice $invoice): array
    {
        $lower = mb_strtolower($message);

        $itemIndex = null;
        if (preg_match('/item\s*#?\s*(\d+)/i', $message, $m)) {
            $n = (int) $m[1] - 1;
            if ($n >= 0 && $n < $invoice->items->count()) {
                $itemIndex = $n;
            }
        }

        $itemMap = [
            'mrp' => 'mrp',
            'retail price' => 'mrp',
            'sro serial' => 'serial_no',
            'serial number' => 'serial_no',
            'serial no' => 'serial_no',
            'sro' => 'sro_schedule_no',
            'hs code' => 'hs_code',
            'hscode' => 'hs_code',
            'description' => 'description',
            'quantity' => 'quantity',
            'unit of measure' => 'quantity',
            'uom' => 'quantity',
            'tax rate' => 'tax_rate',
            'rate is missing' => 'tax_rate',
            'sale type' => 'schedule_type',
            'schedule' => 'schedule_type',
            'sales tax' => 'tax',
            'tax amount' => 'tax',
            'zero' => 'price',
            'value' => 'price',
            'price' => 'price',
        ];

        $headerMap = [
            'invoice date' => 'invoice_date',
            'date' => 'invoice_date',
            'buyer name' => 'buyer_name',
            'buyer business name' => 'buyer_name',
            'buyer address' => 'buyer_address',
            'address' => 'buyer_address',
            'cnic' => 'buyer_cnic',
            'buyer registration' => 'buyer_ntn',
            'buyer ntn' => 'buyer_ntn',
            'ntn' => 'buyer_ntn',
            'destination' => 'destination_province',
            'buyer province' => 'destination_province',
            'province' => 'destination_province',
            'reference' => 'reference_invoice_number',
            'invoice type' => 'document_type',
            'document type' => 'document_type',
        ];

        if ($itemIndex !== null) {
            foreach ($itemMap as $needle => $field) {
                if (str_contains($lower, $needle)) {
                    return [$itemIndex, $field];
                }
            }

            return [$itemIndex, 'description'];
        }

        foreach ($headerMap as $needle => $field) {
            if (str_contains($lower, $needle)) {
                return [null, $field];
            }
        }

        // Item-worded complaints that never named an item still belong to the
        // items — pin them on the first line rather than losing the highlight.
        foreach ($itemMap as $needle => $field) {
            if (str_contains($lower, $needle) && $invoice->items->count() === 1) {
                return [0, $field];
            }
        }

        return [null, null];
    }

    /** Anything that reached FBR is frozen here. */
    public function isEditable(Invoice $invoice): bool
    {
        if (in_array($invoice->status, ['locked', 'pending_verification'], true)) {
            return false;
        }
        if (!empty($invoice->fbr_invoice_number)) {
            return false;
        }
        if ($invoice->is_fbr_processing) {
            return false;
        }

        return true;
    }

    private function lockReason(Invoice $invoice): string
    {
        if ($invoice->status === 'locked' || !empty($invoice->fbr_invoice_number)) {
            return 'Already submitted to FBR — cannot be changed.';
        }
        if ($invoice->status === 'pending_verification') {
            return 'Waiting for FBR verification.';
        }
        if ($invoice->is_fbr_processing) {
            return 'Currently being submitted.';
        }

        return 'Read-only.';
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    /**
     * Apply grid edits to one invoice and re-validate it.
     *
     * Items are matched by id and updated in place — never deleted and
     * recreated — so ids the grid holds stay valid, and the invoice number is
     * never touched.
     *
     * @return array{ok:bool,message?:string,row?:array}
     */
    public function applyInvoiceEdits(Invoice $invoice, Company $company, array $header, array $items): array
    {
        if (!$this->isEditable($invoice)) {
            return ['ok' => false, 'message' => $this->lockReason($invoice)];
        }

        $branchId = null;
        if (array_key_exists('branch', $header)) {
            $branchResult = $this->resolveBranchValue($company, (string) ($header['branch'] ?? ''));
            if (!$branchResult['ok']) {
                return ['ok' => false, 'message' => $branchResult['message']];
            }
            $branchId = $branchResult['branch_id'];
        }

        $standardTaxRate = $company->getStandardTaxRateValue() ?? 18.0;
        $before = [
            'buyer_name' => $invoice->buyer_name,
            'total_amount' => $invoice->total_amount,
        ];

        DB::transaction(function () use ($invoice, $company, $header, $items, $standardTaxRate, $branchId) {
            $existing = $invoice->items()->orderBy('id')->get()->keyBy('id');

            foreach ($items as $payload) {
                $itemId = (int) ($payload['id'] ?? 0);
                $item = $existing->get($itemId);
                if (!$item) {
                    continue;
                }
                $this->applyItemFields($item, $payload, $company, $invoice, $standardTaxRate);
            }

            $updates = [];
            foreach (self::HEADER_FIELDS as $field) {
                if (!array_key_exists($field, $header)) {
                    continue;
                }
                if ($field === 'branch') {
                    $updates['branch_id'] = $branchId;
                    continue;
                }
                $normalized = $this->normalizeHeaderValue($field, $header[$field]);
                // Blanking the date would file the sale under "no date" — keep
                // whatever it already had instead.
                if ($field === 'invoice_date' && $normalized === null) {
                    continue;
                }
                $updates[$field] = $normalized;
            }

            if (array_key_exists('buyer_ntn', $updates) || array_key_exists('buyer_cnic', $updates)) {
                $updates['buyer_registration_type'] = \App\Http\Controllers\InvoiceController::detectBuyerRegistrationType(
                    $updates['buyer_ntn'] ?? $invoice->buyer_ntn,
                    $updates['buyer_cnic'] ?? $invoice->buyer_cnic
                );
            }

            // A Sale Invoice carries no reference — clearing it here stops a
            // stale reference from failing validation after a type change.
            if (($updates['document_type'] ?? $invoice->document_type) === 'Sale Invoice') {
                $updates['reference_invoice_number'] = null;
            }

            $invoice->fill($updates);
            $invoice->save();

            $this->recalculateTotals($invoice);
        });

        $invoice->refresh()->load(['items' => fn ($q) => $q->orderBy('id'), 'company']);

        InvoiceActivityService::log($invoice->id, $invoice->company_id, 'edited', [
            'old' => $before,
            'new' => ['buyer_name' => $invoice->buyer_name, 'total_amount' => $invoice->total_amount],
            'via' => 'batch_review',
        ]);

        return ['ok' => true, 'row' => $this->rowFor($invoice, $company)];
    }

    /**
     * "Fix this everywhere": set $field to $value on every row of the batch
     * whose current value equals $matchValue.
     *
     * @return array{changed_invoices:array<int,int>,changed_rows:int,skipped:int}
     */
    public function applyBulkFix(array $invoiceIds, Company $company, string $field, string $matchValue, string $value): array
    {
        $isHeader = in_array($field, self::HEADER_FIELDS, true);
        $isItem = in_array($field, self::ITEM_FIELDS, true);
        if (!$isHeader && !$isItem) {
            return ['changed_invoices' => [], 'changed_rows' => 0, 'skipped' => 0];
        }

        // A bad bulk date must fail loudly BEFORE any row is touched — writing
        // null here would wipe the real sale date off every matching invoice
        // and FBR would then receive the created_at fallback.
        if ($field === 'invoice_date' && $this->normalizeHeaderValue('invoice_date', $value) === null) {
            return [
                'changed_invoices' => [],
                'changed_rows' => 0,
                'skipped' => 0,
                'error' => trim($value) === ''
                    ? 'Enter an invoice date — it cannot be left blank.'
                    : "'" . trim($value) . "' is not a usable invoice date. Use YYYY-MM-DD (e.g. 2026-08-15) or DD/MM/YYYY, and not a future date.",
            ];
        }

        $bulkBranchId = null;
        if ($field === 'branch') {
            $branchResult = $this->resolveBranchValue($company, $value);
            if (!$branchResult['ok']) {
                return [
                    'changed_invoices' => [],
                    'changed_rows' => 0,
                    'skipped' => 0,
                    'error' => $branchResult['message'],
                ];
            }
            $bulkBranchId = $branchResult['branch_id'];
        }

        $standardTaxRate = $company->getStandardTaxRateValue() ?? 18.0;
        $needle = $this->compareKey($matchValue);

        $changedInvoices = [];
        $changedRows = 0;
        $skipped = 0;

        foreach (array_chunk($invoiceIds, 100) as $chunk) {
            $invoices = Invoice::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->whereIn('id', $chunk)
                ->with(['items' => fn ($q) => $q->orderBy('id'), 'company'])
                ->get();

            foreach ($invoices as $invoice) {
                if (!$this->isEditable($invoice)) {
                    $skipped++;
                    continue;
                }

                $touched = false;

                DB::transaction(function () use ($invoice, $company, $field, $needle, $value, $isHeader, $standardTaxRate, $bulkBranchId, &$touched, &$changedRows) {
                    if ($isHeader) {
                        if ($field === 'branch') {
                            if ($this->compareKey($this->branchName($invoice)) !== $needle) {
                                return;
                            }
                            $invoice->branch_id = $bulkBranchId;
                            $invoice->save();
                            $touched = true;
                            $changedRows++;

                            return;
                        }
                        if ($this->compareKey((string) ($invoice->{$field} ?? '')) !== $needle) {
                            return;
                        }
                        $updates = [$field => $this->normalizeHeaderValue($field, $value)];
                        if ($field === 'buyer_ntn' || $field === 'buyer_cnic') {
                            $updates['buyer_registration_type'] = \App\Http\Controllers\InvoiceController::detectBuyerRegistrationType(
                                $field === 'buyer_ntn' ? $updates[$field] : $invoice->buyer_ntn,
                                $field === 'buyer_cnic' ? $updates[$field] : $invoice->buyer_cnic
                            );
                        }
                        if ($field === 'document_type' && $updates[$field] === 'Sale Invoice') {
                            $updates['reference_invoice_number'] = null;
                        }
                        $invoice->fill($updates);
                        $invoice->save();
                        $touched = true;
                        $changedRows++;

                        return;
                    }

                    foreach ($invoice->items as $item) {
                        if ($this->compareKey((string) ($item->{$field} ?? '')) !== $needle) {
                            continue;
                        }
                        $this->applyItemFields($item, ['id' => $item->id, $field => $value], $company, $invoice, $standardTaxRate);
                        $touched = true;
                        $changedRows++;
                    }

                    if ($touched) {
                        $this->recalculateTotals($invoice);
                    }
                });

                if ($touched) {
                    $changedInvoices[] = (int) $invoice->id;
                    InvoiceActivityService::log($invoice->id, $invoice->company_id, 'edited', [
                        'via' => 'batch_review_bulk_fix',
                        'field' => $field,
                    ]);
                }
            }
        }

        return ['changed_invoices' => $changedInvoices, 'changed_rows' => $changedRows, 'skipped' => $skipped];
    }

    /** Write one item's editable fields, re-deriving what depends on them. */
    private function applyItemFields(InvoiceItem $item, array $payload, Company $company, Invoice $invoice, float $standardTaxRate): void
    {
        $hsChanged = false;
        $scheduleChanged = false;

        foreach (self::ITEM_FIELDS as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }
            $raw = $payload[$field];

            switch ($field) {
                case 'quantity':
                case 'price':
                case 'tax':
                    $item->{$field} = is_numeric($raw) ? (float) $raw : 0.0;
                    break;
                case 'tax_rate':
                    $item->tax_rate = ($raw === '' || $raw === null) ? null : (float) $raw;
                    break;
                case 'mrp':
                    $item->mrp = ($raw === '' || $raw === null) ? null : (float) $raw;
                    break;
                case 'schedule_type':
                    $new = in_array($raw, InvoiceImportService::VALID_SCHEDULE_TYPES, true) ? $raw : 'standard';
                    $scheduleChanged = $scheduleChanged || $new !== $item->schedule_type;
                    $item->schedule_type = $new;
                    break;
                case 'hs_code':
                    $new = preg_replace('/[^0-9]/', '', (string) $raw);
                    $hsChanged = $hsChanged || $new !== (string) $item->hs_code;
                    $item->hs_code = $new;
                    break;
                case 'sro_schedule_no':
                case 'serial_no':
                    $item->{$field} = trim((string) $raw) === '' ? null : trim((string) $raw);
                    break;
                default:
                    $item->{$field} = trim((string) $raw);
            }
        }

        if ($scheduleChanged) {
            $item->sale_type = ScheduleEngine::mapSaleType($item->schedule_type ?? 'standard');
            if (!array_key_exists('tax_rate', $payload)) {
                $item->tax_rate = ScheduleEngine::getTaxRate($item->schedule_type ?? 'standard', $company->province ?? null);
            }
        }

        if ($hsChanged && $item->hs_code !== '') {
            try {
                $resolved = GlobalHsService::resolveForInvoiceItem($item->hs_code, $standardTaxRate, $company->id, $invoice->id);
                $item->pct_code = $resolved['pct_code'] ?? $item->pct_code;
                $item->default_uom = $resolved['default_uom'] ?? $item->default_uom;
            } catch (\Throwable $e) {
                Log::warning('Batch review HS re-resolve failed: ' . $e->getMessage());
            }
        }

        if (empty($item->sale_type)) {
            $item->sale_type = ScheduleEngine::mapSaleType($item->schedule_type ?? 'standard');
        }

        $item->save();
    }

    /** Same arithmetic InvoiceController::update() uses — one formula only. */
    private function recalculateTotals(Invoice $invoice): void
    {
        $items = $invoice->items()->get();
        $valueExcludingST = 0.0;
        $salesTax = 0.0;
        foreach ($items as $item) {
            $valueExcludingST += (float) $item->price * (float) $item->quantity;
            $salesTax += (float) $item->tax;
        }
        $total = round($valueExcludingST + $salesTax, 2);

        $updates = [
            'total_value_excluding_st' => round($valueExcludingST, 2),
            'total_sales_tax' => round($salesTax, 2),
            'total_amount' => $total,
            'wht_rate' => 0,
            'wht_amount' => 0,
            'net_receivable' => $total,
        ];

        // A failed invoice that has just been corrected goes back to the
        // draft pool so bulk submit will pick it up again.
        if ($invoice->status === 'failed') {
            $updates['status'] = 'draft';
            $updates['fbr_status'] = 'pending';
        }

        $invoice->fill($updates);
        $invoice->save();
        $invoice->setRelation('items', $items);
    }

    private function normalizeHeaderValue(string $field, $raw): ?string
    {
        $value = trim((string) $raw);

        if ($field === 'document_type') {
            return in_array($value, InvoiceImportService::VALID_DOC_TYPES, true) ? $value : 'Sale Invoice';
        }

        if ($field === 'invoice_date') {
            // Unreadable or future dates are dropped by the caller rather than
            // written — an invoice must never lose the date it already has.
            $normalized = InvoiceImportService::normalizeDate($value);

            return ($normalized !== null && $normalized <= now()->toDateString()) ? $normalized : null;
        }

        if ($field === 'buyer_ntn' || $field === 'buyer_cnic') {
            $value = preg_replace('/[^0-9]/', '', $value);
        }

        return $value === '' ? null : $value;
    }

    private function compareKey(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private function num($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $float = (float) $value;

        return rtrim(rtrim(number_format($float, 4, '.', ''), '0'), '.') ?: '0';
    }

    private function branchResolver(): BranchResolver
    {
        return $this->branchResolverInstance ??= new BranchResolver();
    }

    private function branchStorageAvailable(): bool
    {
        return $this->branchStorageAvailable ??= Schema::hasColumn('invoices', 'branch_id')
            && $this->branchResolver()->branchesTableExists();
    }

    private function branchName(Invoice $invoice): string
    {
        if (!$this->branchStorageAvailable() || !$invoice->branch_id) {
            return '';
        }

        return (string) (Branch::withoutGlobalScopes()
            ->where('company_id', $invoice->company_id)
            ->whereKey($invoice->branch_id)
            ->value('name') ?? '');
    }

    /** @return array{ok:bool,branch_id:?int,message?:string} */
    private function resolveBranchValue(Company $company, string $value): array
    {
        if (!$this->branchStorageAvailable()) {
            return ['ok' => false, 'branch_id' => null, 'message' => 'Branches are not available in this database.'];
        }

        $raw = trim($value);
        if ($raw === '') {
            $lookup = $this->branchResolver()->branchLookup($company);
            if ($lookup === []) {
                return ['ok' => true, 'branch_id' => null];
            }
            $headId = $this->branchResolver()->headOfficeBranchId($company);
            if ($headId === null) {
                return ['ok' => false, 'branch_id' => null, 'message' => 'Branch is blank but no branch is marked as the head office.'];
            }

            return ['ok' => true, 'branch_id' => $headId];
        }

        $lookup = $this->branchResolver()->branchLookup($company);
        $hit = $lookup[$this->branchResolver()->normalizeBranchKey($raw)] ?? null;
        if ($hit === false) {
            return ['ok' => false, 'branch_id' => null, 'message' => "Branch '{$raw}' matches more than one branch. Choose the exact branch name."];
        }
        if ($hit === null) {
            return [
                'ok' => false,
                'branch_id' => null,
                'message' => "Branch '{$raw}' did not match any branch. Available: " . $this->branchResolver()->branchChoices($company) . '.',
            ];
        }

        return ['ok' => true, 'branch_id' => (int) $hit];
    }

    // ------------------------------------------------------------------
    // Export (download only — this file is never re-uploaded)
    // ------------------------------------------------------------------

    /**
     * Verification copy of the batch: one row per line item, plus a Status and
     * a plain-language "what is wrong" column, with the offending cells filled
     * red so the reviewer sees the problem without reading anything.
     */
    public function exportXlsx(array $batch, array $rows): StreamedResponse
    {
        $columns = [
            'invoice_number', 'status', 'issues',
            'invoice_date',
            'branch',
            'buyer_name', 'buyer_ntn', 'buyer_cnic', 'buyer_address', 'destination_province',
            'document_type', 'reference_invoice_number',
            'hs_code', 'description', 'quantity', 'price', 'tax', 'tax_rate', 'schedule_type',
            'mrp', 'sro_schedule_no', 'serial_no',
        ];

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Review');

        foreach ($columns as $i => $col) {
            $sheet->setCellValue([$i + 1, 1], $col);
        }
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($columns));
        $sheet->getStyle('A1:' . $lastCol . '1')->getFont()->setBold(true);
        $sheet->getStyle('A1:' . $lastCol . '1')->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2E8F0');
        $sheet->freezePane('A2');

        foreach (self::CODE_FIELDS as $codeCol) {
            $idx = array_search($codeCol, $columns, true);
            if ($idx === false) {
                continue;
            }
            $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx + 1);
            $sheet->getStyle($letter . ':' . $letter)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        }
        $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(array_search('issues', $columns, true) + 1))->setWidth(80);
        $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(array_search('description', $columns, true) + 1))->setWidth(32);
        $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(array_search('buyer_address', $columns, true) + 1))->setWidth(32);
        $sheet->getColumnDimension('A')->setWidth(20);

        $r = 2;
        foreach ($rows as $row) {
            $items = !empty($row['items']) ? $row['items'] : [[]];
            foreach ($items as $idx => $item) {
                $values = [
                    'invoice_number' => $row['number'],
                    'status' => $idx === 0 ? $this->statusLabel($row['status']) : '',
                    'issues' => $idx === 0 ? implode(' | ', $row['issues']) : '',
                ];
                foreach (self::HEADER_FIELDS as $field) {
                    $values[$field] = (string) ($row['header'][$field] ?? '');
                }
                foreach (self::ITEM_FIELDS as $field) {
                    $values[$field] = (string) ($item[$field] ?? '');
                }

                foreach ($columns as $i => $col) {
                    $value = (string) ($values[$col] ?? '');
                    if (in_array($col, self::CODE_FIELDS, true)) {
                        $sheet->setCellValueExplicit([$i + 1, $r], $value, DataType::TYPE_STRING);
                    } else {
                        $sheet->setCellValue([$i + 1, $r], $value);
                    }

                    $bad = isset($row['header_issues'][$col]) || isset($item['issues'][$col]);
                    if ($bad) {
                        $sheet->getStyle([$i + 1, $r, $i + 1, $r])->getFill()
                            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FECACA');
                    }
                }

                if ($idx === 0 && $row['status'] === 'error') {
                    $statusIdx = array_search('status', $columns, true) + 1;
                    $sheet->getStyle([$statusIdx, $r, $statusIdx, $r])->getFill()
                        ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FCA5A5');
                }

                $r++;
            }
        }

        $filename = 'batch_review_' . $batch['type'] . '_' . preg_replace('/[^A-Za-z0-9_-]/', '', $batch['ref']) . '.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new XlsxWriter($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'ok' => 'OK',
            'error' => 'NEEDS FIX',
            'submitted' => 'SUBMITTED',
            default => strtoupper($status),
        };
    }
}
