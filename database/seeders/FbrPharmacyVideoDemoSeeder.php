<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;

/**
 * FBR POS **Pharmacy Mode** video demo shop — fictional "Shifa Medical Store".
 *
 * DEV ONLY. Used to record the pharmacy walkthrough video: medicines with
 * salt/strength/schedule, batches with mixed expiries (one short-dated, one
 * expired, the rest healthy), suppliers, khata customers and a week of sales
 * history so the dashboard and reports are not empty on camera.
 *
 * Idempotent and re-runnable between takes: the products the video CREATES on
 * camera (Augmentin) are deleted here so every take starts from the same shelf,
 * and every batch quantity is reset to its scripted value.
 */
class FbrPharmacyVideoDemoSeeder extends Seeder
{
    public const COMPANY_NAME = 'Shifa Medical Store';
    public const LOGIN_EMAIL = 'pharmacydemo@nestpos.pk';

    /** Products the scenario adds on camera — must NOT exist when the take starts. */
    public const ON_CAMERA_PRODUCTS = ['Augmentin 625mg'];

    public function run(): void
    {
        // 🔒 FAIL-CLOSED — this seeder rewrites stock/ledgers for a demo shop.
        // APP_ENV is 'production' even in the workspace, so the shared guard
        // requires the recording script's explicit opt-in AND the exact local
        // staging connection (driver + db name + host), never a substring.
        \App\Support\DevStagingGuard::assertLocalStaging('FbrPharmacyVideoDemoSeeder');
        $password = FbrVideoDemoShopSeeder::loginPassword();

        DB::beginTransaction();
        try {
            // ── Company ──────────────────────────────────────────────────
            $existing = DB::table('companies')->where('email', self::LOGIN_EMAIL)->where('product_type', 'fbrpos')->first();
            if ($existing && !$existing->is_internal_account) {
                throw new \RuntimeException('Refusing: the demo login belongs to a non-internal company.');
            }
            $companyData = [
                'name' => self::COMPANY_NAME,
                'owner_name' => 'Hakeem Sahab',
                'ntn' => '9999999999997',
                'phone' => '03000000002',
                'address' => 'Hospital Road, Satellite Town, Rawalpindi',
                'city' => 'Rawalpindi',
                'province' => 'Punjab',
                'sector_type' => 'Retail',
                'business_category' => 'pharmacy',
                'product_type' => 'fbrpos',
                'status' => 'approved',
                'company_status' => 'active',
                'is_internal_account' => true,
                'onboarding_completed' => true,
                'standard_tax_rate' => 18,
                'default_language' => 'en',
                'fbr_pos_enabled' => true,
                'fbr_universal_enabled' => true,
                // Reporting ON in fiscal_device+agent mode: bills queue 'pending'
                // for the Desktop Agent, so the screen shows FBR reporting with
                // zero regulator calls.
                'fbr_reporting_enabled' => true,
                'fbr_connection_mode' => 'fiscal_device',
                'agent_enabled' => true,
                'inventory_enabled' => true,
                'pharmacy_mode' => true,
                'updated_at' => now(),
            ];
            // The payment popup normally auto-closes after 10s; the narration
            // needs it on screen for ~25s (FBR flip + receipt batch/expiry).
            if (Schema::hasColumn('companies', 'pos_receipt_autoclose_seconds')) {
                $companyData['pos_receipt_autoclose_seconds'] = 0;
            }
            $companyData += [
                'feature_flags' => json_encode([
                    'inventory' => true,
                    'barcode' => true,
                    'customer_profile' => true,
                    'pharmacy' => true,
                    'batch_expiry' => true,
                    'loose_sale' => true,
                    'prescription' => true,
                ]),
                'updated_at' => now(),
            ];
            if ($existing) {
                $companyId = $existing->id;
                DB::table('companies')->where('id', $companyId)->update($companyData);
            } else {
                $company = \App\Models\Company::create($companyData + ['email' => self::LOGIN_EMAIL]);
                $companyId = $company->id;
                DB::table('companies')->where('id', $companyId)->update($companyData);
            }

            // ── Owner login ──────────────────────────────────────────────
            $userData = [
                'name' => 'Hakeem Sahab',
                'password' => Hash::make($password),
                'company_id' => $companyId,
                'product_type' => 'fbrpos',
                'role' => 'company_admin',
                'pos_role' => null,
                'is_active' => true,
                'language' => 'en',
                'pra_elaan_seen_at' => now(),
                'updated_at' => now(),
            ];
            // Only a user that already belongs to THIS demo company may be
            // rewritten; any other row with the same email is a collision.
            $collisions = DB::table('users')->where('email', self::LOGIN_EMAIL)->get(['id', 'company_id', 'product_type']);
            $user = $collisions->first(fn ($u) => (int) $u->company_id === (int) $companyId && $u->product_type === 'fbrpos');
            if ($collisions->count() > ($user ? 1 : 0)) {
                throw new \RuntimeException('Refusing: ' . self::LOGIN_EMAIL . ' is also used by a user outside the demo company.');
            }
            if ($user) {
                DB::table('users')->where('id', $user->id)->update($userData);
                $userId = $user->id;
            } else {
                $userId = DB::table('users')->insertGetId($userData + ['email' => self::LOGIN_EMAIL, 'created_at' => now()]);
            }

            // ── Subscription on the pharmacy-capable plan ─────────────────
            $planId = DB::table('pricing_plans')->where('product_type', 'fbrpos')->where('pharmacy_enabled', true)->orderBy('id')->value('id')
                ?: DB::table('pricing_plans')->where('product_type', 'fbrpos')->orderBy('id')->value('id');
            DB::table('subscriptions')->where('company_id', $companyId)->update(['active' => false]);
            DB::table('subscriptions')->insert([
                'company_id' => $companyId,
                'pricing_plan_id' => $planId,
                'billing_cycle' => 'annual',
                'start_date' => now()->toDateString(),
                'end_date' => now()->addYears(10)->toDateString(),
                'active' => true,
                'override_type' => 'lifetime',
                'override_granted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // ── Silence every nag overlay for the demo user ──────────────
            $unseen = DB::table('app_updates')
                ->whereNotIn('id', DB::table('app_update_seens')->where('user_id', $userId)->pluck('app_update_id'))
                ->pluck('id');
            foreach ($unseen as $updateId) {
                DB::table('app_update_seens')->insert([
                    'app_update_id' => $updateId, 'user_id' => $userId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('survey_responses')) {
                foreach (DB::table('surveys')->pluck('id') as $surveyId) {
                    DB::table('survey_responses')->updateOrInsert(
                        ['survey_id' => $surveyId, 'user_id' => $userId],
                        ['company_id' => $companyId, 'answered_at' => now(), 'created_at' => now(), 'updated_at' => now()]
                    );
                }
            }

            // ── Suppliers ────────────────────────────────────────────────
            $supplierIds = [];
            foreach ([
                ['Muller & Phipps', '0512345678', 'Islamabad'],
                ['Premier Distributors', '0519876543', 'Rawalpindi'],
                ['Getz Pharma Distributor', '0515551234', 'Rawalpindi'],
            ] as [$sname, $sphone, $scity]) {
                DB::table('suppliers')->updateOrInsert(
                    ['company_id' => $companyId, 'name' => $sname],
                    ['phone' => $sphone, 'city' => $scity, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]
                );
                $supplierIds[$sname] = DB::table('suppliers')->where('company_id', $companyId)->where('name', $sname)->value('id');
            }

            // ── Medicines ────────────────────────────────────────────────
            // [name, generic, strength, form, manufacturer, schedule, rx, units_per_strip, loose, price, cost, barcode, shelf]
            // Stocked unit = one strip (or one bottle/pack); loose = single tablets.
            $meds = [
                ['Panadol 500mg',        'Paracetamol',                 '500mg',  'tablet',  'GSK',      'OTC', 0, 10, 1, 40,  34,  '8964000111014', 'A-1'],
                ['Panadol Extra',        'Paracetamol + Caffeine',      '500mg',  'tablet',  'GSK',      'OTC', 0, 10, 1, 60,  51,  '8964000111021', 'A-1'],
                ['Brufen 400mg',         'Ibuprofen',                   '400mg',  'tablet',  'Abbott',   'OTC', 0, 10, 1, 65,  56,  '8964000111038', 'A-2'],
                ['Disprin',              'Aspirin',                     '300mg',  'tablet',  'Reckitt',  'OTC', 0, 10, 1, 25,  20,  '8964000111045', 'A-2'],
                ['Arinac Forte',         'Ibuprofen + Pseudoephedrine', '400mg',  'tablet',  'Abbott',   'OTC', 0, 10, 1, 90,  76,  '8964000111052', 'A-3'],
                ['Risek 20mg',           'Omeprazole',                  '20mg',   'capsule', 'Getz',     'G',   0, 14, 1, 270, 232, '8964000111069', 'B-1'],
                ['Flagyl 400mg',         'Metronidazole',               '400mg',  'tablet',  'Sanofi',   'G',   1, 10, 1, 80,  68,  '8964000111076', 'B-1'],
                ['Amoxil 500mg',         'Amoxicillin',                 '500mg',  'capsule', 'GSK',      'H',   1, 12, 0, 240, 205, '8964000111083', 'B-2'],
                ['Glucophage 500mg',     'Metformin',                   '500mg',  'tablet',  'Merck',    'G',   1, 10, 1, 95,  81,  '8964000111090', 'B-3'],
                ['Zyrtec 10mg',          'Cetirizine',                  '10mg',   'tablet',  'GSK',      'OTC', 0, 10, 1, 85,  72,  '8964000111106', 'A-3'],
                ['Calpol Syrup 60ml',    'Paracetamol',                 '120mg/5ml', 'syrup', 'GSK',    'OTC', 0, 0,  0, 95,  81,  '8964000111113', 'C-1'],
                ['Ventolin Inhaler',     'Salbutamol',                  '100mcg', 'inhaler', 'GSK',      'G',   1, 0,  0, 380, 325, '8964000111120', 'C-2'],
                ['ORS Sachet',           'Oral Rehydration Salts',      '',       'sachet',  'Searle',   'OTC', 0, 0,  0, 20,  16,  '8964000111137', 'C-1'],
                ['Surbex-Z',             'Vitamin B-Complex + Zinc',    '',       'tablet',  'Abbott',   'OTC', 0, 30, 0, 230, 196, '8964000111144', 'C-3'],
            ];
            // On-camera products start absent.
            $onCam = DB::table('products')->where('company_id', $companyId)->whereIn('name', self::ON_CAMERA_PRODUCTS)->pluck('id');
            if ($onCam->isNotEmpty()) {
                DB::table('product_batches')->where('company_id', $companyId)->whereIn('product_id', $onCam)->delete();
                DB::table('inventory_stocks')->where('company_id', $companyId)->whereIn('product_id', $onCam)->delete();
                DB::table('inventory_movements')->where('company_id', $companyId)->whereIn('product_id', $onCam)->delete();
                DB::table('fbr_pos_transaction_items')->whereIn('product_id', $onCam)
                    ->whereIn('transaction_id', DB::table('fbr_pos_transactions')->where('company_id', $companyId)->select('id'))->delete();
                DB::table('products')->whereIn('id', $onCam)->delete();
            }

            $productIds = [];
            foreach ($meds as $i => [$name, $generic, $strength, $form, $mfr, $sched, $rx, $ups, $loose, $price, $cost, $barcode, $shelf]) {
                $sku = 'SMS-' . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT);
                DB::table('products')->updateOrInsert(
                    ['company_id' => $companyId, 'sku' => $sku],
                    [
                        'name' => $name,
                        'barcode' => $barcode,
                        'hs_code' => '3004.9099',
                        'uom' => $ups > 0 ? 'PCS' : 'PCS',
                        'default_price' => $price,
                        'cost_price' => $cost,
                        'default_tax_rate' => 0, // medicines: exempt
                        'tax_type' => 'exempt',
                        'is_price_editable' => true,
                        'is_active' => true,
                        'show_on_sale' => true,
                        'generic_name' => $generic,
                        'strength' => $strength ?: null,
                        'dosage_form' => $form,
                        'manufacturer' => $mfr,
                        'drug_schedule' => $sched,
                        'prescription_required' => $rx,
                        'shelf_location' => $shelf,
                        'strips_per_pack' => $ups > 0 ? 1 : null,
                        'units_per_strip' => $ups > 0 ? $ups : null,
                        'allow_loose_sale' => $loose,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
                $pid = DB::table('products')->where('company_id', $companyId)->where('sku', $sku)->value('id');
                $productIds[$name] = ['id' => $pid, 'price' => $price, 'cost' => $cost];
            }

            // ── Batches (the point of the whole video) ───────────────────
            // [product, batch no, expiry (Y-m-d relative), qty, supplier]
            $today = now()->startOfDay();
            $batches = [
                // Panadol: a short-dated strip lot (FEFO picks it first) + a fresh one.
                ['Panadol 500mg',     'PN2409A', $today->copy()->addDays(38)->endOfMonth(),  18, 'Muller & Phipps'],
                ['Panadol 500mg',     'PN2503B', $today->copy()->addMonths(14)->endOfMonth(), 60, 'Muller & Phipps'],
                ['Panadol Extra',     'PX2412',  $today->copy()->addMonths(11)->endOfMonth(), 40, 'Muller & Phipps'],
                // Brufen: one EXPIRED lot still on the shelf (blocked at the counter, claim material) + a good one.
                ['Brufen 400mg',      'BF2308',  $today->copy()->subDays(22),                12, 'Premier Distributors'],
                ['Brufen 400mg',      'BF2502',  $today->copy()->addMonths(9)->endOfMonth(),  45, 'Premier Distributors'],
                ['Disprin',           'DS2501',  $today->copy()->addMonths(16)->endOfMonth(), 80, 'Premier Distributors'],
                ['Arinac Forte',      'AF2410',  $today->copy()->addMonths(7)->endOfMonth(),  30, 'Premier Distributors'],
                // Risek: near expiry (inside the 90-day window).
                ['Risek 20mg',        'RK2406',  $today->copy()->addDays(75)->endOfMonth(),  14, 'Getz Pharma Distributor'],
                ['Risek 20mg',        'RK2501',  $today->copy()->addMonths(12)->endOfMonth(), 36, 'Getz Pharma Distributor'],
                ['Flagyl 400mg',      'FG2411',  $today->copy()->addMonths(10)->endOfMonth(), 25, 'Muller & Phipps'],
                ['Amoxil 500mg',      'AM2412',  $today->copy()->addMonths(13)->endOfMonth(), 22, 'Muller & Phipps'],
                ['Glucophage 500mg',  'GL2410',  $today->copy()->addMonths(15)->endOfMonth(), 50, 'Premier Distributors'],
                ['Zyrtec 10mg',       'ZY2409',  $today->copy()->addMonths(8)->endOfMonth(),  28, 'Muller & Phipps'],
                // Calpol: EXPIRED bottles.
                ['Calpol Syrup 60ml', 'CP2307',  $today->copy()->subDays(40),                 6, 'Muller & Phipps'],
                ['Calpol Syrup 60ml', 'CP2502',  $today->copy()->addMonths(10)->endOfMonth(), 20, 'Muller & Phipps'],
                ['Ventolin Inhaler',  'VT2411',  $today->copy()->addMonths(18)->endOfMonth(), 9,  'Muller & Phipps'],
                ['ORS Sachet',        'OR2501',  $today->copy()->addMonths(20)->endOfMonth(), 120, 'Premier Distributors'],
                ['Surbex-Z',          'SB2412',  $today->copy()->addMonths(12)->endOfMonth(), 15, 'Premier Distributors'],
            ];
            $ids = array_map(fn ($p) => $p['id'], $productIds);
            DB::table('product_batches')->where('company_id', $companyId)->whereIn('product_id', $ids)->delete();
            DB::table('pharmacy_stock_actions')->where('company_id', $companyId)->delete();
            DB::table('pharmacy_claim_items')->where('company_id', $companyId)->delete();
            DB::table('pharmacy_claims')->where('company_id', $companyId)->delete();
            $totals = [];
            foreach ($batches as [$pname, $bno, $exp, $qty, $sup]) {
                $p = $productIds[$pname];
                DB::table('product_batches')->insert([
                    'company_id' => $companyId,
                    'product_id' => $p['id'],
                    'branch_id' => null,
                    'batch_number' => $bno,
                    'expiry_date' => $exp->toDateString(),
                    'quantity' => $qty,
                    'cost_price' => $p['cost'],
                    'retail_price' => $p['price'],
                    'supplier_id' => $supplierIds[$sup] ?? null,
                    'status' => 'active',
                    'received_at' => $today->copy()->subDays(mt_rand(20, 90))->toDateString(),
                    'created_by' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $totals[$p['id']] = ($totals[$p['id']] ?? 0) + $qty;
            }
            foreach ($productIds as $pname => $p) {
                $qty = $totals[$p['id']] ?? 0;
                $row = DB::table('inventory_stocks')->where('company_id', $companyId)->where('product_id', $p['id'])->whereNull('branch_id')->first();
                $data = ['quantity' => $qty, 'min_stock_level' => 10, 'avg_purchase_price' => $p['cost'], 'last_purchase_price' => $p['cost'], 'updated_at' => now()];
                if ($row) {
                    DB::table('inventory_stocks')->where('id', $row->id)->update($data);
                } else {
                    DB::table('inventory_stocks')->insert($data + ['company_id' => $companyId, 'product_id' => $p['id'], 'branch_id' => null, 'created_at' => now()]);
                }
            }

            // ── Khata customers ──────────────────────────────────────────
            $khata = [
                ['Haji Rafiq',      '03215550011', 1850],
                ['Dr. Saeed Clinic', '03005550022', 4200],
                ['Baji Shabana',    '03335550033', 720],
                ['Ustad Karim',     '03455550044', 300],
            ];
            $customerIds = [];
            foreach ($khata as [$cname, $cphone, $bal]) {
                DB::table('pos_customers')->updateOrInsert(
                    ['company_id' => $companyId, 'phone' => $cphone],
                    ['name' => $cname, 'type' => 'individual', 'is_active' => true, 'khata_balance' => $bal, 'updated_at' => now(), 'created_at' => now()]
                );
                $customerIds[$cname] = DB::table('pos_customers')->where('company_id', $companyId)->where('phone', $cphone)->value('id');
            }
            // Ledger rows drive the aging buckets (0-15 / 16-30 / 31-60 / 60+):
            // udhaar entries of different ages, then a couple of wasooli.
            DB::table('fbr_customer_ledgers')->where('company_id', $companyId)->delete();
            $ledger = [
                // [customer, type, amount, days ago]
                ['Haji Rafiq',       'udhaar',  1450, 12],
                ['Haji Rafiq',       'udhaar',  1400, 4],
                ['Haji Rafiq',       'wasooli', 1000, 3],
                ['Dr. Saeed Clinic', 'udhaar',  2600, 24],
                ['Dr. Saeed Clinic', 'udhaar',  1600, 9],
                ['Baji Shabana',     'udhaar',  1220, 47],
                ['Baji Shabana',     'wasooli', 500,  6],
                ['Ustad Karim',      'udhaar',  300,  72],
            ];
            $running = [];
            foreach ($ledger as [$cname, $type, $amt, $daysAgo]) {
                $running[$cname] = ($running[$cname] ?? 0) + ($type === 'udhaar' ? $amt : -$amt);
                $at = now()->subDays($daysAgo)->setTime(mt_rand(10, 20), mt_rand(0, 59));
                DB::table('fbr_customer_ledgers')->insert([
                    'company_id' => $companyId, 'customer_id' => $customerIds[$cname],
                    'entry_type' => $type, 'amount' => $amt, 'balance_after' => $running[$cname],
                    'note' => $type === 'wasooli' ? 'Naqad wasooli' : 'Udhaar bill',
                    'created_by' => $userId,
                    'created_at' => $at, 'updated_at' => $at,
                ]);
            }
            foreach ($running as $cname => $bal) {
                DB::table('pos_customers')->where('id', $customerIds[$cname])->update(['khata_balance' => $bal]);
            }

            // ── Sales history (last 7 days) — dashboard KPIs, movers, prescriptions ──
            // Re-seeded every run so a take always starts with the same history
            // and no leftovers from the previous take's on-camera bill.
            DB::table('fbr_pos_transaction_items')->whereIn('transaction_id', DB::table('fbr_pos_transactions')->where('company_id', $companyId)->select('id'))->delete();
            DB::table('fbr_pos_transactions')->where('company_id', $companyId)->delete();
            DB::table('fbr_day_close_reports')->where('company_id', $companyId)->delete();
            {
                mt_srand(7);
                $names = array_keys($productIds);
                $doctors = ['Dr. Saeed Anwar', 'Dr. Farhat Naz', 'Dr. Bilal Khan'];
                $patients = ['Muhammad Asif', 'Rukhsana Bibi', 'Ahmed Raza', 'Sadia Noor'];
                $seq = 1;
                $nowHour = (int) now()->format('G');
                for ($day = 7; $day >= 0; $day--) {
                    $billCount = $day === 0 ? ($nowHour >= 12 ? 5 : 2) : mt_rand(9, 14);
                    $dayTotals = ['count' => 0, 'gross' => 0.0, 'cash' => 0.0, 'udhaar' => 0.0, 'first' => null, 'last' => null];
                    for ($b = 0; $b < $billCount; $b++) {
                        $hour = $day === 0 ? max(9, min($nowHour - 1, 9 + $b)) : mt_rand(9, 21);
                        $at = now()->subDays($day)->setTime($hour, mt_rand(0, 59));
                        $lineCount = mt_rand(1, 3);
                        $subtotal = 0; $items = []; $needsRx = false;
                        $picked = (array) array_rand(array_flip($names), $lineCount);
                        foreach ($picked as $pname) {
                            $p = $productIds[$pname];
                            $qty = mt_rand(1, 3);
                            $lineEx = round($p['price'] * $qty, 2);
                            $subtotal += $lineEx;
                            $rxFlag = in_array($pname, ['Flagyl 400mg', 'Amoxil 500mg', 'Glucophage 500mg', 'Ventolin Inhaler'], true);
                            $needsRx = $needsRx || $rxFlag;
                            $items[] = [
                                'product_id' => $p['id'], 'item_name' => $pname, 'uom' => 'PCS',
                                'quantity' => $qty, 'unit_price' => $p['price'], 'cost_price' => $p['cost'],
                                'discount' => 0, 'item_discount' => 0, 'tax_rate' => 0, 'tax_amount' => 0,
                                'subtotal' => $lineEx, 'total' => $lineEx, 'is_tax_exempt' => true,
                                'created_at' => $at, 'updated_at' => $at,
                            ];
                        }
                        $payment = mt_rand(1, 10) <= 8 ? 'cash' : 'credit';
                        $dayTotals['count']++;
                        $dayTotals['gross'] += $subtotal;
                        $dayTotals[$payment === 'cash' ? 'cash' : 'udhaar'] += $subtotal;
                        $invNo = 'SMS' . $at->format('ymd') . str_pad((string) $seq++, 3, '0', STR_PAD_LEFT);
                        $dayTotals['first'] = $dayTotals['first'] ?? $invNo;
                        $dayTotals['last'] = $invNo;
                        $txnId = DB::table('fbr_pos_transactions')->insertGetId([
                            'company_id' => $companyId,
                            'invoice_number' => $invNo,
                            'invoice_mode' => 'fbr',
                            'transaction_type' => 'sale',
                            'subtotal' => round($subtotal, 2),
                            'discount_amount' => 0,
                            'tax_amount' => 0,
                            'total_amount' => round($subtotal, 2),
                            'payment_method' => $payment,
                            'status' => 'completed',
                            'fbr_status' => 'submitted',
                            'doctor_name' => $needsRx ? $doctors[array_rand($doctors)] : null,
                            'patient_name' => $needsRx ? $patients[array_rand($patients)] : null,
                            'business_date' => $at->toDateString(),
                            'created_by' => $userId,
                            'created_at' => $at,
                            'updated_at' => $at,
                        ]);
                        DB::table('fbr_pos_transactions')->where('id', $txnId)
                            ->update(['fbr_invoice_number' => '7000' . str_pad((string) $txnId, 9, '0', STR_PAD_LEFT)]);
                        foreach ($items as $it) {
                            DB::table('fbr_pos_transaction_items')->insert($it + ['transaction_id' => $txnId]);
                        }
                    }
                    // Every prior day was closed properly — otherwise the dashboard
                    // opens with a red "days never closed" banner on camera.
                    if ($day > 0) {
                        $closedAt = now()->subDays($day)->setTime(22, 15);
                        DB::table('fbr_day_close_reports')->insert([
                            'company_id' => $companyId,
                            'report_date' => $closedAt->toDateString(),
                            'report_number' => 'DC-' . $closedAt->format('Ymd'),
                            'total_invoices' => $dayTotals['count'],
                            'fbr_invoices' => $dayTotals['count'],
                            'local_invoices' => 0,
                            'failed_invoices' => 0,
                            'gross_sales' => round($dayTotals['gross'], 2),
                            'total_discount' => 0,
                            'net_sales' => round($dayTotals['gross'], 2),
                            'total_tax' => 0,
                            'total_fbr_fee' => $dayTotals['count'],
                            'total_amount' => round($dayTotals['gross'], 2),
                            'cash_amount' => round($dayTotals['cash'], 2),
                            'card_amount' => 0,
                            'other_amount' => 0,
                            'udhaar_amount' => round($dayTotals['udhaar'], 2),
                            'opening_float' => 5000,
                            'counted_cash' => round(5000 + $dayTotals['cash'], 2),
                            'expected_cash' => round(5000 + $dayTotals['cash'], 2),
                            'cash_variance' => 0,
                            'first_invoice_number' => $dayTotals['first'],
                            'last_invoice_number' => $dayTotals['last'],
                            'closed_by' => $userId,
                            'created_at' => $closedAt,
                            'updated_at' => $closedAt,
                        ]);
                    }
                }
            }

            DB::commit();
            $this->command?->info("Pharmacy video demo shop ready: company #{$companyId} (" . self::LOGIN_EMAIL . ')');
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
