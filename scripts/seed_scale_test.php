<?php
/**
 * Scale-test seeder: 1000 realistic companies PER product (di / pos / fbrpos).
 * All seeded rows are identifiable via email domain @scaletest.pk for one-command purge.
 *
 * Run:   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER -u PGPASSWORD -u PGDATABASE php scripts/seed_scale_test.php
 * Add:   env -u ... php scripts/seed_scale_test.php --more=500   (adds N MORE companies per product, continues numbering)
 * Purge: env -u ... php scripts/seed_scale_test.php --purge
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

const DOMAIN = 'scaletest.pk';

$perProduct = 1000;
foreach ($argv as $arg) {
    if (preg_match('/^--more=(\d+)$/', $arg, $m)) $perProduct = (int) $m[1];
}

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

// ---------------------------------------------------------------------------
// Pakistani business taxonomy: the most common business types per product,
// weighted by real-world frequency. Each type carries its own name suffixes,
// (optional) home cities, and an item pool so a pharmacy sells medicine and a
// rice mill sells rice — never shampoo on a steel invoice.
// DI items: [name, hs_code, price] | POS items: [name, price] | FBR items: [name, hs_code, price]
// ---------------------------------------------------------------------------
$bizCatalog = [
'di' => [
    ['w'=>18,'suffixes'=>['Textiles','Textile Mills','Weaving Mills','Cotton Ginners','Fabrics','Textile Industries'],'cities'=>['Faisalabad','Lahore','Karachi','Multan'],'items'=>[
        ['Cotton Yarn 20/1 (bag)','5205.1100',28500],['Grey Fabric 100m Than','5208.1000',12000],['Polyester Yarn DTY (kg)','5402.3300',560],
        ['Dyed Lawn Fabric (m)','5208.5200',285],['Cotton Waste Comber (kg)','5202.9900',180],['Sizing Starch 25kg','3505.1000',4100]]],
    ['w'=>14,'suffixes'=>['Traders','Trading Company','Brothers','& Sons','Enterprises','Distributors','Agencies'],'items'=>[
        ['Cooking Oil Carton 5x5L','1511.9020',12250],['Sugar 50kg Bag','1701.9910',8250],['Washing Powder Carton 12x1kg','3402.2000',4680],
        ['Biscuits Carton 12x6','1905.3100',5200],['Black Tea Carton 12x950g','0902.3000',28000],['Beverage 1.5L Case x6','2202.1010',1050]]],
    ['w'=>8,'suffixes'=>['Steel Mills','Steel Traders','Pipe Mills','Steel Industries','Steel & Iron Merchants'],'cities'=>['Gujranwala','Lahore','Karachi'],'items'=>[
        ['Steel Bar G60 (ton)','7214.9910',255000],['MS Pipe 2in (ton)','7306.3000',238000],['GI Sheet 22g (ton)','7210.4900',265000],['Angle Iron (ton)','7216.2100',242000]]],
    ['w'=>8,'suffixes'=>['Rice Mills','Rice Traders','Rice Exporters','Agro Mills'],'cities'=>['Sheikhupura','Gujranwala','Sahiwal','Okara'],'items'=>[
        ['Super Kernel Basmati 25kg','1006.3010',6800],['IRRI-6 White Rice 50kg','1006.3090',7200],['Rice Bran (ton)','2302.4000',52000],['Broken Rice 50kg','1006.4000',4300]]],
    ['w'=>6,'suffixes'=>['Flour Mills','Roller Flour Mills'],'items'=>[
        ['Fine Atta 80kg','1101.0010',7400],['Maida 80kg','1101.0020',7800],['Wheat Bran 40kg','2302.3000',2100],['Suji 50kg','1103.1100',5900]]],
    ['w'=>8,'suffixes'=>['Chemicals','Chemical Industries','Polymers','Plastics Industries'],'cities'=>['Karachi','Lahore','Faisalabad'],'items'=>[
        ['Caustic Soda 50kg','2815.1100',5200],['PVC Resin (ton)','3904.1000',345000],['Polyester Chips (kg)','3907.6110',340],['HDPE Granules (kg)','3901.2000',395],['Hydrogen Peroxide 35kg','2847.0000',3800]]],
    ['w'=>6,'suffixes'=>['Packages','Packaging Industries','Paper Products','Printing & Packaging'],'items'=>[
        ['Corrugated Cartons (100 pcs)','4819.1000',12000],['Kraft Paper Reel (kg)','4804.1100',185],['BOPP Tape Carton','3919.1010',4600],['Shopping Bags 10kg','3923.2100',3400]]],
    ['w'=>6,'suffixes'=>['Foods','Food Industries','Oil Mills','Banaspati Mills'],'items'=>[
        ['Vegetable Ghee 16kg Tin','1516.2010',6800],['Banaspati Carton 5x2.5kg','1516.2010',5400],['Recorded Spices Carton','0910.9100',7200],['Squash 12x800ml Carton','2202.9900',3960]]],
    ['w'=>5,'suffixes'=>['Surgical Works','Surgical Industries','Instruments Company','Surgico'],'cities'=>['Sialkot'],'items'=>[
        ['Surgical Scissors (dozen)','9018.9010',5400],['Artery Forceps Set (dozen)','9018.9090',7800],['Dental Instruments Kit','9018.4900',12500],['Manicure Sets (gross)','8214.2000',28800]]],
    ['w'=>5,'suffixes'=>['Sports Industries','Sports Works','Sporting Goods'],'cities'=>['Sialkot'],'items'=>[
        ['Match Football (dozen)','9506.6200',21600],['Training Football (dozen)','9506.6200',9600],['Boxing Gloves (dozen pair)','4203.2100',26400],['Team Sports Kits (set)','6211.3300',4200]]],
    ['w'=>5,'suffixes'=>['Tanneries','Leather Industries','Leather Works'],'cities'=>['Sialkot','Karachi','Kasur'],'items'=>[
        ['Leather Jacket (pc)','4203.1000',9500],['Finished Leather (sq ft)','4107.1200',480],['Leather Working Gloves (dozen)','4203.2990',7200]]],
    ['w'=>6,'suffixes'=>['Cables','Electric Industries','Fan Industries','Engineering Works'],'cities'=>['Gujranwala','Gujrat','Lahore'],'items'=>[
        ['Copper Wire 7/29 (90m)','8544.4990',12800],['PVC Insulated Cable 3/29 (90m)','8544.4990',6900],['Ceiling Fan 56in','8414.5110',8200],['Electric Motor 1HP','8501.4010',13400]]],
    ['w'=>5,'suffixes'=>['Pharmaceuticals','Laboratories','Healthcare','Pharma Distributors'],'items'=>[
        ['Paracetamol 500mg (1000 tabs)','3004.9099',1450],['Antibiotic Syrup Carton x48','3004.2000',8600],['ORS Sachets (box 100)','3004.9010',1900],['Surgical Gauze Carton','3005.9010',5200]]],
    ['w'=>4,'suffixes'=>['Auto Industries','Engineering','Auto Parts Industries','Motors & Parts'],'cities'=>['Lahore','Karachi','Gujranwala'],'items'=>[
        ['Motorcycle Chain Kits (10 sets)','8714.1090',12500],['Brake Pads Carton','8708.3000',9800],['Air Filters (50 pcs)','8421.3100',14000],['Shock Absorbers (10 pair)','8708.8000',38000]]],
],
'pos' => [
    ['w'=>20,'suffixes'=>['Kiryana Store','General Store','Cash & Carry','Mart','Superstore','Karyana & General Store'],'items'=>[
        ['Sugar 1kg',165],['Fine Atta 10kg',1150],['Basmati Rice 5kg',1650],['Cooking Oil 5L',2450],['Dalda Ghee 1kg',560],
        ['Daal Chana 1kg',330],['Milk Pack 1L',220],['Eggs Dozen',330],['Bread Large',150],['Surf 1kg',390],
        ['Coke 1.5L',180],['Lays Masala',100],['Tea 480g',1250],['Rooh Afza 800ml',420]]],
    ['w'=>14,'rest'=>true,'suffixes'=>['Restaurant','Biryani House','Tikka House','BBQ & Grill','Karahi Point','Hotel & Restaurant','Shinwari Restaurant','Fast Food'],'items'=>[
        ['Chicken Biryani',450],['Beef Pulao',500],['Chicken Karahi Full',1800],['Chicken Karahi Half',950],['Seekh Kabab (4 pcs)',480],
        ['Chicken Tikka',380],['Naan',25],['Roghni Naan',40],['Raita',80],['Fresh Salad',100],['Cold Drink Regular',120],['Mineral Water Small',90],['Doodh Patti Chai',80],['Zinger Burger',550],['Fries Large',300]]],
    ['w'=>10,'suffixes'=>['Pharmacy','Medical Store','Medicos','Medical & General Store'],'items'=>[
        ['Panadol Extra Strip',45],['Augmentin 625mg (6 tabs)',590],['Disprin Strip',30],['Cough Syrup 120ml',180],['ORS Sachet',25],
        ['Vitamin C 500mg (20)',150],['Digital Thermometer',350],['Hand Sanitizer 250ml',250],['Elastic Bandage',120],['BP Monitor',4500]]],
    ['w'=>10,'suffixes'=>['Garments','Cloth House','Fabrics','Dress Palace','Collections'],'items'=>[
        ['Ladies Lawn Suit 3pc',4850],['Gents Kurta',2450],['Stitched Shalwar Qameez',3200],['Chiffon Dupatta',850],['Gents Trouser',1450],['Kids Suit',1800],['Cotton Than 5m',3750]]],
    ['w'=>9,'suffixes'=>['Mobiles','Mobile Zone','Mobile Center','Telecom','Communications'],'items'=>[
        ['Mobile Charger Fast',850],['Handsfree',450],['Mobile Cover',350],['Tempered Glass',250],['Power Bank 10000mAh',2800],['Memory Card 32GB',1200],['Data Cable Type-C',400],['Bluetooth Speaker',3200]]],
    ['w'=>8,'suffixes'=>['Sweets & Bakers','Bakers','Bakery & Sweets','Sweets House','Nimco & Sweets'],'items'=>[
        ['Cake 2lb',2200],['Gulab Jamun 1kg',900],['Barfi 1kg',1100],['Cake Rusk 500g',380],['Chicken Patties',120],['Samosa',40],['Bread Large',150],['Nimco Mix 500g',450],['Biscuits 1kg Tin',550]]],
    ['w'=>6,'suffixes'=>['Electronics','Electric Store','Home Appliances','Electronics & Appliances'],'items'=>[
        ['LED Bulb 12W',350],['Extension Lead 5m',1200],['Dry Iron',4200],['Electric Kettle',3800],['Table Fan',6500],['LED TV 32in',32000],['Room Cooler',18500]]],
    ['w'=>5,'suffixes'=>['Hardware Store','Hardware & Sanitary','Paint & Hardware','Sanitary Store'],'items'=>[
        ['PVC Pipe 4in',950],['Cement Bag 50kg',1180],['Paint Gallon',3800],['Door Lock Set',1500],['Tap Mixer',2800],['Plywood Sheet',3200],['Wire Nails 5kg',1400]]],
    ['w'=>5,'suffixes'=>['Autos','Auto Parts','Auto Store','Bike Center','Auto Care'],'items'=>[
        ['Engine Oil 4L',3200],['Brake Shoe',850],['Spark Plug',450],['Chain Kit 70cc',1250],['Motorcycle Tyre',3500],['Battery 9AH',5200],['Air Filter',380]]],
    ['w'=>4,'suffixes'=>['Shoe Palace','Shoes','Footwear','Shoe Center'],'items'=>[
        ['Sports Joggers',3950],['Gents Sandals',1800],['Chappal',850],['School Shoes',2200],['Ladies Pumps',2400]]],
    ['w'=>3,'suffixes'=>['Cosmetics','Beauty Palace','Cosmetics & Jewellery'],'items'=>[
        ['Lipstick',850],['Foundation',1900],['Face Wash 100ml',450],['Nail Polish',300],['Hair Oil 200ml',380],['Kajal',250]]],
    ['w'=>3,'suffixes'=>['Book Depot','Stationers','Books & Stationery','Kitab Ghar'],'items'=>[
        ['Register 200pg',250],['Ball Pen Box',300],['Notebooks (6 pack)',540],['Geometry Box',350],['Color Pencils Set',480],['Chart Paper',30],['Paper Ream A4',1350]]],
    ['w'=>3,'suffixes'=>['Milk Shop','Dairy & Sweets','Doodh Dahi Shop'],'items'=>[
        ['Fresh Milk 1L',220],['Dahi 1kg',280],['Butter 250g',600],['Desi Ghee 1kg',2600],['Lassi Glass',120],['Khoya 1kg',1400]]],
],
'fbrpos' => [
    ['w'=>20,'suffixes'=>['Boutique','Fashion','Collections','Fabrics','Pret Wear','Apparel'],'items'=>[
        ['Ladies Lawn Suit 3pc','6204.2000',4850],['Gents Kurta','6205.2000',2450],['Formal Shirt','6205.2000',3200],['Denim Jeans','6203.4200',3800],
        ['Kids Suit','6209.2000',1800],['Silk Dupatta','6214.1000',1500],['Waistcoat','6211.3900',4500]]],
    ['w'=>12,'suffixes'=>['Departmental Store','Superstore','Hyper Mart','Cash & Carry','Grand Mart'],'items'=>[
        ['Cooking Oil 5L','1511.9020',2450],['Diapers Mega Pack','9619.0010',2200],['Imported Chocolates','1806.3100',850],['Shampoo 700ml','3305.1000',720],
        ['Detergent 3kg','3402.2000',1150],['Basmati Rice 5kg','1006.3010',1650],['Cheddar Cheese 500g','0406.9000',950]]],
    ['w'=>12,'suffixes'=>['Electronics','Mobiles & Electronics','Gadget Store','Electronics Gallery'],'items'=>[
        ['Fast Charger 33W','8504.4020',850],['Wireless Earbuds','8518.3000',4500],['Smart Watch','8517.6200',6800],['LED TV 32in','8528.7212',32000],
        ['Juicer Blender','8509.4010',5600],['Power Bank 10000mAh','8507.6000',2800]]],
    ['w'=>10,'suffixes'=>['Shoes','Footwear','Shoe Gallery'],'items'=>[
        ['Sports Joggers','6404.1100',3950],['Formal Leather Shoes','6403.5900',5500],['Gents Sandals','6402.9900',1800],['School Shoes','6405.9000',2200],['Ladies Heels','6404.1990',3200]]],
    ['w'=>8,'suffixes'=>['Pharmacy','Pharmacy & Superstore','Medicare Pharmacy'],'items'=>[
        ['Panadol Extra Strip','3004.9099',45],['Multivitamins (30)','3004.5000',950],['Sunblock SPF60','3304.9900',1800],['Baby Formula 900g','1901.1000',3600],
        ['Glucometer Strips (50)','9027.8000',1600],['Cough Syrup 120ml','3004.9010',180]]],
    ['w'=>8,'suffixes'=>['Bakers','Bakers & Confectioners','Sweets & Bakers','Cake Studio'],'items'=>[
        ['Cake 2lb','1905.9000',2200],['Mithai 1kg','1704.9000',1100],['Brownies Box','1905.9000',950],['Cookies 500g','1905.3100',850],['Chicken Patties','1905.9000',180]]],
    ['w'=>8,'suffixes'=>['Cosmetics','Beauty Store','Perfumes & Cosmetics','Fragrances'],'items'=>[
        ['Perfume 100ml','3303.0010',2800],['Foundation','3304.9100',1900],['Face Serum 30ml','3304.9900',2400],['Lipstick','3304.1000',850],['Makeup Kit','3304.2000',5200]]],
    ['w'=>6,'suffixes'=>['Jewellers','Jewellery House','Gems & Jewels'],'items'=>[
        ['Artificial Jewellery Set','7117.1900',3800],['Bangles Set','7117.9000',1200],['Silver Ring','7113.1100',2600],['Bridal Set Artificial','7117.1900',8500],['Ear Tops','7117.1900',950]]],
    ['w'=>6,'suffixes'=>['Home Textiles','Linen House','Bed & Bath','Home Collection'],'items'=>[
        ['Bed Sheet King','6304.1900',3200],['Comforter Set','9404.9000',7800],['Towel Set (3)','6302.6000',2400],['Curtains Pair','6303.9200',4500],['Pillow Covers Pair','6304.9200',850]]],
    ['w'=>5,'suffixes'=>['Opticals','Optics & Eyewear','Watch House','Time House'],'items'=>[
        ['Eyeglass Frame','9003.1100',3500],['Sunglasses','9004.1000',2800],['Wrist Watch','9102.1100',6500],['Wall Clock','9105.2100',1450],['Lens Solution','3307.9000',650]]],
    ['w'=>5,'suffixes'=>['Sports','Sports House','Sports & Fitness'],'items'=>[
        ['Football','9506.6200',2200],['Cricket Bat','9506.9910',4500],['Badminton Racket','9506.5100',3200],['Gym Gloves','4203.2100',850],['Skipping Rope','9506.9100',450]]],
],
];

function pickBiz(array $types): array {
    $sum = 0; foreach ($types as $t) $sum += $t['w'];
    $r = mt_rand(1, $sum);
    foreach ($types as $t) { $r -= $t['w']; if ($r <= 0) return $t; }
    return $types[0];
}

$hash = password_hash('Scale@12345', PASSWORD_BCRYPT); // one hash reused (speed)
$now = date('Y-m-d H:i:s');

$plans = [];
foreach (DB::table('pricing_plans')->get() as $p) $plans[$p->product_type][] = $p;

function pick(array $a) { return $a[array_rand($a)]; }

// Continue numbering from any previous run so batches never collide.
$maxEmailNum = 0;
foreach (DB::table('companies')->where('email', 'like', '%@' . DOMAIN)->pluck('email') as $e) {
    if (preg_match('/(\d+)@/', $e, $m)) $maxEmailNum = max($maxEmailNum, (int) $m[1]);
}
$emailSeq = $maxEmailNum + 1;
$phoneSeq = 300000000 + $maxEmailNum;
$maxNtn = (int) DB::table('companies')->where('email', 'like', '%@' . DOMAIN)
    ->selectRaw('MAX(CAST(ntn AS UNSIGNED)) m')->value('m');
$ntnSeq = max(8100000, $maxNtn + 1);
if ($maxEmailNum > 0) echo "Incremental batch: continuing from #" . $maxEmailNum . " (ntn {$ntnSeq})\n";
$summary = [];

foreach (['di', 'pos', 'fbrpos'] as $product) {
    echo strtoupper($product) . ": companies";
    $bizTypes = $bizCatalog[$product];
    $companies = []; $meta = [];
    for ($i = 0; $i < $perProduct; $i++) {
        $ownerFirst = pick($firstNames); $ownerLast = pick($lastNames);
        $biz = pickBiz($bizTypes);
        $city = isset($biz['cities']) && mt_rand(1, 100) <= 70 ? pick($biz['cities']) : pick($cities);
        $roll = mt_rand(1, 100);
        if ($roll <= 55)     $name = pick($prefixes) . ' ' . pick($biz['suffixes']);
        elseif ($roll <= 80) $name = $ownerLast . ' ' . pick($biz['suffixes']); // family-named businesses
        else                 $name = $city . ' ' . pick($biz['suffixes']); // city-named businesses
        $email = 'st' . $product . $emailSeq++ . '@' . DOMAIN;
        $ntn = (string)($ntnSeq++);
        $created = date('Y-m-d H:i:s', strtotime("-" . mt_rand(5, 540) . " days -" . mt_rand(0, 86400) . " seconds"));
        $pending = mt_rand(1, 100) <= 5;
        $isRestaurant = $product !== 'di' && !empty($biz['rest']);
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
            $row['pos_setup_completed'] = 1; // established shops — skip first-run setup wizard
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
        $meta[$email] = ['created' => $created, 'pending' => $pending, 'standalone' => $standalone, 'reporting' => $reporting, 'owner' => $ownerFirst . ' ' . $ownerLast, 'items' => $biz['items']];
    }
    foreach (array_chunk($companies, 500) as $chunk) DB::table('companies')->insert($chunk);
    echo " ✓";

    // map ids — ONLY the emails created in THIS run (incremental-safe)
    $newEmails = array_column($companies, 'email');
    $rows = collect();
    foreach (array_chunk($newEmails, 500) as $ec) {
        $rows = $rows->merge(DB::table('companies')->whereIn('email', $ec)->get(['id', 'email', 'name', 'ntn', 'created_at', 'invoice_number_prefix', 'pos_integration_mode', 'pra_reporting_enabled', 'fbr_reporting_enabled', 'restaurant_mode', 'status']));
    }

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
    $userIds = collect();
    foreach (array_chunk($newEmails, 500) as $ec) {
        $userIds = $userIds->union(DB::table('users')->whereIn('email', $ec)->pluck('id', 'company_id'));
    }
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
            // 23 Aug 2026: BOTH POS lines store the ANNUAL rate in `price`.
            // fbrpos used to be a monthly rate charged x12x0.94 — never again.
            $cycle = 'annual'; $disc = 0;
            $final = $plan->price;
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
                    [$desc, $hs, $price] = pick($meta[$c->email]['items']);
                    $qty = $price >= 20000 ? mt_rand(1, 6) : mt_rand(1, 40);
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
                    'buyer_name' => pick($prefixes) . ' ' . pick(['Traders', 'Enterprises', 'Brothers', '& Sons', 'Corporation', 'Industries', 'Impex', 'International', 'Textiles', 'Foods']),
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
                    [$iname, $price] = pick($meta[$c->email]['items']);
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
                    [$iname, $hs, $price] = pick($meta[$c->email]['items']);
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
