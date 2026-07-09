<?php
/**
 * Scale-test seeder: 1000 realistic companies PER product (di / pos / fbrpos).
 * All seeded rows are identifiable via email domain @scaletest.pk for one-command purge.
 *
 * Run:   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER -u PGPASSWORD -u PGDATABASE php scripts/seed_scale_test.php
 * Purge: env -u ... php scripts/seed_scale_test.php --purge
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

const DOMAIN = 'scaletest.pk';
const PER_PRODUCT = 1000;

// ---------------------------------------------------------------- purge mode
if (in_array('--purge', $argv)) {
    $ids = DB::table('companies')->where('email', 'like', '%@' . DOMAIN)->pluck('id');
    if ($ids->isEmpty()) { echo "Nothing to purge.\n"; exit; }
    echo 'Purging ' . $ids->count() . " companies...\n";
    foreach ($ids->chunk(200) as $chunk) {
        $c = $chunk->all();
        $invIds = DB::table('invoices')->whereIn('company_id', $c)->pluck('id');
        foreach ($invIds->chunk(1000) as $ic) DB::table('invoice_items')->whereIn('invoice_id', $ic->all())->delete();
        DB::table('invoices')->whereIn('company_id', $c)->delete();
        $txIds = DB::table('pos_transactions')->whereIn('company_id', $c)->pluck('id');
        foreach ($txIds->chunk(1000) as $tc) DB::table('pos_transaction_items')->whereIn('transaction_id', $tc->all())->delete();
        DB::table('pos_transactions')->whereIn('company_id', $c)->delete();
        $ftxIds = DB::table('fbr_pos_transactions')->whereIn('company_id', $c)->pluck('id');
        foreach ($ftxIds->chunk(1000) as $fc) DB::table('fbr_pos_transaction_items')->whereIn('transaction_id', $fc->all())->delete();
        DB::table('fbr_pos_transactions')->whereIn('company_id', $c)->delete();
        DB::table('subscriptions')->whereIn('company_id', $c)->delete();
        DB::table('users')->whereIn('company_id', $c)->delete();
        DB::table('registered_credentials')->whereIn('company_id', $c)->delete();
        DB::table('companies')->whereIn('id', $c)->delete();
        echo '.';
    }
    echo "\nPurged.\n";
    exit;
}

// ---------------------------------------------------------------- data pools
$firstNames = ['Muhammad','Ahmed','Ali','Hassan','Hussain','Usman','Bilal','Imran','Kamran','Faisal','Tariq','Zafar','Naveed','Shahid','Rashid','Khalid','Asif','Amir','Waqas','Adnan','Salman','Farhan','Junaid','Nadeem','Saeed','Arif','Javed','Iqbal','Ramzan','Shafiq'];
$lastNames  = ['Khan','Malik','Butt','Sheikh','Chaudhry','Qureshi','Ansari','Siddiqui','Awan','Raja','Mirza','Baig','Dar','Gill','Bhatti','Cheema','Warraich','Abbasi','Hashmi','Rizvi'];
$cities     = ['Lahore','Karachi','Faisalabad','Rawalpindi','Multan','Gujranwala','Sialkot','Peshawar','Quetta','Islamabad','Hyderabad','Bahawalpur','Sargodha','Sahiwal','Okara','Kasur','Sheikhupura','Gujrat','Jhelum','Rahim Yar Khan'];
$prefixes   = ['Al-Madina','Bismillah','Pak','Punjab','Chenab','Ravi','Indus','Khyber','Shaheen','Crescent','Star','Falcon','Metro','Royal','Prime','Elite','Zam Zam','Rehmat','Barkat','Madni','Habib','Ittehad','United','National','Allied','Mehran','Sapphire','Diamond','Golden','Silver'];

$suffixByProduct = [
    'di'     => ['Traders','Textiles','Industries','Enterprises','Steel Mills','Chemicals','Foods','Packages','Corporation','Brothers','& Sons','Impex','International','Engineering','Pipe Mills','Flour Mills','Cotton Ginners','Plastics','Paper Products','Cables'],
    'pos'    => ['General Store','Cash & Carry','Mart','Superstore','Karyana Store','Restaurant','Biryani House','Sweets & Bakers','Tikka House','Pharmacy','Garments','Shoe Palace','Mobile Zone','Electronics','Departmental Store','Milk Shop','Fruit Mandi','Autos','Hardware Store','Book Depot'],
    'fbrpos' => ['Boutique','Garments','Shoes','Electronics','Mobiles','Departmental Store','Bakers','Pharmacy','Fabrics','Collections','Cosmetics','Jewellers','Watch House','Opticals','Sports','Toys & Gifts','Crockery House','Home Textiles','Perfumes','Leather Goods'],
];

$diItems = [
    ['Cotton Yarn 20/1', '5205.1100', 850], ['Grey Fabric 100m', '5208.1000', 12000], ['PVC Pipe 4in', '3917.2300', 950],
    ['Caustic Soda 50kg', '2815.1100', 5200], ['Wheat Flour 80kg', '1101.0010', 7400], ['Steel Bar G60 (ton)', '7214.9910', 255000],
    ['Corrugated Cartons', '4819.1000', 120], ['Vegetable Ghee 16kg', '1516.2010', 6800], ['Copper Wire 8mm (kg)', '7408.1100', 2900],
    ['Polyester Chips (kg)', '3907.6110', 340],
];
$posItems = [
    ['Sugar 1kg', 165], ['Basmati Rice 5kg', 1650], ['Cooking Oil 5L', 2450], ['Coke 1.5L', 180], ['Bread Large', 150],
    ['Eggs Dozen', 330], ['Milk 1L', 220], ['Surf Excel 1kg', 390], ['Shampoo 360ml', 480], ['Chicken Biryani', 450],
    ['Beef Pulao', 500], ['Chicken Karahi Full', 1800], ['Naan', 25], ['Lays Masala', 100], ['Green Tea 30 Bags', 340],
];
$fbrItems = [
    ['Gents Kurta', '6205.2000', 2450], ['Ladies Lawn Suit 3pc', '6204.2000', 4850], ['Sports Joggers', '6404.1100', 3950],
    ['LED Bulb 12W', '8539.5000', 350], ['Mobile Charger', '8504.4020', 850], ['Cake Rusk 500g', '1905.4000', 380],
    ['Panadol Extra Strip', '3004.9099', 45], ['Bed Sheet King', '6304.1900', 3200], ['Perfume 100ml', '3303.0010', 2800],
    ['Wall Clock', '9105.2100', 1450],
];

$hash = password_hash('Scale@12345', PASSWORD_BCRYPT); // one hash reused (speed)
$now = date('Y-m-d H:i:s');

$plans = [];
foreach (DB::table('pricing_plans')->get() as $p) $plans[$p->product_type][] = $p;

function pick(array $a) { return $a[array_rand($a)]; }

$ntnSeq = 8100000; $phoneSeq = 300000000; $emailSeq = 1;
$summary = [];

foreach (['di', 'pos', 'fbrpos'] as $product) {
    echo strtoupper($product) . ": companies";
    $suffixes = $suffixByProduct[$product];
    $companies = []; $meta = [];
    for ($i = 0; $i < PER_PRODUCT; $i++) {
        $ownerFirst = pick($firstNames); $ownerLast = pick($lastNames);
        $name = pick($prefixes) . ' ' . pick($suffixes);
        if (mt_rand(1, 100) <= 25) $name = $ownerLast . ' ' . pick($suffixes); // family-named businesses
        $city = pick($cities);
        $email = 'st' . $product . $emailSeq++ . '@' . DOMAIN;
        $ntn = (string)($ntnSeq++);
        $created = date('Y-m-d H:i:s', strtotime("-" . mt_rand(5, 540) . " days -" . mt_rand(0, 86400) . " seconds"));
        $pending = mt_rand(1, 100) <= 5;
        $isRestaurant = $product !== 'di' && mt_rand(1, 100) <= ($product === 'pos' ? 22 : 8);
        $standalone = $product === 'pos' && mt_rand(1, 100) <= 30;
        $reporting = mt_rand(1, 100) <= 80;

        $row = [
            'name' => $name,
            'owner_name' => $ownerFirst . ' ' . $ownerLast,
            'ntn' => $ntn,
            'email' => $email,
            'phone' => '03' . ($phoneSeq++),
            'address' => 'Shop ' . mt_rand(1, 99) . ', ' . pick(['Main Bazar', 'GT Road', 'Circular Road', 'Mall Road', 'Industrial Estate', 'College Road', 'Railway Road', 'Model Town', 'Satellite Town', 'City Center']) . ', ' . $city,
            'city' => $city,
            'product_type' => $product,
            'status' => $pending ? 'pending' : 'approved',
            'company_status' => 'active',
            'onboarding_completed' => 1,
            'is_internal_account' => 0,
            'created_at' => $created,
            'updated_at' => $created,
        ];
        if ($product === 'di') {
            $row['invoice_number_prefix'] = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name), 0, 3));
            $row['next_invoice_number'] = 1;
            $row['province'] = pick(['Punjab', 'Sindh', 'KPK', 'Balochistan']);
        } else {
            $row['pos_type'] = $isRestaurant ? 'restaurant' : 'general';
            $row['restaurant_mode'] = $isRestaurant ? 1 : 0;
            if ($product === 'pos') {
                $row['pos_integration_mode'] = $standalone ? 'standalone' : 'pra';
                $row['pra_reporting_enabled'] = (!$standalone && $reporting) ? 1 : 0;
                $row['pra_environment'] = 'production';
                $row['pra_connection_mode'] = (!$standalone && mt_rand(1, 100) <= 15) ? 'fiscal_device' : 'cloud';
            } else {
                $row['fbr_pos_enabled'] = 1;
                $row['fbr_reporting_enabled'] = $reporting ? 1 : 0;
                $row['fbr_pos_environment'] = 'production';
                $row['fbr_universal_enabled'] = mt_rand(1, 100) <= 40 ? 1 : 0;
            }
        }
        $companies[] = $row;
        $meta[$email] = ['created' => $created, 'pending' => $pending, 'standalone' => $standalone, 'reporting' => $reporting, 'owner' => $ownerFirst . ' ' . $ownerLast];
    }
    foreach (array_chunk($companies, 500) as $chunk) DB::table('companies')->insert($chunk);
    echo " ✓";

    // map ids
    $rows = DB::table('companies')->where('email', 'like', 'st' . $product . '%@' . DOMAIN)->get(['id', 'email', 'name', 'ntn', 'created_at', 'invoice_number_prefix', 'pos_integration_mode', 'pra_reporting_enabled', 'fbr_reporting_enabled', 'restaurant_mode', 'status']);

    // users
    $users = [];
    foreach ($rows as $c) {
        $m = $meta[$c->email];
        $users[] = [
            'name' => $m['owner'], 'email' => $c->email, 'phone' => null, 'username' => null,
            'password' => $hash, 'company_id' => $c->id, 'role' => 'company_admin',
            'pos_role' => $product === 'pos' ? 'pos_admin' : null, 'is_active' => 1,
            'created_at' => $c->created_at, 'updated_at' => $c->created_at,
        ];
    }
    foreach (array_chunk($users, 500) as $chunk) DB::table('users')->insert($chunk);
    $userIds = DB::table('users')->where('email', 'like', 'st' . $product . '%@' . DOMAIN)->pluck('id', 'company_id');
    echo " users ✓";

    // subscriptions
    $subs = [];
    $productPlans = $plans[$product];
    $paidPlans = array_values(array_filter($productPlans, fn($p) => !$p->is_trial));
    $trialPlan = array_values(array_filter($productPlans, fn($p) => $p->is_trial))[0];
    $standalonePaid = isset($plans['standalone']) ? array_values(array_filter($plans['standalone'], fn($p) => !$p->is_trial)) : [];
    $standaloneTrial = isset($plans['standalone']) ? (array_values(array_filter($plans['standalone'], fn($p) => $p->is_trial))[0] ?? $trialPlan) : $trialPlan;

    foreach ($rows as $c) {
        $m = $meta[$c->email];
        $isStandalone = ($c->pos_integration_mode ?? null) === 'standalone';
        $poolPaid = $isStandalone && $standalonePaid ? $standalonePaid : $paidPlans;
        $poolTrial = $isStandalone ? $standaloneTrial : $trialPlan;
        $roll = mt_rand(1, 100);
        $start = date('Y-m-d', strtotime($c->created_at));
        if ($roll <= 12 || $m['pending']) { // trial
            $subs[] = [
                'company_id' => $c->id, 'pricing_plan_id' => $poolTrial->id, 'billing_cycle' => 'monthly',
                'discount_percent' => 0, 'final_price' => 0, 'start_date' => $start,
                'end_date' => date('Y-m-d', strtotime($start . ' +3 days')),
                'trial_ends_at' => date('Y-m-d H:i:s', strtotime($start . ' +3 days')),
                'active' => 1, 'created_at' => $c->created_at, 'updated_at' => $c->created_at,
            ];
            continue;
        }
        $plan = pick($poolPaid);
        $expired = $roll > 95; // ~5% lapsed
        if ($product === 'di') {
            $cycles = ['monthly' => [1, 0], 'quarterly' => [3, 1], 'semi_annual' => [6, 3], 'annual' => [12, 6]];
            $cycle = array_rand($cycles);
            [$months, $disc] = $cycles[$cycle];
            $final = round($plan->price * $months * (1 - $disc / 100));
            $end = date('Y-m-d', strtotime($start . " +$months months"));
        } else {
            $cycle = 'annual'; $disc = 6;
            $final = $product === 'fbrpos' ? round($plan->price * 12 * 0.94) : $plan->price;
            $end = date('Y-m-d', strtotime($start . ' +12 months'));
        }
        if ($expired) $end = date('Y-m-d', strtotime('-' . mt_rand(3, 60) . ' days'));
        $subs[] = [
            'company_id' => $c->id, 'pricing_plan_id' => $plan->id, 'billing_cycle' => $cycle,
            'discount_percent' => $disc, 'final_price' => $final, 'start_date' => $start,
            'end_date' => $end, 'trial_ends_at' => null,
            'active' => $expired ? 0 : 1, 'created_at' => $c->created_at, 'updated_at' => $c->created_at,
        ];
    }
    foreach (array_chunk($subs, 500) as $chunk) DB::table('subscriptions')->insert($chunk);
    echo " subs ✓ docs";

    // documents — explicit IDs (autoinc_lock_mode=2 makes batch ids non-consecutive)
    $docTable  = $product === 'di' ? 'invoices' : ($product === 'pos' ? 'pos_transactions' : 'fbr_pos_transactions');
    $itemTable = $product === 'di' ? 'invoice_items' : ($product === 'pos' ? 'pos_transaction_items' : 'fbr_pos_transaction_items');
    $fkCol     = $product === 'di' ? 'invoice_id' : 'transaction_id';
    $nextDocId = ((int) DB::table($docTable)->max('id')) + 1000; // safety gap above live rows
    $docCount = 0; $itemCount = 0;
    $docBuf = []; $itemBuf = [];
    $flush = function (&$docBuf, &$itemBuf) use (&$docCount, &$itemCount, $docTable, $itemTable) {
        if (!$docBuf) return;
        DB::table($docTable)->insert($docBuf);
        foreach (array_chunk($itemBuf, 1000) as $chunk) DB::table($itemTable)->insert($chunk);
        $docCount += count($docBuf); $itemCount += count($itemBuf);
        $docBuf = []; $itemBuf = [];
    };

    foreach ($rows as $c) {
        if ($meta[$c->email]['pending']) continue; // pending companies can't act
        $uid = $userIds[$c->id] ?? null;
        $n = mt_rand($product === 'di' ? 3 : 6, $product === 'di' ? 10 : 16);
        $companyStart = strtotime($c->created_at);
        for ($d = 0; $d < $n; $d++) {
            $ts = mt_rand($companyStart, time());
            $dt = date('Y-m-d H:i:s', $ts);
            $numItems = mt_rand(1, $product === 'di' ? 4 : 5);

            if ($product === 'di') {
                $sub = 0; $tax = 0; $docItems = [];
                for ($k = 0; $k < $numItems; $k++) {
                    [$desc, $hs, $price] = pick($diItems);
                    $qty = mt_rand(1, 50);
                    $lineVal = round($price * $qty, 2); $lineTax = round($lineVal * 0.18, 2);
                    $sub += $lineVal; $tax += $lineTax;
                    $docItems[] = ['hs_code' => $hs, 'description' => $desc, 'quantity' => $qty, 'price' => $price, 'tax_rate' => 18, 'tax' => $lineTax, 'default_uom' => 'PCS', 'sale_type' => 'Goods at standard rate (default)', 'created_at' => $dt, 'updated_at' => $dt];
                }
                $locked = mt_rand(1, 100) <= 70;
                $seq = $d + 1;
                $docId = $nextDocId++;
                foreach ($docItems as $it) { $it[$fkCol] = $docId; $itemBuf[] = $it; }
                $docBuf[] = [
                    'id' => $docId,
                    'company_id' => $c->id,
                    'invoice_number' => ($c->invoice_number_prefix ?: 'INV') . 'DI' . str_pad($seq, 5, '0', STR_PAD_LEFT),
                    'internal_invoice_number' => ($c->invoice_number_prefix ?: 'INV') . 'DI' . str_pad($seq, 5, '0', STR_PAD_LEFT),
                    'status' => $locked ? 'locked' : 'draft',
                    'fbr_invoice_number' => $locked ? (mt_rand(1, 100) <= 85 ? $c->ntn . 'DI' . date('ymd', $ts) . mt_rand(1000, 9999) : null) : null,
                    'fbr_status' => $locked ? 'submitted' : null,
                    'invoice_date' => date('Y-m-d', $ts),
                    'buyer_name' => pick($prefixes) . ' ' . pick($suffixByProduct['di']),
                    'buyer_ntn' => (string) mt_rand(1000000, 9999999),
                    'buyer_registration_type' => pick(['Registered', 'Unregistered']),
                    'supplier_province' => 'Punjab', 'destination_province' => pick(['Punjab', 'Sindh', 'KPK']),
                    'total_value_excluding_st' => round($sub, 2), 'total_sales_tax' => round($tax, 2),
                    'total_amount' => round($sub + $tax, 2), 'net_receivable' => round($sub + $tax, 2),
                    'document_type' => 'Sale Invoice',
                    'created_at' => $dt, 'updated_at' => $dt,
                ];
                if (count($docBuf) >= 400) $flush($docBuf, $itemBuf);
            } elseif ($product === 'pos') {
                $method = pick(['cash', 'cash', 'cash', 'card', 'digital']);
                $rate = $method === 'cash' ? 16 : 8;
                $sub = 0; $docItems = [];
                for ($k = 0; $k < $numItems; $k++) {
                    [$iname, $price] = pick($posItems);
                    $qty = mt_rand(1, 5);
                    $line = round($price * $qty, 2); $lineTax = round($line * $rate / 100, 2);
                    $sub += $line;
                    $docItems[] = ['item_type' => 'manual', 'item_id' => null, 'item_name' => $iname, 'quantity' => $qty, 'unit_price' => $price, 'subtotal' => $line, 'is_tax_exempt' => 0, 'tax_rate' => $rate, 'tax_amount' => $lineTax, 'created_at' => $dt, 'updated_at' => $dt];
                }
                $tax = round($sub * $rate / 100, 2);
                $total = (int) round($sub + $tax); // whole-rupee convention
                $isStandalone = ($c->pos_integration_mode ?? null) === 'standalone';
                $reportingOn = !$isStandalone && (int) $c->pra_reporting_enabled === 1;
                $praStatus = $reportingOn ? (mt_rand(1, 100) <= 90 ? 'submitted' : 'offline') : null;
                $docId = $nextDocId++;
                foreach ($docItems as $it) { $it[$fkCol] = $docId; $itemBuf[] = $it; }
                $docBuf[] = [
                    'id' => $docId,
                    'company_id' => $c->id, 'invoice_number' => 'POS-' . $c->id . '-' . str_pad($d + 1, 5, '0', STR_PAD_LEFT),
                    'invoice_mode' => 'pra', 'customer_name' => mt_rand(1, 100) <= 40 ? pick($firstNames) . ' ' . pick($lastNames) : null,
                    'subtotal' => round($sub, 2), 'discount_amount' => 0, 'tax_rate' => $rate, 'tax_amount' => $tax,
                    'total_amount' => $total, 'payment_method' => $method,
                    'cash_received' => $method === 'cash' ? $total : null, 'change_due' => 0,
                    'status' => 'completed',
                    'pra_invoice_number' => $praStatus === 'submitted' ? mt_rand(100000000, 999999999) . '' : null,
                    'pra_status' => $praStatus,
                    'created_by' => $uid, 'created_at' => $dt, 'updated_at' => $dt,
                ];
                if (count($docBuf) >= 400) $flush($docBuf, $itemBuf);
            } else { // fbrpos
                $sub = 0; $tax = 0; $docItems = [];
                for ($k = 0; $k < $numItems; $k++) {
                    [$iname, $hs, $price] = pick($fbrItems);
                    $qty = mt_rand(1, 4);
                    $line = round($price * $qty, 2); $lineTax = round($line * 0.18, 2);
                    $sub += $line; $tax += $lineTax;
                    $docItems[] = ['item_name' => $iname, 'hs_code' => $hs, 'uom' => 'PCS', 'quantity' => $qty, 'unit_price' => $price, 'discount' => 0, 'tax_rate' => 18, 'tax_amount' => $lineTax, 'subtotal' => $line, 'total' => round($line + $lineTax, 2), 'is_tax_exempt' => 0, 'created_at' => $dt, 'updated_at' => $dt];
                }
                $reportingOn = (int) $c->fbr_reporting_enabled === 1;
                $fbrStatus = $reportingOn ? (mt_rand(1, 100) <= 92 ? 'submitted' : 'pending') : null;
                $total = round($sub + $tax + ($reportingOn ? 1 : 0), 2); // Rs 1 FBR fee when reporting
                $docId = $nextDocId++;
                foreach ($docItems as $it) { $it[$fkCol] = $docId; $itemBuf[] = $it; }
                $docBuf[] = [
                    'id' => $docId,
                    'company_id' => $c->id, 'invoice_number' => 'FBR-' . $c->id . '-' . str_pad($d + 1, 5, '0', STR_PAD_LEFT),
                    'invoice_mode' => 'fbr', 'transaction_type' => 'sale',
                    'customer_name' => mt_rand(1, 100) <= 30 ? pick($firstNames) . ' ' . pick($lastNames) : null,
                    'subtotal' => round($sub, 2), 'discount_amount' => 0, 'tax_rate' => 18, 'tax_amount' => round($tax, 2),
                    'fbr_service_charge' => $reportingOn ? 1 : 0, 'total_amount' => $total,
                    'payment_method' => pick(['cash', 'cash', 'card']), 'status' => 'completed',
                    'fbr_invoice_number' => $fbrStatus === 'submitted' ? mt_rand(1000000000, 2000000000) . '' : null,
                    'fbr_status' => $fbrStatus,
                    'created_by' => $uid, 'created_at' => $dt, 'updated_at' => $dt,
                ];
                if (count($docBuf) >= 400) $flush($docBuf, $itemBuf);
            }
        }
    }
    $flush($docBuf, $itemBuf);

    echo " ✓ ({$docCount} docs, {$itemCount} items)\n";
    $summary[$product] = ['companies' => count($companies), 'docs' => $docCount, 'items' => $itemCount];
}

echo "\nDONE:\n";
foreach ($summary as $p => $s) echo "  $p: {$s['companies']} companies, {$s['docs']} documents, {$s['items']} items\n";
