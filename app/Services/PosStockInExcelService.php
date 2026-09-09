<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Ingredient;
use App\Models\PosProduct;
use App\Models\PosStockInLine;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * NestPOS Stock-In Excel — parse + match only. Posting lives in PosStockInService.
 *
 * Sheet StockIn. Never creates ingredients or products. Never writes stock.
 */
class PosStockInExcelService
{
    public const SAMPLE_MARKER = 'Misal:';
    public const SHEET_NAME = 'StockIn';
    public const FILENAME = 'nestpos_stock_in.xlsx';
    public const MAX_DATA_ROWS = 5000;

    public const HEADERS = [
        'Line Type',
        'NestPOS Code',
        'Supplier Item Code',
        'Supplier Item Name',
        'Qty',
        'Unit',
        'Rate',
        'Branch',
        'Reference',
        'Date',
        'Notes',
    ];

    public function streamTemplate()
    {
        $spreadsheet = $this->buildTemplateSpreadsheet();
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, self::FILENAME, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    public function buildTemplateSpreadsheet(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::SHEET_NAME);
        $sheet->fromArray(self::HEADERS, null, 'A1');
        $sheet->getStyle('A1:K1')->getFont()->setBold(true);
        $sheet->getStyle('A1:K1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('D1FAE5');
        $sheet->freezePane('A2');

        foreach (['A' => 14, 'B' => 16, 'C' => 18, 'D' => 22, 'E' => 10, 'F' => 10, 'G' => 10, 'H' => 12, 'I' => 14, 'J' => 12, 'K' => 18] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        $sheet->getStyle('B:B')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        $sheet->getStyle('C:C')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);

        $comments = [
            'A' => "INGREDIENT = kitchen item (recipes ON).\nITEM = sellable product (inventory ON).\nThis file never creates catalog rows.",
            'B' => 'NestPOS Ingredient Code or product barcode/SKU. TEXT.',
            'C' => 'Distributor code. Label only. Not used for matching.',
            'D' => 'Name on the supplier bill.',
            'E' => 'Quantity received. Must be greater than 0.',
            'F' => 'Kitchen unit must equal the ingredient unit. ITEM unit is product UOM.',
            'G' => 'Optional rate. Cost update is OFF unless you tick it on the screen.',
            'I' => 'Supplier bill / delivery note. Required (or enter on the screen).',
        ];
        foreach ($comments as $col => $text) {
            $sheet->getComment($col . '1')->getText()->createTextRun($text);
            $sheet->getComment($col . '1')->setWidth('220px');
            $sheet->getComment($col . '1')->setHeight('90px');
        }

        $validation = $sheet->getCell('A2')->getDataValidation();
        $validation->setType(DataValidation::TYPE_LIST);
        $validation->setErrorStyle(DataValidation::STYLE_STOP);
        $validation->setAllowBlank(true);
        $validation->setShowDropDown(true);
        $validation->setFormula1('"INGREDIENT,ITEM"');
        $validation->setPromptTitle('Line Type');
        $validation->setPrompt('INGREDIENT = kitchen. ITEM = finished product.');
        $validation->setErrorTitle('Invalid Line Type');
        $validation->setError('Use INGREDIENT or ITEM.');
        $validation->setSqref('A2:A2000');

        $m = self::SAMPLE_MARKER . ' ';
        $samples = [
            ['INGREDIENT', 'RICE-25', 'RICE-25', $m . 'Basmati Rice', 500, 'kg', 300, '', 'INV-88', '', ''],
            ['INGREDIENT', 'CHK-01', 'CHK-01', $m . 'Chicken', 200, 'kg', 650, '', 'INV-88', '', ''],
            ['ITEM', 'DK-500', '', $m . 'Coke 500ml', 24, 'NOS', 55, '', 'INV-88', '', ''],
        ];
        $rowNum = 2;
        foreach ($samples as $sample) {
            $this->writeRow($sheet, $rowNum, $sample);
            $sheet->getStyle("A{$rowNum}:K{$rowNum}")->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB('FEF3C7');
            $rowNum++;
        }

        return $spreadsheet;
    }

    /**
     * @return array{ok:bool,fatal:?string,rows:array<int,array>}
     */
    public function parseFile(string $path): array
    {
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($path);
        } catch (\Throwable $e) {
            return ['ok' => false, 'fatal' => __('pos.stock_in_file_unreadable'), 'rows' => []];
        }

        $sheet = $spreadsheet->getSheetByName(self::SHEET_NAME) ?: $spreadsheet->getActiveSheet();
        if ($sheet->getHighestDataRow() > self::MAX_DATA_ROWS + 15) {
            $spreadsheet->disconnectWorksheets();
            return ['ok' => false, 'fatal' => __('pos.stock_in_too_many_rows', ['max' => self::MAX_DATA_ROWS]), 'rows' => []];
        }
        $rows = $sheet->toArray(null, true, false, false);
        $spreadsheet->disconnectWorksheets();

        $headerIdx = $this->findHeaderRow($rows);
        if ($headerIdx === null) {
            return ['ok' => false, 'fatal' => __('pos.stock_in_missing_header'), 'rows' => []];
        }
        $header = $this->normalizeHeader($rows[$headerIdx]);
        $map = $this->columnMap($header);

        $out = [];
        for ($i = $headerIdx + 1; $i < count($rows); $i++) {
            $data = $rows[$i];
            $parsed = $this->extractRow($data, $map);
            if ($this->isEmptyRow($parsed) || $this->isSampleRow($parsed)) {
                continue;
            }
            $parsed['source_row_no'] = $i + 1;
            $out[] = $parsed;
        }

        if ($out === []) {
            return ['ok' => false, 'fatal' => __('pos.stock_in_no_data_rows'), 'rows' => []];
        }

        return ['ok' => true, 'fatal' => null, 'rows' => $out];
    }

    /**
     * Match one parsed row. Never creates catalog records.
     *
     * @return array{match_status:string,matched_ingredient_id:?int,matched_product_id:?int,invalid_reason:?string,selected:bool}
     */
    public function matchRow(array $parsed, int $companyId, bool $inventoryOn, bool $recipesOn): array
    {
        $type = $parsed['line_type'];
        if ($type === null) {
            return $this->status(PosStockInLine::INVALID, __('pos.stock_in_line_type_invalid'));
        }

        $qty = $parsed['qty'];
        if ($qty === null || $qty <= 0) {
            return $this->status(PosStockInLine::INVALID, __('pos.stock_in_qty_invalid'));
        }

        if ($type === PosStockInLine::TYPE_INGREDIENT) {
            if (!$recipesOn) {
                return $this->status(PosStockInLine::SKIPPED_GATE, __('pos.stock_in_skip_recipes_off'), selected: false);
            }
            return $this->matchIngredient($parsed, $companyId);
        }

        if (!$inventoryOn) {
            return $this->status(PosStockInLine::SKIPPED_GATE, __('pos.stock_in_skip_inventory_off'), selected: false);
        }

        return $this->matchItem($parsed, $companyId);
    }

    private function matchIngredient(array $parsed, int $companyId): array
    {
        $code = $parsed['nestpos_code'];
        $name = strtolower(trim((string) $parsed['supplier_item_name']));
        $unit = strtolower(trim((string) $parsed['unit']));

        if ($code) {
            $hits = Ingredient::where('company_id', $companyId)
                ->whereRaw('LOWER(code) = ?', [strtolower($code)])
                ->get();
            if ($hits->count() === 1) {
                $ing = $hits->first();
                if ($unit !== '' && strtolower((string) $ing->unit) !== $unit) {
                    return $this->status(PosStockInLine::INVALID, __('pos.stock_in_unit_mismatch', [
                        'file' => $unit, 'master' => $ing->unit,
                    ]));
                }
                return $this->status(PosStockInLine::MATCHED, null, ingredientId: (int) $ing->id);
            }
            if ($hits->count() > 1) {
                return $this->status(PosStockInLine::NEEDS_CLEARANCE, __('pos.stock_in_code_ambiguous'));
            }
        }

        if ($name === '' || $unit === '') {
            return $this->status(PosStockInLine::NEEDS_CLEARANCE, __('pos.stock_in_unmatched_ingredient'));
        }

        if (!in_array($unit, RecipeInventoryService::UNITS, true)) {
            return $this->status(PosStockInLine::INVALID, __('pos.stock_in_ing_unit_invalid', [
                'units' => implode(', ', RecipeInventoryService::UNITS),
            ]));
        }

        $hits = Ingredient::where('company_id', $companyId)
            ->whereRaw('LOWER(name) = ?', [$name])
            ->whereRaw('LOWER(unit) = ?', [$unit])
            ->get();
        if ($hits->count() === 1) {
            return $this->status(PosStockInLine::MATCHED, null, ingredientId: (int) $hits->first()->id);
        }
        if ($hits->count() > 1) {
            return $this->status(PosStockInLine::NEEDS_CLEARANCE, __('pos.stock_in_name_ambiguous'));
        }

        return $this->status(PosStockInLine::NEEDS_CLEARANCE, __('pos.stock_in_unmatched_ingredient'));
    }

    private function matchItem(array $parsed, int $companyId): array
    {
        $code = $parsed['nestpos_code'];
        $name = strtolower(trim((string) $parsed['supplier_item_name']));
        $unit = strtolower(trim((string) $parsed['unit']));

        if ($code) {
            $byBarcode = PosProduct::where('company_id', $companyId)
                ->whereRaw('LOWER(barcode) = ?', [strtolower($code)])
                ->get();
            if ($byBarcode->count() === 1) {
                return $this->finishItemMatch($byBarcode->first(), $unit);
            }
            if ($byBarcode->count() > 1) {
                return $this->status(PosStockInLine::NEEDS_CLEARANCE, __('pos.stock_in_code_ambiguous'));
            }

            $bySku = PosProduct::where('company_id', $companyId)
                ->whereRaw('LOWER(sku) = ?', [strtolower($code)])
                ->get();
            if ($bySku->count() === 1) {
                return $this->finishItemMatch($bySku->first(), $unit);
            }
            if ($bySku->count() > 1) {
                return $this->status(PosStockInLine::NEEDS_CLEARANCE, __('pos.stock_in_code_ambiguous'));
            }
        }

        if ($name === '') {
            return $this->status(PosStockInLine::NEEDS_CLEARANCE, __('pos.stock_in_unmatched_item'));
        }

        $hits = PosProduct::where('company_id', $companyId)
            ->whereRaw('LOWER(name) = ?', [$name])
            ->get();
        if ($hits->count() === 1) {
            return $this->finishItemMatch($hits->first(), $unit);
        }
        if ($hits->count() > 1) {
            return $this->status(PosStockInLine::NEEDS_CLEARANCE, __('pos.stock_in_name_ambiguous'));
        }

        return $this->status(PosStockInLine::NEEDS_CLEARANCE, __('pos.stock_in_unmatched_item'));
    }

    private function finishItemMatch(PosProduct $product, string $fileUnit): array
    {
        $masterUnit = strtolower(trim((string) $product->uom));
        if ($fileUnit !== '' && $masterUnit !== '' && $fileUnit !== $masterUnit) {
            return $this->status(PosStockInLine::INVALID, __('pos.stock_in_unit_mismatch', [
                'file' => $fileUnit, 'master' => $product->uom,
            ]));
        }
        return $this->status(PosStockInLine::MATCHED, null, productId: (int) $product->id);
    }

    private function status(
        string $status,
        ?string $reason,
        bool $selected = true,
        ?int $ingredientId = null,
        ?int $productId = null
    ): array {
        $matched = $status === PosStockInLine::MATCHED;
        return [
            'match_status' => $status,
            'matched_ingredient_id' => $ingredientId,
            'matched_product_id' => $productId,
            'invalid_reason' => $reason,
            'selected' => $matched && $selected,
        ];
    }

    private function writeRow($sheet, int $rowNum, array $vals): void
    {
        $sheet->setCellValue('A' . $rowNum, $vals[0]);
        $sheet->setCellValueExplicit('B' . $rowNum, (string) $vals[1], DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('C' . $rowNum, (string) $vals[2], DataType::TYPE_STRING);
        $sheet->setCellValue('D' . $rowNum, $vals[3]);
        $sheet->setCellValue('E' . $rowNum, $vals[4]);
        $sheet->setCellValue('F' . $rowNum, $vals[5]);
        $sheet->setCellValue('G' . $rowNum, $vals[6]);
        $sheet->setCellValue('H' . $rowNum, $vals[7]);
        $sheet->setCellValue('I' . $rowNum, $vals[8]);
        $sheet->setCellValue('J' . $rowNum, $vals[9]);
        $sheet->setCellValue('K' . $rowNum, $vals[10]);
    }

    private function findHeaderRow(array $rows): ?int
    {
        $max = min(10, count($rows));
        for ($i = 0; $i < $max; $i++) {
            $header = $this->normalizeHeader($rows[$i]);
            if ($this->findColumn($header, ['line type', 'line_type', 'row type', 'type']) !== false) {
                return $i;
            }
        }
        return null;
    }

    private function normalizeHeader(array $row): array
    {
        return array_map(function ($h) {
            return strtolower(trim(preg_replace('/[\x{FEFF}]/u', '', (string) $h)));
        }, $row);
    }

    private function columnMap(array $header): array
    {
        return [
            'line_type' => $this->findColumn($header, ['line type', 'line_type', 'row type', 'type']),
            'nestpos_code' => $this->findColumn($header, ['nestpos code', 'nestpos_code', 'code', 'sku', 'barcode']),
            'supplier_code' => $this->findColumn($header, ['supplier item code', 'item code', 'sku', 'supplier code']),
            'supplier_name' => $this->findColumn($header, ['supplier item name', 'item name', 'name', 'product', 'ingredient name']),
            'qty' => $this->findColumn($header, ['qty', 'quantity', 'miqdaar']),
            'unit' => $this->findColumn($header, ['unit', 'uom', 'unit (uom)']),
            'rate' => $this->findColumn($header, ['rate', 'cost', 'kharid', 'unit price']),
            'branch' => $this->findColumn($header, ['branch']),
            'reference' => $this->findColumn($header, ['reference', 'bill no', 'invoice', 'supplier invoice']),
            'date' => $this->findColumn($header, ['date']),
            'notes' => $this->findColumn($header, ['notes', 'note']),
        ];
    }

    private function extractRow(array $data, array $map): array
    {
        $name = $this->cell($data, $map['supplier_name']);
        return [
            'line_type' => $this->normalizeLineType($this->cell($data, $map['line_type'])),
            'nestpos_code' => $this->cleanImportCode($this->raw($data, $map['nestpos_code'])),
            'supplier_item_code' => $this->cleanImportCode($this->raw($data, $map['supplier_code'])),
            'supplier_item_name' => $name,
            'qty' => $this->parseQty($this->raw($data, $map['qty'])),
            'unit' => strtolower($this->cell($data, $map['unit'])),
            'rate' => $this->parseQty($this->raw($data, $map['rate'])),
            'reference_override' => $this->cell($data, $map['reference']),
            'notes' => trim($this->cell($data, $map['date']) . ' ' . $this->cell($data, $map['notes'])),
        ];
    }

    private function normalizeLineType(?string $raw): ?string
    {
        $t = strtoupper(trim((string) $raw));
        if ($t === 'INGREDIENT' || $t === 'ITEM') {
            return $t;
        }
        return null;
    }

    private function isEmptyRow(array $parsed): bool
    {
        return ($parsed['line_type'] === null)
            && ($parsed['nestpos_code'] === null)
            && trim((string) $parsed['supplier_item_name']) === ''
            && $parsed['qty'] === null;
    }

    private function isSampleRow(array $parsed): bool
    {
        $name = (string) ($parsed['supplier_item_name'] ?? '');
        return $name !== '' && stripos($name, self::SAMPLE_MARKER) === 0;
    }

    private function findColumn(array $header, array $aliases)
    {
        foreach ($header as $i => $h) {
            foreach ($aliases as $alias) {
                if ($h === $alias) {
                    return $i;
                }
            }
        }
        return false;
    }

    private function cell(array $data, $idx): string
    {
        if ($idx === false || !array_key_exists($idx, $data) || $data[$idx] === null) {
            return '';
        }
        return trim((string) $data[$idx]);
    }

    private function raw(array $data, $idx)
    {
        if ($idx === false || !array_key_exists($idx, $data)) {
            return null;
        }
        return $data[$idx];
    }

    private function parseQty($raw): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_numeric($raw)) {
            return (float) $raw;
        }
        $s = trim(str_replace(',', '', (string) $raw));
        if ($s === '' || !is_numeric($s)) {
            return null;
        }
        return (float) $s;
    }

    public function cleanImportCode($raw): ?string
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
}
