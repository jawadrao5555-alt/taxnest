<?php

namespace App\Http\Controllers;

use App\Services\ScheduleEngine;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class HsMasterExportController extends Controller
{
    public function index(Request $request)
    {
        $format = $request->query('format', 'view');
        $data = $this->buildHsMaster();

        if ($format === 'csv') {
            return $this->exportCsv($data);
        }

        if ($format === 'xlsx') {
            return $this->exportXlsx($data);
        }

        if ($format === 'json') {
            return response()->json([
                'total_hs_codes' => count($data),
                'exported_at' => now()->toIso8601String(),
                'records' => $data,
            ], 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        return view('admin.hs-master-export', [
            'records' => $data,
            'totalCount' => count($data),
        ]);
    }

    private function buildHsMaster(): array
    {
        $master = [];

        foreach (ScheduleEngine::$hsLookupTable as $hsCode => $entry) {
            $rules = ScheduleEngine::resolveValidationRules($entry['schedule_type'], $entry['tax_rate']);
            $master[$hsCode] = [
                'hsCode' => $hsCode,
                'description' => $this->getHsDescription($hsCode, $entry['schedule_type']),
                'pctCode' => $entry['pct_code'],
                'scheduleType' => $entry['schedule_type'],
                'taxRate' => $entry['tax_rate'],
                'defaultUom' => null,
                'sroRequired' => $rules['requires_sro'],
                'sroNumber' => null,
                'sroItemSerialNo' => null,
                'mrpRequired' => $rules['requires_mrp'],
                'source' => 'ScheduleEngine',
                'created_at' => null,
                'updated_at' => null,
            ];
        }

        $products = Product::select('hs_code', 'name', 'pct_code', 'schedule_type', 'default_tax_rate', 'uom', 'sro_reference', 'created_at', 'updated_at')
            ->whereNotNull('hs_code')
            ->where('hs_code', '!=', '')
            ->get();

        foreach ($products as $product) {
            $hsCode = preg_replace('/[^0-9]/', '', $product->hs_code);
            if (empty($hsCode)) continue;

            $scheduleType = self::normalizeScheduleType($product->schedule_type);
            $rules = ScheduleEngine::resolveValidationRules($scheduleType, $product->default_tax_rate);

            if (isset($master[$hsCode])) {
                if (empty($master[$hsCode]['defaultUom']) && $product->uom) {
                    $master[$hsCode]['defaultUom'] = $product->uom;
                }
                if (empty($master[$hsCode]['sroNumber']) && $product->sro_reference) {
                    $master[$hsCode]['sroNumber'] = $product->sro_reference;
                }
                if (empty($master[$hsCode]['description']) || $master[$hsCode]['description'] === 'N/A') {
                    $master[$hsCode]['description'] = $product->name;
                }
                if (!$master[$hsCode]['created_at'] && $product->created_at) {
                    $master[$hsCode]['created_at'] = $product->created_at->toIso8601String();
                    $master[$hsCode]['updated_at'] = $product->updated_at?->toIso8601String();
                }
                $master[$hsCode]['source'] = 'ScheduleEngine+Products';
            } else {
                $master[$hsCode] = [
                    'hsCode' => $hsCode,
                    'description' => $product->name,
                    'pctCode' => $product->pct_code,
                    'scheduleType' => $scheduleType,
                    'taxRate' => (float) $product->default_tax_rate,
                    'defaultUom' => $product->uom,
                    'sroRequired' => $rules['requires_sro'],
                    'sroNumber' => $product->sro_reference,
                    'sroItemSerialNo' => null,
                    'mrpRequired' => $rules['requires_mrp'],
                    'source' => 'Products',
                    'created_at' => $product->created_at?->toIso8601String(),
                    'updated_at' => $product->updated_at?->toIso8601String(),
                ];
            }
        }

        $sroRules = DB::table('special_sro_rules')
            ->where('is_active', true)
            ->get();

        foreach ($sroRules as $sro) {
            $hsCode = preg_replace('/[^0-9]/', '', $sro->hs_code ?? '');
            if (empty($hsCode)) continue;

            if (isset($master[$hsCode])) {
                if (empty($master[$hsCode]['sroNumber'])) {
                    $master[$hsCode]['sroNumber'] = $sro->sro_number;
                }
                if (empty($master[$hsCode]['sroItemSerialNo'])) {
                    $master[$hsCode]['sroItemSerialNo'] = $sro->serial_no;
                }
            } else {
                $scheduleType = self::normalizeScheduleType($sro->schedule_type);
                $rules = ScheduleEngine::resolveValidationRules($scheduleType, $sro->concessionary_rate);
                $master[$hsCode] = [
                    'hsCode' => $hsCode,
                    'description' => $sro->description ?? 'N/A',
                    'pctCode' => null,
                    'scheduleType' => $scheduleType,
                    'taxRate' => (float) ($sro->concessionary_rate ?? 0),
                    'defaultUom' => null,
                    'sroRequired' => $rules['requires_sro'],
                    'sroNumber' => $sro->sro_number,
                    'sroItemSerialNo' => $sro->serial_no,
                    'mrpRequired' => $rules['requires_mrp'],
                    'source' => 'SRO Rules',
                    'created_at' => $sro->created_at,
                    'updated_at' => $sro->updated_at,
                ];
            }
        }

        $invoiceHsCodes = DB::table('invoice_items')
            ->select('hs_code', 'description', 'pct_code', 'schedule_type', 'tax_rate', 'default_uom', 'sro_schedule_no', 'serial_no', 'mrp')
            ->whereNotNull('hs_code')
            ->where('hs_code', '!=', '')
            ->groupBy('hs_code', 'description', 'pct_code', 'schedule_type', 'tax_rate', 'default_uom', 'sro_schedule_no', 'serial_no', 'mrp')
            ->get();

        foreach ($invoiceHsCodes as $item) {
            $hsCode = preg_replace('/[^0-9]/', '', $item->hs_code);
            if (empty($hsCode)) continue;

            if (isset($master[$hsCode])) {
                if (empty($master[$hsCode]['defaultUom']) && $item->default_uom) {
                    $master[$hsCode]['defaultUom'] = $item->default_uom;
                }
                if (empty($master[$hsCode]['sroNumber']) && $item->sro_schedule_no) {
                    $master[$hsCode]['sroNumber'] = $item->sro_schedule_no;
                }
                if (empty($master[$hsCode]['sroItemSerialNo']) && $item->serial_no) {
                    $master[$hsCode]['sroItemSerialNo'] = $item->serial_no;
                }
            } else {
                $scheduleType = $item->schedule_type ?? 'standard';
                $rules = ScheduleEngine::resolveValidationRules($scheduleType, $item->tax_rate);
                $master[$hsCode] = [
                    'hsCode' => $hsCode,
                    'description' => $item->description ?? 'N/A',
                    'pctCode' => $item->pct_code,
                    'scheduleType' => $scheduleType,
                    'taxRate' => (float) ($item->tax_rate ?? 0),
                    'defaultUom' => $item->default_uom,
                    'sroRequired' => $rules['requires_sro'],
                    'sroNumber' => $item->sro_schedule_no,
                    'sroItemSerialNo' => $item->serial_no,
                    'mrpRequired' => $rules['requires_mrp'],
                    'source' => 'InvoiceItems',
                    'created_at' => null,
                    'updated_at' => null,
                ];
            }
        }

        ksort($master);
        return array_values($master);
    }

    private static function normalizeScheduleType(?string $type): string
    {
        if (empty($type)) return 'standard';
        $normalized = strtolower(trim($type));
        $normalized = str_replace([' ', '-'], '_', $normalized);
        $map = [
            '3rd_schedule' => '3rd_schedule',
            '3rd schedule' => '3rd_schedule',
            'third_schedule' => '3rd_schedule',
            'standard' => 'standard',
            'standard_rate' => 'standard',
            'reduced' => 'reduced',
            'reduced_rate' => 'reduced',
            'exempt' => 'exempt',
            'zero_rated' => 'zero_rated',
            'zero rated' => 'zero_rated',
        ];
        return $map[$normalized] ?? 'standard';
    }

    private function getHsDescription(string $hsCode, string $scheduleType): string
    {
        $descriptions = [
            '15179090' => 'Margarine / Edible Oil Preparations',
            '25232900' => 'Portland Cement',
            '31021000' => 'Urea Fertilizer',
            '84713010' => 'Laptop / Notebook Computers',
            '87032100' => 'Motor Vehicles (1000-1500cc)',
            '02023000' => 'Boneless Meat (Bovine)',
            '04011000' => 'Fresh Milk (Fat ≤1%)',
            '10063090' => 'Semi/Wholly Milled Rice',
            '11010010' => 'Wheat Flour (Atta)',
            '30049099' => 'Medicaments (Packaged)',
            '48191000' => 'Corrugated Paper Cartons/Boxes',
            '61091000' => 'T-Shirts / Singlets (Cotton Knit)',
            '62034200' => 'Trousers / Shorts (Cotton Woven)',
            '85171100' => 'Smartphones / Cellular Phones',
            '27101990' => 'Other Petroleum Oils',
        ];
        return $descriptions[$hsCode] ?? 'N/A';
    }

    private function exportCsv(array $data)
    {
        $headers = ['hsCode', 'description', 'pctCode', 'scheduleType', 'taxRate', 'defaultUom', 'sroRequired', 'sroNumber', 'sroItemSerialNo', 'mrpRequired', 'source', 'created_at', 'updated_at'];
        $filename = 'HS_Master_Compliance_Export_' . date('Y-m-d_His') . '.csv';

        $callback = function () use ($data, $headers) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $headers);
            foreach ($data as $row) {
                fputcsv($file, [
                    $row['hsCode'],
                    $row['description'],
                    $row['pctCode'] ?? '',
                    $row['scheduleType'],
                    $row['taxRate'],
                    $row['defaultUom'] ?? '',
                    $row['sroRequired'] ? 'Yes' : 'No',
                    $row['sroNumber'] ?? '',
                    $row['sroItemSerialNo'] ?? '',
                    $row['mrpRequired'] ? 'Yes' : 'No',
                    $row['source'],
                    $row['created_at'] ?? '',
                    $row['updated_at'] ?? '',
                ]);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-cache, no-store',
        ]);
    }

    private function exportXlsx(array $data)
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('HS Master');

        $headers = ['HS Code', 'Description', 'PCT Code', 'Schedule Type', 'Tax Rate (%)', 'Default UOM', 'SRO Required', 'SRO Number', 'SRO Item Serial No', 'MRP Required', 'Source', 'Created At', 'Updated At'];
        foreach ($headers as $col => $header) {
            $sheet->setCellValue([$col + 1, 1], $header);
            $sheet->getStyle([$col + 1, 1])->getFont()->setBold(true);
        }

        $row = 2;
        foreach ($data as $record) {
            $sheet->setCellValue([1, $row], $record['hsCode']);
            $sheet->setCellValue([2, $row], $record['description']);
            $sheet->setCellValue([3, $row], $record['pctCode'] ?? '');
            $sheet->setCellValue([4, $row], $record['scheduleType']);
            $sheet->setCellValue([5, $row], $record['taxRate']);
            $sheet->setCellValue([6, $row], $record['defaultUom'] ?? '');
            $sheet->setCellValue([7, $row], $record['sroRequired'] ? 'Yes' : 'No');
            $sheet->setCellValue([8, $row], $record['sroNumber'] ?? '');
            $sheet->setCellValue([9, $row], $record['sroItemSerialNo'] ?? '');
            $sheet->setCellValue([10, $row], $record['mrpRequired'] ? 'Yes' : 'No');
            $sheet->setCellValue([11, $row], $record['source']);
            $sheet->setCellValue([12, $row], $record['created_at'] ?? '');
            $sheet->setCellValue([13, $row], $record['updated_at'] ?? '');
            $row++;
        }

        foreach (range(1, 13) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }

        $filename = 'HS_Master_Compliance_Export_' . date('Y-m-d_His') . '.xlsx';
        $tempFile = tempnam(sys_get_temp_dir(), 'hs_export_') . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempFile);

        return response()->download($tempFile, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-cache, no-store',
        ])->deleteFileAfterSend(true);
    }
}
