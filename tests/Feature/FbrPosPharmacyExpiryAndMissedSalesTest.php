<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\PharmacyBatchService;
use App\Services\PharmacyExpirySummaryService;
use App\Services\PosFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * FBR Pharmacy Mode — expiry alerts + missed-sale log (Sep 2026).
 *
 * Locks the counter-side contracts that the dashboard tile, the daily layout
 * banner, the alternatives panel's stock probe and the missed-sale report all
 * rely on:
 *
 *   1. ONE expiry resolver: near-expiry window comes from the shop's own
 *      setting (clamped, default when absent); near/expired counts ignore
 *      empty and written-off batches and respect the branch view.
 *   2. Dashboard tile + banner only for pharmacy shops with batch tracking,
 *      only for managers/owners, never for a pending company.
 *   3. Missed-sale write path: any pharmacy user may write, uuid replays are
 *      idempotent, junk (too short / a barcode) is refused; report + handled
 *      toggle are manager-only and company-scoped.
 *   4. Stock probe answers only the caller's own products and says so when
 *      inventory is off; near-days save validates its range.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' CACHE_STORE=array php vendor/bin/phpunit \
 *     tests/Feature/FbrPosPharmacyExpiryAndMissedSalesTest.php --testdox
 */
class FbrPosPharmacyExpiryAndMissedSalesTest extends TestCase
{
    private Company $company;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        PosFeatureService::flushGateCaches();
        Cache::flush();
        Schema::dropAllTables();
        $this->buildSchema();
        [$this->company, $this->admin] = $this->seedShop();
    }

    protected function tearDown(): void
    {
        PosFeatureService::flushGateCaches();
        parent::tearDown();
    }

    // ── 1. Summary service ──────────────────────────────────────────────────

    public function test_window_days_reads_setting_and_clamps_junk(): void
    {
        $this->assertSame(PharmacyBatchService::NEAR_EXPIRY_DAYS, PharmacyExpirySummaryService::windowDays($this->company));

        $this->setFlags(['near_expiry_days' => 45]);
        $this->assertSame(45, PharmacyExpirySummaryService::windowDays($this->company->fresh()));

        $this->setFlags(['near_expiry_days' => 2]);
        $this->assertSame(PharmacyBatchService::NEAR_EXPIRY_DAYS, PharmacyExpirySummaryService::windowDays($this->company->fresh()));

        $this->setFlags(['near_expiry_days' => 9999]);
        $this->assertSame(PharmacyBatchService::NEAR_EXPIRY_DAYS, PharmacyExpirySummaryService::windowDays($this->company->fresh()));
    }

    public function test_summary_counts_near_and_expired_and_ignores_dead_batches(): void
    {
        $this->setFlags(['near_expiry_days' => 30]);
        $p1 = $this->product('Brufen 400');
        $p2 = $this->product('Panadol');

        $this->batch($p1, 'B1', now()->addDays(10)->toDateString(), 5, 20, 30);      // near
        $this->batch($p2, 'B2', now()->addDays(29)->toDateString(), 2, 10, 15);      // near (edge)
        $this->batch($p1, 'B3', now()->addDays(31)->toDateString(), 9, 10, 15);      // outside window
        $this->batch($p1, 'B4', now()->subDay()->toDateString(), 3, 10, 12);          // expired on shelf
        $this->batch($p1, 'B5', now()->subDay()->toDateString(), 0, 10, 12);          // empty → ignored
        $this->batch($p2, 'B6', now()->subDays(5)->toDateString(), 4, 10, 12, 'written_off'); // written off → ignored
        $this->batch($p2, 'B7', now()->addDays(3)->toDateString(), 7, 10, 12, 'active', 99);   // other branch

        $all = PharmacyExpirySummaryService::compute($this->company->fresh(), null);
        $this->assertSame(30, $all['window_days']);
        $this->assertSame(3, $all['near_count']);
        $this->assertSame(2, $all['products_near']);
        $this->assertSame(14.0, $all['near_qty']);
        $this->assertSame(5 * 20 + 2 * 10 + 7 * 10.0, $all['near_cost']);
        $this->assertSame(1, $all['expired_count']);
        $this->assertSame(3.0, $all['expired_qty']);

        $branch = PharmacyExpirySummaryService::compute($this->company->fresh(), 1);
        $this->assertSame(2, $branch['near_count']);
        $this->assertSame(1, $branch['expired_count']);

        // Another company's shelf never leaks in.
        $other = Company::create(['name' => 'Other', 'product_type' => 'fbrpos', 'status' => 'approved', 'company_status' => 'active']);
        $this->assertSame(0, PharmacyExpirySummaryService::compute($other, null)['near_count']);
    }

    public function test_summary_cache_is_forgotten_after_a_batch_write(): void
    {
        $p = $this->product('Brufen 400');
        $this->assertSame(0, PharmacyExpirySummaryService::summary($this->company, null)['near_count']);
        $this->batch($p, 'B1', now()->addDays(3)->toDateString(), 5, 20, 30);
        // Still cached …
        $this->assertSame(0, PharmacyExpirySummaryService::summary($this->company, null)['near_count']);
        // … until a batch write path forgets it.
        PharmacyExpirySummaryService::forget($this->company->id);
        $this->assertSame(1, PharmacyExpirySummaryService::summary($this->company, null)['near_count']);
    }

    // ── 2. Dashboard tile + banner gating ───────────────────────────────────

    public function test_dashboard_shows_tile_and_banner_for_admin(): void
    {
        $p = $this->product('Brufen 400');
        $this->batch($p, 'B1', now()->addDays(3)->toDateString(), 5, 20, 30);
        $this->batch($p, 'B2', now()->subDays(3)->toDateString(), 2, 20, 30);

        $r = $this->actingAs($this->admin, 'fbrpos')->get('/fbr-pos/dashboard');
        $r->assertOk();
        $r->assertViewHas('pharmacyExpiry', fn ($s) => is_array($s) && $s['near_count'] === 1 && $s['expired_count'] === 1);
        $r->assertSee('data-testid="pharmacy-expiry-tile"', false);
        $r->assertSee('data-testid="pharmacy-expiry-banner"', false);
        $r->assertSee(__('pos.ph_expiry_alert_expired', ['count' => 1]));
    }

    public function test_dashboard_hides_tile_and_banner_when_pharmacy_is_off(): void
    {
        $this->company->forceFill(['pharmacy_mode' => false, 'feature_flags' => ['inventory' => true]])->save();
        PosFeatureService::flushGateCaches();

        $r = $this->actingAs($this->admin, 'fbrpos')->get('/fbr-pos/dashboard');
        $r->assertOk();
        $r->assertViewHas('pharmacyExpiry', null);
        $r->assertDontSee('data-testid="pharmacy-expiry-tile"', false);
        $r->assertDontSee('data-testid="pharmacy-expiry-banner"', false);
    }

    public function test_cashier_and_pending_company_never_get_the_banner(): void
    {
        $p = $this->product('Brufen 400');
        $this->batch($p, 'B1', now()->addDays(3)->toDateString(), 5, 20, 30);

        $cashier = $this->makeUser('pos_cashier', 'cashier@ph.pk');
        $r = $this->actingAs($cashier, 'fbrpos')->get('/fbr-pos/dashboard');
        $r->assertOk();
        $r->assertDontSee('data-testid="pharmacy-expiry-banner"', false);

        $this->company->forceFill(['status' => 'pending', 'company_status' => 'pending'])->save();
        $r = $this->actingAs($this->admin, 'fbrpos')->get('/fbr-pos/customize');
        $this->assertStringNotContainsString('data-testid="pharmacy-expiry-banner"', (string) $r->getContent());
    }

    // ── 3. Missed-sale write path + report ──────────────────────────────────

    public function test_missed_sale_store_is_uuid_idempotent_and_refuses_junk(): void
    {
        $cashier = $this->makeUser('pos_cashier', 'cashier@ph.pk');
        $uuid = 'aaaa-1111';

        $r = $this->actingAs($cashier, 'fbrpos')->postJson('/fbr-pos/pharmacy/missed-sales', [
            'term' => '  Brufen   400 ', 'qty' => 2, 'reason' => 'no_match', 'uuid' => $uuid,
        ]);
        $r->assertOk()->assertJson(['success' => true]);
        $this->assertSame(1, DB::table('pharmacy_missed_sales')->count());
        $row = DB::table('pharmacy_missed_sales')->first();
        $this->assertSame('Brufen 400', $row->term);
        $this->assertSame('brufen 400', $row->term_key);
        $this->assertSame($cashier->id, (int) $row->user_id);

        // Offline replay of the same ask → same row, no second count.
        $this->actingAs($cashier, 'fbrpos')->postJson('/fbr-pos/pharmacy/missed-sales', [
            'term' => 'Brufen 400', 'uuid' => $uuid,
        ])->assertOk()->assertJson(['success' => true, 'duplicate' => true]);
        $this->assertSame(1, DB::table('pharmacy_missed_sales')->count());

        // Too short / a scanned barcode → never a "missed" medicine.
        $this->actingAs($cashier, 'fbrpos')->postJson('/fbr-pos/pharmacy/missed-sales', ['term' => 'a'])->assertStatus(422);
        $this->actingAs($cashier, 'fbrpos')->postJson('/fbr-pos/pharmacy/missed-sales', ['term' => '8964000123456'])->assertStatus(422);
        $this->assertSame(1, DB::table('pharmacy_missed_sales')->count());
    }

    public function test_missed_sale_store_is_refused_when_pharmacy_is_off(): void
    {
        $this->company->forceFill(['pharmacy_mode' => false, 'feature_flags' => ['inventory' => true]])->save();
        PosFeatureService::flushGateCaches();
        $this->actingAs($this->admin, 'fbrpos')->postJson('/fbr-pos/pharmacy/missed-sales', ['term' => 'Brufen 400'])->assertStatus(403);
    }

    public function test_missed_sales_report_groups_terms_and_is_manager_only(): void
    {
        $cashier = $this->makeUser('pos_cashier', 'cashier@ph.pk');
        foreach ([['Brufen 400', 'u1'], ['brufen  400', 'u2'], ['Augmentin 625', 'u3']] as [$term, $uuid]) {
            $this->actingAs($cashier, 'fbrpos')->postJson('/fbr-pos/pharmacy/missed-sales', ['term' => $term, 'uuid' => $uuid])->assertOk();
        }
        // A different shop's ask must never show.
        DB::table('pharmacy_missed_sales')->insert([
            'company_id' => $this->company->id + 1, 'term' => 'Ponstan', 'term_key' => 'ponstan', 'reason' => 'no_match',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($cashier, 'fbrpos')->get('/fbr-pos/pharmacy/missed-sales')->assertStatus(403);

        $r = $this->actingAs($this->admin, 'fbrpos')->get('/fbr-pos/pharmacy/missed-sales');
        $r->assertOk();
        $r->assertViewHas('groups', function ($groups) {
            $g = $groups->keyBy('term_key');
            return $g->count() === 2
                && (int) $g['brufen 400']->asks === 2
                && (int) $g['augmentin 625']->asks === 1
                && !$g->has('ponstan');
        });
        $r->assertSee('Brufen 400');
        $r->assertDontSee('Ponstan');

        // Mark handled → disappears from "open", shows under "handled".
        $this->actingAs($this->admin, 'fbrpos')->postJson('/fbr-pos/pharmacy/missed-sales/handled', ['term' => 'BRUFEN 400', 'handled' => true])
            ->assertOk()->assertJson(['success' => true, 'updated' => 2]);
        $this->assertSame(2, DB::table('pharmacy_missed_sales')->whereNotNull('handled_at')->count());
        $this->actingAs($this->admin, 'fbrpos')->get('/fbr-pos/pharmacy/missed-sales')
            ->assertViewHas('groups', fn ($g) => $g->count() === 1 && $g->first()->term_key === 'augmentin 625');
        $this->actingAs($this->admin, 'fbrpos')->get('/fbr-pos/pharmacy/missed-sales?show=handled')
            ->assertViewHas('groups', fn ($g) => $g->count() === 1 && $g->first()->term_key === 'brufen 400');

        // Reopen.
        $this->actingAs($this->admin, 'fbrpos')->postJson('/fbr-pos/pharmacy/missed-sales/handled', ['term' => 'brufen 400', 'handled' => false])
            ->assertOk()->assertJson(['success' => true, 'updated' => 2]);
        $this->assertSame(0, DB::table('pharmacy_missed_sales')->whereNotNull('handled_at')->count());

        // The cashier may not toggle either.
        $this->actingAs($cashier, 'fbrpos')->postJson('/fbr-pos/pharmacy/missed-sales/handled', ['term' => 'brufen 400'])->assertStatus(403);
    }

    // ── 4. Stock probe + near-days setting ──────────────────────────────────

    public function test_stock_check_answers_only_own_products_and_reports_inventory_state(): void
    {
        $mine = $this->product('Brufen 400');
        $other = DB::table('products')->insertGetId(['company_id' => $this->company->id + 1, 'name' => 'Foreign', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('inventory_stocks')->insert([
            ['company_id' => $this->company->id, 'product_id' => $mine, 'branch_id' => null, 'quantity' => 12.5, 'created_at' => now(), 'updated_at' => now()],
            ['company_id' => $this->company->id + 1, 'product_id' => $other, 'branch_id' => null, 'quantity' => 7, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $r = $this->actingAs($this->admin, 'fbrpos')->getJson('/fbr-pos/pharmacy/stock-check?ids=' . $mine . ',' . $other . ',abc');
        $r->assertOk()->assertJson(['success' => true, 'inventory' => true]);
        $stock = $r->json('stock');
        $this->assertSame(12.5, (float) $stock[(string) $mine]);
        $this->assertArrayNotHasKey((string) $other, $stock);

        $this->company->forceFill(['inventory_enabled' => false])->save();
        PosFeatureService::flushGateCaches();
        $this->actingAs($this->admin, 'fbrpos')->getJson('/fbr-pos/pharmacy/stock-check?ids=' . $mine)
            ->assertOk()->assertJson(['success' => true, 'inventory' => false]);
    }

    public function test_near_days_setting_validates_range_and_is_manager_only(): void
    {
        $this->actingAs($this->admin, 'fbrpos')->postJson('/fbr-pos/pharmacy/near-days', ['days' => 3])->assertStatus(422);
        $this->actingAs($this->admin, 'fbrpos')->postJson('/fbr-pos/pharmacy/near-days', ['days' => 400])->assertStatus(422);
        $this->actingAs($this->admin, 'fbrpos')->postJson('/fbr-pos/pharmacy/near-days', ['days' => 60])
            ->assertOk()->assertJson(['success' => true, 'days' => 60]);
        $this->assertSame(60, PharmacyExpirySummaryService::windowDays($this->company->fresh()));

        $cashier = $this->makeUser('pos_cashier', 'cashier@ph.pk');
        $this->actingAs($cashier, 'fbrpos')->postJson('/fbr-pos/pharmacy/near-days', ['days' => 60])->assertStatus(403);
        $this->assertSame(60, PharmacyExpirySummaryService::windowDays($this->company->fresh()));
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function setFlags(array $extra): void
    {
        $flags = array_merge(['pharmacy' => true, 'batch_expiry' => true, 'loose_sale' => true, 'inventory' => true], $extra);
        $this->company->forceFill(['feature_flags' => $flags])->save();
    }

    private function product(string $name): int
    {
        return DB::table('products')->insertGetId([
            'company_id' => $this->company->id, 'name' => $name, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function batch(int $productId, string $number, string $expiry, float $qty, float $cost, float $retail, string $status = 'active', ?int $branchId = 1): void
    {
        DB::table('product_batches')->insert([
            'company_id' => $this->company->id, 'product_id' => $productId, 'branch_id' => $branchId,
            'batch_number' => $number, 'expiry_date' => $expiry, 'quantity' => $qty,
            'cost_price' => $cost, 'retail_price' => $retail, 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeUser(string $posRole, string $email): User
    {
        return User::create([
            'name' => ucfirst($posRole), 'email' => $email,
            'password' => bcrypt('secret'), 'company_id' => $this->company->id,
            'role' => $posRole === 'pos_cashier' ? 'user' : 'company_admin',
            'pos_role' => $posRole,
            'is_active' => true,
        ]);
    }

    private function seedShop(): array
    {
        $planId = DB::table('pricing_plans')->insertGetId([
            'name' => 'FBR Business', 'product_type' => 'fbrpos', 'is_trial' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $company = Company::create([
            'name' => 'Shifa Medical Store', 'product_type' => 'fbrpos',
            'status' => 'approved', 'company_status' => 'active',
            'fbr_reporting_enabled' => false, 'fbr_pos_enabled' => true,
            'inventory_enabled' => true, 'pharmacy_mode' => true,
            'feature_flags' => ['pharmacy' => true, 'batch_expiry' => true, 'loose_sale' => true, 'inventory' => true],
        ]);

        DB::table('subscriptions')->insert([
            'company_id' => $company->id, 'pricing_plan_id' => $planId,
            'active' => true, 'override_type' => 'none',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $user = User::create([
            'name' => 'Owner', 'email' => 'owner@ph.pk',
            'password' => bcrypt('secret'), 'company_id' => $company->id,
            'role' => 'company_admin', 'pos_role' => 'pos_admin',
            'is_active' => true,
        ]);

        return [$company, $user];
    }

    private function buildSchema(): void
    {
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->default('fbrpos');
            $t->string('status')->default('active');
            $t->string('company_status')->default('active');
            $t->boolean('is_internal_account')->default(false);
            $t->integer('invoice_limit_override')->nullable();
            $t->boolean('fbr_reporting_enabled')->default(false);
            $t->boolean('fbr_pos_enabled')->default(true);
            $t->boolean('inventory_enabled')->default(false);
            $t->boolean('pharmacy_mode')->default(false);
            $t->json('feature_flags')->nullable();
            $t->string('fbr_connection_mode')->nullable();
            $t->string('fbr_environment')->nullable();
            $t->string('pos_dayclose_provisional_action')->nullable();
            $t->string('pos_dashboard_style')->nullable();
            $t->string('default_language')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->nullable()->unique();
            $t->string('password')->nullable();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('default_branch_id')->nullable();
            $t->string('role')->nullable();
            $t->string('pos_role')->nullable();
            $t->boolean('is_active')->default(true);
            $t->string('language')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name')->nullable();
            $t->boolean('is_head_office')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name');
            $t->string('generic_name')->nullable();
            $t->string('strength')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('inventory_stocks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->decimal('quantity', 15, 4)->default(0);
            $t->timestamps();
        });

        Schema::create('product_batches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->string('batch_number')->nullable();
            $t->date('expiry_date')->nullable();
            $t->decimal('quantity', 15, 4)->default(0);
            $t->decimal('cost_price', 15, 4)->default(0);
            $t->decimal('retail_price', 15, 4)->nullable();
            $t->unsignedBigInteger('supplier_id')->nullable();
            $t->unsignedBigInteger('purchase_order_id')->nullable();
            $t->string('status')->default('active');
            $t->date('received_at')->nullable();
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('pharmacy_missed_sales', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('term', 150);
            $t->string('term_key', 150);
            $t->decimal('quantity', 12, 3)->nullable();
            $t->string('reason', 20)->default('no_match');
            $t->unsignedBigInteger('product_id')->nullable();
            $t->string('client_uuid', 64)->nullable();
            $t->timestamp('handled_at')->nullable();
            $t->unsignedBigInteger('handled_by')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'client_uuid']);
        });

        Schema::create('fbr_pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->string('invoice_number');
            $t->string('transaction_type')->default('sale');
            $t->string('status')->nullable();
            $t->string('invoice_mode')->nullable();
            $t->string('fbr_status')->nullable();
            $t->string('fbr_invoice_number')->nullable();
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->decimal('discount_amount', 12, 2)->default(0);
            $t->decimal('tax_amount', 12, 2)->default(0);
            $t->decimal('fbr_service_charge', 12, 2)->nullable();
            $t->decimal('total_amount', 12, 2)->default(0);
            $t->string('payment_method')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('fbr_pos_transaction_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('transaction_id');
            $t->unsignedBigInteger('product_id')->nullable();
            $t->string('item_name');
            $t->decimal('quantity', 12, 4)->default(1);
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->decimal('tax_amount', 12, 2)->nullable();
            $t->decimal('item_discount', 12, 2)->nullable();
            $t->decimal('promotion_discount', 12, 2)->nullable();
            $t->timestamps();
        });

        Schema::create('fbr_day_close_reports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->date('report_date');
            $t->string('report_number')->nullable();
            $t->integer('total_invoices')->default(0);
            $t->decimal('total_amount', 14, 2)->default(0);
            $t->unsignedBigInteger('closed_by')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'report_date']);
        });

        Schema::create('pricing_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('product_type')->nullable();
            $t->boolean('is_trial')->default(false);
            $t->decimal('price', 12, 2)->default(0);
            $t->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('pricing_plan_id')->nullable();
            $t->boolean('active')->default(true);
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->timestamp('trial_ends_at')->nullable();
            $t->string('override_type')->default('none');
            $t->timestamp('override_until')->nullable();
            $t->timestamp('override_granted_at')->nullable();
            $t->integer('free_invoice_limit')->nullable();
            $t->timestamps();
        });

        Schema::create('notifications', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('type');
            $t->string('title');
            $t->text('message');
            $t->boolean('read')->default(false);
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
    }
}
