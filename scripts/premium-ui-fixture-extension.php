<?php
declare(strict_types=1);

/*
 * Add only synthetic sale-screen products to the disposable RC browser DB.
 * This deliberately refuses every database except the exact RC socket/database
 * contract injected by scripts/rc-safe-run.
 */

use App\Models\Company;
use App\Models\Product;
use App\Models\PosProduct;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Schema;

if ((string) getenv('RC_BROWSER_FIXTURE_EXTENSION') !== '1') {
    fwrite(STDERR, "premium-ui-fixture-extension: set RC_BROWSER_FIXTURE_EXTENSION=1\n");
    exit(2);
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$socket = (string) getenv('DB_SOCKET');
$database = (string) getenv('DB_DATABASE');
if (
    (string) config('database.default') !== 'mysql'
    || (string) config('database.connections.mysql.database') !== 'taxnest_rc_browser'
    || (string) config('database.connections.mysql.host') !== '127.0.0.1'
    || $database !== 'taxnest_rc_browser'
    || !preg_match('#^/tmp/taxnest-rc-mariadb-browser-[0-9]+/run/mariadb\.sock$#', $socket)
    || !is_socket_path($socket)
    || is_link($socket)
) {
    fwrite(STDERR, "premium-ui-fixture-extension: refused non-isolated database\n");
    exit(2);
}
if (!Schema::hasTable('pos_products')) {
    fwrite(STDERR, "premium-ui-fixture-extension: pos_products table is absent\n");
    exit(2);
}
$requiredColumns = [
    'company_id', 'name', 'price', 'category', 'image', 'sku', 'barcode',
    'is_active', 'show_on_sale', 'stock_quantity', 'low_stock_threshold',
    'cost_price', 'tax_rate', 'uom', 'is_tax_exempt',
];
$availableColumns = Schema::getColumnListing('pos_products');
foreach ($requiredColumns as $column) {
    if (!in_array($column, $availableColumns, true)) {
        fwrite(STDERR, "premium-ui-fixture-extension: pos_products.{$column} is absent\n");
        exit(2);
    }
}
if (!Schema::hasTable('products')) {
    fwrite(STDERR, "premium-ui-fixture-extension: products table is absent\n");
    exit(2);
}
$fbrColumns = [
    'company_id', 'name', 'hs_code', 'pct_code', 'default_tax_rate', 'tax_type',
    'uom', 'default_price', 'is_price_editable', 'is_active', 'show_on_sale',
    'sku', 'barcode',
];
$availableFbrColumns = Schema::getColumnListing('products');
foreach ($fbrColumns as $column) {
    if (!in_array($column, $availableFbrColumns, true)) {
        fwrite(STDERR, "premium-ui-fixture-extension: products.{$column} is absent\n");
        exit(2);
    }
}

$companies = Company::withoutGlobalScopes()
    ->whereIn('email', [
        'hotel-company@rc-browser.invalid',
        'category-pra-restaurant@rc-browser.invalid',
        'fiscal-company@rc-browser.invalid',
    ])
    ->get()
    ->keyBy('email');

$required = [
    'hotel-company@rc-browser.invalid',
    'category-pra-restaurant@rc-browser.invalid',
    'fiscal-company@rc-browser.invalid',
];
foreach ($required as $email) {
    if (!$companies->has($email)) {
        fwrite(STDERR, "premium-ui-fixture-extension: missing synthetic company {$email}\n");
        exit(2);
    }
}

$products = [
    'hotel-company@rc-browser.invalid' => [
        ['name' => 'Synthetic Garden Burger', 'price' => 850, 'sku' => 'RC-PRA-HOTEL-001', 'category' => 'Mains', 'slug' => 'hotel-garden-burger', 'color' => '#fb7185'],
        ['name' => 'Synthetic Citrus Cooler', 'price' => 320, 'sku' => 'RC-PRA-HOTEL-002', 'category' => 'Drinks', 'slug' => 'hotel-citrus-cooler', 'color' => '#22d3ee'],
        ['name' => 'Synthetic Lemon Herb Bowl', 'price' => 690, 'sku' => 'RC-PRA-HOTEL-003', 'category' => 'Mains', 'slug' => 'hotel-lemon-herb-bowl', 'color' => '#a3e635'],
        ['name' => 'Synthetic Firecracker Fries', 'price' => 390, 'sku' => 'RC-PRA-HOTEL-004', 'category' => 'Sides', 'slug' => 'hotel-firecracker-fries', 'color' => '#f59e0b'],
        ['name' => 'Synthetic Mango Sparkler', 'price' => 350, 'sku' => 'RC-PRA-HOTEL-005', 'category' => 'Drinks', 'slug' => 'hotel-mango-sparkler', 'color' => '#f97316'],
        ['name' => 'Synthetic Creamy Pasta', 'price' => 760, 'sku' => 'RC-PRA-HOTEL-006', 'category' => 'Mains', 'slug' => 'hotel-creamy-pasta', 'color' => '#c084fc'],
        ['name' => 'Synthetic Green Salad', 'price' => 440, 'sku' => 'RC-PRA-HOTEL-007', 'category' => 'Sides', 'slug' => 'hotel-green-salad', 'color' => '#34d399'],
        ['name' => 'Synthetic Cocoa Brownie', 'price' => 280, 'sku' => 'RC-PRA-HOTEL-008', 'category' => 'Mains', 'slug' => 'hotel-cocoa-brownie', 'color' => '#a78bfa'],
    ],
    'category-pra-restaurant@rc-browser.invalid' => [
        ['name' => 'Synthetic Garden Burger', 'price' => 850, 'sku' => 'RC-PRA-REST-001', 'category' => 'Mains', 'slug' => 'restaurant-garden-burger', 'color' => '#fb7185'],
        ['name' => 'Synthetic Citrus Cooler', 'price' => 320, 'sku' => 'RC-PRA-REST-002', 'category' => 'Drinks', 'slug' => 'restaurant-citrus-cooler', 'color' => '#22d3ee'],
        ['name' => 'Synthetic Lemon Herb Bowl', 'price' => 690, 'sku' => 'RC-PRA-REST-003', 'category' => 'Mains', 'slug' => 'restaurant-lemon-herb-bowl', 'color' => '#a3e635'],
        ['name' => 'Synthetic Firecracker Fries', 'price' => 390, 'sku' => 'RC-PRA-REST-004', 'category' => 'Sides', 'slug' => 'restaurant-firecracker-fries', 'color' => '#f59e0b'],
        ['name' => 'Synthetic Mango Sparkler', 'price' => 350, 'sku' => 'RC-PRA-REST-005', 'category' => 'Drinks', 'slug' => 'restaurant-mango-sparkler', 'color' => '#f97316'],
        ['name' => 'Synthetic Creamy Pasta', 'price' => 760, 'sku' => 'RC-PRA-REST-006', 'category' => 'Mains', 'slug' => 'restaurant-creamy-pasta', 'color' => '#c084fc'],
        ['name' => 'Synthetic Green Salad', 'price' => 440, 'sku' => 'RC-PRA-REST-007', 'category' => 'Sides', 'slug' => 'restaurant-green-salad', 'color' => '#34d399'],
        ['name' => 'Synthetic Cocoa Brownie', 'price' => 280, 'sku' => 'RC-PRA-REST-008', 'category' => 'Mains', 'slug' => 'restaurant-cocoa-brownie', 'color' => '#a78bfa'],
    ],
    'fiscal-company@rc-browser.invalid' => [
        ['name' => 'Synthetic Retail Notebook', 'price' => 275, 'sku' => 'RC-FBR-RET-001', 'category' => 'Stationery', 'slug' => 'retail-notebook', 'color' => '#60a5fa'],
        ['name' => 'Synthetic Retail Desk Lamp', 'price' => 1850, 'sku' => 'RC-FBR-RET-002', 'category' => 'Essentials', 'slug' => 'retail-desk-lamp', 'color' => '#fbbf24'],
        ['name' => 'Synthetic Retail Cotton Tote', 'price' => 520, 'sku' => 'RC-FBR-RET-003', 'category' => 'Essentials', 'slug' => 'retail-cotton-tote', 'color' => '#34d399'],
        ['name' => 'Synthetic Retail Gel Pen', 'price' => 95, 'sku' => 'RC-FBR-RET-004', 'category' => 'Stationery', 'slug' => 'retail-gel-pen', 'color' => '#a78bfa'],
        ['name' => 'Synthetic Retail USB-C Cable', 'price' => 680, 'sku' => 'RC-FBR-RET-005', 'category' => 'Gadgets', 'slug' => 'retail-usbc-cable', 'color' => '#22d3ee'],
        ['name' => 'Synthetic Retail Ceramic Mug', 'price' => 430, 'sku' => 'RC-FBR-RET-006', 'category' => 'Essentials', 'slug' => 'retail-ceramic-mug', 'color' => '#fb7185'],
        ['name' => 'Synthetic Retail Weekly Planner', 'price' => 340, 'sku' => 'RC-FBR-RET-007', 'category' => 'Stationery', 'slug' => 'retail-weekly-planner', 'color' => '#f97316'],
        ['name' => 'Synthetic Retail Travel Adapter', 'price' => 1250, 'sku' => 'RC-FBR-RET-008', 'category' => 'Gadgets', 'slug' => 'retail-travel-adapter', 'color' => '#c084fc'],
    ],
];

$fixtureAssetRoot = $root . '/docs/ui-premium/fixture-assets';
$imageRoot = $root . '/storage/app/public/products/rc-premium';
if (
    is_link($fixtureAssetRoot)
    || !is_dir($fixtureAssetRoot)
    || is_link($imageRoot)
    || (!is_dir($imageRoot) && !mkdir($imageRoot, 0700, true) && !is_dir($imageRoot))
) {
    fwrite(STDERR, "premium-ui-fixture-extension: unsafe synthetic image directory\n");
    exit(2);
}

/*
 * Keep the PRA visual fixture honest: these are eight locally versioned,
 * compact JPGs from the attributed source manifest, copied into the same
 * isolated public directory that the existing image field already serves.
 * FBR's legacy products table has no image column and intentionally remains
 * monogram-only; no retail photo is invented for that catalogue.
 */
$praImageAssets = [
    'hotel-garden-burger' => 'burger.jpg',
    'hotel-citrus-cooler' => 'citrus-cooler.jpg',
    'hotel-lemon-herb-bowl' => 'lemon-herb-bowl.jpg',
    'hotel-firecracker-fries' => 'firecracker-fries.jpg',
    'hotel-mango-sparkler' => 'mango-sparkler.jpg',
    'hotel-creamy-pasta' => 'creamy-pasta.jpg',
    'hotel-green-salad' => 'green-salad.jpg',
    'hotel-cocoa-brownie' => 'cocoa-brownie.jpg',
    'restaurant-garden-burger' => 'burger.jpg',
    'restaurant-citrus-cooler' => 'citrus-cooler.jpg',
    'restaurant-lemon-herb-bowl' => 'lemon-herb-bowl.jpg',
    'restaurant-firecracker-fries' => 'firecracker-fries.jpg',
    'restaurant-mango-sparkler' => 'mango-sparkler.jpg',
    'restaurant-creamy-pasta' => 'creamy-pasta.jpg',
    'restaurant-green-salad' => 'green-salad.jpg',
    'restaurant-cocoa-brownie' => 'cocoa-brownie.jpg',
];

foreach ($praImageAssets as $slug => $assetName) {
    $sourcePath = $fixtureAssetRoot . '/' . $assetName;
    $imagePath = $imageRoot . '/' . $slug . '.jpg';
    $imageInfo = is_link($sourcePath) ? false : @getimagesize($sourcePath);
    if (
        is_link($sourcePath)
        || !is_file($sourcePath)
        || $imageInfo === false
        || ($imageInfo['mime'] ?? null) !== 'image/jpeg'
        || (filesize($sourcePath) ?: 0) > 100 * 1024
        || is_link($imagePath)
        || !copy($sourcePath, $imagePath)
    ) {
        fwrite(STDERR, "premium-ui-fixture-extension: synthetic JPG copy failed\n");
        exit(2);
    }
    chmod($imagePath, 0600);
}

/*
 * A reused disposable runtime may still contain the first-generation SVGs.
 * Remove only those generated fixture files so stale generic symbols cannot
 * appear in a capture; this path is already protected by the exact RC DB and
 * socket guards above.
 */
foreach (glob($imageRoot . '/*.svg') ?: [] as $legacyImage) {
    if (is_link($legacyImage) || !is_file($legacyImage) || !unlink($legacyImage)) {
        fwrite(STDERR, "premium-ui-fixture-extension: stale synthetic image cleanup failed\n");
        exit(2);
    }
}

$created = 0;
foreach ($products as $email => $rows) {
    $companyId = (int) $companies[$email]->id;
    foreach ($rows as $row) {
        $product = PosProduct::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('sku', $row['sku'])
            ->first();
        $values = [
            'company_id' => $companyId,
            'name' => $row['name'],
            'price' => $row['price'],
            'cost_price' => round($row['price'] * 0.6, 2),
            'stock_quantity' => 48,
            'low_stock_threshold' => 5,
            'tax_rate' => 0,
            'uom' => 'NOS',
            'category' => $row['category'],
            'image' => $email === 'fiscal-company@rc-browser.invalid'
                ? null
                : 'rc-premium/' . $row['slug'] . '.jpg',
            'sku' => $row['sku'],
            'barcode' => $row['sku'],
            'is_active' => true,
            'show_on_sale' => true,
            'is_tax_exempt' => true,
        ];
        if ($product === null) {
            PosProduct::withoutGlobalScopes()->create($values);
            $created++;
        } else {
            $product->forceFill($values)->save();
        }
    }
}

/*
 * FBR deliberately uses the legacy `products` catalogue, not pos_products.
 * Keep these rows as a separate explicit write: the two product models have
 * different prices, tax fields, and sale controllers. This is the reason the
 * first version of this fixture showed All(0) on /fbr-pos/create.
 */
$fbrCompanyId = (int) $companies['fiscal-company@rc-browser.invalid']->id;
foreach ($products['fiscal-company@rc-browser.invalid'] as $row) {
    $product = Product::withoutGlobalScopes()
        ->where('company_id', $fbrCompanyId)
        ->where('sku', $row['sku'])
        ->first();
    $values = [
        'company_id' => $fbrCompanyId,
        'name' => $row['name'],
        'hs_code' => '96081000',
        'pct_code' => null,
        'default_tax_rate' => 0,
        'tax_type' => 'exempt',
        'uom' => 'NOS',
        'default_price' => $row['price'],
        'is_price_editable' => false,
        'is_active' => true,
        'show_on_sale' => true,
        'sku' => $row['sku'],
        'barcode' => $row['sku'],
    ];
    if ($product === null) {
        Product::withoutGlobalScopes()->create($values);
        $created++;
    } else {
        $product->forceFill($values)->save();
    }
}

printf("PREMIUM_UI_FIXTURE_PRODUCTS_READY created=%d\n", $created);

function is_socket_path(string $path): bool
{
    return file_exists($path) && @filetype($path) === 'socket';
}