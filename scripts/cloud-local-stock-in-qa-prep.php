<?php

/**
 * Local loopback Stock-In QA prep. Fail-closed to taxnest_dev|taxnest_staging.
 * Never run against production.
 */

use App\Models\Company;
use App\Models\Ingredient;
use App\Models\PosProduct;
use App\Models\User;
use App\Support\DevStagingGuard;
use Database\Seeders\VideoDemoShopSeeder;
use Illuminate\Support\Facades\Hash;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if ($problems = DevStagingGuard::problems()) {
    fwrite(STDERR, "REFUSED: " . implode('; ', $problems) . "\n");
    exit(1);
}

$company = Company::query()->where('name', VideoDemoShopSeeder::COMPANY_NAME)->first();
if (! $company || ! $company->is_internal_account) {
    fwrite(STDERR, "demo company missing\n");
    exit(1);
}

$flags = is_array($company->feature_flags) ? $company->feature_flags : [];
$flags['inventory'] = true;
$flags['recipes'] = true;
$company->inventory_enabled = true;
$company->feature_flags = $flags;
$company->save();

$product = PosProduct::query()->firstOrCreate(
    ['company_id' => $company->id, 'sku' => 'DK-500'],
    [
        'name' => 'Coke 500ml',
        'price' => 80,
        'barcode' => '628100000001',
        'uom' => 'NOS',
        'stock_quantity' => 0,
        'is_active' => true,
    ]
);

$ingredient = Ingredient::query()->firstOrCreate(
    ['company_id' => $company->id, 'code' => 'RICE-25'],
    [
        'name' => 'Basmati Rice',
        'unit' => 'kg',
        'cost_per_unit' => 300,
        'current_stock' => 10,
        'min_stock_level' => 50,
        'is_active' => true,
    ]
);

$cashierEmail = 'videocashier@nestpos.pk';
$pass = (string) env('VIDEO_DEMO_PASS');
if ($pass === '') {
    fwrite(STDERR, "VIDEO_DEMO_PASS missing\n");
    exit(1);
}
$cashier = User::query()->where('email', $cashierEmail)->first();
if (! $cashier) {
    User::query()->create([
        'name' => 'QA Cashier',
        'email' => $cashierEmail,
        'password' => Hash::make($pass),
        'company_id' => $company->id,
        'role' => 'pos_user',
        'pos_role' => 'pos_cashier',
        'is_active' => true,
    ]);
}

$dir = __DIR__ . '/../.local';
if (! is_dir($dir)) {
    mkdir($dir, 0700, true);
}
$reference = 'QA-INV-' . date('YmdHis');
$path = $dir . '/stock-in-qa.xlsx';
$ss = new Spreadsheet();
$sheet = $ss->getActiveSheet();
$sheet->setTitle('StockIn');
$sheet->fromArray(\App\Services\PosStockInExcelService::HEADERS, null, 'A1');
$sheet->fromArray(['INGREDIENT', 'RICE-25', 'RICE-25', 'Basmati Rice', 2, 'kg', 300, '', $reference, '', 'local qa'], null, 'A2');
$sheet->fromArray(['ITEM', 'DK-500', '', 'Coke 500ml', 6, 'NOS', 55, '', $reference, '', 'local qa'], null, 'A3');
(new Xlsx($ss))->save($path);
file_put_contents($dir . '/stock-in-qa.json', json_encode(['reference' => $reference, 'xlsx' => $path], JSON_PRETTY_PRINT));

echo json_encode([
    'ok' => true,
    'company_id' => $company->id,
    'product_id' => $product->id,
    'ingredient_id' => $ingredient->id,
    'xlsx' => $path,
    'reference' => $reference,
    'cashier' => $cashierEmail,
], JSON_PRETTY_PRINT) . "\n";
