<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Invoice;
use App\Services\BulkDraftReviewService;
use App\Services\InvoiceImportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Batch review — the screen a user lands on after a bulk upload (Excel/CSV or
 * AI photos) to see WHICH drafts FBR would reject and fix them on the spot.
 *
 * Deliberately NOT a spreadsheet round-trip: fixes are applied to the drafts
 * directly, so there is no file to lose, no duplicate invoices, no re-charged
 * quota and no way to disturb an invoice that already went to FBR. The Excel
 * here is download-only — a verification copy for the accountant.
 */
class BulkDraftReviewController extends Controller
{
    public function __construct(private BulkDraftReviewService $service = new BulkDraftReviewService())
    {
    }

    public function show(Request $request, string $type, string $ref)
    {
        [$company, $batch, $error] = $this->context($type, $ref);
        if ($error) {
            return redirect('/invoices')->with('error', $error);
        }

        $review = $this->service->buildReview($batch, $company);

        return view('invoice.batch-review', [
            'batch' => $batch,
            'rows' => $review['rows'],
            'summary' => $review['summary'],
            'truncated' => $review['truncated'],
            'totalInvoices' => $review['total_invoices'],
            'provinces' => InvoiceImportService::VALID_PROVINCES,
            'documentTypes' => InvoiceImportService::VALID_DOC_TYPES,
            'scheduleTypes' => InvoiceImportService::VALID_SCHEDULE_TYPES,
            'maxReview' => BulkDraftReviewService::MAX_REVIEW_INVOICES,
        ]);
    }

    /** Fresh verdicts for the whole batch (used after a bulk fix). */
    public function rows(Request $request, string $type, string $ref)
    {
        [$company, $batch, $error] = $this->context($type, $ref);
        if ($error) {
            return response()->json(['error' => $error], 404);
        }

        $review = $this->service->buildReview($batch, $company);

        return response()->json([
            'rows' => $review['rows'],
            'summary' => $review['summary'],
            'truncated' => $review['truncated'],
            'total_invoices' => $review['total_invoices'],
        ]);
    }

    /** Save inline grid edits for one or more invoices of this batch. */
    public function save(Request $request, string $type, string $ref)
    {
        [$company, $batch, $error] = $this->context($type, $ref);
        if ($error) {
            return response()->json(['error' => $error], 404);
        }

        $request->validate([
            'invoices' => 'required|array|min:1|max:200',
            'invoices.*.id' => 'required|integer',
            'invoices.*.header' => 'nullable|array',
            'invoices.*.items' => 'nullable|array|max:100',
            'invoices.*.items.*.id' => 'required|integer',
        ]);

        $allowed = array_flip($this->service->invoiceIdsForBatch($batch, $company->id));

        $saved = [];
        $skipped = [];
        foreach ($request->input('invoices', []) as $payload) {
            $id = (int) ($payload['id'] ?? 0);
            if (!isset($allowed[$id])) {
                $skipped[] = ['id' => $id, 'message' => 'This invoice is not part of this batch.'];
                continue;
            }

            $invoice = Invoice::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->with(['items' => fn ($q) => $q->orderBy('id'), 'company'])
                ->find($id);
            if (!$invoice) {
                $skipped[] = ['id' => $id, 'message' => 'Invoice not found.'];
                continue;
            }

            $result = $this->service->applyInvoiceEdits(
                $invoice,
                $company,
                (array) ($payload['header'] ?? []),
                (array) ($payload['items'] ?? [])
            );

            if (!empty($result['ok'])) {
                $saved[] = $result['row'];
            } else {
                $skipped[] = ['id' => $id, 'message' => $result['message'] ?? 'Could not be saved.'];
            }
        }

        return response()->json([
            'status' => 'ok',
            'saved' => $saved,
            'skipped' => $skipped,
        ]);
    }

    /** "Fix this everywhere" — one value, every row of the batch that has it. */
    public function bulkFix(Request $request, string $type, string $ref)
    {
        [$company, $batch, $error] = $this->context($type, $ref);
        if ($error) {
            return response()->json(['error' => $error], 404);
        }

        $request->validate([
            'field' => 'required|string|max:50',
            // Nullable, not just present: empty inputs arrive as null (the
            // convert-empty-strings middleware), and a blank value is a real
            // case the service validates and explains per field.
            'match_value' => 'present|nullable|string|max:500',
            'value' => 'present|nullable|string|max:500',
        ]);

        $field = (string) $request->input('field');
        if (!in_array($field, array_merge(BulkDraftReviewService::HEADER_FIELDS, BulkDraftReviewService::ITEM_FIELDS), true)) {
            return response()->json(['error' => 'That column cannot be bulk-edited.'], 422);
        }

        $ids = $this->service->invoiceIdsForBatch($batch, $company->id);
        $ids = array_slice($ids, 0, BulkDraftReviewService::MAX_REVIEW_INVOICES);

        $result = $this->service->applyBulkFix(
            $ids,
            $company,
            $field,
            (string) $request->input('match_value'),
            (string) $request->input('value')
        );

        if (!empty($result['error'])) {
            return response()->json(['status' => 'error', 'message' => $result['error']], 422);
        }

        $review = $this->service->buildReview($batch, $company);

        return response()->json([
            'status' => 'ok',
            'changed_rows' => $result['changed_rows'],
            'changed_invoices' => count($result['changed_invoices']),
            'skipped' => $result['skipped'],
            'rows' => $review['rows'],
            'summary' => $review['summary'],
        ]);
    }

    /** Verification copy — download only, never re-uploaded. */
    public function export(Request $request, string $type, string $ref): StreamedResponse|\Illuminate\Http\RedirectResponse
    {
        [$company, $batch, $error] = $this->context($type, $ref);
        if ($error) {
            return redirect('/invoices')->with('error', $error);
        }

        $review = $this->service->buildReview($batch, $company);

        return $this->service->exportXlsx($batch, $review['rows']);
    }

    /**
     * @return array{0:?Company,1:?array,2:?string}
     */
    private function context(string $type, string $ref): array
    {
        $company = Company::find(app('currentCompanyId'));
        if (!$company) {
            return [null, null, 'Company context missing.'];
        }

        if (!in_array($type, [BulkDraftReviewService::TYPE_IMPORT, BulkDraftReviewService::TYPE_AI], true)) {
            return [null, null, 'Unknown batch type.'];
        }

        $batch = $this->service->resolveBatch($type, $ref, $company->id);
        if (!$batch) {
            return [null, null, 'That batch could not be found.'];
        }

        return [$company, $batch, null];
    }
}
