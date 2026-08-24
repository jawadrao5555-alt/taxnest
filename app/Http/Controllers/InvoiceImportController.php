<?php

namespace App\Http\Controllers;

use App\Exceptions\AiReaderException;
use App\Jobs\ProcessInvoiceImportBatchJob;
use App\Models\Company;
use App\Models\InvoiceImportBatch;
use App\Models\InvoiceImportMapping;
use App\Services\AiImportAssistService;
use App\Services\AiInvoiceReaderService;
use App\Services\DiFeatureService;
use App\Services\InvoiceImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * DI bulk invoice import — .xlsx/CSV upload with row-level FBR pre-validation,
 * background draft creation and polled progress.
 *
 * Files whose headers don't match the template (DMS day-end exports from
 * Voyage/TMX/Salesflo/etc.) go through a column-mapping step: the upload is
 * held on disk, the user maps the export's columns to our fields (or applies
 * a saved preset), and applyMapping() feeds the remapped rows into the SAME
 * validate -> preview -> batch pipeline. Template-matching files never see
 * the mapping step.
 *
 * The legacy CSV endpoints (CsvImportController) stay as a fallback; both
 * paths run the SAME validation via InvoiceImportService.
 */
class InvoiceImportController extends Controller
{
    private const PREVIEW_LIMIT = 100;

    /** Held DMS uploads awaiting mapping die after this many seconds. */
    private const HOLD_TTL_SECONDS = 86400;

    /** Cap on saved mapping presets per company. */
    private const MAX_PRESETS = 30;

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

        $parsed = $this->service->parseFile($file->getRealPath(), $extension, InvoiceImportService::MAX_ROWS, true);
        if (isset($parsed['error'])) {
            return response()->json(['error' => $parsed['error']], 422);
        }

        // Headers don't match the template — hold the file and open the
        // column-mapping step instead of failing with "missing columns".
        if (!empty($parsed['needs_mapping'])) {
            return $this->mappingNeededResponse($file, $extension, $company, $parsed['headers']);
        }

        $result = $this->service->validateRows($parsed['rows'], $company);

        return response()->json($this->buildBatchPayload(
            $company,
            substr($file->getClientOriginalName(), 0, 255),
            $extension,
            $result
        ));
    }

    /**
     * Apply a column mapping (built on the mapping screen, or a saved preset)
     * to a held DMS upload, then continue the normal validate/preview flow.
     */
    public function applyMapping(Request $request)
    {
        $request->validate([
            'mapping_token' => 'required|string|size:40|alpha_num',
            'preset_id' => 'nullable|integer',
            'mapping' => 'nullable|array',
            'defaults' => 'nullable|array',
            'save_preset_name' => 'nullable|string|max:100',
        ]);

        $company = Company::find(app('currentCompanyId'));
        if (!$company) {
            return response()->json(['error' => 'Company context missing.'], 403);
        }

        $token = (string) $request->input('mapping_token');
        $dir = $this->holdDir((int) $company->id);
        $metaPath = $dir . '/' . $token . '.json';
        $meta = is_file($metaPath) ? json_decode((string) @file_get_contents($metaPath), true) : null;
        $extension = strtolower((string) ($meta['extension'] ?? ''));
        $dataPath = $dir . '/' . $token . '.' . $extension;
        if (!is_array($meta) || !in_array($extension, ['xlsx', 'xls', 'csv', 'txt'], true) || !is_file($dataPath)) {
            return response()->json(['error' => 'This upload has expired. Please upload the file again.'], 422);
        }

        $mapping = (array) $request->input('mapping', []);
        $defaults = (array) $request->input('defaults', []);
        $presetName = trim((string) $request->input('save_preset_name', ''));

        if ($request->filled('preset_id')) {
            $preset = InvoiceImportMapping::where('company_id', $company->id)->find((int) $request->input('preset_id'));
            if (!$preset) {
                return response()->json(['error' => 'Preset not found.'], 404);
            }
            $mapping = $preset->mappingArray();
            $defaults = $preset->defaultsArray();
            $presetName = ''; // applying an existing preset never re-saves it
        }

        $mapping = collect($mapping)->map(fn ($v) => is_scalar($v) ? (string) $v : '')->all();
        $defaults = collect($defaults)->map(fn ($v) => is_scalar($v) ? (string) $v : '')->all();

        $parsed = $this->service->parseFileWithMapping($dataPath, $extension, $mapping, $defaults);
        if (isset($parsed['error'])) {
            // Hold is kept so the user can fix the mapping without re-uploading.
            return response()->json(['error' => $parsed['error']], 422);
        }

        $result = $this->service->validateRows($parsed['rows'], $company);

        $presetSaved = null;
        if ($presetName !== '') {
            $presetSaved = $this->savePreset($company, $presetName, $mapping, $defaults);
        }

        $payload = $this->buildBatchPayload(
            $company,
            (string) ($meta['original_filename'] ?? ('mapped-import.' . $extension)),
            $extension,
            $result
        );
        $payload['preset_saved'] = $presetSaved;

        @unlink($dataPath);
        @unlink($metaPath);

        return response()->json($payload);
    }

    public function renameMapping(Request $request, int $id)
    {
        $request->validate(['name' => 'required|string|max:100']);

        $preset = InvoiceImportMapping::where('company_id', app('currentCompanyId'))->find($id);
        if (!$preset) {
            return response()->json(['error' => 'Preset not found.'], 404);
        }

        $name = trim((string) $request->input('name'));
        if ($name === '') {
            return response()->json(['error' => 'Preset name cannot be empty.'], 422);
        }

        $duplicate = InvoiceImportMapping::where('company_id', app('currentCompanyId'))
            ->where('name', $name)
            ->where('id', '!=', $preset->id)
            ->exists();
        if ($duplicate) {
            return response()->json(['error' => 'A preset with this name already exists.'], 422);
        }

        $preset->update(['name' => $name]);

        return response()->json(['success' => true, 'id' => $preset->id, 'name' => $name]);
    }

    public function deleteMapping(int $id)
    {
        $preset = InvoiceImportMapping::where('company_id', app('currentCompanyId'))->find($id);
        if (!$preset) {
            return response()->json(['error' => 'Preset not found.'], 404);
        }

        $preset->delete();

        return response()->json(['success' => true]);
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
            // Batch review: check every created draft against FBR's own rules
            // and fix them in place, instead of hunting them in the list.
            'review_url' => ($batch->created_invoices ?? 0) > 0
                ? '/invoices/review/import/' . $batch->id
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

    // ------------------------------------------------------------------
    // Task 1238: AI assist (suggestions only — the user confirms every
    // change, and the deterministic validation stays the only gatekeeper)
    // ------------------------------------------------------------------

    /**
     * AI column-mapping suggestions for a held (non-template) upload.
     * Same availability rules as the AI Invoice Reader: ai_reader plan gate,
     * OpenAI key configured, shared monthly quota.
     */
    public function aiMapSuggest(Request $request)
    {
        $request->validate([
            'mapping_token' => 'required|string|size:40|alpha_num',
            'mapping' => 'nullable|array',
            'defaults' => 'nullable|array',
        ]);

        $company = Company::find(app('currentCompanyId'));
        if (!$company) {
            return response()->json(['error' => 'Company context missing.'], 403);
        }
        if ($gate = $this->aiGateError($company)) {
            return $gate;
        }

        $token = (string) $request->input('mapping_token');
        $dir = $this->holdDir((int) $company->id);
        $metaPath = $dir . '/' . $token . '.json';
        $meta = is_file($metaPath) ? json_decode((string) @file_get_contents($metaPath), true) : null;
        $extension = strtolower((string) ($meta['extension'] ?? ''));
        $dataPath = $dir . '/' . $token . '.' . $extension;
        if (!is_array($meta) || !in_array($extension, ['xlsx', 'xls', 'csv', 'txt'], true) || !is_file($dataPath)) {
            return response()->json(['error' => 'This upload has expired. Please upload the file again.'], 422);
        }

        $headers = array_map(fn ($h) => (string) $h, (array) ($meta['headers'] ?? []));
        if (empty($headers)) {
            return response()->json(['error' => 'No headers found for this upload. Please upload the file again.'], 422);
        }

        // Current selections: AI only fills what's still unresolved and must
        // not reuse columns the user already assigned.
        $currentMapping = collect((array) $request->input('mapping', []))
            ->map(fn ($v) => is_scalar($v) ? trim((string) $v) : '')->filter()->all();
        $currentDefaults = collect((array) $request->input('defaults', []))
            ->map(fn ($v) => is_scalar($v) ? trim((string) $v) : '')->filter()->all();

        $samples = $this->service->sampleRows($dataPath, $extension, AiImportAssistService::MAX_SAMPLE_ROWS);

        try {
            $result = AiImportAssistService::suggestMapping(
                $headers,
                $samples,
                $company,
                $currentMapping,
                $currentDefaults,
                (string) ($meta['original_filename'] ?? '')
            );
        } catch (AiReaderException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($result + ['quota' => AiInvoiceReaderService::quotaState($company)]);
    }

    /**
     * AI fix suggestions for rows that failed validation (capped batch).
     * Suggestions are stored on the batch so the downloadable error report
     * can include them as a suggestion column.
     */
    public function aiRowFixes(int $batchId)
    {
        $batch = $this->findBatch($batchId);
        if (!$batch) {
            return response()->json(['error' => 'Import batch not found.'], 404);
        }

        $company = Company::find(app('currentCompanyId'));
        if (!$company) {
            return response()->json(['error' => 'Company context missing.'], 403);
        }
        if ($gate = $this->aiGateError($company)) {
            return $gate;
        }

        if ($batch->status !== 'validated' || $batch->isPruned()) {
            return response()->json(['error' => 'AI suggestions are only available on the validation preview, before processing starts.'], 422);
        }

        $rows = $batch->rowsArray();

        $invalid = [];
        foreach ($rows as $row) {
            if (!empty($row['valid'])) {
                continue;
            }
            $invalid[] = [
                'row' => (int) ($row['row'] ?? 0),
                'data' => (array) ($row['data'] ?? []),
                'errors' => (array) ($row['errors'] ?? []),
            ];
        }
        if (empty($invalid)) {
            return response()->json(['error' => 'There are no failing rows in this batch.'], 422);
        }

        $capped = array_slice($invalid, 0, AiImportAssistService::MAX_FIX_ROWS);

        // Deterministic context: schedule_type each buyer's PASSING rows use —
        // the classic "one schedule per buyer" fix.
        $invalidBuyers = [];
        foreach ($capped as $entry) {
            $name = trim((string) ($entry['data']['buyer_name'] ?? ''));
            if ($name !== '') {
                $invalidBuyers[mb_strtolower($name)] = $name;
            }
        }
        $buyerHints = [];
        foreach ($rows as $row) {
            if (empty($row['valid'])) {
                continue;
            }
            $name = trim((string) ($row['data']['buyer_name'] ?? ''));
            $schedule = trim((string) ($row['data']['schedule_type'] ?? ''));
            $key = mb_strtolower($name);
            if ($name !== '' && $schedule !== '' && isset($invalidBuyers[$key]) && !isset($buyerHints[$name])) {
                $buyerHints[$name] = $schedule;
            }
        }

        try {
            $suggestions = AiImportAssistService::suggestRowFixes($capped, $buyerHints, $company, (string) $batch->original_filename);
        } catch (AiReaderException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        // Persist for the error report (merge over earlier calls). Guarded so
        // a drifted install missing the column still returns the suggestions.
        if (!empty($suggestions) && Schema::hasColumn('invoice_import_batches', 'ai_suggestions_json')) {
            $stored = $batch->aiSuggestionsArray();
            foreach ($suggestions as $s) {
                $stored[(string) $s['row']] = ['fixes' => $s['fixes'], 'note' => $s['note']];
            }
            $batch->update(['ai_suggestions_json' => json_encode($stored)]);
        }

        return response()->json([
            'suggestions' => $suggestions,
            'covered' => count($capped),
            'invalid_total' => count($invalid),
            'truncated' => count($invalid) > count($capped),
            'quota' => AiInvoiceReaderService::quotaState($company),
        ]);
    }

    /**
     * Apply user-CONFIRMED AI suggestions to a batch's rows, then re-run the
     * exact same deterministic validation over ALL rows. Deterministic step
     * (no AI call, so no key/quota check), but still part of the AI-assist
     * flow: it requires the ai_reader plan gate and only values the server
     * itself stored as suggestions can be applied — this is NOT a general
     * row-edit API.
     */
    public function applyRowFixes(Request $request, int $batchId)
    {
        $request->validate([
            'fixes' => 'required|array|min:1|max:200',
            'fixes.*.row' => 'required|integer|min:1',
            'fixes.*.fields' => 'required|array|min:1',
        ]);

        $batch = $this->findBatch($batchId);
        if (!$batch) {
            return response()->json(['error' => 'Import batch not found.'], 404);
        }
        if ($batch->status !== 'validated' || $batch->isPruned()) {
            return response()->json(['error' => 'This batch can no longer be edited.'], 409);
        }

        $company = Company::find($batch->company_id);
        if (!$company) {
            return response()->json(['error' => 'Company context missing.'], 403);
        }
        if (!DiFeatureService::planAllows($company, 'ai_reader')) {
            return response()->json(['error' => 'AI assistance is a Premium plan feature. Please upgrade your plan to use it.'], 403);
        }

        // Only server-stored suggestion values are applicable: row => field => value.
        $allowed = [];
        foreach ($batch->aiSuggestionsArray() as $rowKey => $entry) {
            foreach ((array) ($entry['fixes'] ?? []) as $fix) {
                if (is_array($fix) && isset($fix['field'])) {
                    $allowed[(int) $rowKey][(string) $fix['field']] = (string) ($fix['value'] ?? '');
                }
            }
        }
        if (empty($allowed)) {
            return response()->json(['error' => 'No AI suggestions have been generated for this batch yet.'], 422);
        }

        $fixMap = [];
        foreach ((array) $request->input('fixes', []) as $fix) {
            $rowNum = (int) ($fix['row'] ?? 0);
            foreach ((array) ($fix['fields'] ?? []) as $field => $value) {
                if (is_scalar($value)
                    && isset($allowed[$rowNum][$field])
                    && trim((string) $value) === $allowed[$rowNum][$field]) {
                    $fixMap[$rowNum][$field] = $allowed[$rowNum][$field];
                }
            }
        }
        if (empty($fixMap)) {
            return response()->json(['error' => 'Only AI-suggested values can be applied here. Refresh the suggestions and try again.'], 422);
        }

        $allFields = array_merge(InvoiceImportService::REQUIRED_COLUMNS, InvoiceImportService::OPTIONAL_COLUMNS);

        $rows = $batch->rowsArray();
        if (empty($rows)) {
            return response()->json(['error' => 'This batch has no rows to fix.'], 422);
        }

        // Patch + strip old validation state; validateRows() re-decides
        // everything (batch rows are never pre-flagged, so no errors carry over).
        $applied = 0;
        $parsed = [];
        foreach ($rows as $row) {
            $rowNum = (int) ($row['row'] ?? 0);
            $data = [];
            foreach ($allFields as $f) {
                $data[$f] = (string) ($row['data'][$f] ?? '');
            }
            if (isset($fixMap[$rowNum])) {
                foreach ($fixMap[$rowNum] as $f => $v) {
                    $data[$f] = $v;
                }
                $applied++;
            }
            $parsed[] = ['row' => $rowNum, 'data' => $data];
        }
        if ($applied === 0) {
            return response()->json(['error' => 'None of the fixes matched a row in this batch.'], 422);
        }

        $result = $this->service->validateRows($parsed, $company);

        // Compare-and-swap: the new rows may only land while the batch is
        // still 'validated'. process() atomically moves validated -> queued,
        // so this cannot interleave with a processing run — once processing
        // claimed the batch, this update matches 0 rows and we bail out.
        $updated = InvoiceImportBatch::whereKey($batch->id)
            ->where('status', 'validated')
            ->update([
                'total_rows' => $result['total'],
                'valid_rows' => $result['valid_count'],
                'invalid_rows' => $result['error_count'],
                'rows_json' => json_encode($result['rows']),
                'updated_at' => now(),
            ]);
        if (!$updated) {
            return response()->json(['error' => 'This batch has already started processing — the fixes were not applied.'], 409);
        }

        return response()->json([
            'batch_id' => $batch->id,
            'total' => $result['total'],
            'valid_count' => $result['valid_count'],
            'error_count' => $result['error_count'],
            'preview' => array_slice($result['rows'], 0, self::PREVIEW_LIMIT),
            'preview_limit' => self::PREVIEW_LIMIT,
            'has_more' => $result['total'] > self::PREVIEW_LIMIT,
            'error_report_url' => $result['error_count'] > 0 ? '/invoices/import/' . $batch->id . '/error-report' : null,
            'applied' => $applied,
        ]);
    }

    /**
     * Shared availability rules with the AI Invoice Reader: plan gate →
     * key configured → monthly quota. Returns a friendly JSON error or null.
     */
    private function aiGateError(Company $company)
    {
        if (!DiFeatureService::planAllows($company, 'ai_reader')) {
            return response()->json(['error' => 'AI assistance is a Premium plan feature. Please upgrade your plan to use it.'], 403);
        }
        if (!AiImportAssistService::enabled()) {
            return response()->json(['error' => 'AI service is not configured yet. Please contact support — the manual import flow works as usual.'], 503);
        }
        $quota = AiInvoiceReaderService::quotaState($company);
        if (!$quota['unlimited'] && $quota['remaining'] <= 0) {
            return response()->json(['error' => 'Monthly AI usage limit reached (' . $quota['used'] . '/' . $quota['quota'] . ', shared with the AI Invoice Reader). It resets on the 1st of next month.'], 429);
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /** Create the batch row and build the JSON payload shared by upload() and applyMapping(). */
    private function buildBatchPayload(Company $company, string $originalFilename, string $extension, array $result): array
    {
        $batch = InvoiceImportBatch::create([
            'company_id' => $company->id,
            'user_id' => auth()->id(),
            'original_filename' => substr($originalFilename, 0, 255),
            'source_format' => in_array($extension, ['xlsx', 'xls'], true) ? 'xlsx' : 'csv',
            'status' => 'validated',
            'total_rows' => $result['total'],
            'valid_rows' => $result['valid_count'],
            'invalid_rows' => $result['error_count'],
            'rows_json' => json_encode($result['rows']),
        ]);

        return [
            'batch_id' => $batch->id,
            'total' => $result['total'],
            'valid_count' => $result['valid_count'],
            'error_count' => $result['error_count'],
            'preview' => array_slice($result['rows'], 0, self::PREVIEW_LIMIT),
            'preview_limit' => self::PREVIEW_LIMIT,
            'has_more' => $result['total'] > self::PREVIEW_LIMIT,
            'error_report_url' => $result['error_count'] > 0 ? '/invoices/import/' . $batch->id . '/error-report' : null,
        ];
    }

    /** Hold the non-template upload on disk and return the mapping-screen payload. */
    private function mappingNeededResponse($file, string $extension, Company $company, array $headers)
    {
        $dir = $this->holdDir((int) $company->id);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        // Housekeeping: abandoned holds die after the TTL.
        foreach (glob($dir . '/*') ?: [] as $old) {
            $mtime = @filemtime($old);
            if ($mtime !== false && $mtime < time() - self::HOLD_TTL_SECONDS) {
                @unlink($old);
            }
        }

        $token = Str::random(40);
        if (!@copy($file->getRealPath(), $dir . '/' . $token . '.' . $extension)) {
            return response()->json(['error' => 'Could not hold the file for mapping. Please try again.'], 500);
        }
        @file_put_contents($dir . '/' . $token . '.json', json_encode([
            'original_filename' => substr($file->getClientOriginalName(), 0, 255),
            'extension' => $extension,
            'headers' => $headers,
            'created_at' => now()->toIso8601String(),
        ]));

        // Saved presets, flagged by whether every mapped source column exists
        // in THIS file's headers (matching presets apply in one click).
        $headerKeys = array_map([InvoiceImportService::class, 'normalizeHeaderKey'], $headers);
        $presets = InvoiceImportMapping::where('company_id', $company->id)
            ->orderBy('name')
            ->get()
            ->map(function ($p) use ($headerKeys) {
                $mapping = $p->mappingArray();
                $missing = [];
                foreach ($mapping as $field => $source) {
                    if (!in_array(InvoiceImportService::normalizeHeaderKey((string) $source), $headerKeys, true)) {
                        $missing[] = (string) $source;
                    }
                }
                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'matches' => !empty($mapping) && empty($missing),
                    'missing_columns' => $missing,
                ];
            })
            ->values();

        return response()->json([
            'needs_mapping' => true,
            'mapping_token' => $token,
            'headers' => $headers,
            'fields' => $this->service->mappingFieldMeta(),
            'suggestions' => $this->service->suggestMapping($headers),
            'presets' => $presets,
            'company_province' => $this->service->normalizeProvince((string) ($company->province ?? '')) ?? '',
            'original_filename' => substr($file->getClientOriginalName(), 0, 255),
        ]);
    }

    /** Save/update a named preset. Returns the saved name, or null when the cap blocked a new one. */
    private function savePreset(Company $company, string $name, array $mapping, array $defaults): ?string
    {
        $mappingJson = json_encode(array_filter($mapping, fn ($v) => trim((string) $v) !== ''));
        $defaultsJson = json_encode(array_filter($defaults, fn ($v) => trim((string) $v) !== ''));

        $existing = InvoiceImportMapping::where('company_id', $company->id)->where('name', $name)->first();
        if ($existing) {
            $existing->update(['mapping_json' => $mappingJson, 'defaults_json' => $defaultsJson, 'user_id' => auth()->id()]);
            return $name;
        }

        if (InvoiceImportMapping::where('company_id', $company->id)->count() >= self::MAX_PRESETS) {
            return null; // import still proceeds; UI reports the cap
        }

        InvoiceImportMapping::create([
            'company_id' => $company->id,
            'user_id' => auth()->id(),
            'name' => $name,
            'mapping_json' => $mappingJson,
            'defaults_json' => $defaultsJson,
        ]);

        return $name;
    }

    private function holdDir(int $companyId): string
    {
        return storage_path('app/import-holds/' . $companyId);
    }

    private function findBatch(int $batchId): ?InvoiceImportBatch
    {
        return InvoiceImportBatch::whereKey($batchId)
            ->where('company_id', app('currentCompanyId'))
            ->first();
    }
}
