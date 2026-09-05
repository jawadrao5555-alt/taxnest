<?php

/**
 * Dev-only fixture for eyeballing the pharmacy screens.
 *
 * Creates (or reuses) one approved healthcare company with the pharmacy module
 * on, an owner login, and just enough medicine / stock / prescription / sale
 * data that every pharmacy screen has something real to render instead of an
 * empty state.
 *
 * Usage (dev MySQL only):
 *   PHARMACY_DEV_PASSWORD='choose-one' php scripts/health-pharmacy-dev-seed.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Company;
use App\Models\HealthMedicine;
use App\Models\HealthPrescription;
use App\Models\HealthPrescriptionItem;
use App\Models\User;
use App\Services\HealthAccessService;
use App\Services\HealthModuleService;
use App\Services\HealthPharmacyCheckoutService;
use App\Services\HealthPharmacyService;
use App\Services\HealthPharmacyStockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$email = getenv('PHARMACY_DEV_EMAIL') ?: 'pharmacy.dev@healthdev.test';

// No password is committed here. Supply one for the run:
//   PHARMACY_DEV_PASSWORD='...' php scripts/health-pharmacy-dev-seed.php
$password = getenv('PHARMACY_DEV_PASSWORD') ?: '';
if ($password === '') {
    fwrite(STDERR, "Set PHARMACY_DEV_PASSWORD before running this fixture.\n");
    exit(1);
}

$phone = '03009' . random_int(100000, 999999);

$user = User::where('email', $email)->first();

if ($user) {
    $company = Company::find($user->company_id);
    echo "Reusing company #{$company->id}\n";
} else {
    $modules = HealthModuleService::defaultsForOrgType('hospital');
    if (is_array($modules) && !in_array('pharmacy', $modules, true) && !array_key_exists('pharmacy', $modules)) {
        $modules[] = 'pharmacy';
    }

    $company = Company::create([
        'name' => 'Dev Hospital (pharmacy render check)',
        'ntn' => '9' . random_int(1000000, 9999999),
        'email' => $email,
        'phone' => $phone,
        'company_status' => 'active',
        'status' => 'active',
        'product_type' => 'health',
        'health_org_type' => 'hospital',
        'health_modules' => $modules,
    ]);

    $user = User::create([
        'name' => 'Dev Pharmacist',
        'email' => $email,
        'phone' => $phone,
        'password' => Hash::make($password),
        'company_id' => $company->id,
        'role' => 'company_admin',
        'health_role' => HealthAccessService::ROLE_OWNER,
        'is_active' => true,
    ]);

    echo "Created company #{$company->id}\n";
}

// Reusing the fixture must not leave an old password behind: the render sweep
// logs in over real HTTP, and a stale hash reads as a broken panel.
$user->password = Hash::make($password);
$user->save();

// A healthcare package must actually sell the pharmacy module, otherwise the
// panel correctly refuses the screens. The Hospital plan sells all of them.
if (!DB::table('subscriptions')->where('company_id', $company->id)->where('active', true)->exists()) {
    $plan = DB::table('pricing_plans')->where('product_type', 'health')->where('name', 'Hospital')->first()
        ?: DB::table('pricing_plans')->where('product_type', 'health')->where('is_trial', true)->first();

    if ($plan) {
        DB::table('subscriptions')->insert([
            'company_id' => $company->id,
            'pricing_plan_id' => $plan->id,
            'active' => true,
            'billing_cycle' => 'annual',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        echo "Attached plan {$plan->name}\n";
    }
}

$companyId = (int) $company->id;
$branchId = null;

HealthPharmacyService::forget();

// ── Medicines ────────────────────────────────────────────────────────────────
$catalogue = [
    ['name' => 'Panadol', 'generic_name' => 'Paracetamol', 'strength' => '500mg', 'form' => 'tablet', 'manufacturer' => 'GSK', 'purchase_price' => 4, 'sale_price' => 6, 'reorder_level' => 100],
    ['name' => 'Augmentin', 'generic_name' => 'Amoxicillin + Clavulanate', 'strength' => '625mg', 'form' => 'tablet', 'manufacturer' => 'GSK', 'purchase_price' => 38, 'sale_price' => 48, 'reorder_level' => 50, 'requires_prescription' => true],
    ['name' => 'Brufen', 'generic_name' => 'Ibuprofen', 'strength' => '400mg', 'form' => 'tablet', 'manufacturer' => 'Abbott', 'purchase_price' => 6, 'sale_price' => 9, 'reorder_level' => 60],
    ['name' => 'Ventolin Inhaler', 'generic_name' => 'Salbutamol', 'strength' => '100mcg', 'form' => 'inhaler', 'manufacturer' => 'GSK', 'purchase_price' => 420, 'sale_price' => 520, 'reorder_level' => 10],
    ['name' => 'Insulin Mixtard', 'generic_name' => 'Insulin', 'strength' => '30/70', 'form' => 'injection', 'manufacturer' => 'Novo Nordisk', 'purchase_price' => 780, 'sale_price' => 900, 'reorder_level' => 8, 'is_refrigerated' => true],
    ['name' => 'Morphine', 'generic_name' => 'Morphine Sulphate', 'strength' => '10mg', 'form' => 'injection', 'manufacturer' => 'Searle', 'purchase_price' => 260, 'sale_price' => 320, 'reorder_level' => 5, 'is_controlled' => true, 'is_narcotic' => true, 'requires_prescription' => true],
];

$medicines = [];
foreach ($catalogue as $row) {
    $existing = HealthMedicine::withoutGlobalScopes()
        ->where('company_id', $companyId)->where('name', $row['name'])->first();

    $medicines[$row['name']] = $existing ?: HealthPharmacyService::createMedicine($companyId, $row, $user->id);
}

HealthPharmacyService::syncSubstitutes($medicines['Panadol'], [$medicines['Brufen']->id]);

// ── Stock: a healthy lot, a short-dated lot and an expired lot ───────────────
$hasStock = DB::table('health_medicine_batches')->where('company_id', $companyId)->exists();

if (!$hasStock) {
    $supplierId = DB::table('suppliers')->insertGetId([
        'company_id' => $companyId,
        'name' => 'Dev Pharma Distributors',
        'phone' => '0512345678',
        'city' => 'Islamabad',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $lots = [
        ['Panadol', 'PN-2401', '+14 months', 400, 4, 6],
        ['Panadol', 'PN-2312', '+18 days', 60, 4, 6],
        ['Augmentin', 'AG-2405', '+9 months', 120, 38, 48],
        ['Brufen', 'BR-2402', '-9 days', 40, 6, 9],
        ['Ventolin Inhaler', 'VN-2403', '+20 months', 14, 420, 520],
        ['Insulin Mixtard', 'IN-2404', '+40 days', 9, 780, 900],
        ['Morphine', 'MR-2401', '+11 months', 12, 260, 320],
    ];

    foreach ($lots as [$name, $batchNo, $offset, $qty, $cost, $price]) {
        HealthPharmacyStockService::receive(
            $companyId,
            $medicines[$name],
            [
                'quantity' => $qty,
                'batch_no' => $batchNo,
                'expiry_date' => now()->modify($offset)->toDateString(),
                'cost_price' => $cost,
                'sale_price' => $price,
                'supplier_id' => $supplierId,
            ],
            $branchId,
            ['type' => 'dev_seed', 'id' => null, 'number' => 'DEV'],
            $user->id
        );
    }

    echo "Received " . count($lots) . " lots\n";
}

// ── A prescription part-filled, a counter sale, and a return ────────────────
if (!HealthPrescription::withoutGlobalScopes()->where('company_id', $companyId)->exists()) {
    $prescription = HealthPrescription::withoutGlobalScopes()->create([
        'company_id' => $companyId,
        'branch_id' => $branchId,
        'prescription_no' => HealthPharmacyService::nextNumber($companyId, 'health_prescriptions', 'prescription_no', 'RX'),
        'patient_name' => 'Ahmed Raza',
        'patient_mr_no' => 'MR-1001',
        'patient_phone' => '03011112222',
        'doctor_name' => 'Dr Sana Malik',
        'prescribed_on' => now()->toDateString(),
        'status' => HealthPrescription::STATUS_ISSUED,
        'issued_at' => now(),
        'dispense_status' => HealthPrescription::DISPENSE_PENDING,
        'created_by' => $user->id,
    ]);

    $line = 1;

    foreach ([['Panadol', 20, '1+1+1'], ['Augmentin', 14, '1+0+1']] as [$name, $qty, $dosage]) {
        HealthPrescriptionItem::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'health_prescription_id' => $prescription->id,
            'line_no' => $line++,
            'medicine_id' => $medicines[$name]->id,
            'medicine_name' => $medicines[$name]->display_name,
            'quantity' => $qty,
            'dispensed_quantity' => 0,
            'frequency' => $dosage,
            'duration' => '7 din',
        ]);
    }

    $first = $prescription->items()->first();

    HealthPharmacyCheckoutService::sell($companyId, [
        'prescription_id' => $prescription->id,
        'payment_method' => 'cash',
        'lines' => [[
            'medicine_id' => $first->medicine_id,
            'quantity' => 12,
            'prescription_item_id' => $first->id,
        ]],
    ], $branchId, $user->id, $company);

    $sale = HealthPharmacyCheckoutService::sell($companyId, [
        'payment_method' => 'cash',
        'paid_amount' => 1000,
        'lines' => [
            ['medicine_id' => $medicines['Panadol']->id, 'quantity' => 10],
            ['medicine_id' => $medicines['Ventolin Inhaler']->id, 'quantity' => 1],
        ],
    ], $branchId, $user->id, $company);

    $item = $sale->items()->first();
    HealthPharmacyCheckoutService::refund($companyId, $sale, [
        ['sale_item_id' => $item->id, 'quantity' => 2],
    ], true, 'patient changed mind', $user->id);

    echo "Seeded prescription, sale #{$sale->sale_number} and a return\n";
}

echo "company_id={$companyId}\n";
echo "login={$email}\n";
echo "password=(the PHARMACY_DEV_PASSWORD you passed in)\n";
