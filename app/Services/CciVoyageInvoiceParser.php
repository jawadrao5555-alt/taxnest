<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;

/** Recognizes the observed detailed block layout; changed layouts require manual mapping. */
final class CciVoyageInvoiceParser
{
    public function parse(string $path): ?array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $sourceRows = array_sum(array_column($reader->listWorksheetInfo($path), 'totalRows'));
        if ($sourceRows > InvoiceImportService::MAX_ROWS) {
            return ['error' => 'Detailed report exceeds the import row limit. Split the source report.'];
        }
        $book = $reader->load($path);
        try {
            $sheets = [];
            $count = 0;
            foreach ($book->getAllSheets() as $sheet) {
                $count += $sheet->getHighestDataRow();
                if ($count > InvoiceImportService::MAX_ROWS) {
                    return ['error' => 'Detailed report exceeds the import row limit. Split the source report.'];
                }
                $sheets[$sheet->getTitle()] = $sheet->toArray(null, true, false, false);
            }
            return $this->parseGrids($sheets);
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function parseGrids(array $sheets): ?array
    {
        $result = [];
        $recognized = false;
        $offset = 0;
        $unknownSheets = [];
        foreach ($sheets as $name => $grid) {
            $sheetRecognized = false;
            $documentType = null;
            $block = null;
            $lines = [];
            $products = false;
            $flush = function () use (&$result, &$block, &$lines) {
                foreach ($lines as $line) {
                    $line['data'] = array_merge($line['data'], $block);
                    $result[] = $line;
                }
                $lines = [];
            };
            foreach ($grid as $index => $cells) {
                if (($cells[22] ?? '') === 'Outlet Slip Type:') {
                    $documentType = ($cells[27] ?? '') === 'Sales Invoice' ? 'Sale Invoice' : null;
                }
                if (($cells[4] ?? null) === 'Invoice Nr:') {
                    if ($documentType === null) {
                        return ['error' => 'Voyage report document type must be explicitly Sales Invoice; other or missing types require review.'];
                    }
                    $recognized = true;
                    $sheetRecognized = true;
                    if ($block !== null) {
                        $flush();
                    }
                    if (($cells[9] ?? '') !== 'DATE') {
                        return ['error' => 'Changed Voyage invoice header layout. Review the format mapping.'];
                    }
                    $block = ['source_invoice_number' => trim((string) ($cells[5] ?? '')),
                        'invoice_date' => InvoiceImportService::normalizeDate($cells[11] ?? '') ?? '',
                        'source_outlet' => '', 'document_type' => $documentType,
                        'invoice_gross' => '', 'invoice_discount' => '', 'invoice_vat' => '', 'invoice_net' => ''];
                    $products = false;
                    continue;
                }
                if ($block === null) {
                    continue;
                }
                if (($cells[0] ?? '') === 'Outlet:') {
                    $block['source_outlet'] = trim((string) ($cells[4] ?? ''));
                    $block['buyer_name'] = trim((string) ($cells[5] ?? ''));
                    $block['buyer_address'] = trim((string) ($cells[15] ?? ''));
                }
                if (($cells[0] ?? '') === 'Product Code') {
                    foreach ([3 => 'Product Name', 12 => 'Ph. Case', 14 => 'Unit Price', 16 => 'Gross Amnt', 19 => 'VAT %', 21 => 'Net Total'] as $column => $label) {
                        if (($cells[$column] ?? '') !== $label) {
                            return ['error' => 'Changed Voyage product columns. Review the format mapping.'];
                        }
                    }
                    $products = true;
                    continue;
                }
                $footers = ['Gross Total:' => 'invoice_gross', 'Total Discount:' => 'invoice_discount', 'VAT Total:' => 'invoice_vat', 'Net Amount:' => 'invoice_net'];
                if (isset($footers[$cells[15] ?? ''])) {
                    $block[$footers[$cells[15]]] = $this->footerMoney($cells[19] ?? '');
                    $products = false;
                    if (($cells[15] ?? '') === 'Net Amount:') {
                        $flush();
                        $block = null;
                    }
                    continue;
                }
                if ($products && trim((string) ($cells[0] ?? '')) !== '') {
                    $data = array_fill_keys(array_merge(InvoiceImportService::REQUIRED_COLUMNS, InvoiceImportService::OPTIONAL_COLUMNS), '');
                    $data['source_sku'] = trim((string) $cells[0]);
                    $data['description'] = trim((string) ($cells[3] ?? ''));
                    $data['quantity'] = trim((string) ($cells[12] ?? ''));
                    $data['source_gross'] = trim((string) ($cells[16] ?? ''));
                    // VAT % and reported Net Total are evidence, not a line VAT allocation.
                    $data['source_reported_net'] = trim((string) ($cells[21] ?? ''));
                    $data['source_vat_percent'] = trim((string) ($cells[19] ?? ''));
                    $data['_source_cells'] = $cells;
                    $data['_source_sheet'] = $name;
                    $data['_source_row'] = $index + 1;
                    $lines[] = ['row' => $offset + $index + 1, 'data' => $data];
                }
            }
            if ($block !== null) {
                $flush();
            }
            if (!$sheetRecognized && array_filter($grid, fn ($cells) => array_filter($cells, fn ($value) => $value !== null && $value !== ''))) {
                $unknownSheets[] = $name;
            }
            $offset += count($grid);
        }
        if ($recognized && $unknownSheets) {
            return ['error' => 'Unrecognized worksheets require review: ' . implode(', ', $unknownSheets)];
        }
        return $recognized ? ($result ? ['rows' => $result] : ['error' => 'Voyage report contains no product rows.']) : null;
    }

    private function footerMoney($value): string
    {
        $v = trim((string) $value);
        // The verified block layout prints decimal commas in footer text only.
        return preg_match('/^-?\d+,\d{1,2}$/D', $v) ? str_replace(',', '.', $v) : $v;
    }
}
