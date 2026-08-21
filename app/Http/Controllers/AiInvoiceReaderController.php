<?php

namespace App\Http\Controllers;

use App\Exceptions\AiReaderException;
use App\Mail\BulkAiReviewSummaryMail;
use App\Models\AiInvoiceParse;
use App\Models\BulkAiImageBatch;
use App\Models\Company;
use App\Services\AiInvoiceReaderService;
use App\Services\AnnexureProductService;
use App\Services\BulkAiImageImportService;
use App\Services\DiFeatureService;
use App\Services\MailHealth;
use App\Services\PlanLimitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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

    /**
     * Task 1342: `?batch=` reopens a past batch. The workspace used to keep
     * the batch id in the Alpine component only, so a refresh — or a
     * colleague opening the page later — lost the results table, the retry
     * buttons, and the review summary download.
     */
    public function bulk(Request $request, BulkAiImageImportService $service)
    {
        $company = Company::findOrFail(app('currentCompanyId'));
        $allowed = DiFeatureService::planAllows($company, 'ai_reader');
        $configured = AiInvoiceReaderService::enabled();
        $quota = $allowed ? $service->quotaState($company) : null;

        $openBatchId = null;
        if ($allowed && $request->filled('batch')) {
            // Company-scoped exactly like the status endpoint: another
            // company's batch id is a 404, never a silent empty workspace.
            $batch = $service->batchForCompany((int) $request->query('batch'), (int) $company->id);
            if (!$batch) {
                abort(404);
            }
            $openBatchId = (int) $batch->id;
        }

        return view('invoice.ai-reader-bulk', compact('company', 'allowed', 'configured', 'quota', 'openBatchId'));
    }

    /**
     * Task 1342: company-scoped list of past bulk photo batches so results
     * stay reachable after the tab is closed. Batches whose private source
     * photos were already pruned still list their stored review data.
     */
    public function bulkHistory(BulkAiImageImportService $service)
    {
        $company = Company::findOrFail(app('currentCompanyId'));
        $allowed = DiFeatureService::planAllows($company, 'ai_reader');
        $batches = $allowed
            ? $service->historyForCompany((int) $company->id)
            : new \Illuminate\Pagination\LengthAwarePaginator([], 0, 20);
        $summaries = $allowed ? $service->historySummaries($batches->items()) : [];

        return view('invoice.ai-reader-bulk-history', compact('company', 'allowed', 'batches', 'summaries'));
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

    public function bulkAnnexureUpload(Request $request, int $batchId, AnnexureProductService $annexure)
    {
        $batch = app(BulkAiImageImportService::class)->batchForCompany($batchId, (int) app('currentCompanyId'));
        if (!$batch) {
            return response()->json(['error' => 'Bulk AI batch not found.'], 404);
        }
        $request->validate([
            'annexure' => 'required|file|max:5120|mimes:xlsx,xls,csv,txt',
        ]);
        try {
            return response()->json($annexure->upload($batch, $request->file('annexure')));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function bulkAnnexureApply(Request $request, int $batchId, AnnexureProductService $annexure)
    {
        $batch = app(BulkAiImageImportService::class)->batchForCompany($batchId, (int) app('currentCompanyId'));
        if (!$batch) {
            return response()->json(['error' => 'Bulk AI batch not found.'], 404);
        }
        $request->validate(['mapping' => 'required|array']);
        try {
            return response()->json($annexure->applyMapping($batch, (array) $request->input('mapping')));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function bulkAnnexureCatalogAction(Request $request, int $batchId, AnnexureProductService $annexure)
    {
        $batch = app(BulkAiImageImportService::class)->batchForCompany($batchId, (int) app('currentCompanyId'));
        if (!$batch) {
            return response()->json(['error' => 'Bulk AI batch not found.'], 404);
        }
        $request->validate([
            'annexure_row' => 'required|integer|min:1',
            'action' => 'required|in:create,update',
            'product_id' => 'nullable|integer',
            'price_decision' => 'required|in:keep_current,update_catalog,batch_only',
            'fields' => 'nullable|array',
            'fields.*' => 'string|in:name,barcode,sku,hs_code,pct_code,uom,default_tax_rate,tax_type,schedule_type,sro_reference,serial_number,mrp',
        ]);
        try {
            return response()->json($annexure->saveCatalogDecision(
                $batch,
                Company::findOrFail($batch->company_id),
                (int) auth()->id(),
                $request->all()
            ));
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function bulkAnnexureReverse(Request $request, int $batchId, int $auditId, AnnexureProductService $annexure)
    {
        $batch = app(BulkAiImageImportService::class)->batchForCompany($batchId, (int) app('currentCompanyId'));
        if (!$batch) return response()->json(['error' => 'Bulk AI batch not found.'], 404);
        try {
            return response()->json($annexure->reverseCatalogDecision($batch, Company::findOrFail($batch->company_id), (int) auth()->id(), $auditId));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
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

    /**
     * Task 1330: shareable batch review summary (CSV or printable PDF) so a
     * distributor can hand failed / duplicate / needs-review source invoices
     * to another reviewer outside this browser session.
     *
     * Scoped exactly like the live status endpoint — the batch lookup is
     * company-bound, so another company's batch is a 404 — and built from the
     * stored review data only, never from the private source photo.
     */
    public function bulkReport(Request $request, int $batchId, BulkAiImageImportService $service)
    {
        $batch = $service->batchForCompany($batchId, (int) app('currentCompanyId'));
        if (!$batch) {
            abort(404);
        }

        if (strtolower((string) $request->query('format')) === 'pdf') {
            return $service->reviewReportPdf($batch)->download($service->reviewReportFilename($batch, 'pdf'));
        }

        return $service->reviewReportCsv($batch);
    }

    /**
     * Task 1343: email the same PDF summary straight to another reviewer
     * (typically the shop's accountant) so the hand-off never leaves TaxNest.
     *
     * Guard rails:
     *   - company scoped exactly like the download (another company's batch
     *     is a 404) and refused while nothing has been processed yet;
     *   - capped per send (max recipients) AND per company per rolling 24h,
     *     on top of the route's per-minute throttle;
     *   - every recipient is recorded — sent or failed — so an owner can see
     *     who the summary went to and who sent it;
     *   - the PDF is rendered ONCE and reused for each recipient; the private
     *     source photos are never attached or linked.
     */
    public function bulkReportEmail(Request $request, int $batchId, BulkAiImageImportService $service)
    {
        $companyId = (int) app('currentCompanyId');
        $batch = $service->batchForCompany($batchId, $companyId);
        if (!$batch) {
            return response()->json(['error' => 'Bulk AI batch not found.'], 404);
        }

        $recipients = $this->parseRecipients($request->input('recipients'));
        if (!$recipients) {
            return response()->json(['error' => 'Kam az kam ek sahi email address likhein.'], 422);
        }
        if (count($recipients) > BulkAiImageImportService::REPORT_SHARE_MAX_RECIPIENTS) {
            return response()->json([
                'error' => 'Ek waqt mein zyada se zyada ' . BulkAiImageImportService::REPORT_SHARE_MAX_RECIPIENTS . ' email addresses par bhej sakte hain.',
            ], 422);
        }
        foreach ($recipients as $email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return response()->json(['error' => 'Yeh email address sahi format mein nahi hai: ' . $email], 422);
            }
        }

        $report = $service->reviewReport($batch);
        if (($report['batch']['processed'] ?? 0) < 1) {
            return response()->json(['error' => 'Abhi tak koi photo process nahi hui — summary bhejne se pehle batch ko chalne dein.'], 422);
        }

        $senderName = (string) (auth()->user()?->name ?? '');

        // Claim the 24h allowance BEFORE rendering or sending anything, so two
        // simultaneous sends cannot both read the same remaining allowance.
        $reservation = $service->reserveReportShares($batch, $recipients, auth()->id(), $senderName);
        if (!$reservation['rows']) {
            $allowance = $reservation['allowance_left'];

            return response()->json([
                'error' => $allowance > 0
                    ? 'Aaj sirf ' . $allowance . ' aur email bheji ja sakti hain (24 ghante mein ' . BulkAiImageImportService::REPORT_SHARE_DAILY_LIMIT . ' ki hadd).'
                    : '24 ghante ki email limit (' . BulkAiImageImportService::REPORT_SHARE_DAILY_LIMIT . ') poori ho chuki hai. Baad mein dobara koshish karein.',
                'shares' => $service->reportShares($batch),
            ], 429);
        }

        $company = Company::findOrFail($batch->company_id);
        $filename = $service->reviewReportFilename($batch, 'pdf');

        try {
            $pdfBytes = $service->reviewReportPdf($batch)->output();
        } catch (\Throwable $e) {
            Log::error('Bulk AI review summary PDF render failed', [
                'batch_id' => $batch->id,
                'err' => mb_substr($e->getMessage(), 0, 300),
            ]);
            // The reservation stays spent — it only changes from queued to failed.
            foreach ($reservation['rows'] as $share) {
                $service->settleReportShare($share, 'failed', 'Summary PDF render failed: ' . $e->getMessage());
            }

            return response()->json([
                'error' => 'Summary PDF ban nahi saki. Thori dair baad dobara koshish karein.',
                'shares' => $service->reportShares($batch),
            ], 500);
        }

        $sent = [];
        $failed = [];
        foreach ($reservation['rows'] as $share) {
            $email = (string) $share->recipient;
            try {
                Mail::to($email)->send(new BulkAiReviewSummaryMail($company, $report, $pdfBytes, $filename, $senderName));
                MailHealth::recordSuccess();
                $service->settleReportShare($share, 'sent');
                $sent[] = $email;
            } catch (\Throwable $e) {
                MailHealth::recordFailure('Bulk AI review summary (batch #' . $batch->id . ')', $e);
                Log::warning("Bulk AI batch #{$batch->id} summary email to {$email} failed: " . $e->getMessage());
                $service->settleReportShare($share, 'failed', $e->getMessage());
                $failed[] = $email;
            }
        }

        $shares = $service->reportShares($batch);

        if (!$sent) {
            return response()->json([
                'error' => 'Email send nahi ho saki: ' . implode(', ', $failed) . '. Thori dair baad dobara koshish karein.',
                'shares' => $shares,
            ], 500);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Review summary bhej di gayi: ' . implode(', ', $sent)
                . ($failed ? ' — nakaam: ' . implode(', ', $failed) : ''),
            'sent' => $sent,
            'failed' => $failed,
            'shares' => $shares,
            'allowance_left' => $service->reportShareAllowanceLeft($companyId),
        ]);
    }

    /**
     * Recipients arrive either as a JSON array or as one typed line — commas,
     * semicolons, spaces, and newlines all separate addresses.
     *
     * @return array<int, string> lower-cased, de-duplicated, order preserved
     */
    private function parseRecipients($input): array
    {
        $parts = is_array($input) ? $input : preg_split('/[,;\s]+/', (string) $input);

        $emails = [];
        foreach ((array) $parts as $part) {
            $email = strtolower(trim((string) $part));
            if ($email !== '' && !in_array($email, $emails, true)) {
                $emails[] = $email;
            }
        }

        return $emails;
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
