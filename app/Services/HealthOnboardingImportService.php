<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\HealthAccount;
use App\Models\HealthBatchMovement;
use App\Models\HealthDepartment;
use App\Models\HealthDoctor;
use App\Models\HealthMedicine;
use App\Models\HealthMedicineBatch;
use App\Models\HealthPatient;
use App\Models\HealthProcedure;
use App\Models\HealthStaffProfile;
use App\Models\Supplier;
use App\Models\User;
use App\Support\NestErps;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Hospital setup by spreadsheet (Task 1555 — pilot data onboarding).
 *
 * A hospital does not arrive empty. It arrives with 14 departments, 40
 * consultants, 300 staff, 4,000 medicines, a shelf full of stock and a trial
 * balance — and typing all of that into forms is the reason a pilot slips by a
 * month. This is the controlled way to carry it in.
 *
 * THREE RULES THIS FILE EXISTS TO HOLD
 * ─────────────────────────────────────────────────────────────────────────────
 *  1. NOTHING IS WRITTEN THAT WAS NOT SHOWN FIRST. Upload parses and validates
 *     and shows the hospital exactly what will happen to every row. The commit
 *     re-reads the SAME stored file and re-validates it, so what lands is what
 *     was reviewed — not a browser-held payload that could be edited in between.
 *
 *  2. A BAD ROW NEVER STOPS A GOOD ONE, AND NEVER SNEAKS THROUGH. Rows are
 *     validated one by one; the valid ones import, the invalid ones come back
 *     named with the reason. An all-or-nothing import of 4,000 medicines fails
 *     on row 3,999 and wastes the afternoon; a silent skip loses stock nobody
 *     notices until it will not dispense.
 *
 *  3. RE-RUNNING IS SAFE. Every dataset has a natural key (department code,
 *     doctor name + department, MRN, medicine code…). A second upload of the
 *     same file UPDATES rather than duplicating, because the hospital WILL
 *     re-upload after fixing four rows and must not end up with two of
 *     everything else.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 *  - It does not invent identifiers a hospital did not supply beyond the
 *    panel's own numbering (an MRN left blank is allocated, a medicine code is
 *    not).
 *  - It does not import clinical history. Old visits, prescriptions and
 *    diagnoses stay in the old system; carrying them in unmapped is exactly the
 *    "undocumented legacy data" the pilot scope excludes.
 *  - Column headers are ENGLISH and are never translated. The header row is a
 *    machine contract: an Urdu-locale download that could not be re-uploaded
 *    would be a trap, not a translation.
 */
final class HealthOnboardingImportService
{
    /** Where an uploaded workbook waits between preview and commit. */
    public const DISK_DIRECTORY = 'health-imports';

    /** Rows above this are refused outright rather than half-processed. */
    public const MAX_ROWS = 5000;

    /** How many rows the preview screen renders. */
    public const PREVIEW_ROWS = 200;

    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_ERROR = 'error';

    /**
     * The eight sheets a hospital fills in, in the order they must be filled.
     *
     * ORDER MATTERS AND IS NOT COSMETIC: doctors reference departments, staff
     * reference departments, opening stock references medicines. Importing them
     * out of order does not corrupt anything — the reference simply comes back
     * as "not found" — but it wastes a round trip, so the screen numbers them.
     *
     * `module` is the healthcare module that must be ON for the sheet to be
     * offered at all. A hospital that never bought the pharmacy module is not
     * shown a medicine catalogue template.
     */
    public const DATASETS = [
        'departments' => [
            'module' => null,
            'columns' => [
                'name'        => ['required' => true,  'type' => 'string', 'max' => 190, 'sample' => 'Outpatient Department'],
                'code'        => ['required' => false, 'type' => 'string', 'max' => 32,  'text' => true, 'sample' => 'OPD'],
                'type'        => ['required' => false, 'type' => 'string', 'max' => 20,  'sample' => 'opd'],
                'branch'      => ['required' => false, 'type' => 'string', 'max' => 190, 'sample' => ''],
                'description' => ['required' => false, 'type' => 'string', 'max' => 500, 'sample' => 'General outpatient clinics'],
            ],
        ],
        'services' => [
            'module' => null,
            'columns' => [
                'name'               => ['required' => true,  'type' => 'string',  'max' => 190, 'sample' => 'Appendectomy'],
                'code'               => ['required' => false, 'type' => 'string',  'max' => 40,  'text' => true, 'sample' => 'SRV-0001'],
                'department'         => ['required' => false, 'type' => 'string',  'max' => 190, 'sample' => 'Surgery'],
                'category'           => ['required' => false, 'type' => 'string',  'max' => 80,  'sample' => 'General surgery'],
                'base_price'         => ['required' => true,  'type' => 'decimal', 'sample' => 45000],
                'is_package'         => ['required' => false, 'type' => 'bool',    'sample' => 'no'],
                'package_price'      => ['required' => false, 'type' => 'decimal', 'sample' => ''],
                'default_anaesthesia'=> ['required' => false, 'type' => 'string',  'max' => 20,  'sample' => 'general'],
                'estimated_minutes'  => ['required' => false, 'type' => 'int',     'max' => 1440, 'sample' => 60],
                'description'        => ['required' => false, 'type' => 'string',  'max' => 500, 'sample' => ''],
            ],
        ],
        'doctors' => [
            'module' => 'opd',
            'columns' => [
                'name'             => ['required' => true,  'type' => 'string',  'max' => 190, 'sample' => 'Dr. Ayesha Khan'],
                'department'       => ['required' => false, 'type' => 'string',  'max' => 190, 'sample' => 'Outpatient Department'],
                'specialty'        => ['required' => false, 'type' => 'string',  'max' => 120, 'sample' => 'Cardiology'],
                'qualification'    => ['required' => false, 'type' => 'string',  'max' => 200, 'sample' => 'MBBS, FCPS'],
                'registration_no'  => ['required' => false, 'type' => 'string',  'max' => 60,  'text' => true, 'sample' => 'PMC-12345'],
                'phone'            => ['required' => false, 'type' => 'string',  'max' => 32,  'text' => true, 'sample' => '03001234567'],
                'email'            => ['required' => false, 'type' => 'email',   'max' => 190, 'sample' => ''],
                'gender'           => ['required' => false, 'type' => 'string',  'max' => 10,  'sample' => 'female'],
                'room'             => ['required' => false, 'type' => 'string',  'max' => 60,  'text' => true, 'sample' => '12'],
                'consultation_fee' => ['required' => false, 'type' => 'decimal', 'sample' => 2000],
                'follow_up_fee'    => ['required' => false, 'type' => 'decimal', 'sample' => 1000],
                'follow_up_days'   => ['required' => false, 'type' => 'int',     'max' => 365, 'sample' => 14],
                'slot_minutes'     => ['required' => false, 'type' => 'int',     'max' => 240, 'sample' => 15],
                'branch'           => ['required' => false, 'type' => 'string',  'max' => 190, 'sample' => ''],
            ],
        ],
        'staff' => [
            'module' => 'hr',
            'columns' => [
                'name'               => ['required' => true,  'type' => 'string',  'max' => 190, 'sample' => 'Bilal Ahmed'],
                'email'              => ['required' => true,  'type' => 'email',   'max' => 190, 'sample' => 'bilal@example.com'],
                'health_role'        => ['required' => true,  'type' => 'string',  'max' => 40,  'sample' => 'health_receptionist'],
                'phone'              => ['required' => false, 'type' => 'string',  'max' => 32,  'text' => true, 'sample' => '03001234567'],
                'department'         => ['required' => false, 'type' => 'string',  'max' => 190, 'sample' => 'Outpatient Department'],
                'branch'             => ['required' => false, 'type' => 'string',  'max' => 190, 'sample' => ''],
                'employee_code'      => ['required' => false, 'type' => 'string',  'max' => 32,  'text' => true, 'sample' => 'EMP-001'],
                'designation'        => ['required' => false, 'type' => 'string',  'max' => 120, 'sample' => 'Front Desk Officer'],
                'employment_type'    => ['required' => false, 'type' => 'string',  'max' => 20,  'sample' => 'permanent'],
                'joined_on'          => ['required' => false, 'type' => 'date',    'sample' => '2026-01-15'],
                'cnic'               => ['required' => false, 'type' => 'string',  'max' => 20,  'text' => true, 'sample' => '3520112345671'],
                'basic_salary'       => ['required' => false, 'type' => 'decimal', 'sample' => 45000],
            ],
        ],
        'patients' => [
            'module' => null,
            'columns' => [
                'mrn'           => ['required' => true, 'type' => 'string', 'max' => 32,  'text' => true, 'sample' => ''],
                'name'          => ['required' => true,  'type' => 'string', 'max' => 190, 'sample' => 'Fatima Bibi'],
                'guardian_name' => ['required' => false, 'type' => 'string', 'max' => 190, 'sample' => 'Muhammad Aslam'],
                'gender'        => ['required' => false, 'type' => 'string', 'max' => 10,  'sample' => 'female'],
                'age_years'     => ['required' => false, 'type' => 'int',    'max' => 130, 'sample' => 34],
                'date_of_birth' => ['required' => false, 'type' => 'date',   'sample' => ''],
                'phone'         => ['required' => false, 'type' => 'string', 'max' => 32,  'text' => true, 'sample' => '03001234567'],
                'cnic'          => ['required' => false, 'type' => 'string', 'max' => 20,  'text' => true, 'sample' => ''],
                'address'       => ['required' => false, 'type' => 'string', 'max' => 500, 'sample' => ''],
                'city'          => ['required' => false, 'type' => 'string', 'max' => 100, 'sample' => 'Lahore'],
                'blood_group'   => ['required' => false, 'type' => 'string', 'max' => 8,   'sample' => 'O+'],
                'branch'        => ['required' => false, 'type' => 'string', 'max' => 190, 'sample' => ''],
            ],
        ],
        'medicines' => [
            'module' => 'pharmacy',
            'columns' => [
                'name'                  => ['required' => true,  'type' => 'string',  'max' => 190, 'sample' => 'Panadol 500mg'],
                'generic_name'          => ['required' => false, 'type' => 'string',  'max' => 190, 'sample' => 'Paracetamol'],
                'strength'              => ['required' => false, 'type' => 'string',  'max' => 64,  'text' => true, 'sample' => '500mg'],
                'form'                  => ['required' => false, 'type' => 'string',  'max' => 24,  'sample' => 'tablet'],
                'manufacturer'          => ['required' => false, 'type' => 'string',  'max' => 190, 'sample' => 'GSK'],
                'category'              => ['required' => false, 'type' => 'string',  'max' => 120, 'sample' => 'Analgesic'],
                'code'                  => ['required' => false, 'type' => 'string',  'max' => 64,  'text' => true, 'sample' => 'MED-0001'],
                'barcode'               => ['required' => false, 'type' => 'string',  'max' => 64,  'text' => true, 'sample' => ''],
                'unit_uom'              => ['required' => false, 'type' => 'string',  'max' => 24,  'sample' => 'tablet'],
                'pack_uom'              => ['required' => false, 'type' => 'string',  'max' => 24,  'sample' => 'strip'],
                'pack_size'             => ['required' => false, 'type' => 'decimal', 'sample' => 10],
                'purchase_price'        => ['required' => false, 'type' => 'decimal', 'sample' => 18.50],
                'sale_price'            => ['required' => false, 'type' => 'decimal', 'sample' => 25],
                'tax_rate'              => ['required' => false, 'type' => 'decimal', 'max' => 100, 'sample' => ''],
                'requires_prescription' => ['required' => false, 'type' => 'bool',    'sample' => 'no'],
                'reorder_level'         => ['required' => false, 'type' => 'decimal', 'sample' => 100],
            ],
        ],
        'opening_stock' => [
            'module' => 'pharmacy',
            'columns' => [
                'medicine'    => ['required' => true,  'type' => 'string',  'max' => 190, 'text' => true, 'sample' => 'MED-0001'],
                'batch_no'    => ['required' => false, 'type' => 'string',  'max' => 64,  'text' => true, 'sample' => 'B-2401'],
                'expiry_date' => ['required' => false, 'type' => 'date',    'sample' => '2028-06-30'],
                'quantity'    => ['required' => true,  'type' => 'decimal', 'sample' => 480],
                'cost_price'  => ['required' => false, 'type' => 'decimal', 'sample' => 18.50],
                'sale_price'  => ['required' => false, 'type' => 'decimal', 'sample' => 25],
                'branch'      => ['required' => false, 'type' => 'string',  'max' => 190, 'sample' => ''],
            ],
        ],
        'suppliers' => [
            'module' => 'pharmacy',
            'columns' => [
                'name'                 => ['required' => true,  'type' => 'string',  'max' => 190, 'sample' => 'City Pharma Distributors'],
                'phone'                => ['required' => false, 'type' => 'string',  'max' => 50,  'text' => true, 'sample' => '04235000000'],
                'email'                => ['required' => false, 'type' => 'email',   'max' => 190, 'sample' => ''],
                'contact_person'       => ['required' => false, 'type' => 'string',  'max' => 190, 'sample' => 'Imran Sheikh'],
                'ntn'                  => ['required' => false, 'type' => 'string',  'max' => 50,  'text' => true, 'sample' => ''],
                'address'              => ['required' => false, 'type' => 'string',  'max' => 190, 'sample' => ''],
                'city'                 => ['required' => false, 'type' => 'string',  'max' => 100, 'sample' => 'Lahore'],
                'opening_balance'      => ['required' => false, 'type' => 'decimal', 'sample' => 125000],
                'opening_balance_date' => ['required' => false, 'type' => 'date',    'sample' => '2026-07-01'],
            ],
        ],
        'opening_accounts' => [
            'module' => 'accounts',
            'columns' => [
                'code'                 => ['required' => true,  'type' => 'string',  'max' => 20,  'text' => true, 'sample' => '1500'],
                'name'                 => ['required' => true,  'type' => 'string',  'max' => 190, 'sample' => 'Equipment'],
                'type'                 => ['required' => true,  'type' => 'string',  'max' => 12,  'sample' => 'asset'],
                'subtype'              => ['required' => false, 'type' => 'string',  'max' => 32,  'sample' => 'fixed_asset'],
                'opening_balance'      => ['required' => false, 'type' => 'decimal', 'sample' => 850000],
                'opening_balance_date' => ['required' => false, 'type' => 'date',    'sample' => '2026-07-01'],
            ],
        ],
    ];

    /** Department types the panel understands. */
    private const DEPARTMENT_TYPES = ['opd', 'ipd', 'lab', 'pharmacy', 'radiology', 'admin', 'other'];

    private const GENDERS = ['male', 'female', 'other'];

    private const EMPLOYMENT_TYPES = ['permanent', 'contract', 'visiting', 'locum', 'intern', 'daily_wage'];

    /* ───────────────────────────── Dataset registry ───────────────────────── */

    /**
     * The sheets this organisation may actually fill in.
     *
     * Module-filtered, for the same reason the navigation is: offering a
     * hospital a medicine template it can never import is a lie about what it
     * bought.
     *
     * @return array<int, string>
     */
    public static function datasetsFor(?Company $company): array
    {
        $enabled = HealthModuleService::enabled($company);

        return array_values(array_filter(
            array_keys(self::DATASETS),
            function (string $key) use ($enabled) {
                $module = self::DATASETS[$key]['module'];

                return $module === null || in_array($module, $enabled, true);
            }
        ));
    }

    public static function isDataset(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::DATASETS);
    }

    /** @return array<string, mixed> */
    public static function spec(string $key): array
    {
        return self::DATASETS[$key];
    }

    /** @return array<int, string> */
    public static function headers(string $key): array
    {
        return array_keys(self::DATASETS[$key]['columns']);
    }

    public static function labelKey(string $key): string
    {
        return 'health.import_ds_' . $key;
    }

    public static function descriptionKey(string $key): string
    {
        return 'health.import_ds_' . $key . '_desc';
    }

    /* ──────────────────────────────── Template ────────────────────────────── */

    /**
     * Build the blank workbook for a dataset and return its path on disk.
     *
     * Two sheets: the DATA sheet (header row + one sample row, which the
     * importer ignores) and a GUIDE sheet carrying the product identity and the
     * per-column rules. The guide is a separate sheet rather than banner rows
     * above the headers because a header row that is not row 1 is the single
     * most common reason a re-upload parses as garbage.
     */
    public static function buildTemplate(string $key, Company $company): string
    {
        $spec = self::spec($key);
        $columns = $spec['columns'];

        $book = new Spreadsheet();
        $book->getProperties()
            ->setCreator(NestErps::label(NestErps::HEALTH))
            ->setTitle(NestErps::label(NestErps::HEALTH) . ' — ' . $key)
            ->setCompany((string) ($company->name ?? ''));

        $data = $book->getActiveSheet();
        $data->setTitle('data');

        $index = 1;
        foreach ($columns as $name => $rules) {
            $letter = Coordinate::stringFromColumnIndex($index);

            $data->setCellValue($letter . '1', $name);
            $data->getStyle($letter . '1')->getFont()->setBold(true);
            $data->getStyle($letter . '1')->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB(($rules['required'] ?? false) ? 'CDE9E4' : 'EFEFEF');
            $data->getColumnDimension($letter)->setWidth(max(14, min(32, strlen($name) + 8)));

            /*
             * Text-typed columns are forced to string. A CNIC, a barcode or a
             * batch number that Excel decides is a number comes back as
             * 3.52011E+12 and matches nothing — the same trap the POS product
             * importer already learned.
             */
            if ($rules['text'] ?? false) {
                $data->getStyle($letter . ':' . $letter)
                    ->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            }

            $index++;
        }

        $data->getStyle('A1:' . $data->getHighestColumn() . '1')
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $data->freezePane('A2');

        /*
         * The data sheet ships EMPTY below the header. Examples used to sit in
         * row 2 with a note telling the hospital to delete them, which is a
         * promise made by prose and kept by nobody: an untouched template, or
         * one filled in from row 3 down, would import the example as a real
         * department, a real staff login, real stock. There is no marker to get
         * wrong now — anything under the header is the hospital's own data.
         * The examples live on the guide sheet, which is never parsed.
         */

        // ── Guide sheet ──
        $guide = $book->createSheet();
        $guide->setTitle('guide');
        $guide->getColumnDimension('A')->setWidth(28);
        $guide->getColumnDimension('B')->setWidth(14);
        $guide->getColumnDimension('C')->setWidth(70);
        $guide->getColumnDimension('D')->setWidth(26);

        $guide->setCellValue('A1', NestErps::label(NestErps::HEALTH));
        $guide->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $guide->setCellValue('A2', __('health.import_guide_sheet_for', ['sheet' => __(self::labelKey($key))]));
        $guide->setCellValue('A3', __('health.import_guide_start_row_two'));
        $guide->setCellValue('A4', __('health.import_guide_headers_fixed'));

        $guide->setCellValue('A6', __('health.import_col_column'));
        $guide->setCellValue('B6', __('health.import_col_required'));
        $guide->setCellValue('C6', __('health.import_col_rule'));
        $guide->setCellValue('D6', __('health.import_col_example'));
        $guide->getStyle('A6:D6')->getFont()->setBold(true);

        $row = 7;
        foreach ($columns as $name => $rules) {
            $guide->setCellValue('A' . $row, $name);
            $guide->setCellValue('B' . $row, ($rules['required'] ?? false) ? __('health.import_yes') : __('health.import_no'));
            $guide->setCellValue('C' . $row, self::columnRuleText($key, $name, $rules));
            $sample = (string) ($rules['sample'] ?? '');
            if ($sample !== '') {
                $guide->setCellValueExplicit('D' . $row, $sample, DataType::TYPE_STRING);
            }
            $row++;
        }

        $book->setActiveSheetIndex(0);

        $path = tempnam(sys_get_temp_dir(), 'health-tpl-');
        (new XlsxWriter($book))->save($path);
        $book->disconnectWorksheets();

        return $path;
    }

    /** Human wording for one column's rule, used on the guide sheet. */
    private static function columnRuleText(string $dataset, string $column, array $rules): string
    {
        $parts = [];

        $parts[] = match ($rules['type']) {
            'int', 'decimal' => __('health.import_rule_number'),
            'date' => __('health.import_rule_date'),
            'bool' => __('health.import_rule_bool'),
            'email' => __('health.import_rule_email'),
            default => __('health.import_rule_text', ['max' => (string) ($rules['max'] ?? 190)]),
        };

        $allowed = self::allowedValues($dataset, $column);
        if ($allowed !== null) {
            $parts[] = __('health.import_rule_one_of', ['values' => implode(', ', $allowed)]);
        }

        $note = self::columnNote($dataset, $column);
        if ($note !== null) {
            $parts[] = $note;
        }

        return implode(' ', $parts);
    }

    /** Closed value lists, or null when the column is free text. */
    private static function allowedValues(string $dataset, string $column): ?array
    {
        return match (true) {
            $dataset === 'departments' && $column === 'type' => self::DEPARTMENT_TYPES,
            $column === 'gender' => self::GENDERS,
            $dataset === 'staff' && $column === 'health_role' => array_values(array_filter(
                HealthAccessService::ROLES,
                fn ($r) => $r !== HealthAccessService::ROLE_OWNER
            )),
            $dataset === 'staff' && $column === 'employment_type' => self::EMPLOYMENT_TYPES,
            $dataset === 'services' && $column === 'default_anaesthesia' => HealthProcedure::ANAESTHESIA_TYPES,
            $dataset === 'medicines' && $column === 'form' => HealthMedicine::FORMS,
            $dataset === 'opening_accounts' && $column === 'type' => HealthAccount::TYPES,
            default => null,
        };
    }

    /** The one-line "why" a column needs, where it is not self-evident. */
    private static function columnNote(string $dataset, string $column): ?string
    {
        $key = 'health.import_note_' . $dataset . '_' . $column;
        $text = __($key);

        return $text === $key ? null : $text;
    }

    /* ───────────────────────────────── Parsing ────────────────────────────── */

    /**
     * Read a stored workbook into header-keyed rows.
     *
     * Unknown columns are dropped rather than refused: a hospital that added a
     * working note column to the sheet should not have the whole upload thrown
     * back at them.
     *
     * @return array{rows: array<int, array<string, mixed>>, missing: array<int, string>, error: ?string}
     */
    public static function parseFile(string $dataset, string $absolutePath): array
    {
        $expected = self::headers($dataset);

        try {
            $reader = IOFactory::createReaderForFile($absolutePath);
            $reader->setReadDataOnly(true);
            $book = $reader->load($absolutePath);
        } catch (\Throwable $e) {
            return ['rows' => [], 'missing' => [], 'error' => 'unreadable'];
        }

        $sheet = $book->getSheetByName('data') ?: $book->getSheet(0);
        $grid = $sheet->toArray(null, true, false, false);
        $book->disconnectWorksheets();

        if (empty($grid)) {
            return ['rows' => [], 'missing' => $expected, 'error' => 'empty'];
        }

        $header = array_map(
            fn ($v) => Str::of((string) $v)->trim()->lower()->replace(' ', '_')->value(),
            array_shift($grid)
        );

        $missing = array_values(array_diff($expected, $header));
        if (!empty(array_intersect($expected, $header)) === false) {
            return ['rows' => [], 'missing' => $missing, 'error' => 'headers'];
        }

        $rows = [];
        foreach ($grid as $index => $line) {
            $assoc = [];
            $blank = true;
            foreach ($header as $position => $name) {
                if ($name === '' || !in_array($name, $expected, true)) {
                    continue;
                }
                $value = $line[$position] ?? null;
                if (is_string($value)) {
                    $value = trim($value);
                }
                if ($value !== null && $value !== '') {
                    $blank = false;
                }
                $assoc[$name] = $value;
            }

            if ($blank) {
                continue;
            }

            // +2: row 1 is the header, and the grid is zero-based.
            $assoc['__row'] = $index + 2;
            $rows[] = $assoc;

            if (count($rows) > self::MAX_ROWS) {
                return ['rows' => $rows, 'missing' => $missing, 'error' => 'too_many'];
            }
        }

        return ['rows' => $rows, 'missing' => $missing, 'error' => null];
    }

    /* ──────────────────────────────── Validation ──────────────────────────── */

    /**
     * Decide, row by row, what would happen — without writing anything.
     *
     * The same function runs again inside commit(). That is the point: the
     * preview cannot promise one thing and the commit do another, because they
     * are literally the same decision made twice on the same stored file.
     *
     * @return array{rows: array<int, array<string,mixed>>, summary: array<string,int>}
     */
    public static function analyse(string $dataset, Company $company, array $rows): array
    {
        $companyId = (int) $company->id;
        $context = self::context($dataset, $companyId);
        $seen = [];

        $out = [];
        $summary = [self::ACTION_CREATE => 0, self::ACTION_UPDATE => 0, self::ACTION_ERROR => 0];

        foreach ($rows as $raw) {
            $line = (int) ($raw['__row'] ?? 0);
            $errors = [];
            $clean = self::castRow($dataset, $raw, $errors);

            if (empty($errors)) {
                self::validateRow($dataset, $companyId, $clean, $context, $errors);
            }

            $key = null;
            if (empty($errors)) {
                $key = self::naturalKey($dataset, $clean);
                if ($key !== null && isset($seen[$key])) {
                    $errors[] = __('health.import_err_duplicate_in_file', ['row' => (string) $seen[$key]]);
                } elseif ($key !== null) {
                    $seen[$key] = $line;
                }
            }

            $action = !empty($errors)
                ? self::ACTION_ERROR
                : (self::existingId($dataset, $companyId, $clean, $context) ? self::ACTION_UPDATE : self::ACTION_CREATE);

            $summary[$action]++;
            $out[] = [
                'row' => $line,
                'data' => $clean,
                'errors' => $errors,
                'action' => $action,
            ];
        }

        return ['rows' => $out, 'summary' => $summary];
    }

    /**
     * Lookups every row of a dataset needs, resolved once.
     *
     * Built per import rather than per row: a 4,000-medicine sheet that looks
     * its branch up 4,000 times spends the whole import in the database.
     */
    private static function context(string $dataset, int $companyId): array
    {
        $branches = [];
        if (Schema::hasTable('branches')) {
            foreach (Branch::withoutGlobalScopes()->where('company_id', $companyId)->get(['id', 'name', 'is_head_office']) as $branch) {
                $branches[self::slug($branch->name)] = (int) $branch->id;
            }
        }

        $context = [
            'branches' => $branches,
            'default_branch' => HealthPlatformService::defaultBranchId(Company::find($companyId)),
        ];

        if (in_array($dataset, ['services', 'doctors', 'staff'], true) && Schema::hasTable('health_departments')) {
            $departments = [];
            foreach (HealthDepartment::withoutGlobalScopes()->where('company_id', $companyId)->get(['id', 'name', 'code']) as $department) {
                $departments[self::slug($department->name)] = (int) $department->id;
                if ($department->code) {
                    $departments[self::slug($department->code)] = (int) $department->id;
                }
            }
            $context['departments'] = $departments;
        }

        if ($dataset === 'opening_stock' && Schema::hasTable('health_medicines')) {
            $medicines = [];
            foreach (HealthMedicine::withoutGlobalScopes()->where('company_id', $companyId)->get(['id', 'name', 'code', 'barcode']) as $medicine) {
                foreach ([$medicine->code, $medicine->barcode, $medicine->name] as $handle) {
                    if ($handle) {
                        $medicines[self::slug($handle)] ??= (int) $medicine->id;
                    }
                }
            }
            $context['medicines'] = $medicines;
        }

        return $context;
    }

    /** Cast every cell to the column's declared type, collecting type errors. */
    private static function castRow(string $dataset, array $raw, array &$errors): array
    {
        $clean = ['__row' => (int) ($raw['__row'] ?? 0)];

        foreach (self::spec($dataset)['columns'] as $name => $rules) {
            $value = $raw[$name] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }

            if ($value === null || $value === '') {
                if ($rules['required'] ?? false) {
                    $errors[] = __('health.import_err_required', ['column' => $name]);
                }
                $clean[$name] = null;
                continue;
            }

            switch ($rules['type']) {
                case 'int':
                    if (!is_numeric($value)) {
                        $errors[] = __('health.import_err_number', ['column' => $name]);
                        break;
                    }
                    $clean[$name] = (int) $value;
                    if (isset($rules['max']) && $clean[$name] > $rules['max']) {
                        $errors[] = __('health.import_err_max_number', ['column' => $name, 'max' => (string) $rules['max']]);
                    }
                    break;

                case 'decimal':
                    $normalised = is_string($value) ? str_replace([',', ' '], '', $value) : $value;
                    if (!is_numeric($normalised)) {
                        $errors[] = __('health.import_err_number', ['column' => $name]);
                        break;
                    }
                    $clean[$name] = round((float) $normalised, 3);
                    if (isset($rules['max']) && $clean[$name] > $rules['max']) {
                        $errors[] = __('health.import_err_max_number', ['column' => $name, 'max' => (string) $rules['max']]);
                    }
                    break;

                case 'date':
                    $date = self::toDate($value);
                    if ($date === null) {
                        $errors[] = __('health.import_err_date', ['column' => $name]);
                        break;
                    }
                    $clean[$name] = $date;
                    break;

                case 'bool':
                    $clean[$name] = in_array(
                        Str::lower((string) $value),
                        ['1', 'yes', 'y', 'true', 'haan', 'ha', 'ji'],
                        true
                    );
                    break;

                case 'email':
                    $value = (string) $value;
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $errors[] = __('health.import_err_email', ['column' => $name]);
                        break;
                    }
                    $clean[$name] = Str::lower($value);
                    break;

                default:
                    $value = (string) $value;
                    $max = (int) ($rules['max'] ?? 190);
                    if (mb_strlen($value) > $max) {
                        $errors[] = __('health.import_err_too_long', ['column' => $name, 'max' => (string) $max]);
                        break;
                    }
                    $clean[$name] = $value;
            }

            $allowed = self::allowedValues($dataset, $name);
            if ($allowed !== null && isset($clean[$name])) {
                $candidate = Str::lower((string) $clean[$name]);
                $match = null;
                foreach ($allowed as $option) {
                    if (Str::lower($option) === $candidate) {
                        $match = $option;
                        break;
                    }
                }
                if ($match === null) {
                    $errors[] = __('health.import_err_one_of', [
                        'column' => $name,
                        'values' => implode(', ', $allowed),
                    ]);
                } else {
                    $clean[$name] = $match;
                }
            }
        }

        return $clean;
    }

    /**
     * Excel dates arrive as serial numbers, humans type them four ways.
     *
     * Day-first on purpose for slash dates: Pakistan writes 03/09/2026 for the
     * third of September, and reading it as March would silently back-date
     * every opening balance in the sheet.
     */
    private static function toDate($value): ?string
    {
        if (is_numeric($value)) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
            } catch (\Throwable $e) {
                return null;
            }
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        if (str_contains($text, '/') || str_contains($text, '.')) {
            $text = str_replace('.', '/', $text);
            $parts = explode('/', $text);
            if (count($parts) === 3 && strlen($parts[0]) <= 2) {
                $text = $parts[2] . '-' . str_pad($parts[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($parts[0], 2, '0', STR_PAD_LEFT);
            }
        }

        try {
            return Carbon::parse($text)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Cross-field and cross-table rules a single cell's type cannot express. */
    private static function validateRow(string $dataset, int $companyId, array &$clean, array $context, array &$errors): void
    {
        // Branch, on every dataset that carries one.
        if (array_key_exists('branch', $clean)) {
            $clean['branch_id'] = $context['default_branch'];
            if (!empty($clean['branch'])) {
                $resolved = $context['branches'][self::slug($clean['branch'])] ?? null;
                if ($resolved === null) {
                    $errors[] = __('health.import_err_branch_unknown', ['name' => (string) $clean['branch']]);
                } else {
                    $clean['branch_id'] = $resolved;
                }
            }
        }

        if (array_key_exists('department', $clean)) {
            $clean['health_department_id'] = null;
            if (!empty($clean['department'])) {
                $resolved = $context['departments'][self::slug($clean['department'])] ?? null;
                if ($resolved === null) {
                    $errors[] = __('health.import_err_department_unknown', ['name' => (string) $clean['department']]);
                } else {
                    $clean['health_department_id'] = $resolved;
                }
            }
        }

        switch ($dataset) {
            case 'departments':
                $clean['type'] = $clean['type'] ?: 'opd';
                break;

            case 'services':
                /*
                 * A package price is the all-in figure the hospital quoted the
                 * patient, so ticking "package" without giving one would post
                 * an operation at zero and lose the argument at the discharge
                 * counter silently.
                 */
                if (!empty($clean['is_package']) && (float) ($clean['package_price'] ?? 0) <= 0) {
                    $errors[] = __('health.import_err_package_price');
                }
                if ((float) ($clean['base_price'] ?? 0) < 0) {
                    $errors[] = __('health.import_err_price_negative');
                }
                break;

            case 'staff':
                if (($clean['health_role'] ?? null) === HealthAccessService::ROLE_OWNER) {
                    $errors[] = __('health.import_err_owner_role');
                }
                /*
                 * A login is an identity across the whole platform, so the
                 * uniqueness check is the platform's own scope rule — not a
                 * company-local one. Importing an address already used by a POS
                 * account would create a second identity for one person.
                 */
                $taken = User::withoutGlobalScopes()
                    ->where('email', $clean['email'])
                    ->where('company_id', '!=', $companyId)
                    ->exists();
                if ($taken) {
                    $errors[] = __('health.import_err_email_taken', ['email' => (string) $clean['email']]);
                }
                break;

            case 'patients':
                /*
                 * A patient row without a file number has no stable identity, so
                 * a second upload of the same sheet would register every one of
                 * those people again — duplicate files for the same patient is
                 * the single worst thing a hospital import can do. The desk can
                 * still register a walk-in without one; a bulk migration cannot.
                 */
                if (empty($clean['mrn'])) {
                    $errors[] = __('health.import_err_mrn_required');
                }
                if (empty($clean['age_years']) && empty($clean['date_of_birth'])) {
                    // Not an error: Pakistani reception often knows neither at
                    // registration. The panel already treats both as optional.
                    $clean['age_years'] = null;
                }
                break;

            case 'opening_stock':
                $medicineId = $context['medicines'][self::slug((string) $clean['medicine'])] ?? null;
                if ($medicineId === null) {
                    $errors[] = __('health.import_err_medicine_unknown', ['name' => (string) $clean['medicine']]);
                } else {
                    $clean['medicine_id'] = $medicineId;
                }
                if ((float) ($clean['quantity'] ?? 0) <= 0) {
                    $errors[] = __('health.import_err_quantity_positive');
                }
                if (!empty($clean['expiry_date']) && Carbon::parse($clean['expiry_date'])->isPast()) {
                    $errors[] = __('health.import_err_already_expired');
                }
                break;

            case 'opening_accounts':
                /*
                 * A system account is the engine's own anchor. Renaming one is
                 * allowed on the accounts screen; RETYPING it through a
                 * spreadsheet would silently move every posting that depends on
                 * it, so the import refuses the code outright.
                 */
                $system = HealthAccount::withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->where('code', $clean['code'])
                    ->where('is_system', true)
                    ->first();
                if ($system && $system->type !== $clean['type']) {
                    $errors[] = __('health.import_err_system_account', ['code' => (string) $clean['code']]);
                }
                break;
        }
    }

    /** The in-file uniqueness key, so one sheet cannot hold a row twice. */
    private static function naturalKey(string $dataset, array $clean): ?string
    {
        return match ($dataset) {
            'departments' => self::slug((string) ($clean['code'] ?: $clean['name'])),
            'services' => self::slug((string) ($clean['code'] ?: $clean['name'])),
            'doctors' => self::slug((string) $clean['name']) . '|' . ($clean['health_department_id'] ?? ''),
            'staff' => (string) $clean['email'],
            'patients' => self::slug((string) $clean['mrn']),
            'medicines' => self::slug((string) ($clean['code'] ?: ($clean['name'] . '|' . $clean['strength']))),
            'opening_stock' => ($clean['medicine_id'] ?? '') . '|' . self::slug((string) ($clean['batch_no'] ?? '')) . '|' . ($clean['expiry_date'] ?? '') . '|' . ($clean['branch_id'] ?? ''),
            'suppliers' => self::slug((string) $clean['name']),
            'opening_accounts' => self::slug((string) $clean['code']),
            default => null,
        };
    }

    /** The id this row would update, or null when it would create. */
    private static function existingId(string $dataset, int $companyId, array $clean, array $context): ?int
    {
        $id = match ($dataset) {
            'departments' => HealthDepartment::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->when($clean['code'], fn ($q) => $q->where('code', $clean['code']))
                ->when(!$clean['code'], fn ($q) => $q->whereRaw('LOWER(name) = ?', [Str::lower((string) $clean['name'])]))
                ->value('id'),

            'services' => HealthProcedure::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->when($clean['code'], fn ($q) => $q->where('code', $clean['code']))
                ->when(!$clean['code'], fn ($q) => $q->whereRaw('LOWER(name) = ?', [Str::lower((string) $clean['name'])]))
                ->value('id'),

            /*
             * Name AND department, with a blank department meaning EXACTLY
             * that — not "any department".
             *
             * Skipping the constraint when the sheet leaves the column empty
             * quietly turns the key into the name alone: a row for "Dr Ahmed"
             * with no department would take over the "Dr Ahmed" already sitting
             * in Cardiology and rewrite his fee, his share and his schedule.
             * Two doctors sharing a name is ordinary in a hospital.
             */
            'doctors' => HealthDoctor::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereRaw('LOWER(name) = ?', [Str::lower((string) $clean['name'])])
                ->when(
                    $clean['health_department_id'] ?? null,
                    fn ($q, $d) => $q->where('health_department_id', $d),
                    fn ($q) => $q->whereNull('health_department_id')
                )
                ->value('id'),

            'staff' => User::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('email', $clean['email'])
                ->value('id'),

            'patients' => HealthPatient::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('mrn', $clean['mrn'])
                ->value('id'),

            'medicines' => HealthMedicine::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->when($clean['code'], fn ($q) => $q->where('code', $clean['code']))
                ->when(!$clean['code'], fn ($q) => $q
                    ->whereRaw('LOWER(name) = ?', [Str::lower((string) $clean['name'])])
                    ->where('strength', $clean['strength']))
                ->value('id'),

            // Opening stock DOES update. A hospital counting its shelves gets
            // a figure wrong and uploads a corrected sheet; if that re-upload
            // added to what the first one wrote, the correction would make the
            // error worse. The lot is matched on the same natural key the sheet
            // is de-duplicated by, and the writer restates it to the counted
            // figure rather than receiving it again.
            'opening_stock' => self::openingStockBatch($companyId, $clean)?->id,

            'suppliers' => Supplier::query()
                ->where('company_id', $companyId)
                ->whereRaw('LOWER(name) = ?', [Str::lower((string) $clean['name'])])
                ->value('id'),

            'opening_accounts' => HealthAccount::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('code', $clean['code'])
                ->value('id'),

            default => null,
        };

        return $id ? (int) $id : null;
    }

    /* ───────────────────────────────── Commit ─────────────────────────────── */

    /**
     * Write the valid rows. Invalid rows are reported, never guessed at.
     *
     * Each row commits in its OWN transaction rather than one transaction
     * around the whole sheet: a 4,000-row catalogue that rolls back entirely
     * because row 3,999 hit a unique-index race costs the hospital the whole
     * afternoon, and every row here is independent of the others.
     *
     * @return array{created:int, updated:int, failed:int, messages: array<int,string>, credentials: array<int,array{name:string,email:string,password:string}>}
     */
    public static function commit(string $dataset, Company $company, array $analysed, ?User $actor): array
    {
        $companyId = (int) $company->id;
        $created = 0;
        $updated = 0;
        $failed = 0;
        $messages = [];
        $credentials = [];

        foreach ($analysed as $entry) {
            if ($entry['action'] === self::ACTION_ERROR) {
                $failed++;
                continue;
            }

            try {
                $result = DB::transaction(fn () => self::writeRow($dataset, $company, $entry['data'], $actor));
            } catch (\Throwable $e) {
                $failed++;
                $messages[] = __('health.import_err_row_failed', [
                    'row' => (string) $entry['row'],
                    'reason' => $e->getMessage(),
                ]);
                continue;
            }

            if (($result['created'] ?? false) === true) {
                $created++;
            } else {
                $updated++;
            }

            if (isset($result['credential'])) {
                $credentials[] = $result['credential'];
            }
        }

        // Caches keyed on what we just rewrote.
        HealthChartOfAccountsService::flush();
        HealthPharmacyService::forget();
        HealthScopeService::forget();

        return [
            'created' => $created,
            'updated' => $updated,
            'failed' => $failed,
            'messages' => $messages,
            'credentials' => $credentials,
        ];
    }

    /** @return array{created: bool, credential?: array{name:string,email:string,password:string}} */
    private static function writeRow(string $dataset, Company $company, array $data, ?User $actor): array
    {
        $companyId = (int) $company->id;
        $context = self::context($dataset, $companyId);
        $existing = self::existingId($dataset, $companyId, $data, $context);

        return match ($dataset) {
            'departments' => self::writeDepartment($companyId, $data, $existing),
            'services' => self::writeService($companyId, $data, $existing),
            'doctors' => self::writeDoctor($companyId, $data, $existing),
            'staff' => self::writeStaff($company, $data, $existing, $actor),
            'patients' => self::writePatient($companyId, $data, $existing, $actor),
            'medicines' => self::writeMedicine($companyId, $data, $existing, $actor),
            'opening_stock' => self::writeOpeningStock($companyId, $data, $actor),
            'suppliers' => self::writeSupplier($companyId, $data, $existing),
            'opening_accounts' => self::writeAccount($companyId, $data, $existing, $actor),
            default => ['created' => false],
        };
    }

    private static function writeDepartment(int $companyId, array $data, ?int $existing): array
    {
        $attributes = [
            'company_id' => $companyId,
            'branch_id' => $data['branch_id'] ?? null,
            'name' => $data['name'],
            'code' => $data['code'] ?: null,
            'type' => $data['type'] ?: 'opd',
            'description' => $data['description'] ?: null,
            'is_active' => true,
        ];

        if ($existing) {
            HealthDepartment::withoutGlobalScopes()->whereKey($existing)->update(
                array_diff_key($attributes, ['company_id' => 1])
            );

            return ['created' => false];
        }

        HealthDepartment::withoutGlobalScopes()->create($attributes);

        return ['created' => true];
    }

    private static function writeService(int $companyId, array $data, ?int $existing): array
    {
        $isPackage = (bool) ($data['is_package'] ?? false);

        $attributes = [
            'health_department_id' => $data['health_department_id'] ?? null,
            'name' => $data['name'],
            'code' => $data['code'] ?: null,
            'category' => $data['category'] ?: null,
            'description' => $data['description'] ?: null,
            'base_price' => (float) ($data['base_price'] ?? 0),
            'is_package' => $isPackage,
            'package_price' => $isPackage ? (float) ($data['package_price'] ?? 0) : null,
            'default_anaesthesia' => $data['default_anaesthesia'] ?: null,
            'estimated_minutes' => $data['estimated_minutes'] ?: null,
        ];

        if ($existing) {
            $procedure = HealthProcedure::withoutGlobalScopes()->findOrFail($existing);
            // A re-import corrects the catalogue; it must not silently revive a
            // service the hospital deliberately retired.
            $procedure->fill($attributes)->save();

            return ['created' => false];
        }

        HealthProcedure::withoutGlobalScopes()->create($attributes + [
            'company_id' => $companyId,
            'is_active' => true,
        ]);

        return ['created' => true];
    }

    private static function writeDoctor(int $companyId, array $data, ?int $existing): array
    {
        $attributes = [
            'company_id' => $companyId,
            'branch_id' => $data['branch_id'] ?? null,
            'health_department_id' => $data['health_department_id'] ?? null,
            'name' => $data['name'],
            'specialty' => $data['specialty'],
            'qualification' => $data['qualification'],
            'registration_no' => $data['registration_no'],
            'phone' => $data['phone'],
            'email' => $data['email'],
            'gender' => $data['gender'],
            'room' => $data['room'],
            'consultation_fee' => (float) ($data['consultation_fee'] ?? 0),
            'follow_up_fee' => (float) ($data['follow_up_fee'] ?? 0),
            'follow_up_days' => (int) ($data['follow_up_days'] ?? 0),
            'slot_minutes' => (int) ($data['slot_minutes'] ?? 0) ?: 15,
            'is_active' => true,
        ];

        if ($existing) {
            HealthDoctor::withoutGlobalScopes()->whereKey($existing)->update(
                array_diff_key($attributes, ['company_id' => 1])
            );

            return ['created' => false];
        }

        HealthDoctor::withoutGlobalScopes()->create($attributes);

        return ['created' => true];
    }

    /**
     * A staff row is a LOGIN plus an HR profile.
     *
     * The temporary password is generated when the sheet leaves it blank, and
     * handed back to the caller ONCE so the screen can show it. It is never
     * stored in clear and never written back into the workbook: a spreadsheet
     * of live passwords sitting in a hospital's downloads folder is a breach
     * waiting for its first laptop theft.
     */
    private static function writeStaff(Company $company, array $data, ?int $existing, ?User $actor): array
    {
        $companyId = (int) $company->id;
        $credential = null;

        if ($existing) {
            $user = User::withoutGlobalScopes()->findOrFail($existing);
        } else {
            $allowance = PlanLimitService::canAddUser($companyId);
            if (($allowance['allowed'] ?? false) !== true) {
                throw new \RuntimeException($allowance['reason'] ?? __('health.team_limit_reached'));
            }

            /*
             * The password is generated HERE and nowhere else. A sheet column
             * for it would put a readable credential in an uploaded file that
             * sits on disk between preview and commit, and in whatever copy of
             * that file the hospital keeps in its own email. Generated once,
             * hashed immediately, shown to the owner once at the end of the
             * commit, and never persisted in readable form.
             */
            $password = Str::password(12, true, true, false, false);
            $user = new User();
            $user->company_id = $companyId;
            $user->role = 'employee';
            $user->password = Hash::make($password);
            $credential = ['name' => (string) $data['name'], 'email' => (string) $data['email'], 'password' => $password];
        }

        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->phone = $data['phone'];
        $user->is_active = true;
        if (Schema::hasColumn('users', 'health_role')) {
            $user->health_role = $data['health_role'];
        }
        if (Schema::hasColumn('users', 'health_department_id')) {
            $user->health_department_id = $data['health_department_id'] ?? null;
        }
        $user->save();

        if (!empty($data['branch_id']) && Schema::hasTable('branch_user')) {
            DB::table('branch_user')->updateOrInsert(
                ['branch_id' => $data['branch_id'], 'user_id' => $user->id],
                []
            );
        }

        if (Schema::hasTable('health_staff_profiles')) {
            HealthStaffProfile::withoutGlobalScopes()->updateOrCreate(
                ['company_id' => $companyId, 'user_id' => $user->id],
                [
                    'employee_code' => $data['employee_code'] ?: null,
                    'designation' => $data['designation'],
                    'employment_type' => $data['employment_type'] ?: 'permanent',
                    'employment_status' => 'active',
                    'joined_on' => $data['joined_on'],
                    'branch_id' => $data['branch_id'] ?? null,
                    'basic_salary' => $data['basic_salary'],
                    'cnic' => $data['cnic'],
                ]
            );
        }

        HealthScopeService::forget((int) $user->id);

        $result = ['created' => $existing === null];
        if ($credential) {
            $result['credential'] = $credential;
        }

        return $result;
    }

    private static function writePatient(int $companyId, array $data, ?int $existing, ?User $actor): array
    {
        $attributes = [
            'branch_id' => $data['branch_id'] ?? null,
            'name' => $data['name'],
            'guardian_name' => $data['guardian_name'],
            'gender' => $data['gender'],
            'date_of_birth' => $data['date_of_birth'],
            'age_years' => $data['age_years'],
            'phone' => $data['phone'],
            'phone_digits' => $data['phone'] ? preg_replace('/\D+/', '', (string) $data['phone']) : null,
            'cnic' => $data['cnic'],
            'address' => $data['address'],
            'city' => $data['city'],
            'blood_group' => $data['blood_group'],
            'is_active' => true,
        ];

        if ($existing) {
            HealthPatient::withoutGlobalScopes()->whereKey($existing)->update($attributes);

            return ['created' => false];
        }

        HealthPatient::withoutGlobalScopes()->create(array_merge($attributes, [
            'company_id' => $companyId,
            // The sheet's own file number, which the import requires: it is
            // what makes a corrected re-upload land on the same patient instead
            // of registering them a second time.
            'mrn' => $data['mrn'],
            'registered_by' => $actor->id ?? null,
        ]));

        return ['created' => true];
    }

    private static function writeMedicine(int $companyId, array $data, ?int $existing, ?User $actor): array
    {
        $payload = [
            'name' => $data['name'],
            'generic_name' => $data['generic_name'],
            'strength' => $data['strength'],
            'form' => $data['form'] ?: 'tablet',
            'manufacturer' => $data['manufacturer'],
            'category' => $data['category'],
            'code' => $data['code'],
            'barcode' => $data['barcode'],
            'unit_uom' => $data['unit_uom'] ?: 'unit',
            'pack_uom' => $data['pack_uom'],
            'pack_size' => $data['pack_size'] ?: 1,
            'purchase_price' => $data['purchase_price'] ?? 0,
            'sale_price' => $data['sale_price'] ?? 0,
            'tax_rate' => $data['tax_rate'],
            'requires_prescription' => (bool) ($data['requires_prescription'] ?? false),
            'reorder_level' => $data['reorder_level'] ?? 0,
            'is_active' => true,
        ];

        if ($existing) {
            $medicine = HealthMedicine::withoutGlobalScopes()->findOrFail($existing);
            HealthPharmacyService::updateMedicine($medicine, $payload);

            return ['created' => false];
        }

        HealthPharmacyService::createMedicine($companyId, $payload, $actor->id ?? null);

        return ['created' => true];
    }

    /**
     * The lot an opening-stock row refers to, if the hospital already imported
     * it — medicine, batch, expiry and branch, which is exactly the key the
     * sheet itself is de-duplicated by.
     */
    private static function openingStockBatch(int $companyId, array $clean): ?HealthMedicineBatch
    {
        $medicineId = (int) ($clean['medicine_id'] ?? 0);
        if ($medicineId <= 0) {
            return null;
        }

        $batchNo = trim((string) ($clean['batch_no'] ?? '')) ?: null;
        $expiry = $clean['expiry_date'] ?? null;

        return HealthMedicineBatch::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('medicine_id', $medicineId)
            ->where('branch_id', $clean['branch_id'] ?? null)
            ->where('status', HealthMedicineBatch::STATUS_ACTIVE)
            ->when($batchNo !== null, fn ($q) => $q->where('batch_no', $batchNo))
            ->when($batchNo === null, fn ($q) => $q->whereNull('batch_no'))
            ->when($expiry, fn ($q) => $q->whereDate('expiry_date', $expiry))
            ->when(!$expiry, fn ($q) => $q->whereNull('expiry_date'))
            ->first();
    }

    private static function writeOpeningStock(int $companyId, array $data, ?User $actor): array
    {
        $medicine = HealthMedicine::withoutGlobalScopes()->findOrFail($data['medicine_id']);

        /*
         * RESTATE, never accumulate. An opening balance is a statement about
         * what is on the shelf right now, not a delivery. Re-uploading a
         * corrected count must leave the shelf holding the corrected figure —
         * the difference is written as an adjustment so the movement history
         * still explains itself, and an unchanged row writes no movement at all.
         */
        $existing = self::openingStockBatch($companyId, $data);
        if ($existing) {
            HealthPharmacyStockService::adjust(
                $companyId,
                $existing,
                (float) $data['quantity'],
                __('health.import_opening_stock_note'),
                $actor->id ?? null
            );

            $batch = $existing->fresh();
            $salePrice = (float) ($data['sale_price'] ?? 0);
            $costPrice = (float) ($data['cost_price'] ?? 0);
            if ($batch && ($salePrice > 0 || $costPrice > 0)) {
                if ($salePrice > 0) {
                    $batch->sale_price = $salePrice;
                }
                if ($costPrice > 0) {
                    $batch->cost_price = $costPrice;
                }
                $batch->save();
            }

            return ['created' => false];
        }

        HealthPharmacyStockService::receive(
            $companyId,
            $medicine,
            [
                'quantity' => (float) $data['quantity'],
                'batch_no' => $data['batch_no'],
                'expiry_date' => $data['expiry_date'],
                'cost_price' => $data['cost_price'] ?? 0,
                'sale_price' => $data['sale_price'] ?? 0,
                'notes' => __('health.import_opening_stock_note'),
            ],
            $data['branch_id'] ?? null,
            ['reference_type' => 'onboarding_import'],
            $actor->id ?? null,
            // Typed as an inward adjustment, not a purchase: nothing was bought
            // today, so it must not appear in the month's purchase figures or
            // raise a payable against a supplier who was already paid years ago.
            HealthBatchMovement::TYPE_ADJUSTMENT_IN
        );

        return ['created' => true];
    }

    private static function writeSupplier(int $companyId, array $data, ?int $existing): array
    {
        $attributes = [
            'name' => $data['name'],
            'phone' => $data['phone'],
            'email' => $data['email'],
            'contact_person' => $data['contact_person'],
            'ntn' => $data['ntn'],
            'address' => $data['address'],
            'city' => $data['city'],
            'is_active' => true,
        ];

        if (Schema::hasColumn('suppliers', 'opening_balance')) {
            $attributes['opening_balance'] = $data['opening_balance'] ?? 0;
            $attributes['opening_balance_date'] = $data['opening_balance_date'];
        }

        if ($existing) {
            Supplier::whereKey($existing)->update($attributes);

            return ['created' => false];
        }

        Supplier::create(array_merge($attributes, ['company_id' => $companyId]));

        return ['created' => true];
    }

    /**
     * An account row, and the opening journal behind it.
     *
     * The balance is posted through the ledger service rather than only stored
     * on the account, because a trial balance that does not include the opening
     * figures is not a trial balance. Restating it is that service's job — it
     * reverses the previous opening entry instead of adding to it, so a
     * corrected sheet re-uploaded twice does not double the equity.
     */
    private static function writeAccount(int $companyId, array $data, ?int $existing, ?User $actor): array
    {
        $attributes = [
            'name' => $data['name'],
            'type' => $data['type'],
            'subtype' => $data['subtype'],
            'opening_balance' => $data['opening_balance'] ?? 0,
            'opening_balance_date' => $data['opening_balance_date'],
            'is_active' => true,
        ];

        if ($existing) {
            $account = HealthAccount::withoutGlobalScopes()->findOrFail($existing);
            // A system account keeps its type and its key; only the label and
            // the opening figure are the hospital's to set.
            if ($account->is_system) {
                unset($attributes['type'], $attributes['subtype']);
            }
            $account->fill($attributes)->save();
        } else {
            $account = HealthAccount::withoutGlobalScopes()->create(array_merge($attributes, [
                'company_id' => $companyId,
                'code' => $data['code'],
                'created_by' => $actor->id ?? null,
            ]));
        }

        HealthChartOfAccountsService::flush();

        /*
         * The row is only half done until the balance is in the LEDGER. This
         * post can legitimately refuse — no Opening Balance Equity account on
         * an unseeded chart, or a prior opening entry that would not reverse —
         * and swallowing that refusal is the worst outcome available: the
         * account screen would show the figure the sheet claimed while the
         * books never received it, and nobody would find out until a trial
         * balance failed to balance weeks later.
         *
         * Throwing rolls the whole row back inside its own transaction, so the
         * account is not left holding a balance the ledger has never heard of,
         * and the row is reported as failed with the reason on the screen.
         */
        $posted = HealthLedgerService::postOpeningBalance($companyId, $account->refresh(), $actor);

        if (($posted['ok'] ?? false) !== true) {
            throw new \RuntimeException(__('health.import_err_opening_post_failed', [
                'reason' => (string) ($posted['reason'] ?? 'unknown'),
            ]));
        }

        return ['created' => $existing === null];
    }

    /* ─────────────────────────────── Small helpers ────────────────────────── */

    /** Match handles the way a human typed them: case, spacing and dashes out. */
    private static function slug(?string $value): string
    {
        return Str::of((string) $value)->lower()->replaceMatches('/[^a-z0-9]+/u', '')->value();
    }
}
