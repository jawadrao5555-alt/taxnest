<?php

namespace Tests\Feature;

use Tests\TestCase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Http\Controllers\PosBiometricController;

/**
 * Biometric .xlsx Import — parseExcel path (Task 274 / Aug 2026).
 *
 * PhpSpreadsheet (^5.4, already in vendor) powers parseExcel().
 * Three focused cases:
 *   1. Standard column names + blank-row skip + numeric status codes.
 *   2. ZKTeco aliased headers ("Employee No", "Punch Date", "In/Out" …)
 *      normalised by normalizeHeader().
 *   3. Header-only xlsx → empty row set (processImport returns bio_import_empty error).
 *
 * Pattern: no HTTP / no auth — parseExcel is pure file→array logic;
 * reflection is the right tool here (same approach used by other POS
 * private-method tests in this suite).
 */
class BiometricXlsxImportTest extends TestCase
{
    // ── helpers ───────────────────────────────────────────────────────────

    private function callParseExcel(string $path): array
    {
        $controller = new PosBiometricController();
        $method     = new \ReflectionMethod($controller, 'parseExcel');
        $method->setAccessible(true);
        return $method->invoke($controller, $path);
    }

    private function saveXlsx(array $data, string $suffix = ''): string
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray($data);
        $path = sys_get_temp_dir() . '/bio_test' . $suffix . '_' . getmypid() . '.xlsx';
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($path);
        return $path;
    }

    // ── tests ─────────────────────────────────────────────────────────────

    /**
     * Standard column names: pin / name / date / time / type.
     * Three data rows + one blank row (should be skipped).
     * Covers check_in, check_out, and numeric status codes.
     */
    public function test_standard_headers_parse_three_rows_and_skip_blank(): void
    {
        $path = $this->saveXlsx([
            ['pin', 'name',       'date',         'time',  'type'],
            ['42',  'Ali Hassan', '2026-08-04',   '09:30', 'In'],
            ['42',  'Ali Hassan', '2026-08-04',   '18:00', 'Out'],
            ['7',   'Sara Ahmed', '2026-08-04',   '08:45', '0'],
            ['',    '',           '',             '',      ''],   // blank — must be skipped
        ]);

        try {
            $rows = $this->callParseExcel($path);
        } finally {
            @unlink($path);
        }

        $this->assertCount(3, $rows, 'Blank row must be skipped; 3 data rows expected');

        // Row 0 — check_in
        $this->assertSame('42',         (string) $rows[0]['pin'],  'pin row 0');
        $this->assertSame('2026-08-04', (string) $rows[0]['date'], 'date row 0');
        $this->assertSame('09:30',      (string) $rows[0]['time'], 'time row 0');
        $this->assertSame('In',         (string) $rows[0]['type'], 'type row 0');

        // Row 1 — check_out
        $this->assertSame('42',  (string) $rows[1]['pin'],  'pin row 1');
        $this->assertSame('Out', (string) $rows[1]['type'], 'type row 1');

        // Row 2 — numeric status code (ZKTeco raw)
        $this->assertSame('7', (string) $rows[2]['pin'],  'pin row 2');
        $this->assertSame('0', (string) $rows[2]['type'], 'numeric status row 2');
    }

    /**
     * ZKTeco / eSSL aliased headers are normalised by normalizeHeader():
     *   "Employee No" → pin
     *   "Punch Date"  → date
     *   "Punch Time"  → time
     *   "In/Out"      → type
     */
    public function test_aliased_zkteco_headers_are_normalised(): void
    {
        $path = $this->saveXlsx([
            ['Employee No', 'Employee Name', 'Punch Date', 'Punch Time', 'In/Out'],
            ['101',         'Zain Ul Abdin', '2026-08-05', '10:00',      'C/In'],
        ], '_alias');

        try {
            $rows = $this->callParseExcel($path);
        } finally {
            @unlink($path);
        }

        $this->assertCount(1, $rows);
        $this->assertArrayHasKey('pin',  $rows[0], '"Employee No" must normalise → "pin"');
        $this->assertArrayHasKey('date', $rows[0], '"Punch Date"  must normalise → "date"');
        $this->assertArrayHasKey('time', $rows[0], '"Punch Time"  must normalise → "time"');
        $this->assertSame('101',         (string) $rows[0]['pin'],  'pin value');
        $this->assertSame('2026-08-05',  (string) $rows[0]['date'], 'date value');
        $this->assertSame('10:00',       (string) $rows[0]['time'], 'time value');
    }

    /**
     * Header-only xlsx (no data rows) → empty array.
     * processImport() then returns a bio_import_empty validation error — this
     * guards against a false-success on empty exports.
     */
    public function test_header_only_xlsx_returns_empty_array(): void
    {
        $path = $this->saveXlsx([
            ['pin', 'date', 'time', 'type'],
        ], '_empty');

        try {
            $rows = $this->callParseExcel($path);
        } finally {
            @unlink($path);
        }

        $this->assertSame([], $rows, 'Header-only xlsx must yield an empty row set');
    }
}
