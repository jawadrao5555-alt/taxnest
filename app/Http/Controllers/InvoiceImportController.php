<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessInvoiceImportBatchJob;
use App\Models\Company;
use App\Models\InvoiceImportBatch;
use App\Services\InvoiceImportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * DI bulk invoice import — .xlsx/CSV upload with row-level FBR pre-validation,
 * background draft creation and polled progress.
 *
 * The legacy CSV endpoints (CsvImportController) stay as a fallback; both
 * paths run the SAME validation via InvoiceImportService.
 */
class InvoiceImportController extends Controller
{
    private const PREVIEW_LIMIT = 100;

    public function __construct(private InvoiceImportService $service = new InvoiceImportService())
    {
    }

    public function template(): StreamedResponse
    {
        return $this->service->templateResponse();
    }

    public function upload(Request $request)
    {
        $request->validate([
            'import_file' => 'required|file|max:' . InvoiceImportService::MAX_FILE_KB,
        ]);

        $file = $request->file('import_file');
        $extension = strtolower($file->getClientOriginalExtension() ?: '');
        if (!in_array($extension, ['xlsx', 'xls', 'csv', 'txt'], true)) {
            return response()->json(['error' => 'Unsupported file type. Upload the .xlsx template, or a .csv file.'], 422);
        }

        $company = Company::find(app('currentCompanyId'));
        if (!$company) {
            return response()->json(['error' => 'Company context missing.'], 403);
        }

        $parsed = $this->service->parseFile($file->getRealPath(), $extension);
        if (isset($parsed['error'])) {
            return response()->json(['error' => $parsed['error']], 422);
        }

        $result = $this->service->validateRows($parsed['rows'], $company);

        $batch = InvoiceImportBatch::create([
            'company_id' => $company->id,
            'user_id' => auth()->id(),
            'original_filename' => substr($file->getClientOriginalName(), 0, 255),
            'source_format' => in_array($extension, ['xlsx', 'xls'], true) ? 'xlsx' : 'csv',
            'status' => 'validated',
            'total_rows' => $result['total'],
            'valid_rows' => $result['valid_count'],
            'invalid_rows' => $result['error_count'],
            'rows_json' => json_encode($result['rows']),
        ]);

        return response()->json([
            'batch_id' => $batch->id,
            'total' => $result['total'],
            'valid_count' => $result['valid_count'],
            'error_count' => $result['error_count'],
            'preview' => array_slice($result['rows'], 0, self::PREVIEW_LIMIT),
            'preview_limit' => self::PREVIEW_LIMIT,
            'has_more' => $result['total'] > self::PREVIEW_LIMIT,
            'error_report_url' => $result['error_count'] > 0 ? '/invoices/import/' . $batch->id . '/error-report' : null,
        ]);
    }

    public function process(Request $request, int $batchId)
    {
        $batch = $this->findBatch($batchId);
        if (!$batch) {
            return response()->json(['error' => 'Import batch not found.'], 404);
        }

        if ($batch->valid_rows < 1) {
            return response()->json(['error' => 'No valid rows to import. Fix the errors and upload again.'], 422);
        }

        // Atomic: only one processing run per batch.
        $moved = InvoiceImportBatch::whereKey($batch->id)
            ->where('status', 'validated')
            ->update(['status' => 'queued', 'updated_at' => now()]);
        if (!$moved) {
            return response()->json(['error' => 'This batch is already being processed (status: ' . $batch->status . ').'], 409);
        }

        ProcessInvoiceImportBatchJob::dispatch($batch->id);

        return response()->json([
            'success' => true,
            'batch_id' => $batch->id,
            'status' => InvoiceImportBatch::whereKey($batch->id)->value('status'),
        ]);
    }

    public function status(int $batchId)
    {
        $batch = $this->findBatch($batchId);
        if (!$batch) {
            return response()->json(['error' => 'Import batch not found.'], 404);
        }

        $result = $batch->resultArray();

        return response()->json([
            'batch_id' => $batch->id,
            'status' => $batch->status,
            'total_rows' => $batch->total_rows,
            'valid_rows' => $batch->valid_rows,
            'invalid_rows' => $batch->invalid_rows,
            'processed_rows' => $batch->processed_rows,
            'created_invoices' => $batch->created_invoices,
            'failed_rows' => $batch->failed_rows,
            'error_message' => $batch->error_message,
            'message' => $result['message'] ?? null,
            'created' => array_slice($result['created'] ?? [], 0, 50),
            'created_total' => $result['created_total'] ?? count($result['created'] ?? []),
            'row_errors' => array_slice($result['row_errors'] ?? [], 0, 50),
            'row_errors_total' => count($result['row_errors'] ?? []),
            'error_report_url' => ($batch->invalid_rows > 0 || count($result['row_errors'] ?? []) > 0)
                ? '/invoices/import/' . $batch->id . '/error-report'
                : null,
            'finished_at' => optional($batch->finished_at)->toIso8601String(),
            'updated_at' => optional($batch->updated_at)->toIso8601String(),
        ]);
    }

    /** Import History — past batches for the current company (support/debugging + error report re-download). */
    public function history()
    {
        $batches = InvoiceImportBatch::where('company_id', app('currentCompanyId'))
            ->orderByDesc('created_at')
            ->paginate(25);

        return view('invoice.import-history', ['batches' => $batches]);
    }

    public function errorReport(int $batchId)
    {
        $batch = $this->findBatch($batchId);
        if (!$batch) {
            abort(404);
        }

        // Retention pruning cleared the row details — report the expiry
        // instead of streaming an empty spreadsheet.
        if ($batch->isPruned()) {
            return response(
                'This error report has expired. Row details of old import batches are cleared after the retention period — only summary counts are kept. Please re-upload the file to see errors again.',
                410,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        return $this->service->errorReportResponse($batch);
    }

    private function findBatch(int $batchId): ?InvoiceImportBatch
    {
        return InvoiceImportBatch::whereKey($batchId)
            ->where('company_id', app('currentCompanyId'))
            ->first();
    }
}
