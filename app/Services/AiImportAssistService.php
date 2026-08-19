<?php

namespace App\Services;

use App\Exceptions\AiReaderException;
use App\Models\AiInvoiceParse;
use App\Models\Company;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Task 1238: AI assist for the DI bulk invoice Excel import.
 *
 * Two narrowly-scoped helpers around the existing import pipeline:
 *  - suggestMapping(): file headers + a few sample rows -> column→field
 *    mapping + fixed-default suggestions for the mapping screen.
 *  - suggestRowFixes(): failing rows + their validation errors -> per-row,
 *    per-field fix suggestions for the validation preview.
 *
 * Hard rules (mirrors AiInvoiceReaderService):
 *  - AI only SUGGESTS. Nothing is applied without the user's confirmation,
 *    and applied fixes re-run through InvoiceImportService::validateRows()
 *    — the deterministic validation stays the only thing that decides what
 *    imports.
 *  - Same availability rules as the AI Invoice Reader: OpenAI key via
 *    MadadgarService, 'ai_reader' premium plan gate (enforced by the
 *    controller), and every successful call is recorded as an
 *    AiInvoiceParse row so it counts against the SAME monthly usage quota
 *    (source_type 'import_map' / 'import_fix'; failures are free).
 *  - Per-call cost is bounded: sample rows/cells are trimmed, row-fix calls
 *    are capped at MAX_FIX_ROWS rows, and prompts never include the full file.
 *  - The AI must never invent tax-relevant values: prompts restrict fixes to
 *    corrections derivable from the row itself (spelling, formatting,
 *    arithmetic) or the provided buyer hints.
 */
class AiImportAssistService
{
    /** Sample data rows sent with a mapping-suggestion call. */
    public const MAX_SAMPLE_ROWS = 5;

    /** Cells per sample row / chars per cell (prompt size bound). */
    public const MAX_SAMPLE_COLS = 40;
    public const MAX_CELL_CHARS = 60;

    /** Failing rows per row-fix call (bulk files can't burn unlimited tokens). */
    public const MAX_FIX_ROWS = 30;

    public static function enabled(): bool
    {
        return MadadgarService::apiKey() !== null;
    }

    // ------------------------------------------------------------------
    // Column-mapping suggestions
    // ------------------------------------------------------------------

    /**
     * Ask the AI for column→field mapping + fixed-default suggestions.
     *
     * @param array<int,string> $headers        original header strings from the file
     * @param array<int,array>  $sampleRows     a few raw data rows (already trimmed by caller)
     * @param array<string,string> $currentMapping  fields the user/alias pass already resolved (AI must not touch)
     * @param array<string,string> $currentDefaults fields that already have a fixed value
     * @return array{mapping: array<string,string>, defaults: array<string,string>, note: ?string}
     * @throws AiReaderException on any failure (friendly message)
     */
    public static function suggestMapping(
        array $headers,
        array $sampleRows,
        Company $company,
        array $currentMapping = [],
        array $currentDefaults = [],
        string $filename = ''
    ): array {
        $fields = array_merge(InvoiceImportService::REQUIRED_COLUMNS, InvoiceImportService::OPTIONAL_COLUMNS);

        $fieldLines = [];
        foreach ((new InvoiceImportService())->mappingFieldMeta() as $meta) {
            $line = '- ' . $meta['key'] . ($meta['value_required'] ? ' (value required on every row)' : ' (optional)')
                . ': ' . $meta['hint'];
            if (!empty($meta['options'])) {
                $line .= ' | allowed values: ' . implode(', ', $meta['options']);
            }
            $fieldLines[] = $line;
        }

        $resolved = [];
        foreach ($currentMapping as $f => $h) {
            if (in_array($f, $fields, true) && trim((string) $h) !== '') {
                $resolved[] = $f . ' = column "' . trim((string) $h) . '"';
            }
        }
        foreach ($currentDefaults as $f => $v) {
            if (in_array($f, $fields, true) && trim((string) $v) !== '') {
                $resolved[] = $f . ' = fixed value "' . trim((string) $v) . '"';
            }
        }

        $sampleLines = [];
        foreach (array_slice($sampleRows, 0, self::MAX_SAMPLE_ROWS) as $row) {
            $cells = array_map(
                fn ($v) => mb_substr(trim((string) ($v ?? '')), 0, self::MAX_CELL_CHARS),
                array_slice((array) $row, 0, self::MAX_SAMPLE_COLS)
            );
            $sampleLines[] = implode(' | ', $cells);
        }

        $user = "TEMPLATE FIELDS:\n" . implode("\n", $fieldLines)
            . "\n\nFILE HEADERS (in order):\n" . implode(' | ', array_map(fn ($h) => mb_substr(trim((string) $h), 0, self::MAX_CELL_CHARS), array_slice($headers, 0, self::MAX_SAMPLE_COLS)))
            . "\n\nSAMPLE DATA ROWS (same column order):\n" . (empty($sampleLines) ? '(none)' : implode("\n", $sampleLines))
            . (!empty($resolved)
                ? "\n\nALREADY RESOLVED (do NOT suggest for these fields, and do NOT reuse their columns):\n" . implode("\n", $resolved)
                : '')
            . "\n\nSuggest mappings and defaults for the remaining fields only.";

        [$raw, $tokens, $model] = self::callOpenAi(self::mappingPrompt(), $user, 1500);

        // ---- Sanitize: only real headers, each used once, only known fields ----
        $headerByKey = [];
        foreach ($headers as $h) {
            $key = InvoiceImportService::normalizeHeaderKey((string) $h);
            if ($key !== '' && !isset($headerByKey[$key])) {
                $headerByKey[$key] = (string) $h;
            }
        }
        $usedKeys = [];
        foreach ($currentMapping as $h) {
            $k = InvoiceImportService::normalizeHeaderKey((string) $h);
            if ($k !== '') {
                $usedKeys[$k] = true;
            }
        }

        $mapping = [];
        foreach ((array) ($raw['mapping'] ?? []) as $field => $header) {
            if (!in_array($field, $fields, true) || isset($currentMapping[$field]) || !is_scalar($header)) {
                continue;
            }
            $key = InvoiceImportService::normalizeHeaderKey((string) $header);
            if ($key === '' || !isset($headerByKey[$key]) || isset($usedKeys[$key])) {
                continue;
            }
            $mapping[$field] = $headerByKey[$key];
            $usedKeys[$key] = true;
        }

        $defaults = [];
        foreach ((array) ($raw['defaults'] ?? []) as $field => $value) {
            if (!in_array($field, $fields, true) || !is_scalar($value)
                || isset($mapping[$field]) || isset($currentMapping[$field]) || isset($currentDefaults[$field])) {
                continue;
            }
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $clean = self::cleanDefault($field, $value);
            if ($clean !== null) {
                $defaults[$field] = $clean;
            }
        }

        $note = is_scalar($raw['note'] ?? null) ? mb_substr(trim((string) $raw['note']), 0, 200) : null;

        self::recordUsage($company, 'import_map', $filename, $model, $tokens, [
            'mapping' => $mapping, 'defaults' => $defaults, 'note' => $note,
        ]);

        return ['mapping' => $mapping, 'defaults' => $defaults, 'note' => $note ?: null];
    }

    /** Enum fields snap to canonical values; anything unrecognized is dropped (never guessed). */
    private static function cleanDefault(string $field, string $value): ?string
    {
        if ($field === 'destination_province') {
            return (new InvoiceImportService())->normalizeProvince($value);
        }
        if ($field === 'document_type') {
            foreach (InvoiceImportService::VALID_DOC_TYPES as $valid) {
                if (strcasecmp(preg_replace('/\s+/', ' ', $value), $valid) === 0) {
                    return $valid;
                }
            }
            return null;
        }
        if ($field === 'schedule_type') {
            $v = strtolower(str_replace([' ', '-'], '_', $value));
            return in_array($v, InvoiceImportService::VALID_SCHEDULE_TYPES, true) ? $v : null;
        }
        // Free-text default (e.g. tax_rate "18") — user reviews it, validation decides.
        return mb_substr($value, 0, 255);
    }

    private static function mappingPrompt(): string
    {
        return <<<'PROMPT'
You help map the columns of a Pakistani distributor's Excel/CSV export to the fields of an FBR digital-invoicing bulk-import template. Reply ONLY with a single JSON object, no prose.

Schema:
{
  "mapping": { "<template_field>": "<exact file header>" },
  "defaults": { "<template_field>": "<fixed value applied to EVERY row>" },
  "note": string|null
}

Rules:
- Copy file headers VERBATIM as given. Never invent a header.
- Map each header to at most ONE field, and only when the header name or the sample values clearly fit. When unsure, leave the field out — a wrong suggestion is worse than none.
- "defaults" are only for fields with NO suitable column: document_type (e.g. "Sale Invoice" when the export is plainly a sales report), destination_province (only when the sample addresses clearly show ONE province), schedule_type, or tax_rate when the sample tax values plainly imply one rate.
- NEVER suggest defaults for buyer identity fields (buyer_name, buyer_ntn, buyer_cnic), amounts (quantity, price, tax), hs_code, or description.
- "note": one short sentence only if something needs the user's attention (else null).
PROMPT;
    }

    // ------------------------------------------------------------------
    // Row-fix suggestions
    // ------------------------------------------------------------------

    /**
     * Ask the AI for per-row fix suggestions for rows that failed validation.
     *
     * @param array<int, array{row:int, data:array<string,string>, errors:array<int,string>}> $failedRows already capped by caller
     * @param array<string,string> $buyerScheduleHints buyer_name => schedule_type used by that buyer's VALID rows
     * @return array<int, array{row:int, fixes:array<int, array{field:string, value:string, old:string}>, note:string}>
     * @throws AiReaderException on any failure (friendly message)
     */
    public static function suggestRowFixes(array $failedRows, array $buyerScheduleHints, Company $company, string $filename = ''): array
    {
        $fields = array_merge(InvoiceImportService::REQUIRED_COLUMNS, InvoiceImportService::OPTIONAL_COLUMNS);

        $failedRows = array_slice(array_values($failedRows), 0, self::MAX_FIX_ROWS);
        $byRow = [];
        $rowsPayload = [];
        foreach ($failedRows as $entry) {
            $rowNum = (int) ($entry['row'] ?? 0);
            if ($rowNum < 1) {
                continue;
            }
            $data = [];
            foreach ($fields as $f) {
                $v = trim((string) ($entry['data'][$f] ?? ''));
                if ($v !== '') {
                    $data[$f] = mb_substr($v, 0, 80);
                }
            }
            $byRow[$rowNum] = (array) ($entry['data'] ?? []);
            $rowsPayload[] = [
                'row' => $rowNum,
                'data' => $data,
                'errors' => array_slice(array_map(fn ($e) => mb_substr((string) $e, 0, 220), (array) ($entry['errors'] ?? [])), 0, 8),
            ];
        }
        if (empty($rowsPayload)) {
            throw new AiReaderException('No failing rows to suggest fixes for.');
        }

        $hints = [];
        foreach ($buyerScheduleHints as $buyer => $schedule) {
            $hints[] = 'Buyer "' . mb_substr((string) $buyer, 0, 80) . '" uses schedule_type "' . $schedule . '" on rows that already pass.';
        }

        $user = "FAILING ROWS (JSON):\n" . json_encode($rowsPayload, JSON_UNESCAPED_UNICODE)
            . (!empty($hints) ? "\n\nBUYER HINTS (from this same file's valid rows):\n" . implode("\n", array_slice($hints, 0, 20)) : '')
            . "\n\nSuggest fixes.";

        [$raw, $tokens, $model] = self::callOpenAi(self::fixPrompt(), $user, 3000);

        // ---- Sanitize: known rows, known fields, scalar values only ----
        $suggestions = [];
        foreach ((array) ($raw['rows'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $rowNum = (int) ($entry['row'] ?? 0);
            if (!isset($byRow[$rowNum]) || isset($suggestions[$rowNum])) {
                continue;
            }
            $fixes = [];
            foreach ((array) ($entry['fixes'] ?? []) as $field => $value) {
                if (!in_array($field, $fields, true) || !is_scalar($value)) {
                    continue;
                }
                $value = trim((string) $value);
                $old = trim((string) ($byRow[$rowNum][$field] ?? ''));
                if ($value === $old) {
                    continue; // no-op "fix"
                }
                $fixes[] = ['field' => $field, 'value' => mb_substr($value, 0, 255), 'old' => mb_substr($old, 0, 255)];
            }
            if (empty($fixes)) {
                continue;
            }
            $suggestions[$rowNum] = [
                'row' => $rowNum,
                'fixes' => array_slice($fixes, 0, 8),
                'note' => is_scalar($entry['note'] ?? null) ? mb_substr(trim((string) $entry['note']), 0, 200) : '',
            ];
        }

        $suggestions = array_values($suggestions);

        self::recordUsage($company, 'import_fix', $filename, $model, $tokens, [
            'rows_sent' => count($rowsPayload),
            'rows_with_fixes' => count($suggestions),
        ]);

        return $suggestions;
    }

    private static function fixPrompt(): string
    {
        $provinces = implode(', ', InvoiceImportService::VALID_PROVINCES);
        $docTypes = implode(', ', InvoiceImportService::VALID_DOC_TYPES);
        $schedules = implode(', ', InvoiceImportService::VALID_SCHEDULE_TYPES);

        return <<<PROMPT
You suggest fixes for rows of a Pakistani FBR bulk-invoice import that failed validation. Reply ONLY with a single JSON object, no prose.

Schema:
{ "rows": [ { "row": <row number>, "fixes": { "<field>": "<corrected value>" }, "note": "<short plain-language reason>" } ] }

The validator enforces:
- destination_province: one of {$provinces} (common aliases like KPK, ICT, AJK are accepted too).
- document_type: one of {$docTypes}.
- schedule_type: one of {$schedules}.
- buyer_ntn: exactly 7 digits (NTN) or 13 digits (registration); buyer_cnic: exactly 13 digits. Digits only — dashes/spaces are stripped.
- hs_code: 4-12 digits.
- quantity: positive number. price: 0 or more. tax: the total sales-tax AMOUNT in rupees for the row. tax_rate: percent 0-100.
- exempt / zero_rated rows must have tax = 0 AND tax_rate = 0.
- For other rows, tax must equal quantity x price x tax_rate / 100 (small tolerance).
- Credit Note / Debit Note rows need reference_invoice_number (the original invoice).

Rules:
- Suggest a fix ONLY when the correction is obvious from the row's own values (province spelling, digit formatting, scientific-notation mangling like 8.9E+12, tax arithmetic, schedule/tax consistency) or from the buyer hints provided.
- NEVER invent identity numbers, HS codes, buyer details, descriptions, or amounts that cannot be derived from the row itself. If a required value is simply missing and unknowable, do NOT fix it — mention it in the note instead.
- Fix values must be in the exact format the validator expects (canonical spellings, plain digits, plain numbers without currency symbols).
- "note": under 20 words, plain language a shopkeeper understands.
- Omit rows you cannot help with.
PROMPT;
    }

    // ------------------------------------------------------------------
    // Shared plumbing
    // ------------------------------------------------------------------

    /** @return array{0:array,1:int,2:string} [decoded JSON, total tokens, model] */
    private static function callOpenAi(string $system, string $user, int $maxTokens): array
    {
        $key = MadadgarService::apiKey();
        if ($key === null) {
            throw new AiReaderException('AI service is not configured yet. Please contact support.');
        }
        $model = AiInvoiceReaderService::model();

        try {
            $response = Http::timeout(60)->connectTimeout(10)
                ->retry(2, 1500, function ($exception) {
                    if ($exception instanceof \Illuminate\Http\Client\ConnectionException) {
                        return true;
                    }
                    return $exception instanceof \Illuminate\Http\Client\RequestException
                        && in_array($exception->response->status(), [429, 500, 502, 503, 529], true);
                }, throw: false)
                ->withToken($key)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                    'response_format' => ['type' => 'json_object'],
                    'temperature' => 0.1,
                    'max_tokens' => $maxTokens,
                ]);
        } catch (\Throwable $e) {
            Log::warning('AI import assist OpenAI unreachable', ['err' => mb_substr($e->getMessage(), 0, 200)]);
            throw new AiReaderException('The AI service could not be reached. Please try again in a minute.');
        }

        if (!$response->successful()) {
            Log::warning('AI import assist OpenAI error', ['status' => $response->status()]);
            throw new AiReaderException('The AI service is busy right now. Please try again in a minute.');
        }

        $contentStr = trim((string) $response->json('choices.0.message.content'));
        $contentStr = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $contentStr);
        $decoded = json_decode((string) $contentStr, true);

        if (!is_array($decoded)) {
            throw new AiReaderException('The AI could not produce a usable suggestion. Please try again.');
        }

        return [$decoded, (int) ($response->json('usage.total_tokens') ?? 0), $model];
    }

    /**
     * Successful calls count against the SAME monthly quota as the AI Invoice
     * Reader (AiInvoiceReaderService::usedThisMonth counts success rows).
     * Bookkeeping must never break the user-facing response.
     */
    private static function recordUsage(Company $company, string $sourceType, string $filename, string $model, int $tokens, array $payload): void
    {
        try {
            AiInvoiceParse::create([
                'company_id' => $company->id,
                'user_id' => auth()->id(),
                'status' => 'success',
                'source_type' => $sourceType, // import_map | import_fix (10-char column)
                'original_filename' => mb_substr($filename, 0, 200),
                'payload_json' => $payload,
                'model' => $model,
                'total_tokens' => $tokens,
            ]);
        } catch (\Throwable $ignore) {
        }
    }
}
