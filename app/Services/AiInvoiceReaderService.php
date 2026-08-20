<?php

namespace App\Services;

use App\Exceptions\AiReaderException;
use App\Models\AiInvoiceParse;
use App\Models\Company;
use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Task 142: AI Invoice Reader — upload an old/supplier-format invoice
 * (PDF / photo / Excel / CSV) and get back a reviewable DI draft prefill.
 *
 * Hard rules:
 *  - NEVER auto-submits anything to FBR — output only prefills the normal
 *    create form; the user reviews and saves a DRAFT themselves.
 *  - Premium gate key 'ai_reader' (DiFeatureService) is enforced by the
 *    controller; this service owns the monthly parse quota.
 *  - Per-parse cost is bounded: 5MB file cap, first 4 text-PDF pages
 *    (first 3 pages for scanned PDFs — one vision request, multi-image),
 *    15k chars of text, 120 spreadsheet rows, 30 items.
 *  - OpenAI key comes from the same source as Madadgar (admin-managed
 *    SystemSetting override, fallback env OPENAI_API_KEY — literal value
 *    in live .env, ${VAR} interpolation fails silently there).
 *  - HS codes are only taken from what is PRINTED on the document or
 *    matched from the company's own products — the model must never guess.
 *  - Live cPanel has no pdftotext/pdftoppm: text PDFs go through
 *    smalot/pdfparser (pure PHP); scanned PDFs rasterize via gs when
 *    available, otherwise a friendly "upload a photo instead" error.
 */
class AiInvoiceReaderService
{
    public const MODEL = 'gpt-4o-mini';

    /**
     * Active model — admin can override via SystemSetting 'ai_reader_model'
     * (e.g. bump to a stronger vision model without a deploy). Falls back to
     * the hardcoded default on any read problem (dev MySQL cold-start etc.).
     */
    public static function model(): string
    {
        try {
            $m = trim((string) \App\Models\SystemSetting::get('ai_reader_model', ''));
            return $m !== '' ? $m : self::MODEL;
        } catch (\Throwable $e) {
            return self::MODEL;
        }
    }

    /**
     * Strong escalation model — used for ONE automatic retry when a photo /
     * scanned-PDF first read comes back low-confidence. Only active when the
     * admin sets SystemSetting 'ai_reader_model_strong' (e.g. gpt-4o);
     * unset = no escalation ever (cost control).
     */
    public static function strongModel(): ?string
    {
        try {
            $m = trim((string) \App\Models\SystemSetting::get('ai_reader_model_strong', ''));

            return $m !== '' ? $m : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public const MAX_FILE_BYTES = 5 * 1024 * 1024; // keep in sync with controller validation + view copy
    public const MAX_PDF_PAGES = 4;
    /** Scanned-PDF vision cap: each rasterized page costs ~26-38k vision tokens. */
    public const MAX_SCAN_PAGES = 3;
    public const MAX_TEXT_CHARS = 15000;
    public const MAX_SHEET_ROWS = 120;
    public const MAX_SHEET_COLS = 16;
    public const MAX_ITEMS = 30;

    /** Monthly successful-parse quotas (-1 = unlimited). */
    public const QUOTA_PREMIUM = 200;
    public const QUOTA_TRIAL = 5;
    public const QUOTA_DEFAULT = 25;

    private const SCHEDULE_TYPES = ['standard', 'reduced', '3rd_schedule', 'exempt', 'zero_rated', 'fed_services', 'services'];

    public static function enabled(): bool
    {
        return MadadgarService::apiKey() !== null;
    }

    // ------------------------------------------------------------------
    // Quota
    // ------------------------------------------------------------------

    public static function monthlyQuota(Company $company): int
    {
        if ($company->is_internal_account) {
            return -1;
        }

        $sub = DiFeatureService::effectiveSubscription($company);
        if ($sub && $sub->pricingPlan && $sub->pricingPlan->name === 'Premium') {
            return self::QUOTA_PREMIUM;
        }
        if ($sub && $sub->isTrialActive()) {
            return self::QUOTA_TRIAL;
        }

        return self::QUOTA_DEFAULT;
    }

    /** Successful parses this calendar month (failed attempts are free). */
    public static function usedThisMonth(int $companyId): int
    {
        return AiInvoiceParse::where('company_id', $companyId)
            ->where('status', 'success')
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    public static function quotaState(Company $company): array
    {
        $quota = self::monthlyQuota($company);
        $used = self::usedThisMonth($company->id);
        // A batch reserves before uploading. Include that reservation in the
        // single-reader check as well, otherwise separate browser tabs could
        // consume credits that have already been promised to source photos.
        $reserved = 0;
        if (\Illuminate\Support\Facades\Schema::hasTable('bulk_ai_image_items')) {
            $reserved = \App\Models\BulkAiImageItem::where('company_id', $company->id)
                ->where('reservation_status', 'reserved')
                ->where('created_at', '>=', now()->startOfMonth())
                ->count();
        }

        return [
            'quota' => $quota,
            'used' => $used,
            'reserved' => $reserved,
            'unlimited' => $quota === -1,
            'remaining' => $quota === -1 ? -1 : max(0, $quota - $used - $reserved),
        ];
    }

    // ------------------------------------------------------------------
    // Parse pipeline
    // ------------------------------------------------------------------

    /**
     * Full pipeline: extract -> AI -> normalize/map -> store parse row.
     * Throws AiReaderException with a friendly message on any failure
     * (a 'failed' row is stored for the recent-attempts list; failures
     * never consume quota).
     */
    public static function parseUpload(UploadedFile $file, Company $company, ?int $userId): AiInvoiceParse
    {
        $sourceType = self::detectSourceType($file);
        $filename = self::cleanString((string) $file->getClientOriginalName(), 200);

        $model = self::model();
        $escalated = false;

        try {
            $content = self::extractContent($file, $sourceType);
            [$raw, $tokens] = self::callOpenAi($content, $company, $model);

            if (!is_array($raw) || empty($raw['is_invoice'])) {
                throw new AiReaderException("This file doesn't look like an invoice. Please upload a clear invoice PDF, photo, or Excel file.");
            }

            // Task 358: blurred photo / scanned-PDF escalation — when the cheap
            // model's first read is low-confidence (most items low/shaky, or no
            // items at all), retry ONCE with the admin-set strong vision model
            // and keep whichever read has more high-confidence items. Vision
            // sources only (text/Excel gain nothing); still ONE quota parse.
            $strong = self::strongModel();
            if ($strong !== null && $strong !== $model
                && ($content['kind'] ?? '') === 'image'
                && self::extractionLooksLow($raw)) {
                try {
                    [$raw2, $tokens2] = self::callOpenAi($content, $company, $strong);
                    $escalated = true;
                    $tokens += $tokens2;
                    if (is_array($raw2) && !empty($raw2['is_invoice'])
                        && self::extractionScore($raw2) > self::extractionScore($raw)) {
                        $raw = $raw2;
                        $model = $strong;
                    }
                } catch (\Throwable $e) {
                    // Strong retry is best-effort — keep the first read on any trouble.
                    Log::warning('AI invoice reader strong-model escalation failed', [
                        'company_id' => $company->id,
                        'err' => mb_substr($e->getMessage(), 0, 200),
                    ]);
                }
            }

            $payload = self::mapExtraction($raw, $company, $sourceType, $filename);

            // Extraction-stage warnings (e.g. scanned-PDF page cap) surface with the rest.
            $extractWarnings = array_values(array_filter((array) ($content['extract_warnings'] ?? []), 'is_string'));
            if (!empty($extractWarnings)) {
                $payload['warnings'] = array_slice(array_values(array_unique(array_merge($extractWarnings, (array) ($payload['warnings'] ?? [])))), 0, 12);
            }

            if (empty($payload['items'])) {
                throw new AiReaderException('No line items could be read from this document. Try a clearer copy, or a photo of the itemized section.');
            }
        } catch (AiReaderException $e) {
            self::storeFailure($company->id, $userId, $sourceType, $filename, $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('AI invoice reader parse failed', [
                'company_id' => $company->id,
                'type' => $sourceType,
                'err' => mb_substr($e->getMessage(), 0, 300),
            ]);
            $friendly = 'Could not read this file. Please upload a clear PDF, photo (JPG/PNG), or Excel file.';
            self::storeFailure($company->id, $userId, $sourceType, $filename, $friendly);
            throw new AiReaderException($friendly);
        }

        $payload['meta']['escalated'] = $escalated;

        return AiInvoiceParse::create([
            'company_id' => $company->id,
            'user_id' => $userId,
            'status' => 'success',
            'source_type' => $sourceType,
            'original_filename' => $filename,
            'payload_json' => $payload,
            'model' => $model,
            'total_tokens' => $tokens,
        ]);
    }

    /**
     * First-read quality check for escalation: no items at all, or at least
     * half the items are low-confidence / have an unreadable qty or price.
     */
    private static function extractionLooksLow(array $raw): bool
    {
        $items = is_array($raw['items'] ?? null)
            ? array_values(array_filter($raw['items'], 'is_array'))
            : [];
        if (empty($items)) {
            return true;
        }

        $low = 0;
        foreach ($items as $it) {
            $qty = self::num($it['quantity'] ?? null);
            $price = self::num($it['unit_price'] ?? null);
            if (($it['confidence'] ?? null) === 'low'
                || $qty === null || $qty <= 0
                || $price === null || $price < 0) {
                $low++;
            }
        }

        return $low * 2 >= count($items);
    }

    /**
     * Comparable quality score: high-confidence readable items dominate,
     * total item count breaks ties.
     */
    private static function extractionScore(array $raw): int
    {
        $items = is_array($raw['items'] ?? null)
            ? array_values(array_filter($raw['items'], 'is_array'))
            : [];

        $high = 0;
        foreach ($items as $it) {
            $qty = self::num($it['quantity'] ?? null);
            $price = self::num($it['unit_price'] ?? null);
            if (($it['confidence'] ?? null) === 'high'
                && $qty !== null && $qty > 0
                && $price !== null && $price >= 0) {
                $high++;
            }
        }

        return $high * 1000 + count($items);
    }

    private static function storeFailure(int $companyId, ?int $userId, string $sourceType, string $filename, string $error): void
    {
        try {
            AiInvoiceParse::create([
                'company_id' => $companyId,
                'user_id' => $userId,
                'status' => 'failed',
                'source_type' => $sourceType,
                'original_filename' => $filename,
                'error' => mb_substr($error, 0, 500),
            ]);
        } catch (\Throwable $ignore) {
            // never let bookkeeping break the user-facing error
        }
    }

    public static function detectSourceType(UploadedFile $file): string
    {
        $ext = strtolower((string) $file->getClientOriginalExtension());

        return match (true) {
            $ext === 'pdf' => 'pdf',
            in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) => 'image',
            in_array($ext, ['xlsx', 'xls'], true) => 'xlsx',
            default => 'csv', // csv / txt
        };
    }

    // ------------------------------------------------------------------
    // Content extraction (bounded cost)
    // ------------------------------------------------------------------

    /** @return array{kind:string,text?:string,b64?:string,mime?:string} */
    private static function extractContent(UploadedFile $file, string $sourceType): array
    {
        return match ($sourceType) {
            'pdf' => self::extractPdf($file),
            'image' => self::extractImage($file),
            'xlsx' => self::extractSheet($file),
            default => self::extractCsv($file),
        };
    }

    private static function extractPdf(UploadedFile $file): array
    {
        $path = (string) $file->getRealPath();
        $text = '';
        $pageCount = null;

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($path);
            $pages = $pdf->getPages();
            $pageCount = count($pages);
            $chunks = [];
            foreach (array_slice($pages, 0, self::MAX_PDF_PAGES) as $page) {
                try {
                    $chunks[] = (string) $page->getText();
                } catch (\Throwable $pageErr) {
                    // skip unreadable page, keep the rest
                }
            }
            $text = self::cleanText(implode("\n\n", $chunks));
        } catch (\Throwable $e) {
            $text = '';
        }

        if (mb_strlen($text) >= 80) {
            return ['kind' => 'text', 'text' => mb_substr($text, 0, self::MAX_TEXT_CHARS)];
        }

        // Scanned / image-only PDF -> rasterize the first few pages if possible
        // (all go into ONE vision request; cap bounds per-parse cost).
        $jpegs = self::rasterizePdfPages($path, self::MAX_SCAN_PAGES);
        if (!empty($jpegs)) {
            $content = [
                'kind' => 'image',
                'images' => array_map('base64_encode', $jpegs),
                'mime' => 'image/jpeg',
            ];
            if (($pageCount !== null && $pageCount > self::MAX_SCAN_PAGES)
                || ($pageCount === null && count($jpegs) >= self::MAX_SCAN_PAGES)) {
                $content['extract_warnings'] = ['Scanned PDF: only the first ' . count($jpegs) . ' page' . (count($jpegs) > 1 ? 's were' : ' was') . ' read — items on later pages may be missing.'];
            }

            return $content;
        }

        throw new AiReaderException('This PDF looks scanned (no readable text). Please upload a photo or screenshot of the invoice (JPG/PNG) instead.');
    }

    /**
     * Rasterize the first $maxPages pages of a PDF to JPEGs.
     * Tries pdftoppm (dev) then gs (live cPanel — only gs available).
     *
     * @return string[] JPEG bytes in page order (possibly empty)
     */
    private static function rasterizePdfPages(string $path, int $maxPages): array
    {
        if (!function_exists('shell_exec') || $maxPages < 1) {
            return [];
        }

        $out = rtrim(sys_get_temp_dir(), '/') . '/aiparse_' . bin2hex(random_bytes(6));
        $pages = [];

        try {
            $pdftoppm = trim((string) @shell_exec('command -v pdftoppm 2>/dev/null'));
            if ($pdftoppm !== '') {
                @shell_exec(escapeshellarg($pdftoppm) . ' -f 1 -l ' . (int) $maxPages . ' -jpeg -r 150 ' . escapeshellarg($path) . ' ' . escapeshellarg($out) . ' 2>/dev/null');
                for ($p = 1; $p <= $maxPages; $p++) {
                    // pdftoppm zero-pads the page suffix depending on total page count
                    foreach ([$out . '-' . $p . '.jpg', $out . '-' . sprintf('%02d', $p) . '.jpg', $out . '-' . sprintf('%03d', $p) . '.jpg'] as $f) {
                        if (is_file($f)) {
                            $bytes = (string) file_get_contents($f);
                            @unlink($f);
                            if ($bytes !== '') {
                                $pages[] = $bytes;
                            }
                            break;
                        }
                    }
                }

                return $pages;
            }

            $gs = trim((string) @shell_exec('command -v gs 2>/dev/null'));
            if ($gs === '' && is_executable('/usr/bin/gs')) {
                $gs = '/usr/bin/gs';
            }
            if ($gs !== '') {
                // %d in the output pattern makes gs emit one file per page.
                @shell_exec(escapeshellarg($gs) . ' -dSAFER -dNOPAUSE -dBATCH -sDEVICE=jpeg -dJPEGQ=85 -r150 -dFirstPage=1 -dLastPage=' . (int) $maxPages . ' -sOutputFile=' . escapeshellarg($out . '-%d.jpg') . ' ' . escapeshellarg($path) . ' 2>/dev/null');
                for ($p = 1; $p <= $maxPages; $p++) {
                    $f = $out . '-' . $p . '.jpg';
                    if (!is_file($f)) {
                        break;
                    }
                    $bytes = (string) file_get_contents($f);
                    @unlink($f);
                    if ($bytes !== '') {
                        $pages[] = $bytes;
                    }
                }

                return $pages;
            }
        } catch (\Throwable $e) {
            return $pages;
        }

        return $pages;
    }

    private static function extractImage(UploadedFile $file): array
    {
        $bytes = (string) file_get_contents((string) $file->getRealPath());
        if ($bytes === '') {
            throw new AiReaderException('Could not read the image file. Please try another photo.');
        }
        $mime = $file->getMimeType() ?: 'image/jpeg';

        // Downscale large photos (bounded vision cost) + honor EXIF rotation:
        // phone photos often upload sideways/upside-down, which tanks reading
        // accuracy. GD is web-only on live — parses run as web requests, but
        // guard anyway.
        if (function_exists('imagecreatefromstring')) {
            try {
                $img = @imagecreatefromstring($bytes);
                if ($img !== false) {
                    $dirty = false;

                    // EXIF orientation (JPEG only — PNG/WebP carry none).
                    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
                        $exif = @exif_read_data((string) $file->getRealPath());
                        $deg = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;
                        if ($deg !== 0) {
                            $rot = @imagerotate($img, $deg, 0);
                            if ($rot !== false) {
                                imagedestroy($img);
                                $img = $rot;
                                $dirty = true;
                            }
                        }
                    }

                    $w = imagesx($img);
                    $h = imagesy($img);
                    $max = 1600;
                    if ($w > $max || $h > $max) {
                        $scale = min($max / $w, $max / $h);
                        $nw = max(1, (int) round($w * $scale));
                        $nh = max(1, (int) round($h * $scale));
                        $resized = imagecreatetruecolor($nw, $nh);
                        $white = imagecolorallocate($resized, 255, 255, 255);
                        imagefill($resized, 0, 0, $white); // PNG transparency -> white, not black
                        imagecopyresampled($resized, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
                        imagedestroy($img);
                        $img = $resized;
                        $dirty = true;
                    }

                    if ($dirty) {
                        ob_start();
                        imagejpeg($img, null, 82);
                        $jpeg = (string) ob_get_clean();
                        if ($jpeg !== '') {
                            $bytes = $jpeg;
                            $mime = 'image/jpeg';
                        }
                    }
                    imagedestroy($img);
                }
            } catch (\Throwable $e) {
                // keep original bytes
            }
        }

        return ['kind' => 'image', 'b64' => base64_encode($bytes), 'mime' => $mime];
    }

    private static function extractSheet(UploadedFile $file): array
    {
        try {
            $path = (string) $file->getRealPath();
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);

            // Bound memory BEFORE load: only first sheet rows/cols we need.
            $reader->setReadFilter(new class implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter
            {
                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row <= AiInvoiceReaderService::MAX_SHEET_ROWS
                        && \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($columnAddress) <= AiInvoiceReaderService::MAX_SHEET_COLS;
                }
            });

            $ss = $reader->load($path);
            $sheet = $ss->getSheet(0);
            $rows = min($sheet->getHighestDataRow(), self::MAX_SHEET_ROWS);
            $cols = min(
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn()),
                self::MAX_SHEET_COLS
            );

            $lines = [];
            for ($r = 1; $r <= $rows; $r++) {
                $cells = [];
                for ($c = 1; $c <= $cols; $c++) {
                    try {
                        $cells[] = trim((string) $sheet->getCell([$c, $r])->getFormattedValue());
                    } catch (\Throwable $cellErr) {
                        $cells[] = '';
                    }
                }
                if (implode('', $cells) === '') {
                    continue;
                }
                $lines[] = implode("\t", $cells);
            }
            $ss->disconnectWorksheets();

            $text = self::cleanText(implode("\n", $lines));
            if (mb_strlen($text) < 20) {
                throw new AiReaderException('The spreadsheet looks empty. Please make sure the first sheet contains the invoice data.');
            }

            return ['kind' => 'text', 'text' => mb_substr($text, 0, self::MAX_TEXT_CHARS)];
        } catch (AiReaderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AiReaderException('Could not read this Excel file. Please save it as .xlsx and try again.');
        }
    }

    private static function extractCsv(UploadedFile $file): array
    {
        $text = (string) file_get_contents((string) $file->getRealPath(), false, null, 0, self::MAX_TEXT_CHARS * 2);
        $text = self::cleanText($text);
        if (mb_strlen($text) < 20) {
            throw new AiReaderException('This file looks empty. Please upload the invoice as PDF, photo, or Excel.');
        }

        return ['kind' => 'text', 'text' => mb_substr($text, 0, self::MAX_TEXT_CHARS)];
    }

    // ------------------------------------------------------------------
    // OpenAI call
    // ------------------------------------------------------------------

    /** @return array{0:array|null,1:int} [decoded JSON, total tokens] */
    private static function callOpenAi(array $content, Company $company, ?string $model = null): array
    {
        $model = $model ?? self::model();
        $key = MadadgarService::apiKey();
        if ($key === null) {
            throw new AiReaderException('AI service is not configured yet. Please contact support.');
        }

        $intro = 'SELLER COMPANY (the uploader — never the buyer): ' . $company->name
            . ($company->ntn ? ' | Seller NTN: ' . $company->ntn : '')
            . "\nExtract the invoice data into the JSON schema.";

        if (($content['kind'] ?? '') === 'image') {
            // Single photo OR multiple rasterized scanned-PDF pages — all in ONE request.
            $b64s = isset($content['images']) && is_array($content['images'])
                ? $content['images']
                : [(string) ($content['b64'] ?? '')];
            $mime = $content['mime'] ?? 'image/jpeg';
            $userContent = [
                ['type' => 'text', 'text' => $intro . (count($b64s) > 1 ? "\nThe images are consecutive pages of ONE invoice document, in order." : '')],
            ];
            foreach ($b64s as $b64) {
                $userContent[] = ['type' => 'image_url', 'image_url' => [
                    'url' => 'data:' . $mime . ';base64,' . $b64,
                    'detail' => 'high',
                ]];
            }
        } else {
            $userContent = [
                ['type' => 'text', 'text' => $intro . "\n\nDOCUMENT CONTENT:\n-----\n" . ($content['text'] ?? '') . "\n-----"],
            ];
        }

        try {
            // One automatic retry on transient trouble (connection drop, 429
            // rate-limit, 5xx) — a second attempt a moment later usually
            // succeeds and saves the user a manual re-upload. throw:false =
            // a final bad status falls through to the !successful() branch.
            $response = Http::timeout(90)->connectTimeout(10)
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
                        ['role' => 'system', 'content' => self::systemPrompt()],
                        ['role' => 'user', 'content' => $userContent],
                    ],
                    'response_format' => ['type' => 'json_object'],
                    'temperature' => 0.1,
                    // 30 items ka poora JSON aana chahiye — 2500 par lambi
                    // invoices ka JSON kat jata tha (invalid JSON = fail).
                    'max_tokens' => 4000,
                ]);
        } catch (\Throwable $e) {
            Log::warning('AI invoice reader OpenAI unreachable', ['err' => mb_substr($e->getMessage(), 0, 200)]);
            throw new AiReaderException('The AI service could not be reached. Please try again in a minute.');
        }

        if (!$response->successful()) {
            Log::warning('AI invoice reader OpenAI error', ['status' => $response->status()]);
            throw new AiReaderException('The AI service is busy right now. Please try again in a minute.');
        }

        $contentStr = trim((string) $response->json('choices.0.message.content'));
        $contentStr = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $contentStr);
        $decoded = json_decode((string) $contentStr, true);

        if (!is_array($decoded)) {
            throw new AiReaderException('The AI could not produce a readable result for this file. Please try again, or use a clearer copy.');
        }

        return [$decoded, (int) ($response->json('usage.total_tokens') ?? 0)];
    }

    private static function systemPrompt(): string
    {
        return <<<'PROMPT'
You extract structured data from Pakistani sales invoices (any format: supplier invoices, old invoices, handwritten bills, spreadsheets). Reply ONLY with a single JSON object, no prose.

Schema:
{
  "is_invoice": true/false,           // false if the document is clearly not an invoice/bill
  "buyer": {                          // the CUSTOMER receiving the goods — NOT the seller company given by the user
    "name": string|null, "ntn": string|null, "cnic": string|null,
    "address": string|null, "phone": string|null,
    "confidence": "high"|"medium"|"low"
  },
  "document": {
    "invoice_number": string|null,    // the number printed on the document
    "invoice_date": "YYYY-MM-DD"|null,
    "document_type": "Sale Invoice"|"Credit Note"|"Debit Note",
    "destination_province": "Punjab"|"Sindh"|"Khyber Pakhtunkhwa"|"Balochistan"|"Islamabad"|"Azad Kashmir"|"Gilgit-Baltistan"|"FATA"|null
  },
  "items": [                          // every line item, max 30
    {
      "description": string,
      "barcode": string|null,         // visible barcode / GTIN only when printed
      "sku": string|null,             // visible seller or supplier SKU only when printed
      "hs_code": string|null,         // ONLY if printed on the document. NEVER guess or invent HS codes.
      "quantity": number,
      "unit_price": number,           // price per unit EXCLUDING sales tax when the document separates tax
      "line_total": number|null,
      "tax_rate": number|null,        // percent, only if shown
      "tax_amount": number|null,      // sales tax amount for this line, only if shown
      "mrp": number|null,             // printed retail price (MRP/RP) per unit, ONLY if printed on the document
      "uom": string|null,
      "confidence": "high"|"medium"|"low"
    }
  ],
  "totals": { "subtotal": number|null, "tax": number|null, "grand_total": number|null },
  "warnings": [string]                // short notes about anything unclear/ambiguous (max 5)
}

Rules:
- The user message names the SELLER company. If the document shows that company as issuer, the OTHER party is the buyer. If the seller company appears as the CUSTOMER on a supplier's invoice, then buyer fields = that seller company's customer role does NOT apply — extract the party the document bills TO.
- NTN is 7-8 digits (may have a dash), CNIC is 13 digits. Put values in the right field.
- Numbers: plain digits, no thousand separators or currency symbols.
- If quantity or price is unreadable, use your best reading and lower the confidence.
- Do not invent items, HS codes, or tax rates that are not on the document.
- destination_province: infer from the buyer address city if obvious, else null.
- Documents may be in English, Urdu, or a mix — including HANDWRITTEN bills. Read Urdu and handwriting carefully; write extracted names/descriptions in clear Latin script (transliterate Urdu words, e.g. چینی -> "Cheeni (Sugar)").
- Dates: Pakistani documents write DD/MM/YYYY or DD-MM-YYYY — when ambiguous, the DAY comes first (05/03/2026 = 5 March).
- If a line shows a discount, unit_price = the net per-unit price actually charged after the discount.
- mrp: only when a retail/maximum price is printed for that item (common on 3rd Schedule goods like beverages, packaged foods); never guess it.
PROMPT;
    }

    // ------------------------------------------------------------------
    // Normalize + map to DI fields (HS / schedule / tax)
    // ------------------------------------------------------------------

    /**
     * Convert raw AI output into the create-form prefill payload.
     * Deterministic + DB-driven: printed HS codes resolve through
     * GlobalHsService (companyId=null so parse-time lookups don't spam
     * hs_unmapped_logs), descriptions match against the company's products.
     */
    public static function mapExtraction(array $raw, Company $company, string $sourceType = 'pdf', string $filename = ''): array
    {
        $standardRate = self::companyStandardRate($company);
        $warnings = [];

        foreach (array_slice((array) ($raw['warnings'] ?? []), 0, 5) as $w) {
            if (is_string($w) && trim($w) !== '') {
                $warnings[] = 'AI: ' . self::cleanString($w, 180);
            }
        }

        // ---- Buyer ----
        $buyerRaw = is_array($raw['buyer'] ?? null) ? $raw['buyer'] : [];
        $buyer = [
            'name' => self::cleanString($buyerRaw['name'] ?? '', 255),
            'ntn' => self::cleanIdNumber($buyerRaw['ntn'] ?? '', 20),
            'cnic' => self::cleanIdNumber($buyerRaw['cnic'] ?? '', 15),
            'address' => self::cleanString($buyerRaw['address'] ?? '', 500),
            'phone' => self::cleanString($buyerRaw['phone'] ?? '', 30),
            'confidence' => self::confidence($buyerRaw['confidence'] ?? null),
        ];
        if ($buyer['name'] === '') {
            $warnings[] = 'Buyer name could not be read — please enter it.';
        }
        if ($buyer['address'] === '') {
            $fallback = self::cleanString((string) ($company->city ?: $company->address), 500);
            if ($fallback !== '') {
                $buyer['address'] = $fallback;
                $warnings[] = 'Buyer address not found — filled with your city as a placeholder; please correct.';
            } else {
                $warnings[] = 'Buyer address could not be read — required before saving.';
            }
        }

        // ---- Document ----
        $docRaw = is_array($raw['document'] ?? null) ? $raw['document'] : [];
        $docType = in_array($docRaw['document_type'] ?? '', ['Sale Invoice', 'Credit Note', 'Debit Note'], true)
            ? $docRaw['document_type'] : 'Sale Invoice';
        $origNumber = self::cleanString($docRaw['invoice_number'] ?? '', 100);
        $reference = '';
        if ($docType !== 'Sale Invoice') {
            $reference = $origNumber;
            $warnings[] = $docType . ' detected — check the reference invoice number before saving.';
        }

        $province = 'Punjab';
        $provinces = \App\Http\Controllers\InvoiceController::getPakistanProvinces();
        if (in_array($docRaw['destination_province'] ?? '', $provinces, true)) {
            $province = $docRaw['destination_province'];
        }

        $origDate = '';
        $dateRaw = (string) ($docRaw['invoice_date'] ?? '');
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dateRaw, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $origDate = $dateRaw;
        }

        // ---- Items ----
        $productColumns = ['id', 'name', 'hs_code', 'pct_code', 'default_tax_rate', 'uom', 'schedule_type'];
        foreach (['barcode', 'sku', 'sro_reference', 'serial_number', 'mrp', 'default_price'] as $optionalColumn) {
            if (\Illuminate\Support\Facades\Schema::hasColumn('products', $optionalColumn)) {
                $productColumns[] = $optionalColumn;
            }
        }
        $products = Product::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->limit(500)
            ->get($productColumns);
        $aliases = [];
        if (\Illuminate\Support\Facades\Schema::hasTable('product_aliases')) {
            \App\Models\ProductAlias::where('company_id', $company->id)
                ->where('is_active', true)
                ->get(['product_id', 'alias'])
                ->each(function ($alias) use (&$aliases) {
                    $aliases[(int) $alias->product_id][] = (string) $alias->alias;
                });
        }

        $rawItems = is_array($raw['items'] ?? null) ? $raw['items'] : [];
        if (count($rawItems) > self::MAX_ITEMS) {
            $warnings[] = 'Only the first ' . self::MAX_ITEMS . ' items were read (per-parse cap).';
        }

        $items = [];
        $needsHsIdx = [];
        $amountMismatch = false;
        $idx = 0;

        foreach (array_slice($rawItems, 0, self::MAX_ITEMS) as $itRaw) {
            if (!is_array($itRaw)) {
                continue;
            }
            $idx++;

            $desc = self::cleanString($itRaw['description'] ?? '', 255);
            if ($desc === '') {
                $warnings[] = 'Item ' . $idx . ': description missing — skipped.';
                continue;
            }

            $qty = self::num($itRaw['quantity'] ?? null);
            $price = self::num($itRaw['unit_price'] ?? null);
            $shaky = false;
            if ($qty === null || $qty <= 0) {
                $qty = 1.0;
                $shaky = true;
                $warnings[] = 'Item ' . $idx . ': quantity unclear — set to 1, please verify.';
            }
            $priceSuggestion = null;
            if ($price === null || $price < 0) {
                $price = 0.0;
                $shaky = true;
                $warnings[] = 'Item ' . $idx . ': unit price unclear — set to 0, please enter it.';
            }
            $qty = round($qty, 2);
            $price = round($price, 2);

            // Printed retail price (MRP) — 3rd Schedule goods. Only a sane
            // positive document value is kept; anything else stays blank for
            // the user to fill (never guessed).
            $aiMrp = self::num($itRaw['mrp'] ?? null);
            $mrp = ($aiMrp !== null && $aiMrp > 0) ? round($aiMrp, 2) : '';

            $aiConf = self::confidence($itRaw['confidence'] ?? null);
            $hs = self::cleanHs($itRaw['hs_code'] ?? '');
            $hsSource = $hs !== '' ? 'document' : 'none';

            $product = self::bestProductMatch($desc, $products, [
                'barcode' => $itRaw['barcode'] ?? '',
                'sku' => $itRaw['sku'] ?? '',
            ], $aliases);
            $productMatchType = $product?->_match_type ?? null;
            $productMatchConfidence = $product?->_match_confidence ?? null;
            if ($product && $productMatchType === 'fuzzy') {
                $warnings[] = 'Item ' . $idx . ': product match is low-confidence — verify the mapped profile before saving.';
            }
            if ($product && $price <= 0 && is_numeric($product->default_price ?? null) && (float) $product->default_price > 0) {
                $priceSuggestion = round((float) $product->default_price, 2);
            }
            if ($hs === '' && $product && $product->hs_code) {
                $hs = self::cleanHs($product->hs_code);
                if ($hs !== '') {
                    $hsSource = 'product';
                }
            }

            $scheduleType = 'standard';
            $taxRate = null;
            $pct = '';
            $uom = self::cleanString($itRaw['uom'] ?? '', 100) ?: 'Numbers, pieces, units';
            $sro = '';
            $serial = '';
            $hsFound = false;
            $profileTaxRate = $product && is_numeric($product->default_tax_rate ?? null)
                ? (float) $product->default_tax_rate : null;
            $profileHs = $product ? self::cleanHs($product->hs_code ?? '') : '';

            if ($product && $profileHs !== '' && $hsSource === 'document'
                && preg_replace('/\D/', '', $profileHs) !== preg_replace('/\D/', '', $hs)) {
                $warnings[] = 'Item ' . $idx . ': printed HS code differs from the matched product profile — printed value kept; verify mapping.';
            }

            if ($hs !== '') {
                // companyId = null on purpose: review-time lookups must not spam hs_unmapped_logs
                $resolved = GlobalHsService::resolveForInvoiceItem($hs, $standardRate, null, null);
                if (!empty($resolved['found'])) {
                    $hsFound = true;
                    if (in_array($resolved['schedule_type'] ?? '', self::SCHEDULE_TYPES, true)) {
                        $scheduleType = $resolved['schedule_type'];
                    }
                    if (is_numeric($resolved['tax_rate'] ?? null)) {
                        $taxRate = (float) $resolved['tax_rate'];
                    }
                    $pct = self::cleanString($resolved['pct_code'] ?? '', 50);
                    if (!empty($resolved['default_uom'])) {
                        $uom = self::cleanString($resolved['default_uom'], 100);
                    }
                    if (!empty($resolved['sro_number'])) {
                        $sro = self::cleanString($resolved['sro_number'], 100);
                    }
                    if (!empty($resolved['sro_item_serial_no'])) {
                        $serial = self::cleanString($resolved['sro_item_serial_no'], 100);
                    }
                }
            }

            if (!$hsFound && $product) {
                if (in_array((string) $product->schedule_type, self::SCHEDULE_TYPES, true)) {
                    $scheduleType = (string) $product->schedule_type;
                }
                if ($taxRate === null && is_numeric($product->default_tax_rate)) {
                    $taxRate = (float) $product->default_tax_rate;
                }
                if ($pct === '' && $product->pct_code) {
                    $pct = self::cleanString($product->pct_code, 50);
                }
                if ($product->uom) {
                    $uom = self::cleanString($product->uom, 100);
                }
                if ($sro === '' && !empty($product->sro_reference)) {
                    $sro = self::cleanString($product->sro_reference, 100);
                }
                if ($serial === '' && !empty($product->serial_number)) {
                    $serial = self::cleanString($product->serial_number, 100);
                }
            }

            // Tax rate fallbacks: document rate -> derived from tax amount -> schedule default
            $aiRate = self::num($itRaw['tax_rate'] ?? null);
            $aiTax = self::num($itRaw['tax_amount'] ?? null);
            if ($product && $profileTaxRate !== null && $aiRate !== null
                && abs($profileTaxRate - $aiRate) > 0.01) {
                $warnings[] = 'Item ' . $idx . ': document tax rate differs from the product profile — document values kept; verify before submitting.';
            }
            if ($taxRate === null) {
                if ($aiRate !== null && $aiRate >= 0 && $aiRate <= 100) {
                    $taxRate = $aiRate;
                } elseif ($aiTax !== null && $aiTax > 0 && $qty * $price > 0) {
                    $taxRate = round($aiTax / ($qty * $price) * 100);
                } else {
                    $taxRate = $scheduleType === 'standard' ? $standardRate : ScheduleEngine::getTaxRate($scheduleType, $company->province ?? null);
                }
            }
            if (in_array($scheduleType, ['exempt', 'zero_rated'], true)) {
                $taxRate = 0.0;
            }
            $taxRate = (int) round(max(0.0, min(100.0, (float) $taxRate)));

            $computedTax = round($qty * $price * $taxRate / 100, 2);
            $tax = ($aiTax !== null && $aiTax >= 0) ? round($aiTax, 2) : $computedTax;
            if (in_array($scheduleType, ['exempt', 'zero_rated'], true)) {
                $tax = 0.0;
            }
            if ($aiTax !== null && abs($aiTax - $computedTax) > max(5, 0.2 * max($computedTax, 1))) {
                $amountMismatch = true;
            }

            $needsHs = ($hs === '');
            if ($needsHs) {
                $needsHsIdx[] = $idx;
            }

            // Confidence: AI's own rating, capped down when we had to guess
            $rank = ['low' => 0, 'medium' => 1, 'high' => 2];
            $conf = $rank[$aiConf];
            if ($shaky || $needsHs) {
                $conf = 0;
            } elseif (!$hsFound && $hsSource === 'document') {
                $conf = min($conf, 1); // printed HS unknown to our DB
            }
            $confStr = array_search($conf, $rank, true) ?: 'low';

            $items[] = [
                'description' => $desc,
                'hs_code' => $hs,
                'pct_code' => $pct,
                'quantity' => $qty,
                'price' => $price,
                'tax_rate' => $taxRate,
                'tax' => $tax,
                'schedule_type' => $scheduleType,
                'sro_schedule_no' => $sro,
                'serial_no' => $serial,
                'mrp' => $mrp,
                'price_suggestion' => $priceSuggestion,
                'default_uom' => $uom,
                'ai_confidence' => $confStr,
                'hs_source' => $hsSource, // document | product | none
                'needs_hs' => $needsHs,
                'product_id' => $product?->id,
                'product_match_type' => $productMatchType,
                'product_match_confidence' => $productMatchConfidence,
                'profile_hs_code' => $profileHs,
                'profile_pct_code' => $product ? self::cleanString($product->pct_code ?? '', 50) : '',
                'profile_tax_rate' => $profileTaxRate,
                'profile_uom' => $product ? self::cleanString($product->uom ?? '', 100) : '',
                'profile_schedule_type' => $product ? self::cleanString($product->schedule_type ?? '', 50) : '',
                'profile_sro_reference' => $product ? self::cleanString($product->sro_reference ?? '', 100) : '',
                'profile_serial_number' => $product ? self::cleanString($product->serial_number ?? '', 100) : '',
                'profile_mrp' => $product && is_numeric($product->mrp ?? null) ? (float) $product->mrp : null,
            ];
        }

        // ---- Cross-item warnings ----
        if (!empty($needsHsIdx)) {
            $warnings[] = 'No HS code for item' . (count($needsHsIdx) > 1 ? 's' : '') . ' #'
                . implode(', #', $needsHsIdx) . ' — select the HS code before saving.';
        }
        $scheduleTypes = array_values(array_unique(array_column($items, 'schedule_type')));
        if (count($scheduleTypes) > 1) {
            $warnings[] = 'Items map to different tax schedules (' . implode(', ', $scheduleTypes) . ') — FBR requires one schedule type per invoice; split into separate invoices if needed.';
        }
        if (in_array('3rd_schedule', $scheduleTypes, true)) {
            $mrpMissing = array_filter($items, fn ($i) => $i['schedule_type'] === '3rd_schedule' && $i['mrp'] === '');
            $warnings[] = !empty($mrpMissing)
                ? '3rd Schedule item(s) — enter the printed retail price (MRP) before saving.'
                : '3rd Schedule item(s) — MRP was read from the document; verify it matches the printed retail price.';
        }
        if ($amountMismatch) {
            $warnings[] = "Some line tax amounts don't match their tax rates — document values kept, please verify.";
        }

        $computedSubtotal = round(array_sum(array_map(fn ($i) => $i['quantity'] * $i['price'], $items)), 2);
        $computedTax = round(array_sum(array_column($items, 'tax')), 2);
        $totalsRaw = is_array($raw['totals'] ?? null) ? $raw['totals'] : [];
        $docTotal = self::num($totalsRaw['grand_total'] ?? null);
        if ($docTotal !== null && $docTotal > 0) {
            $computedGrand = $computedSubtotal + $computedTax;
            if ($computedGrand > 0 && abs($computedGrand - $docTotal) / max($docTotal, 1) > 0.02) {
                $warnings[] = 'Document total (' . number_format($docTotal, 2) . ') differs from the extracted items total ('
                    . number_format($computedGrand, 2) . ') — some lines may be missing or misread.';
            }
        }

        return [
            'buyer' => $buyer,
            'document' => [
                'document_type' => $docType,
                'reference_invoice_number' => $reference,
                'destination_province' => $province,
                'original_invoice_number' => $origNumber,
                'original_date' => $origDate,
            ],
            'items' => $items,
            'totals' => [
                'computed_subtotal' => $computedSubtotal,
                'computed_tax' => $computedTax,
                'document_total' => $docTotal,
            ],
            'warnings' => array_slice(array_values(array_unique($warnings)), 0, 12),
            'meta' => [
                'source_type' => $sourceType,
                'filename' => $filename,
                'parsed_at' => now()->toDateTimeString(),
            ],
        ];
    }

    private static function companyStandardRate(Company $company): float
    {
        try {
            if (method_exists($company, 'getStandardTaxRateValue')) {
                return (float) $company->getStandardTaxRateValue();
            }
        } catch (\Throwable $e) {
            // fall through
        }

        return 18.0;
    }

    // ------------------------------------------------------------------
    // Product description matching (conservative: wrong HS is worse than none)
    // ------------------------------------------------------------------

    private static function tokenize(string $s): array
    {
        $s = mb_strtolower($s);
        preg_match_all('/[\p{L}\p{N}]{3,}/u', $s, $m);
        $stop = ['the', 'and', 'for', 'pcs', 'pack', 'box', 'ltr', 'kgs', 'nos', 'qty'];

        return array_values(array_unique(array_filter($m[0], fn ($t) => !in_array($t, $stop, true))));
    }

    /**
     * Match only with evidence a distributor can audit. Barcode/SKU and exact
     * normalized names/approved aliases are decisive; fuzzy names are retained
     * only above a conservative score and only when there is a clear winner.
     *
     * @param \Illuminate\Support\Collection $products
     * @param array{barcode?:mixed,sku?:mixed} $identifiers
     * @param array<int,array<int,string>> $aliases product id => approved aliases
     */
    public static function bestProductMatch(string $description, $products, array $identifiers = [], array $aliases = []): ?object
    {
        $barcode = self::normalizeIdentifier((string) ($identifiers['barcode'] ?? ''));
        $sku = self::normalizeIdentifier((string) ($identifiers['sku'] ?? ''));
        foreach (['barcode' => $barcode, 'sku' => $sku] as $type => $identifier) {
            if ($identifier === '') continue;
            $matches = $products->filter(fn ($p) => $identifier === self::normalizeIdentifier((string) ($p->{$type} ?? '')))->values();
            if ($matches->count() === 1) {
                $matches->first()->setAttribute('_match_type', $type);
                $matches->first()->setAttribute('_match_confidence', 'high');
                return $matches->first();
            }
        }

        $normalizedDescription = self::normalizeProductName($description);
        $names = $products->filter(fn ($p) => $normalizedDescription !== '' && $normalizedDescription === self::normalizeProductName((string) $p->name))->values();
        if ($names->count() === 1) {
            $names->first()->setAttribute('_match_type', 'exact_name');
            $names->first()->setAttribute('_match_confidence', 'high');
            return $names->first();
        }
        $aliasMatches = $products->filter(function ($p) use ($aliases, $normalizedDescription) {
            foreach ((array) ($aliases[(int) $p->id] ?? []) as $alias) {
                if ($normalizedDescription !== '' && $normalizedDescription === self::normalizeProductName((string) $alias)) return true;
            }
            return false;
        })->values();
        if ($aliasMatches->count() === 1) {
            $aliasMatches->first()->setAttribute('_match_type', 'approved_alias');
            $aliasMatches->first()->setAttribute('_match_confidence', 'high');
            return $aliasMatches->first();
        }

        $descTokens = self::tokenize($description);
        if (empty($descTokens)) {
            return null;
        }
        $descLower = mb_strtolower($description);

        $best = null;
        $bestScore = 0.0;
        $runnerUp = 0.0;
        foreach ($products as $p) {
            $name = (string) $p->name;
            $pTokens = self::tokenize($name);
            if (empty($pTokens)) {
                continue;
            }
            $overlap = count(array_intersect($pTokens, $descTokens));
            if ($overlap === 0) {
                continue;
            }
            $score = $overlap / count($pTokens);
            if ($overlap >= 2) {
                $score += 0.25;
            }
            if ($name !== '' && str_contains($descLower, mb_strtolower($name))) {
                $score += 0.5;
            }
            if ($score > $bestScore) {
                $runnerUp = $bestScore;
                $bestScore = $score;
                $best = $p;
            } elseif ($score > $runnerUp) {
                $runnerUp = $score;
            }
        }

        // A near tie is an ambiguous mapping, not a permissible guess.
        if ($bestScore >= 0.85 && ($bestScore - $runnerUp) >= 0.10 && $best) {
            $best->setAttribute('_match_type', 'fuzzy');
            $best->setAttribute('_match_confidence', 'low');
            return $best;
        }

        return null;
    }

    private static function normalizeIdentifier(string $value): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));
    }

    private static function normalizeProductName(string $value): string
    {
        $value = mb_strtolower(trim($value));
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $value);
    }

    // ------------------------------------------------------------------
    // Sanitizers (payload feeds @json into Alpine — must be valid UTF-8)
    // ------------------------------------------------------------------

    private static function cleanText(string $s): string
    {
        $s = (string) mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
        $s = (string) preg_replace("/\n{3,}/", "\n\n", $s);

        return trim($s);
    }

    private static function cleanString($value, int $max): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $s = self::cleanText((string) $value);
        $s = (string) preg_replace('/\s+/u', ' ', $s);

        return mb_substr($s, 0, $max);
    }

    private static function cleanIdNumber($value, int $max): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $s = (string) preg_replace('/[^0-9\-]/', '', (string) $value);

        return mb_substr(trim($s, '-'), 0, $max);
    }

    /** Digits + dots, requires at least 4 digits to count as an HS code. */
    private static function cleanHs($value): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $s = (string) preg_replace('/[^0-9.]/', '', (string) $value);
        $digits = (string) preg_replace('/[^0-9]/', '', $s);

        return strlen($digits) >= 4 ? mb_substr($s, 0, 20) : '';
    }

    private static function num($value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }
        if (is_string($value)) {
            $s = str_replace([',', ' ', 'Rs', 'rs', 'PKR', 'pkr'], '', trim($value));
            if ($s !== '' && is_numeric($s)) {
                return (float) $s;
            }
        }

        return null;
    }

    private static function confidence($value): string
    {
        return in_array($value, ['high', 'medium', 'low'], true) ? $value : 'medium';
    }
}
