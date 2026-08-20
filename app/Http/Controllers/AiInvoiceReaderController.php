<?php

namespace App\Http\Controllers;

use App\Exceptions\AiReaderException;
use App\Models\AiInvoiceParse;
use App\Models\BulkAiImageBatch;
use App\Models\Company;
use App\Services\AiInvoiceReaderService;
use App\Services\BulkAiImageImportService;
use App\Services\DiFeatureService;
use App\Services\PlanLimitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Task 142: AI Invoice Reader (Premium gate key 'ai_reader').
 *
 * Upload an old/supplier-format invoice (PDF / photo / Excel / CSV) →
 * AI extracts buyer + items → user reviews on the normal create form →
 * saves a DRAFT through the existing store() path. Nothing is ever
 * auto-submitted to FBR.
 */
class AiInvoiceReaderController extends Controller
{
    public function show()
    {
        $companyId = app('currentCompanyId');
        $company = Company::findOrFail($companyId);

        $allowed = DiFeatureService::planAllows($company, 'ai_reader');
        $configured = AiInvoiceReaderService::enabled();
        $quota = $allowed ? AiInvoiceReaderService::quotaState($company) : null;
        // Task 1238: import-assist usage rows share the quota but are not
        // reader parses — keep this page's recent list exactly as before.
        $recentParses = $allowed
            ? AiInvoiceParse::where('company_id', $companyId)
                ->whereNotIn('source_type', ['import_map', 'import_fix'])
                ->orderByDesc('id')->limit(6)->get()
            : collect();

        return view('invoice.ai-reader', compact('company', 'allowed', 'configured', 'quota', 'recentParses'));
    }

    public function parse(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::findOrFail($companyId);

        if (!DiFeatureService::planAllows($company, 'ai_reader')) {
            return response()->json(['error' => 'AI Invoice Reader is a Premium plan feature. Upgrade your plan to use it.'], 403);
        }
        if (!AiInvoiceReaderService::enabled()) {
            return response()->json(['error' => 'AI service is not configured yet. Please contact support.'], 503);
        }

        // No point burning an AI parse if the draft can't be saved afterwards.
        $limitCheck = PlanLimitService::canCreateInvoice($companyId);
        if (!($limitCheck['allowed'] ?? false)) {
            return response()->json(['error' => $limitCheck['reason'] ?? 'Monthly invoice limit reached.'], 422);
        }

        $quota = AiInvoiceReaderService::quotaState($company);
        if (!$quota['unlimited'] && $quota['remaining'] <= 0) {
            return response()->json([
                'error' => 'Monthly AI parse limit reached (' . $quota['used'] . '/' . $quota['quota'] . '). It resets on the 1st of next month.',
                'quota' => $quota,
            ], 429);
        }

        $request->validate([
            'invoice_file' => 'required|file|max:5120|mimes:pdf,jpg,jpeg,png,webp,xlsx,xls,csv,txt',
        ], [
            'invoice_file.required' => 'Please choose a file first.',
            'invoice_file.max' => 'File is too large — maximum size is 5MB.',
            'invoice_file.mimes' => 'Unsupported file type. Upload a PDF, photo (JPG/PNG), Excel (.xlsx), or CSV file.',
        ]);

        try {
            $parse = AiInvoiceReaderService::parseUpload($request->file('invoice_file'), $company, auth()->id());
        } catch (AiReaderException $e) {
            return response()->json(['error' => $e->getMessage(), 'retry' => true], 422);
        } catch (\Throwable $e) {
            Log::error('AI invoice reader unexpected failure', [
                'company_id' => $companyId,
                'err' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return response()->json(['error' => 'Something went wrong while reading the file. Please try again.', 'retry' => true], 500);
        }

        $payload = $parse->payload_json ?? [];

        return response()->json([
            'ok' => true,
            'redirect' => '/invoice/create?ai_parse=' . $parse->id,
            'items_count' => count($payload['items'] ?? []),
            'warnings' => $payload['warnings'] ?? [],
        ]);
    }

    public function bulk()
    {
        $company = Company::findOrFail(app('currentCompanyId'));
        $allowed = DiFeatureService::planAllows($company, 'ai_reader');
        $configured = AiInvoiceReaderService::enabled();
        $quota = $allowed ? app(BulkAiImageImportService::class)->quotaState($company) : null;

        return view('invoice.ai-reader-bulk', compact('company', 'allowed', 'configured', 'quota'));
    }

    public function bulkStart(Request $request, BulkAiImageImportService $service)
    {
        $company = Company::findOrFail(app('currentCompanyId'));
        if (!DiFeatureService::planAllows($company, 'ai_reader')) {
            return response()->json(['error' => 'Bulk AI Image Import is a Premium plan feature.'], 403);
        }
        if (!AiInvoiceReaderService::enabled()) {
            return response()->json(['error' => 'AI service is not configured yet. Please contact support.'], 503);
        }
        $limitCheck = PlanLimitService::canCreateInvoice($company->id);
        if (empty($limitCheck['allowed'])) {
            return response()->json(['error' => $limitCheck['reason'] ?? 'Invoice limit reached.'], 422);
        }

        $request->validate([
            'files' => 'required|array|min:1|max:' . BulkAiImageImportService::MAX_IMAGES,
            'files.*.name' => 'required|string|max:255',
            'files.*.size' => 'required|integer|min:1|max:' . BulkAiImageImportService::MAX_IMAGE_BYTES,
            'files.*.type' => 'nullable|string|max:100',
        ]);
        try {
            $batch = $service->createBatch($company, auth()->id(), $request->input('files'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage(), 'quota' => $service->quotaState($company)], 429);
        }

        return response()->json([
            'ok' => true,
            'batch_id' => $batch->id,
            'items' => $batch->items()->orderBy('position')->get(['id', 'position', 'original_filename', 'source_uuid']),
            'quota' => $service->quotaState($company),
        ]);
    }

    public function bulkChunk(Request $request, int $batchId, int $itemId, BulkAiImageImportService $service)
    {
        $item = $service->itemForCompany($batchId, $itemId, (int) app('currentCompanyId'));
        if (!$item) {
            return response()->json(['error' => 'Bulk source photo not found.'], 404);
        }
        $request->validate([
            'chunk' => 'required|file|max:1100',
            'index' => 'required|integer|min:0',
            'total_chunks' => 'required|integer|min:1|max:10',
        ]);
        try {
            return response()->json($service->storeChunk(
                $item,
                $request->file('chunk'),
                (int) $request->input('index'),
                (int) $request->input('total_chunks')
            ));
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function bulkComplete(int $batchId, int $itemId, BulkAiImageImportService $service)
    {
        $item = $service->itemForCompany($batchId, $itemId, (int) app('currentCompanyId'));
        if (!$item) {
            return response()->json(['error' => 'Bulk source photo not found.'], 404);
        }
        try {
            return response()->json($service->completeUpload($item));
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function bulkStatus(int $batchId, BulkAiImageImportService $service)
    {
        $batch = $service->batchForCompany($batchId, (int) app('currentCompanyId'));
        if (!$batch) {
            return response()->json(['error' => 'Bulk AI batch not found.'], 404);
        }
        return response()->json($service->statusPayload($batch));
    }

    public function bulkRetry(int $batchId, int $itemId, BulkAiImageImportService $service)
    {
        $item = $service->itemForCompany($batchId, $itemId, (int) app('currentCompanyId'));
        if (!$item) {
            return response()->json(['error' => 'Bulk source photo not found.'], 404);
        }
        try {
            $service->retry($item);
            return response()->json(['ok' => true, 'status' => 'queued']);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }
}
