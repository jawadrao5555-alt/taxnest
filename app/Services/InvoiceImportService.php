<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceImportBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared engine for DI bulk invoice import (.xlsx + CSV).
 *
 * Single source of truth for template columns, file parsing, row-level
 * FBR pre-validation and draft-invoice creation. Used by:
 *   - InvoiceImportController (new xlsx/background pipeline)
 *   - CsvImportController      (legacy CSV fallback — same validation)
 *   - ProcessInvoiceImportBatchJob (background draft creation)
 *
 * Design notes (mirrors the proven POS product importer):
 *   - Row caps are enforced BEFORE toArray() so a zip-compressed xlsx with
 *     hundreds of thousands of rows can't OOM shared hosting.
 *   - Code columns (HS, NTN, CNIC, serials) are cleaned back to digit
 *     strings — Excel turns them into floats/scientific notation otherwise.
 */
class InvoiceImportService
{
    /** Hard cap on data rows per file (competitor benchmark: 9,000/batch). */
    public const MAX_ROWS = 10000;

    /** Upload size cap in KB (matches route validation). */
    public const MAX_FILE_KB = 10240;

    public const REQUIRED_COLUMNS = [
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

    /** Optional columns (new in xlsx template; old CSV files without them keep working). */
    public const OPTIONAL_COLUMNS = [
        'mrp',
        'sro_schedule_no',
        'sro_serial_no',
        'reference_invoice_number',
    ];

    /**
     * Fields whose VALUE must be present on every row. A column-mapping must
     * supply either a source column or a fixed default for each of these
     * (the rest may stay blank — validateRow() treats blank as optional/auto).
     */
    public const VALUE_REQUIRED_FIELDS = [
        'buyer_name', 'buyer_address', 'destination_province', 'document_type',
        'hs_code', 'description', 'quantity', 'price', 'tax',
    ];

    /** Columns that must survive as literal digit/code strings. */
    private const CODE_COLUMNS = ['buyer_ntn', 'buyer_cnic', 'hs_code', 'sro_serial_no', 'reference_invoice_number'];

    private const NUMERIC_COLUMNS = ['quantity', 'price', 'tax', 'tax_rate', 'mrp'];

    public const VALID_PROVINCES = ['Punjab', 'Sindh', 'Khyber Pakhtunkhwa', 'Balochistan', 'Islamabad', 'Azad Kashmir', 'Gilgit-Baltistan', 'FATA'];

    private const PROVINCE_ALIASES = [
        'kpk' => 'Khyber Pakhtunkhwa',
        'kp' => 'Khyber Pakhtunkhwa',
        'nwfp' => 'Khyber Pakhtunkhwa',
        'ict' => 'Islamabad',
        'islamabad capital territory' => 'Islamabad',
        'ajk' => 'Azad Kashmir',
        'azad jammu and kashmir' => 'Azad Kashmir',
        'gb' => 'Gilgit-Baltistan',
        'gilgit baltistan' => 'Gilgit-Baltistan',
    ];

    public const VALID_DOC_TYPES = ['Sale Invoice', 'Credit Note', 'Debit Note'];

    public const VALID_SCHEDULE_TYPES = ['standard', 'reduced', '3rd_schedule', 'exempt', 'zero_rated', 'fed_services', 'services'];

    /**
     * Template sample rows: uploads that still contain these exact rows skip
     * them silently (same convention as the POS product template).
     * Keyed by buyer_name|hs_code|description.
     */
    private const SAMPLE_ROWS = [
        'ABC Trading Co|15179090|Cooking Oil 1L',
        'Fresh Foods (Pvt) Ltd|02023000|Frozen Beef Cuts',
        'City Electronics|85171100|Smart Phone X100',
    ];

    /**
     * Known DMS-export header aliases per template field, normalized via
     * normalizeHeaderKey() (lowercase, non-alphanumerics stripped). Covers the
     * common wording in distributor day-end exports (Voyage, TMX, Salesflo,
     * Centegy, local DMS); suggestMapping()'s fuzzy pass catches near-misses.
     * Deliberately conservative — a wrong auto-suggestion is worse than none.
     */
    private const FIELD_ALIASES = [
        'buyer_name' => ['customername', 'customer', 'partyname', 'party', 'clientname', 'buyername', 'buyer', 'shopname', 'outletname', 'outlet', 'retailername', 'retailer', 'dealername', 'dealer', 'accountname', 'customertitle', 'shiptoparty', 'shiptopartyname', 'storename'],
        'buyer_ntn' => ['ntn', 'ntnno', 'ntnnumber', 'buyerntn', 'customerntn', 'partyntn', 'strn', 'stregno', 'salestaxregno', 'taxregno'],
        'buyer_cnic' => ['cnic', 'cnicno', 'cnicnumber', 'buyercnic', 'customercnic', 'idcardno', 'nicno'],
        'buyer_address' => ['address', 'customeraddress', 'partyaddress', 'buyeraddress', 'shiptoaddress', 'deliveryaddress', 'outletaddress', 'shopaddress', 'address1'],
        'destination_province' => ['province', 'provincename', 'buyerprovince', 'customerprovince', 'destinationprovince', 'state'],
        'document_type' => ['documenttype', 'doctype', 'invoicetype', 'transactiontype', 'billtype'],
        'hs_code' => ['hscode', 'hs', 'hsno', 'hscodeno', 'pctcode', 'pct', 'tariffcode'],
        'description' => ['productname', 'itemname', 'itemdescription', 'productdescription', 'product', 'item', 'skuname', 'materialdescription', 'itemtitle', 'productdetail'],
        'quantity' => ['qty', 'quantitysold', 'saleqty', 'soldqty', 'salesqty', 'units', 'unitssold', 'pcs', 'noofunits', 'totalqty'],
        'price' => ['unitprice', 'rate', 'saleprice', 'salerate', 'tradeprice', 'unitrate', 'priceperunit', 'extaxprice', 'basicprice', 'netrate'],
        'tax' => ['salestax', 'gst', 'gstamount', 'taxamount', 'stamount', 'salestaxamount', 'outputtax', 'gstvalue', 'taxvalue', 'totaltax'],
        'tax_rate' => ['taxrate', 'gstrate', 'strate', 'taxpercent', 'taxpercentage', 'gstpercent', 'salestaxrate'],
        'schedule_type' => ['scheduletype', 'taxschedule'],
        'mrp' => ['mrp', 'retailprice', 'rrp', 'maxretailprice', 'mrpprice'],
        'sro_schedule_no' => ['sro', 'srono', 'sroscheduleno', 'sronumber', 'sroschedule'],
        'sro_serial_no' => ['sroserialno', 'sroserial', 'sroitemserial', 'sroitemserialno'],
        'reference_invoice_number' => ['referenceinvoice', 'refinvoice', 'refinvoiceno', 'referenceinvoiceno', 'originalinvoice', 'originalinvoiceno'],
    ];

    /** Short per-field hints for the mapping screen (mirrors the template Help sheet). */
    private const FIELD_HINTS = [
        'buyer_name' => 'Customer / buyer legal name',
        'buyer_ntn' => '7-digit NTN or 13-digit registration (optional)',
        'buyer_cnic' => '13-digit CNIC (optional)',
        'buyer_address' => 'Buyer address',
        'destination_province' => 'Buyer province',
        'document_type' => 'Sale Invoice / Credit Note / Debit Note',
        'hs_code' => '4-12 digit HS code',
        'description' => 'Item description / product name',
        'quantity' => 'Units sold (positive number)',
        'price' => 'Unit price EXCLUDING sales tax',
        'tax' => 'Total sales tax AMOUNT for the row (0 for exempt)',
        'schedule_type' => 'Blank = auto-detected from HS code',
        'tax_rate' => 'Percent, e.g. 18. Blank = derived from tax amount',
        'mrp' => 'Retail price — needed for 3rd Schedule items',
        'sro_schedule_no' => 'SRO / schedule number (auto-filled when known)',
        'sro_serial_no' => 'SRO item serial number (auto-filled when known)',
        'reference_invoice_number' => 'Original invoice — Credit/Debit Notes only',
    ];

    // ------------------------------------------------------------------
    // Parsing
    // ------------------------------------------------------------------

    /**
     * Parse an uploaded xlsx/xls/csv/txt file into raw template-keyed rows.
     *
     * With $captureHeadersOnMismatch, a file whose headers don't match the
     * template returns ['needs_mapping' => true, 'headers' => [...]] instead
     * of the missing-columns error, so the caller can start the column-mapping
     * flow (DMS day-end exports). Template-matching files are unaffected.
     *
     * @return array{error?: string, needs_mapping?: bool, headers?: array<int,string>, rows?: array<int, array{row:int, data:array<string,string>}>}
     */
    public function parseFile(string $path, string $extension, int $maxRows = self::MAX_ROWS, bool $captureHeadersOnMismatch = false): array
    {
        $extension = strtolower($extension);

        try {
            $grid = in_array($extension, ['xlsx', 'xls'], true)
                ? $this->readGridExcel($path, $maxRows)
                : $this->readGridCsv($path, $maxRows);
        } catch (\Throwable $e) {
            Log::warning('Invoice import parse failed: ' . $e->getMessage());
            return ['error' => 'Could not read the file. Please use the downloaded template (.xlsx) or a valid CSV.'];
        }

        if (count($grid) > $maxRows + 1) {
            return ['error' => 'This file has more than ' . number_format($maxRows) . ' data rows. Please split it into smaller files (max ' . number_format($maxRows) . ' rows each).'];
        }

        if (empty($grid)) {
            return ['error' => 'The file is empty.'];
        }

        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $grid[0] ?? []);
        $missing = array_diff(self::REQUIRED_COLUMNS, $header);
        if (!empty($missing)) {
            if ($captureHeadersOnMismatch) {
                $rawHeaders = [];
                foreach ($grid[0] ?? [] as $h) {
                    $h = trim((string) $h);
                    if ($h !== '' && !in_array($h, $rawHeaders, true)) {
                        $rawHeaders[] = $h;
                    }
                }
                if (!empty($rawHeaders)) {
                    return ['needs_mapping' => true, 'headers' => $rawHeaders];
                }
            }
            return ['error' => 'Missing required columns: ' . implode(', ', $missing)];
        }

        $columnIndexes = [];
        foreach (array_merge(self::REQUIRED_COLUMNS, self::OPTIONAL_COLUMNS) as $col) {
            $idx = array_search($col, $header, true);
            if ($idx !== false) {
                $columnIndexes[$col] = $idx;
            }
        }

        $rows = [];
        $gridCount = count($grid);
        for ($i = 1; $i < $gridCount; $i++) {
            $rowNum = $i + 1; // 1-based, matching what the user sees in Excel
            $raw = $grid[$i];

            $data = [];
            foreach ($columnIndexes as $col => $idx) {
                $data[$col] = $this->cleanCell($col, $raw[$idx] ?? null);
            }

            // Skip fully empty rows.
            if (empty(array_filter($data, fn ($v) => $v !== ''))) {
                continue;
            }

            // Skip untouched template sample rows.
            $sampleKey = ($data['buyer_name'] ?? '') . '|' . ($data['hs_code'] ?? '') . '|' . ($data['description'] ?? '');
            if (in_array($sampleKey, self::SAMPLE_ROWS, true)) {
                continue;
            }

            $rows[] = ['row' => $rowNum, 'data' => $data];
        }

        if (empty($rows)) {
            return ['error' => 'No data rows found in the file (template sample rows are ignored).'];
        }

        return ['rows' => $rows];
    }

    /**
     * Parse a DMS export using a user-built column mapping.
     *
     * @param array<string,string> $columnMap our field => source column header (as shown in the file)
     * @param array<string,string> $defaults  our field => fixed value for every row (fields with NO mapped column)
     * @return array{error?: string, rows?: array<int, array{row:int, data:array<string,string>}>}
     */
    public function parseFileWithMapping(string $path, string $extension, array $columnMap, array $defaults = [], int $maxRows = self::MAX_ROWS): array
    {
        $extension = strtolower($extension);

        try {
            $grid = in_array($extension, ['xlsx', 'xls'], true)
                ? $this->readGridExcel($path, $maxRows)
                : $this->readGridCsv($path, $maxRows);
        } catch (\Throwable $e) {
            Log::warning('Invoice import (mapped) parse failed: ' . $e->getMessage());
            return ['error' => 'Could not read the file. Please upload a valid .xlsx or CSV file.'];
        }

        if (count($grid) > $maxRows + 1) {
            return ['error' => 'This file has more than ' . number_format($maxRows) . ' data rows. Please split it into smaller files (max ' . number_format($maxRows) . ' rows each).'];
        }

        if (empty($grid)) {
            return ['error' => 'The file is empty.'];
        }

        $allFields = array_merge(self::REQUIRED_COLUMNS, self::OPTIONAL_COLUMNS);

        // Normalized header -> column index (first occurrence wins on duplicates).
        $headerIndex = [];
        foreach ($grid[0] ?? [] as $idx => $h) {
            $key = self::normalizeHeaderKey((string) $h);
            if ($key !== '' && !isset($headerIndex[$key])) {
                $headerIndex[$key] = $idx;
            }
        }
        if (empty($headerIndex)) {
            return ['error' => 'The file has no header row.'];
        }

        // Resolve mapped source columns to indexes (match by name, so a saved
        // preset keeps working even when the DMS reorders columns).
        $columnIndexes = [];
        $notFound = [];
        foreach ($columnMap as $field => $source) {
            if (!in_array($field, $allFields, true)) {
                continue;
            }
            $source = trim((string) $source);
            if ($source === '') {
                continue;
            }
            $key = self::normalizeHeaderKey($source);
            if ($key === '' || !isset($headerIndex[$key])) {
                $notFound[$field] = $source;
                continue;
            }
            $columnIndexes[$field] = $headerIndex[$key];
        }
        if (!empty($notFound)) {
            $parts = [];
            foreach ($notFound as $field => $source) {
                $parts[] = "'{$source}' (mapped to {$field})";
            }
            return ['error' => 'These mapped columns were not found in the file: ' . implode(', ', $parts) . '. Re-check the mapping against this file\'s headers.'];
        }

        // Fixed defaults only apply to fields WITHOUT a mapped column; they go
        // through the same code/number cleaning as cell values.
        $cleanDefaults = [];
        foreach ($defaults as $field => $value) {
            if (!in_array($field, $allFields, true) || isset($columnIndexes[$field])) {
                continue;
            }
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $cleanDefaults[$field] = $this->cleanCell($field, $value);
        }

        $missing = [];
        foreach (self::VALUE_REQUIRED_FIELDS as $field) {
            if (!isset($columnIndexes[$field]) && !isset($cleanDefaults[$field])) {
                $missing[] = $field;
            }
        }
        if (!empty($missing)) {
            return ['error' => 'These required fields have no mapped column and no fixed value: ' . implode(', ', $missing)];
        }

        $rows = [];
        $gridCount = count($grid);
        for ($i = 1; $i < $gridCount; $i++) {
            $rowNum = $i + 1; // 1-based, matching what the user sees in Excel
            $raw = $grid[$i];

            $data = [];
            $hasMappedValue = false;
            foreach ($columnIndexes as $col => $idx) {
                $data[$col] = $this->cleanCell($col, $raw[$idx] ?? null);
                if ($data[$col] !== '') {
                    $hasMappedValue = true;
                }
            }

            // Empty-row skip must look at MAPPED cells only — fixed defaults
            // would otherwise turn every blank line into a phantom row.
            if (!$hasMappedValue) {
                continue;
            }

            foreach ($cleanDefaults as $col => $value) {
                $data[$col] = $value;
            }
            // Every template key exists so validation/preview see a full row.
            foreach ($allFields as $col) {
                if (!array_key_exists($col, $data)) {
                    $data[$col] = '';
                }
            }

            $rows[] = ['row' => $rowNum, 'data' => $data];
        }

        if (empty($rows)) {
            return ['error' => 'No data rows found in the file.'];
        }

        return ['rows' => $rows];
    }

    /** Header/source-column comparison key: lowercase, non-alphanumerics stripped. */
    public static function normalizeHeaderKey(string $h): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower(trim($h)));
    }

    /**
     * Auto-suggest a mapping (our field => file header) from alias + fuzzy
     * matching. Each header is used at most once; required fields get first
     * pick. Fuzzy pass is conservative (>= 85% similarity).
     *
     * @param array<int,string> $headers original header strings from the file
     * @return array<string,string>
     */
    public function suggestMapping(array $headers): array
    {
        $normalized = []; // normKey => original header
        foreach ($headers as $h) {
            $key = self::normalizeHeaderKey((string) $h);
            if ($key !== '' && !isset($normalized[$key])) {
                $normalized[$key] = (string) $h;
            }
        }

        $fields = array_merge(self::REQUIRED_COLUMNS, self::OPTIONAL_COLUMNS);
        $suggestions = [];
        $used = [];

        // Pass 1: exact field-name or alias match.
        foreach ($fields as $field) {
            $candidates = array_merge([self::normalizeHeaderKey($field)], self::FIELD_ALIASES[$field] ?? []);
            foreach ($candidates as $cand) {
                if (isset($normalized[$cand]) && !isset($used[$cand])) {
                    $suggestions[$field] = $normalized[$cand];
                    $used[$cand] = true;
                    break;
                }
            }
        }

        // Pass 2: fuzzy match for still-unmapped fields ("Customer Name.", "Qty Sold").
        foreach ($fields as $field) {
            if (isset($suggestions[$field])) {
                continue;
            }
            $candidates = array_merge([self::normalizeHeaderKey($field)], self::FIELD_ALIASES[$field] ?? []);
            $bestKey = null;
            $bestScore = 0.0;
            foreach ($normalized as $normKey => $orig) {
                if (isset($used[$normKey])) {
                    continue;
                }
                foreach ($candidates as $cand) {
                    similar_text($cand, (string) $normKey, $pct);
                    if ($pct > $bestScore) {
                        $bestScore = $pct;
                        $bestKey = $normKey;
                    }
                }
            }
            if ($bestKey !== null && $bestScore >= 85.0) {
                $suggestions[$field] = $normalized[$bestKey];
                $used[$bestKey] = true;
            }
        }

        return $suggestions;
    }

    /**
     * Field metadata for the mapping screen: key, whether a value is required
     * on every row, enum options for fixed-value dropdowns, and a short hint.
     *
     * @return array<int, array{key:string, value_required:bool, options?:array, hint:string}>
     */
    public function mappingFieldMeta(): array
    {
        $meta = [];
        foreach (array_merge(self::REQUIRED_COLUMNS, self::OPTIONAL_COLUMNS) as $field) {
            $entry = [
                'key' => $field,
                'value_required' => in_array($field, self::VALUE_REQUIRED_FIELDS, true),
                'hint' => self::FIELD_HINTS[$field] ?? '',
            ];
            if ($field === 'destination_province') {
                $entry['options'] = self::VALID_PROVINCES;
            } elseif ($field === 'document_type') {
                $entry['options'] = self::VALID_DOC_TYPES;
            } elseif ($field === 'schedule_type') {
                $entry['options'] = self::VALID_SCHEDULE_TYPES;
            }
            $meta[] = $entry;
        }
        return $meta;
    }

    /** Per-cell cleaning shared by the template and mapped parse paths. */
    private function cleanCell(string $col, $value): string
    {
        if (in_array($col, self::CODE_COLUMNS, true)) {
            return (string) (self::cleanCode($value) ?? '');
        }
        if (in_array($col, self::NUMERIC_COLUMNS, true)) {
            $rawStr = trim((string) ($value ?? ''));
            if ($rawStr === '') {
                return '';
            }
            $cleaned = self::cleanNumber($value);
            // Keep the unparseable original so validation can name it.
            return $cleaned === null ? $rawStr : $this->numberToString($cleaned);
        }
        return trim((string) ($value ?? ''));
    }

    private function readGridExcel(string $path, int $maxRows): array
    {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();

        // Row-cap BEFORE materializing the sheet — a small xlsx can hold
        // hundreds of thousands of rows (zip compression); toArray() on that
        // would OOM shared cPanel PHP before any post-parse count check ran.
        if ($sheet->getHighestDataRow() > $maxRows + 1) {
            $spreadsheet->disconnectWorksheets();
            return array_fill(0, $maxRows + 2, []); // triggers the friendly cap error upstream
        }

        $rows = $sheet->toArray(null, true, false, false);
        $spreadsheet->disconnectWorksheets();
        return $rows;
    }

    private function readGridCsv(string $path, int $maxRows): array
    {
        $handle = fopen($path, 'r');
        if (!$handle) {
            throw new \RuntimeException('Could not open file');
        }

        // Excel (regional settings) sometimes saves CSV with ; or TAB — auto-detect
        // from the header line instead of assuming comma.
        $firstLine = fgets($handle) ?: '';
        $delims = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($delims);
        $delim = array_key_first($delims);
        if ($delims[$delim] === 0) {
            $delim = ',';
        }
        rewind($handle);

        $rows = [];
        while (($data = fgetcsv($handle, 0, $delim)) !== false) {
            $rows[] = $data;
            if (count($rows) > $maxRows + 2) {
                break;
            }
        }
        fclose($handle);
        return $rows;
    }

    /** "Rs 1,200", "1200.50", "16%" → float; anything non-numeric → null. */
    public static function cleanNumber($raw): ?float
    {
        if (is_int($raw) || is_float($raw)) {
            return (float) $raw;
        }
        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }
        $s = str_ireplace(['rs.', 'rs', 'pkr', '%'], '', $s);
        $s = str_replace([',', ' '], '', $s);
        if (!is_numeric($s)) {
            return null;
        }
        return (float) $s;
    }

    /**
     * Code cleaner: Excel numeric cells arrive as floats (8901234567890.0)
     * and CSV round-trips arrive as scientific notation ("8.90123E+12") — both
     * are restored to plain digit strings. Empty → null.
     */
    public static function cleanCode($raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        if (is_int($raw) || is_float($raw)) {
            return sprintf('%.0f', (float) $raw);
        }
        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }
        if (preg_match('/^\d+(\.\d+)?E\+?\d+$/i', $s)) {
            return sprintf('%.0f', (float) $s);
        }
        if (preg_match('/^\d+\.0+$/', $s)) {
            return preg_replace('/\.0+$/', '', $s);
        }
        return $s;
    }

    private function numberToString(float $n): string
    {
        $s = rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
        return $s === '' || $s === '-' ? '0' : $s;
    }

    // ------------------------------------------------------------------
    // Validation (shared by xlsx + CSV paths)
    // ------------------------------------------------------------------

    /**
     * Validate parsed rows and normalize their data in place.
     *
     * @param array<int, array{row:int, data:array}> $parsedRows
     * @return array{rows: array<int, array{row:int, data:array, valid:bool, errors:array}>, total:int, valid_count:int, error_count:int}
     */
    public function validateRows(array $parsedRows, Company $company): array
    {
        $standardTaxRate = $company->getStandardTaxRateValue() ?? 18.0;

        $rows = [];
        foreach ($parsedRows as $parsed) {
            // Pre-flagged rows (e.g. CSV column-count mismatch) pass through untouched.
            if (!empty($parsed['errors'])) {
                $rows[] = [
                    'row' => $parsed['row'],
                    'data' => $parsed['data'],
                    'valid' => false,
                    'errors' => array_values($parsed['errors']),
                ];
                continue;
            }

            $data = $parsed['data'];
            $errors = $this->validateRow($data, $company, $standardTaxRate);
            $rows[] = [
                'row' => $parsed['row'],
                'data' => $data,
                'valid' => empty($errors),
                'errors' => $errors,
            ];
        }

        // Group-level rule: FBR allows ONE schedule type per invoice. Rows for the
        // same buyer+document group must agree, otherwise submit would be blocked.
        $groups = [];
        foreach ($rows as $idx => $row) {
            if (!$row['valid']) {
                continue;
            }
            $groups[$this->groupKey($row['data'])][] = $idx;
        }
        foreach ($groups as $indexes) {
            $schedules = [];
            foreach ($indexes as $idx) {
                $schedules[$rows[$idx]['data']['schedule_type'] ?? 'standard'] = true;
            }
            if (count($schedules) > 1) {
                $buyer = $rows[$indexes[0]]['data']['buyer_name'] ?? '';
                $msg = "Mixed schedule types (" . implode(', ', array_keys($schedules)) . ") for buyer '{$buyer}' — FBR allows one schedule type per invoice. Use the same schedule_type for this buyer or split into different buyers.";
                foreach ($indexes as $idx) {
                    $rows[$idx]['valid'] = false;
                    $rows[$idx]['errors'][] = $msg;
                }
            }
        }

        $validCount = count(array_filter($rows, fn ($r) => $r['valid']));

        return [
            'rows' => $rows,
            'total' => count($rows),
            'valid_count' => $validCount,
            'error_count' => count($rows) - $validCount,
        ];
    }

    /** Rows for the same buyer + document type (+ reference) merge into one invoice. */
    public function groupKey(array $data): string
    {
        return ($data['buyer_name'] ?? '') . '|' . ($data['buyer_ntn'] ?? '') . '|'
            . ($data['document_type'] ?? 'Sale Invoice') . '|' . ($data['reference_invoice_number'] ?? '');
    }

    /**
     * Validate ONE row against the same rules FBR enforces at submit time.
     * Normalizes + resolves data in place (enums, codes, SRO/serial/MRP,
     * pct_code/UOM stashed under _-prefixed keys for creation).
     *
     * @return array<string> error messages (empty = valid)
     */
    public function validateRow(array &$data, Company $company, float $standardTaxRate = 18.0): array
    {
        $errors = [];

        // Restore Excel/CSV-mangled codes (floats, scientific notation) before
        // validating — the legacy CSV path reaches here without parseFile().
        foreach (self::CODE_COLUMNS as $codeCol) {
            if (array_key_exists($codeCol, $data)) {
                $data[$codeCol] = (string) (self::cleanCode($data[$codeCol]) ?? '');
            }
        }

        // --- Buyer fields ---
        $buyerName = trim((string) ($data['buyer_name'] ?? ''));
        if ($buyerName === '') {
            $errors[] = 'buyer_name is required';
        } elseif (mb_strlen($buyerName) > 255) {
            $errors[] = 'buyer_name must be 255 characters or less';
        }

        $buyerAddress = trim((string) ($data['buyer_address'] ?? ''));
        if ($buyerAddress === '') {
            $errors[] = 'buyer_address is required';
        } elseif (mb_strlen($buyerAddress) > 500) {
            $errors[] = 'buyer_address must be 500 characters or less';
        }

        $ntnRaw = trim((string) ($data['buyer_ntn'] ?? ''));
        if ($ntnRaw !== '') {
            $ntnDigits = preg_replace('/[^0-9]/', '', $ntnRaw);
            if (!in_array(strlen($ntnDigits), [7, 13], true)) {
                $errors[] = "buyer_ntn must be 7 digits (NTN) or 13 digits (CNIC-style registration) — got '{$ntnRaw}'";
            } else {
                $data['buyer_ntn'] = $ntnDigits;
            }
        }

        $cnicRaw = trim((string) ($data['buyer_cnic'] ?? ''));
        if ($cnicRaw !== '') {
            $cnicDigits = preg_replace('/[^0-9]/', '', $cnicRaw);
            if (strlen($cnicDigits) !== 13) {
                $errors[] = "buyer_cnic must be 13 digits — got '{$cnicRaw}'";
            } else {
                $data['buyer_cnic'] = $cnicDigits;
            }
        }

        // --- Province ---
        $provinceRaw = trim((string) ($data['destination_province'] ?? ''));
        if ($provinceRaw === '') {
            $errors[] = 'destination_province is required';
        } else {
            $canonical = $this->normalizeProvince($provinceRaw);
            if ($canonical === null) {
                $errors[] = 'Invalid destination_province. Must be one of: ' . implode(', ', self::VALID_PROVINCES);
            } else {
                $data['destination_province'] = $canonical;
            }
        }

        // --- Document type ---
        $docRaw = trim((string) ($data['document_type'] ?? ''));
        $docType = null;
        if ($docRaw === '') {
            $errors[] = 'document_type is required';
        } else {
            foreach (self::VALID_DOC_TYPES as $valid) {
                if (strcasecmp(preg_replace('/\s+/', ' ', $docRaw), $valid) === 0) {
                    $docType = $valid;
                    break;
                }
            }
            if ($docType === null) {
                $errors[] = 'Invalid document_type. Must be one of: ' . implode(', ', self::VALID_DOC_TYPES);
            } else {
                $data['document_type'] = $docType;
            }
        }

        // --- Description ---
        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '') {
            $errors[] = 'description is required';
        } elseif (mb_strlen($description) > 255) {
            $errors[] = 'description must be 255 characters or less';
        }

        // --- Numbers ---
        $quantity = self::cleanNumber($data['quantity'] ?? '');
        if ($quantity === null || $quantity <= 0) {
            $errors[] = 'quantity must be a positive number';
        } else {
            $data['quantity'] = $this->numberToString($quantity);
        }

        $price = self::cleanNumber($data['price'] ?? '');
        if ($price === null || $price < 0) {
            $errors[] = 'price must be a number (0 or more)';
        } else {
            $data['price'] = $this->numberToString($price);
        }

        $tax = self::cleanNumber($data['tax'] ?? '');
        if ($tax === null || $tax < 0) {
            $errors[] = 'tax must be a number — the total sales tax AMOUNT in rupees for this row (use 0 for no tax)';
        } else {
            $data['tax'] = $this->numberToString($tax);
        }

        $taxRateGiven = trim((string) ($data['tax_rate'] ?? '')) !== '';
        $taxRate = null;
        if ($taxRateGiven) {
            $taxRate = self::cleanNumber($data['tax_rate']);
            if ($taxRate === null || $taxRate < 0 || $taxRate > 100) {
                $errors[] = 'tax_rate must be a number between 0 and 100';
                $taxRate = null;
                $taxRateGiven = false;
            }
        }

        $mrpGiven = trim((string) ($data['mrp'] ?? '')) !== '';
        $mrp = null;
        if ($mrpGiven) {
            $mrp = self::cleanNumber($data['mrp']);
            if ($mrp === null || $mrp <= 0) {
                $errors[] = 'mrp (retail price) must be a positive number when provided';
                $mrp = null;
            }
        }

        // --- Schedule type ---
        $scheduleRaw = strtolower(str_replace([' ', '-'], '_', trim((string) ($data['schedule_type'] ?? ''))));
        $scheduleType = null;
        if ($scheduleRaw !== '') {
            if (!in_array($scheduleRaw, self::VALID_SCHEDULE_TYPES, true)) {
                $errors[] = 'Invalid schedule_type. Must be one of: ' . implode(', ', self::VALID_SCHEDULE_TYPES);
            } else {
                $scheduleType = $scheduleRaw;
            }
        }

        // --- HS code + resolution against the HS master ---
        $hsRaw = trim((string) ($data['hs_code'] ?? ''));
        $hsResolved = null;
        if ($hsRaw === '') {
            $errors[] = 'hs_code is required';
        } else {
            $hsDigits = preg_replace('/[^0-9]/', '', $hsRaw);
            $len = strlen($hsDigits);
            if ($len < 4 || $len > 12) {
                $errors[] = "hs_code must be 4-12 digits — got '{$hsRaw}'";
            } else {
                $data['hs_code'] = $hsDigits;
                // companyId deliberately null here: validation must not spam the
                // unmapped-HS log; creation resolves again with real context.
                $hsResolved = GlobalHsService::resolveForInvoiceItem($hsDigits, $standardTaxRate, null, null);
                if (!empty($hsResolved['found'])) {
                    $data['_pct_code'] = $hsResolved['pct_code'] ?? null;
                    $data['_default_uom'] = $hsResolved['default_uom'] ?? null;
                    if ($scheduleType === null && !empty($hsResolved['schedule_type'])
                        && in_array($hsResolved['schedule_type'], self::VALID_SCHEDULE_TYPES, true)) {
                        $scheduleType = $hsResolved['schedule_type'];
                    }
                }
            }
        }

        $scheduleType = $scheduleType ?? 'standard';
        $data['schedule_type'] = $scheduleType;
        $data['_sale_type'] = ScheduleEngine::mapSaleType($scheduleType);

        // --- Tax rate: given, derived from amount, or schedule default ---
        $subtotal = ($quantity !== null && $price !== null) ? $quantity * $price : 0.0;
        if ($taxRate === null) {
            if ($tax !== null && $subtotal > 0) {
                $taxRate = round(($tax / $subtotal) * 100, 2);
            } else {
                $taxRate = $scheduleType === 'standard' ? $standardTaxRate : ScheduleEngine::getTaxRate($scheduleType, $company->province ?? null);
            }
        }
        $data['tax_rate'] = $this->numberToString($taxRate);

        // --- FBR rules: exempt / zero-rated must carry zero tax ---
        if (in_array($scheduleType, ['exempt', 'zero_rated'], true)) {
            $label = $scheduleType === 'exempt' ? 'Exempt' : 'Zero Rated';
            if ($tax !== null && $tax != 0.0) {
                $errors[] = "{$label} items must have tax = 0 (got {$data['tax']})";
            }
            if ($taxRate != 0.0) {
                $errors[] = "{$label} items must have tax_rate = 0 (got {$data['tax_rate']})";
            }
        } elseif ($taxRateGiven && $tax !== null && $subtotal > 0) {
            // Consistency: FBR item payloads are built from tax_rate; the header
            // totals come from the tax amount. A mismatch means Excel formula errors.
            $expected = round($subtotal * $taxRate / 100, 2);
            $tolerance = max(1.0, $expected * 0.01);
            if (abs($tax - $expected) > $tolerance) {
                $errors[] = "tax ({$data['tax']}) does not match tax_rate {$data['tax_rate']}% of quantity x price (expected ~" . number_format($expected, 2, '.', '') . ')';
            }
        }

        // --- Credit/Debit notes need a resolvable reference invoice ---
        if (in_array($docType, ['Credit Note', 'Debit Note'], true)) {
            $ref = trim((string) ($data['reference_invoice_number'] ?? ''));
            if ($ref === '') {
                $errors[] = "{$docType} rows need reference_invoice_number (the original invoice being adjusted) — FBR rejects notes without it";
            } else {
                $exists = Invoice::withoutGlobalScopes()
                    ->where('company_id', $company->id)
                    ->where(function ($q) use ($ref) {
                        $q->where('fbr_invoice_number', $ref)
                          ->orWhere('internal_invoice_number', $ref)
                          ->orWhere('invoice_number', $ref);
                    })
                    ->exists();
                if (!$exists) {
                    $errors[] = "reference_invoice_number '{$ref}' not found in your invoices";
                }
            }
        } else {
            $data['reference_invoice_number'] = '';
        }

        // --- SRO / serial / MRP requirements (what blocks FBR submission) ---
        $rules = ScheduleEngine::resolveValidationRules($scheduleType, $taxRate, $standardTaxRate);
        $sro = trim((string) ($data['sro_schedule_no'] ?? ''));
        $serial = trim((string) ($data['sro_serial_no'] ?? ''));

        if ((!empty($rules['requires_sro']) && $sro === '') || (!empty($rules['requires_serial']) && $serial === '')) {
            $suggestion = null;
            if (!empty($data['hs_code'])) {
                try {
                    $suggestion = GlobalHsService::suggestSro($data['hs_code'], $scheduleType, $taxRate, $standardTaxRate);
                } catch (\Throwable $e) {
                    Log::warning('Import SRO suggestion failed: ' . $e->getMessage());
                }
            }
            if ($sro === '' && !empty($suggestion['sro'])) {
                $sro = (string) $suggestion['sro'];
            }
            if ($serial === '' && !empty($suggestion['serial'])) {
                $serial = (string) $suggestion['serial'];
            }
        }

        if (!empty($rules['requires_mrp']) && $mrp === null && $price !== null && $price > 0) {
            // Same fallback FBR payloads use: retail price defaults to unit price.
            $mrp = $price;
        }

        $config = ScheduleEngine::getScheduleConfig($scheduleType);
        if (!empty($rules['requires_sro']) && $sro === '') {
            $errors[] = "SRO Schedule No is required for {$config['label']} — add a sro_schedule_no column value";
        }
        if (!empty($rules['requires_serial']) && $serial === '') {
            $errors[] = "SRO Item Serial No is required for {$config['label']} — add a sro_serial_no column value";
        }
        if (!empty($rules['requires_mrp']) && ($mrp === null || $mrp <= 0)) {
            $errors[] = "MRP (retail price) is required for {$config['label']} — add a mrp column value";
        }

        $data['sro_schedule_no'] = $sro;
        $data['sro_serial_no'] = $serial;
        $data['mrp'] = $mrp !== null ? $this->numberToString($mrp) : '';

        return $errors;
    }

    public function normalizeProvince(string $raw): ?string
    {
        $needle = strtolower(trim($raw));
        foreach (self::VALID_PROVINCES as $province) {
            if (strcasecmp($needle, $province) === 0) {
                return $province;
            }
        }
        return self::PROVINCE_ALIASES[$needle] ?? null;
    }

    // ------------------------------------------------------------------
    // Draft creation (used by the background job; grouping mirrors CSV path)
    // ------------------------------------------------------------------

    /**
     * Create draft invoices from validated rows, one invoice per buyer group.
     * Each group commits in its own transaction so one bad group never rolls
     * back the whole batch.
     *
     * @param array<int, array{row:int, data:array}> $validRows
     * @param callable|null $onProgress fn(int $processedRows, int $created, int $failedRows)
     * @return array{created: array, row_errors: array, created_count:int, failed_rows:int, processed_rows:int}
     */
    public function createDraftsFromRows(
        array $validRows,
        Company $company,
        ?int $userId,
        string $source = 'bulk_import',
        ?int $maxInvoices = null,
        ?callable $onProgress = null
    ): array {
        $standardTaxRate = $company->getStandardTaxRateValue() ?? 18.0;

        $grouped = [];
        foreach ($validRows as $entry) {
            $grouped[$this->groupKey($entry['data'])][] = $entry;
        }

        $created = [];
        $rowErrors = [];
        $processedRows = 0;
        $failedRows = 0;

        foreach ($grouped as $entries) {
            $rowNums = array_map(fn ($e) => $e['row'], $entries);
            $first = $entries[0]['data'];

            if ($maxInvoices !== null && count($created) >= $maxInvoices) {
                foreach ($rowNums as $rowNum) {
                    $rowErrors[] = ['row' => $rowNum, 'errors' => ['Invoice limit reached — plan allows ' . $maxInvoices . ' more invoice(s); this buyer was skipped. Upgrade your plan or import fewer buyers.']];
                }
                $processedRows += count($entries);
                $failedRows += count($entries);
                if ($onProgress) {
                    $onProgress($processedRows, count($created), $failedRows);
                }
                continue;
            }

            try {
                $invoice = DB::transaction(function () use ($entries, $first, $company, $userId, $source, $standardTaxRate) {
                    $buyerNtn = $first['buyer_ntn'] ?: null;
                    $buyerCnic = $first['buyer_cnic'] ?: null;
                    $buyerRegType = \App\Http\Controllers\InvoiceController::detectBuyerRegistrationType($buyerNtn, $buyerCnic);

                    $totalValueExcludingST = 0.0;
                    $totalSalesTax = 0.0;
                    foreach ($entries as $entry) {
                        $totalValueExcludingST += floatval($entry['data']['price']) * floatval($entry['data']['quantity']);
                        $totalSalesTax += floatval($entry['data']['tax']);
                    }
                    $totalAmount = round($totalValueExcludingST + $totalSalesTax, 2);

                    $invoiceNumber = InvoiceNumberingService::generateNextNumber($company->id);

                    $invoice = Invoice::create([
                        'company_id' => $company->id,
                        'invoice_number' => $invoiceNumber,
                        'internal_invoice_number' => $invoiceNumber,
                        'buyer_name' => $first['buyer_name'],
                        'buyer_ntn' => $buyerNtn,
                        'buyer_cnic' => $buyerCnic,
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
                        'document_type' => $first['document_type'] ?: 'Sale Invoice',
                        'reference_invoice_number' => $first['reference_invoice_number'] ?: null,
                        'destination_province' => $first['destination_province'],
                        'supplier_province' => $company->province ?? null,
                        'invoice_date' => now()->toDateString(),
                    ]);

                    foreach ($entries as $entry) {
                        $item = $entry['data'];
                        $scheduleType = $item['schedule_type'] ?: 'standard';

                        $pctCode = $item['_pct_code'] ?? null;
                        $defaultUom = $item['_default_uom'] ?? null;
                        if ($pctCode === null || $defaultUom === null) {
                            // Re-resolve with real context (also logs unmapped HS codes).
                            $hsResolved = GlobalHsService::resolveForInvoiceItem(
                                $item['hs_code'], $standardTaxRate, $company->id, $invoice->id
                            );
                            $pctCode = $pctCode ?? ($hsResolved['pct_code'] ?? null);
                            $defaultUom = $defaultUom ?? ($hsResolved['default_uom'] ?? null);
                        }

                        InvoiceItem::create([
                            'invoice_id' => $invoice->id,
                            'hs_code' => $item['hs_code'],
                            'schedule_type' => $scheduleType,
                            'pct_code' => $pctCode,
                            'tax_rate' => floatval($item['tax_rate'] !== '' ? $item['tax_rate'] : ScheduleEngine::getTaxRate($scheduleType, $company->province ?? null)),
                            'sro_schedule_no' => $item['sro_schedule_no'] !== '' ? $item['sro_schedule_no'] : null,
                            'serial_no' => $item['sro_serial_no'] !== '' ? $item['sro_serial_no'] : null,
                            'mrp' => $item['mrp'] !== '' ? floatval($item['mrp']) : null,
                            'default_uom' => $defaultUom ?? 'Numbers, pieces, units',
                            'sale_type' => $item['_sale_type'] ?? ScheduleEngine::mapSaleType($scheduleType),
                            'st_withheld_at_source' => false,
                            'petroleum_levy' => null,
                            'description' => $item['description'],
                            'quantity' => $item['quantity'],
                            'price' => $item['price'],
                            'tax' => $item['tax'],
                        ]);
                    }

                    InvoiceActivityService::log($invoice->id, $company->id, 'created', [
                        'buyer_name' => $first['buyer_name'],
                        'total_amount' => $totalAmount,
                        'items_count' => count($entries),
                        'document_type' => $first['document_type'] ?: 'Sale Invoice',
                        'source' => $source,
                    ]);

                    AuditLogService::log('invoice_created', 'Invoice', $invoice->id, null, [
                        'invoice_number' => $invoiceNumber,
                        'buyer_name' => $first['buyer_name'],
                        'total_amount' => $totalAmount,
                        'document_type' => $first['document_type'] ?: 'Sale Invoice',
                        'source' => $source,
                    ], $company->id, $userId);

                    return $invoice;
                });

                $created[] = [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'buyer_name' => $invoice->buyer_name,
                    'total_amount' => (float) $invoice->total_amount,
                    'items_count' => count($entries),
                ];
                $processedRows += count($entries);
            } catch (\Throwable $e) {
                Log::error('Invoice import group failed: ' . $e->getMessage(), ['buyer' => $first['buyer_name'] ?? '?']);
                foreach ($rowNums as $rowNum) {
                    $rowErrors[] = ['row' => $rowNum, 'errors' => ['Failed to create invoice: ' . $e->getMessage()]];
                }
                $processedRows += count($entries);
                $failedRows += count($entries);
            }

            if ($onProgress) {
                $onProgress($processedRows, count($created), $failedRows);
            }
        }

        return [
            'created' => $created,
            'row_errors' => $rowErrors,
            'created_count' => count($created),
            'failed_rows' => $failedRows,
            'processed_rows' => $processedRows,
        ];
    }

    // ------------------------------------------------------------------
    // Template + error report (.xlsx)
    // ------------------------------------------------------------------

    public function templateResponse(): StreamedResponse
    {
        $columns = array_merge(self::REQUIRED_COLUMNS, self::OPTIONAL_COLUMNS);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Invoices');

        foreach ($columns as $i => $col) {
            $sheet->setCellValue([$i + 1, 1], $col);
        }
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($columns));
        $sheet->getStyle('A1:' . $lastCol . '1')->getFont()->setBold(true);
        $sheet->getStyle('A1:' . $lastCol . '1')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('D1FAE5');

        // Code columns stay TEXT so Excel can't mangle codes into 8.9E+12.
        $colIndex = fn (string $name) => array_search($name, $columns, true) + 1;
        foreach (self::CODE_COLUMNS as $codeCol) {
            $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex($codeCol));
            $sheet->getStyle($letter . ':' . $letter)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            $sheet->getColumnDimension($letter)->setWidth(18);
        }
        foreach (['buyer_name', 'buyer_address', 'description'] as $wide) {
            $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex($wide));
            $sheet->getColumnDimension($letter)->setWidth(28);
        }
        foreach (['destination_province', 'document_type', 'schedule_type', 'sro_schedule_no'] as $mid) {
            $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex($mid));
            $sheet->getColumnDimension($letter)->setWidth(18);
        }

        $samples = [
            ['ABC Trading Co', '1234567', '', '123 Main St, Lahore', 'Punjab', 'Sale Invoice', '15179090', 'Cooking Oil 1L', '10', '250', '450', 'standard', '18', '', '', '', ''],
            ['Fresh Foods (Pvt) Ltd', '7654321', '', 'Shop 5, Empress Market, Karachi', 'Sindh', 'Sale Invoice', '02023000', 'Frozen Beef Cuts', '25', '900', '0', 'exempt', '0', '', '', '', ''],
            ['City Electronics', '', '4220112345671', '45 Hall Road, Lahore', 'Punjab', 'Sale Invoice', '85171100', 'Smart Phone X100', '5', '40000', '34000', '3rd_schedule', '17', '42500', '', '', ''],
        ];
        foreach ($samples as $r => $sample) {
            foreach ($sample as $c => $value) {
                $colName = $columns[$c];
                if (in_array($colName, self::CODE_COLUMNS, true)) {
                    $sheet->setCellValueExplicit([$c + 1, $r + 2], $value, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue([$c + 1, $r + 2], $value);
                }
            }
        }

        $help = $spreadsheet->createSheet();
        $help->setTitle('Help');
        $helpRows = [
            ['DI Bulk Invoice Import — Help'],
            [''],
            ['Each row is ONE invoice line item. Rows with the same buyer_name + buyer_ntn + document_type combine into a single draft invoice.'],
            ['The 3 sample rows on the Invoices sheet are ignored on import — replace them with your data.'],
            ['Limits: max ' . number_format(self::MAX_ROWS) . ' rows and 10 MB per file. Larger imports: split into multiple files.'],
            ['Imported rows become DRAFT invoices. You submit them to FBR from the Invoices screen as usual.'],
            [''],
            ['Column guide:'],
            ['buyer_name', 'Required. Customer / buyer legal name.'],
            ['buyer_ntn', 'Optional. 7-digit NTN or 13-digit registration number. Keep the column TEXT-formatted.'],
            ['buyer_cnic', 'Optional. 13-digit CNIC (dashes allowed).'],
            ['buyer_address', 'Required.'],
            ['destination_province', 'Required. One of: ' . implode(', ', self::VALID_PROVINCES)],
            ['document_type', 'Required. One of: ' . implode(', ', self::VALID_DOC_TYPES)],
            ['hs_code', 'Required. 4-12 digit HS code (e.g. 15179090). Keep TEXT-formatted so leading zeros survive.'],
            ['description', 'Required. Item description.'],
            ['quantity', 'Required. Positive number.'],
            ['price', 'Required. Unit price EXCLUDING sales tax.'],
            ['tax', 'Required. TOTAL sales tax AMOUNT in rupees for the row (quantity x price x rate). Use 0 for exempt/zero-rated.'],
            ['schedule_type', 'Optional. One of: ' . implode(', ', self::VALID_SCHEDULE_TYPES) . '. Blank = auto-detected from HS code, else standard.'],
            ['tax_rate', 'Optional. Percent (e.g. 18). Blank = derived from tax amount.'],
            ['mrp', 'Retail price — required for 3rd_schedule items (blank = defaults to price).'],
            ['sro_schedule_no', 'Optional. SRO / schedule number for reduced or exempt items (auto-filled when known).'],
            ['sro_serial_no', 'Optional. SRO item serial number (auto-filled when known).'],
            ['reference_invoice_number', 'Required for Credit Note / Debit Note rows: the original invoice number being adjusted.'],
        ];
        foreach ($helpRows as $r => $cells) {
            foreach ($cells as $c => $value) {
                $help->setCellValue([$c + 1, $r + 1], $value);
            }
        }
        $help->getColumnDimension('A')->setWidth(30);
        $help->getColumnDimension('B')->setWidth(110);
        $help->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $spreadsheet->setActiveSheetIndex(0);

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new XlsxWriter($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, 'invoice_import_template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Downloadable .xlsx of failed rows (validation failures + processing
     * failures) with a per-row reason column — fix and re-upload.
     */
    public function errorReportResponse(InvoiceImportBatch $batch): StreamedResponse
    {
        $columns = array_merge(self::REQUIRED_COLUMNS, self::OPTIONAL_COLUMNS);

        // Processing-stage failures (from the job), keyed by row number.
        $processingErrors = [];
        foreach (($batch->resultArray()['row_errors'] ?? []) as $entry) {
            $processingErrors[$entry['row']] = $entry['errors'] ?? [];
        }

        $failedRows = [];
        foreach ($batch->rowsArray() as $row) {
            $errors = [];
            if (!($row['valid'] ?? false)) {
                $errors = $row['errors'] ?? [];
            }
            if (isset($processingErrors[$row['row']])) {
                $errors = array_merge($errors, $processingErrors[$row['row']]);
            }
            if (!empty($errors)) {
                $failedRows[] = ['row' => $row['row'], 'data' => $row['data'] ?? [], 'errors' => $errors];
            }
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Errors');

        $sheet->setCellValue([1, 1], 'row_number');
        foreach ($columns as $i => $col) {
            $sheet->setCellValue([$i + 2, 1], $col);
        }
        $errorColIdx = count($columns) + 2;
        $sheet->setCellValue([$errorColIdx, 1], 'errors');
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($errorColIdx);
        $sheet->getStyle('A1:' . $lastCol . '1')->getFont()->setBold(true);
        $sheet->getStyle('A1:' . $lastCol . '1')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('FEE2E2');

        foreach (self::CODE_COLUMNS as $codeCol) {
            $idx = array_search($codeCol, $columns, true) + 2;
            $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx);
            $sheet->getStyle($letter . ':' . $letter)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        }
        $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($errorColIdx))->setWidth(80);

        $r = 2;
        foreach ($failedRows as $failed) {
            $sheet->setCellValue([1, $r], $failed['row']);
            foreach ($columns as $i => $col) {
                $value = (string) ($failed['data'][$col] ?? '');
                if (in_array($col, self::CODE_COLUMNS, true)) {
                    $sheet->setCellValueExplicit([$i + 2, $r], $value, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue([$i + 2, $r], $value);
                }
            }
            $sheet->setCellValue([$errorColIdx, $r], implode(' | ', $failed['errors']));
            $r++;
        }

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new XlsxWriter($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, 'invoice_import_errors_batch_' . $batch->id . '.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
