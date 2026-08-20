<?php

namespace App\Services;

use App\Models\AnnexureProductAudit;
use App\Models\BulkAiImageBatch;
use App\Models\Company;
use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Bounded, company-scoped Product Master/Annexure handling for AI image
 * batches. Annexure values are reference data until a user explicitly saves a
 * catalog decision; they never replace invoice quantities or document prices.
 */
class AnnexureProductService
{
    public const MAX_FILE_BYTES = 5 * 1024 * 1024;
    public const MAX_ROWS = 5000;
    public const MAX_COLS = 24;

    public const FIELDS = [
        'name', 'barcode', 'sku', 'hs_code', 'pct_code', 'uom',
        'default_tax_rate', 'tax_type', 'schedule_type', 'sro_reference',
        'serial_number', 'mrp', 'default_price',
    ];

    private const ALIASES = [
        'name' => ['name', 'product', 'productname', 'productdescription', 'description', 'item', 'itemname', 'sku_name'],
        'barcode' => ['barcode', 'bar_code', 'ean', 'gtin', 'upc'],
        'sku' => ['sku', 'itemcode', 'productcode', 'code', 'partnumber', 'stockcode'],
        'hs_code' => ['hscode', 'hs', 'hsno', 'pctcode', 'tariffcode', 'pct'],
        'pct_code' => ['pctcode', 'pct', 'pctnumber', 'pctrate'],
        'uom' => ['uom', 'unit', 'unitofmeasure', 'measure', 'unitname'],
        'default_tax_rate' => ['taxrate', 'tax', 'gstrate', 'sales tax', 'salestaxrate'],
        'tax_type' => ['taxtype', 'taxcategory'],
        'schedule_type' => ['scheduletype', 'schedule', 'taxschedule'],
        'sro_reference' => ['sro', 'srono', 'sroreference', 'sroscheduleno', 'sronumber'],
        'serial_number' => ['serial', 'serialnumber', 'sroserial', 'sroserialno', 'serialno'],
        'mrp' => ['mrp', 'rrp', 'retailprice', 'maxretailprice', 'mrpprice'],
        'default_price' => ['defaultprice', 'price', 'tradeprice', 'saleprice', 'unitprice', 'rate'],
    ];

    public function upload(BulkAiImageBatch $batch, UploadedFile $file): array
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (!in_array($extension, ['xlsx', 'xls', 'csv', 'txt'], true)) {
            throw new \InvalidArgumentException('Annexure must be an Excel (.xlsx/.xls) or CSV file.');
        }
        if ((int) $file->getSize() < 1 || (int) $file->getSize() > self::MAX_FILE_BYTES) {
            throw new \InvalidArgumentException('Annexure must be smaller than 5MB.');
        }

        $path = 'private/ai-bulk/' . $batch->company_id . '/' . $batch->id . '/annexure/source.' . $extension;
        $stagingPath = $path . '.uploading';
        Storage::disk('local')->put($stagingPath, (string) file_get_contents($file->getRealPath()));
        try {
            $grid = $this->readGrid(Storage::disk('local')->path($stagingPath), $extension);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($stagingPath);
            throw new \InvalidArgumentException('Could not read this Annexure. Upload a valid Excel or CSV with a header row.');
        }
        if (count($grid) < 1 || empty($grid[0])) {
            Storage::disk('local')->delete($stagingPath);
            throw new \InvalidArgumentException('The Annexure is empty or has no header row.');
        }

        $headers = [];
        $headerKeys = [];
        foreach ((array) $grid[0] as $column => $value) {
            $header = trim((string) $value);
            if ($header === '') {
                continue;
            }
            $key = $this->headerKey($header);
            if (isset($headerKeys[$key])) {
                Storage::disk('local')->delete($stagingPath);
                throw new \InvalidArgumentException('Annexure has duplicate column headers (' . $header . '). Rename one before uploading.');
            }
            $headerKeys[$key] = $column;
            $headers[] = $header;
        }
        if (empty($headers)) {
            Storage::disk('local')->delete($stagingPath);
            throw new \InvalidArgumentException('The Annexure has no named columns.');
        }
        $samples = [];
        foreach (array_slice($grid, 1, 5) as $row) {
            $samples[] = array_map(fn ($v) => $this->cleanCell($v), array_slice($row, 0, self::MAX_COLS));
        }
        $mapping = $this->suggestMapping($headers);
        if ($batch->annexure_storage_path && $batch->annexure_storage_path !== $path) {
            Storage::disk('local')->delete($batch->annexure_storage_path);
        }
        Storage::disk('local')->delete($path);
        Storage::disk('local')->move($stagingPath, $path);
        $batch->update([
            'annexure_filename' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
            'annexure_storage_path' => $path,
            'annexure_status' => 'mapping_pending',
            'annexure_headers_json' => json_encode($headers, JSON_UNESCAPED_UNICODE),
            'annexure_samples_json' => json_encode($samples, JSON_UNESCAPED_UNICODE),
            'annexure_mapping_json' => json_encode($mapping, JSON_UNESCAPED_UNICODE),
            'annexure_rows_json' => null,
            'annexure_uploaded_at' => now(),
        ]);

        return [
            'status' => 'mapping_pending',
            'filename' => $batch->annexure_filename,
            'headers' => $headers,
            'samples' => $samples,
            'suggested_mapping' => $mapping,
            'fields' => $this->fieldMeta(),
        ];
    }

    public function applyMapping(BulkAiImageBatch $batch, array $mapping): array
    {
        $path = (string) $batch->annexure_storage_path;
        if ($path === '' || !Storage::disk('local')->exists($path)) {
            throw new \InvalidArgumentException('This Annexure upload has expired. Please upload it again.');
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $grid = $this->readGrid(Storage::disk('local')->path($path), $extension);
        $headerIndex = [];
        foreach ((array) ($grid[0] ?? []) as $index => $header) {
            $key = $this->headerKey((string) $header);
            if ($key !== '') {
                if (isset($headerIndex[$key])) {
                    throw new \InvalidArgumentException('Annexure has duplicate column headers. Rename one and upload it again.');
                }
                $headerIndex[$key] = $index;
            }
        }
        $indexes = [];
        foreach ($mapping as $field => $source) {
            if (!in_array($field, self::FIELDS, true) || trim((string) $source) === '') {
                continue;
            }
            $key = $this->headerKey((string) $source);
            if ($key === '' || !array_key_exists($key, $headerIndex)) {
                throw new \InvalidArgumentException("Mapped Annexure column for {$field} was not found.");
            }
            $indexes[$field] = $headerIndex[$key];
        }
        if (!isset($indexes['name'])) {
            throw new \InvalidArgumentException('Map the Product Name column before continuing.');
        }

        $rows = [];
        foreach (array_slice($grid, 1, self::MAX_ROWS) as $offset => $raw) {
            $hasValue = false;
            $entry = ['source_row' => $offset + 2];
            foreach (self::FIELDS as $field) {
                $value = array_key_exists($field, $indexes) ? $this->cleanField($field, $raw[$indexes[$field]] ?? '') : '';
                $entry[$field] = $value;
                $hasValue = $hasValue || $value !== '';
            }
            if (!$hasValue) {
                continue;
            }
            [$valid, $errors] = $this->validateEntry($entry);
            $entry['valid'] = $valid;
            $entry['errors'] = $errors;
            $rows[] = $entry;
        }
        if (empty($rows)) {
            throw new \InvalidArgumentException('No Annexure product rows were found.');
        }

        // The raw spreadsheet is no longer needed after normalization. The
        // normalized rows remain batch-scoped and are pruned with the batch.
        Storage::disk('local')->delete($path);
        Storage::disk('local')->deleteDirectory(dirname($path) . '/chunks');
        $batch->update([
            'annexure_storage_path' => null,
            'annexure_status' => 'ready',
            'annexure_mapping_json' => json_encode($mapping, JSON_UNESCAPED_UNICODE),
            'annexure_rows_json' => json_encode($rows, JSON_UNESCAPED_UNICODE),
        ]);

        return [
            'status' => 'ready',
            'rows' => $rows,
            'valid_count' => count(array_filter($rows, fn ($row) => !empty($row['valid']))),
            'invalid_count' => count(array_filter($rows, fn ($row) => empty($row['valid']))),
        ];
    }

    public function fieldMeta(): array
    {
        return array_map(fn ($field) => [
            'key' => $field,
            'label' => ucwords(str_replace('_', ' ', $field)),
            'required' => $field === 'name',
        ], self::FIELDS);
    }

    public function suggestMapping(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $header) {
            $key = $this->headerKey((string) $header);
            if ($key !== '' && !isset($normalized[$key])) {
                $normalized[$key] = (string) $header;
            }
        }
        $result = [];
        $used = [];
        foreach (self::FIELDS as $field) {
            $candidates = array_unique(array_merge([$this->headerKey($field)], array_map([$this, 'headerKey'], self::ALIASES[$field] ?? [])));
            foreach ($candidates as $candidate) {
                if (isset($normalized[$candidate]) && !isset($used[$candidate])) {
                    $result[$field] = $normalized[$candidate];
                    $used[$candidate] = true;
                    break;
                }
            }
        }
        return $result;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,array<string,mixed>> $lines
     * @return array<int,array<string,mixed>>
     */
    public function matchLines(array $lines, array $rows): array
    {
        $validRows = array_values(array_filter($rows, fn ($row) => !empty($row['valid'])));
        return array_map(function ($line, $index) use ($validRows) {
            $barcode = $this->identifier($line['barcode'] ?? '');
            $sku = $this->identifier($line['sku'] ?? '');
            $name = $this->nameKey($line['description'] ?? $line['name'] ?? '');
            $byIdentifier = [];
            foreach ($validRows as $row) {
                if ($barcode !== '' && $barcode === $this->identifier($row['barcode'] ?? '')) $byIdentifier[] = $row;
                if ($sku !== '' && $sku === $this->identifier($row['sku'] ?? '')) $byIdentifier[] = $row;
            }
            $byIdentifier = $this->uniqueRows($byIdentifier);
            if (count($byIdentifier) === 1) {
                return $this->matchResult($byIdentifier[0], 'identifier', 'Matched by exact barcode/SKU.', 1.0, $index);
            }
            if (count($byIdentifier) > 1) {
                return $this->matchResult(null, 'conflict', 'Barcode/SKU values point to more than one Annexure row.', 0.0, $index);
            }

            $exact = array_values(array_filter($validRows, fn ($row) => $name !== '' && $name === $this->nameKey($row['name'] ?? '')));
            $exact = $this->uniqueRows($exact);
            if (count($exact) === 1) {
                return $this->matchResult($exact[0], 'name_exact', 'Matched by exact normalized product name.', 0.98, $index);
            }
            if (count($exact) > 1) {
                return $this->matchResult(null, 'ambiguous', 'More than one Annexure row has this product name.', 0.0, $index);
            }

            $candidates = [];
            foreach ($validRows as $row) {
                $score = $this->nameScore($name, $this->nameKey($row['name'] ?? ''));
                if ($score >= 0.88) $candidates[] = ['row' => $row, 'score' => $score];
            }
            usort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);
            if (count($candidates) === 1 || (count($candidates) > 1 && $candidates[0]['score'] > $candidates[1]['score'] + 0.08)) {
                if (!empty($candidates)) {
                    return $this->matchResult($candidates[0]['row'], 'name_conservative', 'Matched by a conservative name similarity check.', $candidates[0]['score'], $index);
                }
            }
            if (count($candidates) > 1) {
                return $this->matchResult(null, 'ambiguous', 'Several Annexure names are similarly close; choose one manually.', $candidates[0]['score'], $index);
            }
            return [
                'line_index' => $index,
                'status' => 'missing',
                'match_type' => null,
                'confidence' => 0,
                'explanation' => 'No safe Annexure match was found.',
                'source_row' => null,
                'entry' => null,
            ];
        }, $lines, array_keys($lines));
    }

    public function saveCatalogDecision(
        BulkAiImageBatch $batch,
        Company $company,
        int $userId,
        array $input
    ): array {
        $rowNumber = (int) ($input['annexure_row'] ?? 0);
        $row = collect($batch->annexureRowsArray())->first(fn ($candidate) => (int) ($candidate['source_row'] ?? 0) === $rowNumber);
        if (!$row || empty($row['valid'])) {
            throw new \InvalidArgumentException('That Annexure row is missing or needs correction before it can be saved.');
        }
        $action = (string) ($input['action'] ?? '');
        $decision = (string) ($input['price_decision'] ?? 'keep_current');
        if (!in_array($action, ['create', 'update'], true) || !in_array($decision, ['keep_current', 'update_catalog', 'batch_only'], true)) {
            throw new \InvalidArgumentException('Choose a valid catalog action and price decision.');
        }
        $productId = $input['product_id'] ?? null;
        $selected = array_values(array_unique(array_filter((array) ($input['fields'] ?? []), fn ($field) => in_array($field, self::FIELDS, true))));
        sort($selected);
        $key = $action . ':' . $rowNumber . ':' . ((int) $productId) . ':' . $decision . ':' . sha1(json_encode($selected));

        try {
            return DB::transaction(function () use ($batch, $company, $userId, $row, $rowNumber, $action, $decision, $productId, $key, $input) {
            $existingAudit = AnnexureProductAudit::where('company_id', $company->id)
                ->where('batch_id', $batch->id)->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existingAudit) {
                return ['ok' => true, 'idempotent' => true, 'audit_id' => $existingAudit->id, 'product_id' => $existingAudit->product_id];
            }
            $values = $this->catalogValues($row, $decision, $action, (array) ($input['fields'] ?? []));
            $product = null;
            $previous = [];
            if ($action === 'create') {
                \App\Models\Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
                $remaining = PlanLimitService::remainingProductAllowance($company->id, 'fbr');
                if ($remaining !== null && $remaining <= 0) {
                    throw new \RuntimeException('Product limit reached for your plan. Upgrade before adding this product.');
                }
                $product = Product::create($values + ['company_id' => $company->id, 'is_active' => true]);
            } else {
                $product = Product::where('company_id', $company->id)->whereKey((int) $productId)->lockForUpdate()->first();
                if (!$product) {
                    throw new \InvalidArgumentException('The selected product does not belong to this company.');
                }
                $previous = $product->only(array_keys($values));
                $product->update($values);
            }
            $audit = AnnexureProductAudit::create([
                'company_id' => $company->id,
                'batch_id' => $batch->id,
                'product_id' => $product->id,
                'user_id' => $userId,
                'action' => $action === 'create' ? 'product_added' : 'product_updated',
                'decision' => $decision,
                'annexure_row' => $rowNumber,
                'idempotency_key' => $key,
                'previous_values_json' => $previous,
                'approved_values_json' => $decision === 'batch_only'
                    ? $values + ['annexure_default_price_for_batch_only' => $row['default_price'] ?? null]
                    : $values,
            ]);
            return ['ok' => true, 'audit_id' => $audit->id, 'product_id' => $product->id, 'idempotent' => false];
            });
        } catch (QueryException $e) {
            // The unique key is the final protection against two browser tabs
            // reaching an empty idempotency row at exactly the same time.
            $existingAudit = AnnexureProductAudit::where('company_id', $company->id)
                ->where('batch_id', $batch->id)->where('idempotency_key', $key)->first();
            if ($existingAudit) {
                return ['ok' => true, 'idempotent' => true, 'audit_id' => $existingAudit->id, 'product_id' => $existingAudit->product_id];
            }
            throw $e;
        }
    }

    public function auditTrail(BulkAiImageBatch $batch, Company $company): array
    {
        $audits = AnnexureProductAudit::where('company_id', $company->id)->where('batch_id', $batch->id)->latest('id')->get();
        $latestByProduct = $audits->whereIn('action', ['product_added', 'product_updated'])->groupBy('product_id')->map->first();
        return $audits->map(fn ($audit) => [
                'id' => $audit->id, 'product_id' => $audit->product_id, 'action' => $audit->action,
                'decision' => $audit->decision, 'annexure_row' => $audit->annexure_row,
                'previous_values' => $audit->previous_values_json, 'approved_values' => $audit->approved_values_json,
                'reversible' => in_array($audit->action, ['product_added', 'product_updated'], true)
                    && (($latestByProduct[$audit->product_id]->id ?? null) === $audit->id),
                'created_at' => optional($audit->created_at)->toDateTimeString(),
            ])->all();
    }

    public function reverseCatalogDecision(BulkAiImageBatch $batch, Company $company, int $userId, int $auditId): array
    {
        return DB::transaction(function () use ($batch, $company, $userId, $auditId) {
            $audit = AnnexureProductAudit::where('company_id', $company->id)->where('batch_id', $batch->id)
                ->whereKey($auditId)->lockForUpdate()->first();
            if (!$audit || $audit->action === 'product_reverted') {
                throw new \InvalidArgumentException('This catalog decision cannot be reversed.');
            }
            $key = 'reverse:' . $audit->id;
            $existing = AnnexureProductAudit::where('company_id', $company->id)->where('batch_id', $batch->id)
                ->where('idempotency_key', $key)->first();
            if ($existing) return ['ok' => true, 'idempotent' => true, 'audit_id' => $existing->id];

            $product = Product::where('company_id', $company->id)->whereKey($audit->product_id)->lockForUpdate()->first();
            if (!$product) throw new \InvalidArgumentException('The catalog product no longer exists.');
            $latest = AnnexureProductAudit::where('company_id', $company->id)->where('product_id', $product->id)
                ->whereIn('action', ['product_added', 'product_updated'])->latest('id')->lockForUpdate()->first();
            if (!$latest || $latest->id !== $audit->id) {
                throw new \InvalidArgumentException('A newer catalog decision exists. Review that latest decision instead of overwriting it.');
            }
            $approved = array_filter((array) $audit->approved_values_json, fn ($field) => in_array($field, self::FIELDS, true), ARRAY_FILTER_USE_KEY);
            foreach ($approved as $field => $expected) {
                $actual = $product->getAttribute($field);
                if (is_numeric($expected) && is_numeric($actual)) {
                    if (round((float) $expected, 4) !== round((float) $actual, 4)) {
                        throw new \InvalidArgumentException('This product has changed since the Annexure decision. Review it before reversing.');
                    }
                } elseif ((string) $expected !== (string) $actual) {
                    throw new \InvalidArgumentException('This product has changed since the Annexure decision. Review it before reversing.');
                }
            }
            $previous = $product->only(array_keys((array) $audit->approved_values_json));
            $restore = $audit->action === 'product_added'
                ? ['is_active' => false]
                : array_filter((array) $audit->previous_values_json, fn ($key) => in_array($key, self::FIELDS, true), ARRAY_FILTER_USE_KEY);
            if (!empty($restore)) $product->update($restore);
            $reversal = AnnexureProductAudit::create([
                'company_id' => $company->id, 'batch_id' => $batch->id, 'product_id' => $product->id,
                'user_id' => $userId, 'action' => 'product_reverted', 'decision' => 'reversed',
                'annexure_row' => $audit->annexure_row, 'idempotency_key' => $key,
                'previous_values_json' => $previous, 'approved_values_json' => $restore,
            ]);
            return ['ok' => true, 'audit_id' => $reversal->id, 'idempotent' => false];
        });
    }

    private function catalogValues(array $row, string $decision, string $action, array $selectedFields = []): array
    {
        $fields = ['name', 'barcode', 'sku', 'hs_code', 'pct_code', 'default_tax_rate', 'uom', 'tax_type', 'schedule_type', 'sro_reference', 'serial_number', 'mrp'];
        if ($action === 'create' || $decision === 'update_catalog') {
            $fields[] = 'default_price';
        }
        if ($action === 'update' && !empty($selectedFields)) {
            $fields = array_values(array_intersect($fields, self::FIELDS, $selectedFields));
            // A user selecting metadata must still choose a price decision
            // separately; default price is never implicitly included.
            if ($decision === 'update_catalog' && !in_array('default_price', $fields, true)) {
                $fields[] = 'default_price';
            }
        }
        $values = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== '') $values[$field] = $row[$field];
        }
        if ($decision === 'batch_only') unset($values['default_price']);
        if ($action === 'create') {
            $values['uom'] = $values['uom'] ?? 'PCS';
            $values['schedule_type'] = $values['schedule_type'] ?? 'standard';
            $values['default_tax_rate'] = $values['default_tax_rate'] ?? 18;
        }
        if ($action === 'create' && empty($values['tax_type'])) {
            $values['tax_type'] = in_array($values['schedule_type'], ['exempt', 'zero_rated'], true)
                ? 'exempt'
                : ((float) $values['default_tax_rate'] === 18.0 ? 'taxable' : 'custom');
        }
        return $values;
    }

    private function validateEntry(array $entry): array
    {
        $errors = [];
        if (trim((string) $entry['name']) === '') $errors[] = 'Product name is required.';
        if ($entry['hs_code'] === '') $errors[] = 'HS/PCT code is required to save a verified catalog product.';
        elseif (!preg_match('/^\d{4,12}$/', (string) $entry['hs_code'])) $errors[] = 'HS/PCT code must be 4-12 digits.';
        if ($entry['default_tax_rate'] !== '' && (!is_numeric($entry['default_tax_rate']) || (float) $entry['default_tax_rate'] < 0 || (float) $entry['default_tax_rate'] > 100)) $errors[] = 'Tax rate must be between 0 and 100.';
        foreach (['mrp', 'default_price'] as $field) {
            if ($entry[$field] !== '' && (!is_numeric($entry[$field]) || (float) $entry[$field] < 0)) $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' must be zero or more.';
        }
        if ($entry['schedule_type'] !== '' && !in_array($entry['schedule_type'], InvoiceImportService::VALID_SCHEDULE_TYPES, true)) $errors[] = 'Unknown tax schedule.';
        $rules = ScheduleEngine::resolveValidationRules($entry['schedule_type'] ?: 'standard', (float) ($entry['default_tax_rate'] !== '' ? $entry['default_tax_rate'] : 18));
        if ($rules['requires_sro'] && $entry['sro_reference'] === '') $errors[] = 'SRO/reference is required for this schedule.';
        if ($rules['requires_serial'] && $entry['serial_number'] === '') $errors[] = 'SRO serial is required for this schedule.';
        if ($rules['requires_mrp'] && ($entry['mrp'] === '' || (float) $entry['mrp'] <= 0)) $errors[] = 'MRP is required for this schedule.';
        return [empty($errors), $errors];
    }

    private function matchResult(?array $row, string $type, string $explanation, float $confidence, int $lineIndex): array
    {
        return [
            'line_index' => $lineIndex,
            'status' => in_array($type, ['ambiguous', 'conflict'], true) ? $type : 'matched',
            'match_type' => $type,
            'confidence' => round($confidence, 3),
            'explanation' => $explanation,
            'source_row' => $row['source_row'] ?? null,
            'entry' => $row,
        ];
    }

    private function uniqueRows(array $rows): array
    {
        $seen = [];
        return array_values(array_filter($rows, function ($row) use (&$seen) {
            $key = (string) ($row['source_row'] ?? '');
            if ($key === '' || isset($seen[$key])) return false;
            $seen[$key] = true;
            return true;
        }));
    }

    private function nameScore(string $left, string $right): float
    {
        if ($left === '' || $right === '') return 0;
        if ($left === $right) return 1;
        $a = array_unique(preg_split('/\s+/', $left) ?: []);
        $b = array_unique(preg_split('/\s+/', $right) ?: []);
        $intersection = count(array_intersect($a, $b));
        return $intersection / max(1, count(array_unique(array_merge($a, $b))));
    }

    private function nameKey($value): string
    {
        $value = mb_strtolower(trim((string) $value));
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?: '';
        return trim(preg_replace('/\s+/', ' ', $value) ?: '');
    }

    private function identifier($value): string
    {
        return preg_replace('/[^a-z0-9]/i', '', strtolower(trim((string) $value))) ?: '';
    }

    private function headerKey(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(trim($value))) ?: '';
    }

    private function cleanCell($value): string
    {
        return trim(preg_replace('/\s+/', ' ', (string) $value) ?: '');
    }

    private function cleanField(string $field, $value): string
    {
        $value = $this->cleanCell($value);
        if (in_array($field, ['barcode', 'sku', 'hs_code', 'pct_code', 'sro_reference', 'serial_number'], true)) {
            return mb_substr($value, 0, 100);
        }
        if (in_array($field, ['default_tax_rate', 'mrp', 'default_price'], true)) {
            return preg_replace('/[^\d.\-]/', '', $value) ?: '';
        }
        return mb_substr($value, 0, $field === 'name' ? 255 : 100);
    }

    private function readGrid(string $path, string $extension): array
    {
        if (in_array(strtolower($extension), ['csv', 'txt'], true)) {
            $handle = fopen($path, 'rb');
            $rows = [];
            while ($handle && !feof($handle) && count($rows) <= self::MAX_ROWS) {
                $line = fgets($handle);
                if ($line === false) break;
                $delimiter = substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
                $rows[] = str_getcsv(rtrim($line, "\r\n"), $delimiter);
            }
            if (is_resource($handle)) fclose($handle);
            return array_map(fn ($row) => array_slice($row, 0, self::MAX_COLS), $rows);
        }
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($path)->getActiveSheet();
        $rows = [];
        foreach ($sheet->toArray(null, true, true, false) as $row) {
            $rows[] = array_slice(array_values($row), 0, self::MAX_COLS);
            if (count($rows) > self::MAX_ROWS) break;
        }
        return $rows;
    }
}