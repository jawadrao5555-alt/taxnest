<?php

namespace Tests\Feature;

use App\Http\Controllers\FbrPosCatalogueController;
use App\Models\MedicineCatalogueEntry;
use App\Models\MedicinePriceNotice;
use App\Models\Product;
use App\Models\User;
use App\Services\Pharmacy\DrapPriceIndexClient;
use App\Services\Pharmacy\MedicineCatalogueSyncService;
use App\Services\Pharmacy\MedicineCompositionParser;
use App\Services\PlanLimitService;
use App\Services\PosFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * DRAP medicine catalogue + MRP update notices (Task 1579).
 *
 * The rules a demo and a shop's money depend on:
 *   - the composition parser never blocks a row (always returns, raw kept);
 *   - a re-crawl of the SAME page is a no-op (idempotent upsert), a changed
 *     MRP becomes ONE price-history row + ONE notice per linked product;
 *   - "add from catalogue" is pharmacy-mode + owner only, skips already-linked
 *     rows, creates products at MRP with exempt tax and the catalogue link;
 *   - applying a notice moves the MRP; the sale price follows ONLY when it
 *     equalled the old MRP. Nothing reprices on its own.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' CACHE_STORE=array php vendor/bin/phpunit \
 *     tests/Feature/MedicineCatalogueTest.php --testdox
 */
class MedicineCatalogueTest extends TestCase
{
    private const COMPANY = 5101;
    private const OTHER_COMPANY = 5102;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        // PosFeatureService caches plan gates per PHP process — a previous
        // test's "plan lacks pharmacy" verdict must not leak into this one.
        $this->resetFeatureCaches();

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->nullable();
            $t->string('status')->nullable();
            $t->boolean('is_internal_account')->default(false);
            $t->boolean('pharmacy_mode')->default(false);
            $t->text('feature_flags')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('name');
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->string('role')->nullable();
            $t->string('pos_role')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name');
            $t->string('barcode')->nullable();
            $t->decimal('default_price', 12, 2)->default(0);
            $t->decimal('mrp', 12, 2)->nullable();
            $t->boolean('is_price_editable')->default(true);
            $t->string('uom')->nullable();
            $t->string('tax_type')->nullable();
            $t->decimal('default_tax_rate', 5, 2)->default(0);
            $t->boolean('is_third_schedule')->default(false);
            $t->string('generic_name')->nullable();
            $t->string('strength', 60)->nullable();
            $t->string('dosage_form')->nullable();
            $t->string('manufacturer', 150)->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('show_on_sale')->default(true);
            $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('action');
            $t->string('entity_type')->nullable();
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->text('old_values')->nullable();
            $t->text('new_values')->nullable();
            $t->string('ip_address')->nullable();
            $t->string('sha256_hash')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        (require base_path('database/migrations/2026_11_20_100000_medicine_catalogue.php'))->up();
        (require base_path('database/migrations/2026_11_21_100000_products_medicine_catalogue_unique.php'))->up();

        foreach ([self::COMPANY => 'Pharmacy Co', self::OTHER_COMPANY => 'Other Co'] as $id => $name) {
            DB::table('companies')->insert([
                'id' => $id, 'name' => $name, 'product_type' => 'fbrpos', 'status' => 'active',
                'is_internal_account' => true, 'pharmacy_mode' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function fixtureRows(): array
    {
        $html = file_get_contents(base_path('tests/Fixtures/drap-price-page.html'));
        $parsed = DrapPriceIndexClient::parseHtml($html);
        $this->assertNotEmpty($parsed['rows'], 'fixture page must parse into rows');

        return $parsed['rows'];
    }

    private function owner(int $companyId = self::COMPANY, string $role = 'company_admin', ?string $posRole = null): User
    {
        $u = new User();
        $u->forceFill(['company_id' => $companyId, 'name' => 'Owner', 'email' => "o{$companyId}{$role}@x.pk", 'password' => bcrypt('x'), 'role' => $role, 'pos_role' => $posRole]);
        $u->save();
        $this->actingAs($u, 'fbrpos');

        return $u;
    }

    private function controller(): FbrPosCatalogueController
    {
        return app(FbrPosCatalogueController::class);
    }

    private function jsonPost(string $uri, array $data): Request
    {
        $r = Request::create($uri, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode($data));
        app()->instance('request', $r);

        return $r;
    }

    private function jsonGet(string $uri, array $query = []): Request
    {
        $r = Request::create($uri, 'GET', $query, [], [], ['HTTP_ACCEPT' => 'application/json']);
        app()->instance('request', $r);

        return $r;
    }

    // ── parser ───────────────────────────────────────────────────────────

    public function test_parser_derives_generic_strength_and_form_and_never_throws(): void
    {
        $p = new MedicineCompositionParser();

        $r = $p->parse('Panadol Tablets 500mg', 'Each tablet contains Paracetamol 500mg', "200's");
        $this->assertSame('Paracetamol', $r['generic_name']);
        $this->assertStringContainsString('500mg', (string) $r['strength']);
        $this->assertSame('tablet', $r['dosage_form']);

        $r = $p->parse('Augmentin Suspension', 'Each 5ml contains Amoxicillin 125mg; Clavulanic acid 31.25mg', '60ml');
        $this->assertStringContainsString('Amoxicillin', (string) $r['generic_name']);
        $this->assertStringContainsString('Clavulanic', (string) $r['generic_name']);
        $this->assertSame('suspension', $r['dosage_form']);

        $r = $p->parse('Ceftriaxone 1g', 'Powder for Suspension for Injection', '1 vial');
        $this->assertSame('injection', $r['dosage_form']);

        // Garbage in → nulls out, never an exception.
        $r = $p->parse(null, null, null);
        $this->assertSame(['generic_name' => null, 'strength' => null, 'dosage_form' => null], $r);
        $r = $p->parse(str_repeat('x', 5000), "\xB1\xff broken bytes ;;; 999999999mg", '');
        $this->assertArrayHasKey('generic_name', $r);
    }

    // ── sync idempotency + price history ─────────────────────────────────

    public function test_ingesting_the_same_page_twice_is_a_noop_and_mrp_change_writes_history_once(): void
    {
        $svc = app(MedicineCatalogueSyncService::class);
        $rows = $this->fixtureRows();

        $first = $svc->ingestPage($rows);
        $this->assertSame(count($rows), $first['created']);
        $count = MedicineCatalogueEntry::count();
        $this->assertSame(count($rows), $count);
        // First sight of a price is itself a history row (the baseline).
        $baselineHistory = DB::table('medicine_catalogue_prices')->count();
        $this->assertGreaterThan(0, $baselineHistory);

        $second = $svc->ingestPage($rows);
        $this->assertSame(0, $second['created']);
        $this->assertSame(0, $second['price_changes']);
        $this->assertSame($count, MedicineCatalogueEntry::count());
        $this->assertSame($baselineHistory, DB::table('medicine_catalogue_prices')->count());

        // Raw composition text is always kept on the row.
        $entry = MedicineCatalogueEntry::first();
        $this->assertNotSame('', trim((string) $entry->composition));
        $this->assertSame(MedicineCatalogueEntry::SOURCE_DRAP, $entry->source);

        // Change ONE row's MRP in the feed → exactly one price change, non-destructive.
        $changed = $rows;
        $oldMrp = (float) $changed[0]['mrp'];
        $changed[0]['mrp'] = $oldMrp + 10;
        $third = $svc->ingestPage($changed);
        $this->assertSame(1, $third['price_changes']);
        $this->assertSame($baselineHistory + 1, DB::table('medicine_catalogue_prices')->count());
        $this->assertSame($count, MedicineCatalogueEntry::count());
        $hist = DB::table('medicine_catalogue_prices')->orderByDesc('id')->first();
        $this->assertEquals($oldMrp, (float) $hist->old_mrp);
        $this->assertEquals($oldMrp + 10, (float) $hist->new_mrp);
    }

    // ── add from catalogue ───────────────────────────────────────────────

    public function test_add_from_catalogue_creates_linked_products_at_mrp_and_skips_duplicates(): void
    {
        app(MedicineCatalogueSyncService::class)->ingestPage($this->fixtureRows());
        $this->owner();
        $e1 = MedicineCatalogueEntry::whereNotNull('mrp')->orderBy('id')->first();
        $e2 = MedicineCatalogueEntry::whereNotNull('mrp')->orderBy('id')->skip(1)->first();

        $res = $this->controller()->add($this->jsonPost('/fbr-pos/pharmacy/catalogue/add', ['ids' => [$e1->id, $e2->id, 987654]]));
        $this->assertSame(200, $res->getStatusCode());
        $j = $res->getData(true);
        $this->assertTrue($j['success']);
        $this->assertCount(2, $j['created']);
        $this->assertSame([['id' => 987654, 'reason' => 'missing']], $j['skipped']);

        $p = Product::where('company_id', self::COMPANY)->where('medicine_catalogue_id', $e1->id)->first();
        $this->assertNotNull($p);
        $this->assertEquals((float) $e1->mrp, (float) $p->default_price, 'sale price = MRP');
        $this->assertEquals((float) $e1->mrp, (float) $p->mrp);
        $this->assertSame('exempt', $p->tax_type);
        $this->assertEquals(0, (float) $p->default_tax_rate);
        $this->assertSame($e1->drap_reg_no, $p->drap_reg_no);
        $this->assertSame($e1->generic_name, $p->generic_name);
        $this->assertStringStartsWith($e1->brand_name, $p->name);
        $this->assertTrue((bool) $p->is_price_editable);
        $this->assertTrue((bool) $p->show_on_sale);
        $this->assertLessThanOrEqual(60, mb_strlen((string) $p->strength));
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'medicine_catalogue_product_added')->count());

        // Second press: nothing new, duplicates reported as "already".
        $res = $this->controller()->add($this->jsonPost('/fbr-pos/pharmacy/catalogue/add', ['ids' => [$e1->id]]));
        $j = $res->getData(true);
        $this->assertTrue($j['success']);
        $this->assertCount(0, $j['created']);
        $this->assertSame('already', $j['skipped'][0]['reason']);
        $this->assertSame($p->id, $j['skipped'][0]['product_id']);
        $this->assertSame(2, Product::where('company_id', self::COMPANY)->count());

        // Search marks linked rows for THIS company only.
        $res = $this->controller()->search($this->jsonGet('/fbr-pos/pharmacy/catalogue/search', ['q' => mb_substr($e1->brand_name, 0, 4)]));
        $items = collect($res->getData(true)['items']);
        $hit = $items->firstWhere('id', $e1->id);
        $this->assertNotNull($hit);
        $this->assertSame($p->id, $hit['linked_product_id']);

        $this->owner(self::OTHER_COMPANY);
        $res = $this->controller()->search($this->jsonGet('/fbr-pos/pharmacy/catalogue/search', ['q' => mb_substr($e1->brand_name, 0, 4)]));
        $hit = collect($res->getData(true)['items'])->firstWhere('id', $e1->id);
        $this->assertNull($hit['linked_product_id'], 'another shop never sees our link');
    }

    public function test_add_from_catalogue_is_pharmacy_mode_and_owner_only(): void
    {
        app(MedicineCatalogueSyncService::class)->ingestPage($this->fixtureRows());
        $e1 = MedicineCatalogueEntry::orderBy('id')->first();

        // Manager (not company_admin) → 403, nothing created.
        $this->owner(self::COMPANY, 'user', 'pos_manager');
        $res = $this->controller()->add($this->jsonPost('/fbr-pos/pharmacy/catalogue/add', ['ids' => [$e1->id]]));
        $this->assertSame(403, $res->getStatusCode());
        $this->assertSame(0, Product::count());

        // Pharmacy mode OFF → 403 for search AND add, even for the owner.
        DB::table('companies')->where('id', self::COMPANY)->update(['pharmacy_mode' => false]);
        $this->owner();
        $res = $this->controller()->search($this->jsonGet('/fbr-pos/pharmacy/catalogue/search', ['q' => 'pana']));
        $this->assertSame(403, $res->getStatusCode());
        $res = $this->controller()->add($this->jsonPost('/fbr-pos/pharmacy/catalogue/add', ['ids' => [$e1->id]]));
        $this->assertSame(403, $res->getStatusCode());
        $this->assertSame(0, Product::count());

        // Plan without the pharmacy package → OFF even with the shop switch ON.
        DB::table('companies')->where('id', self::COMPANY)->update(['pharmacy_mode' => true, 'is_internal_account' => false]);
        Schema::create('pricing_plans', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('product_type')->default('fbrpos'); $t->boolean('is_trial')->default(false);
            $t->integer('max_products')->nullable(); $t->boolean('pharmacy_enabled')->default(false); $t->timestamps();
        });
        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('pricing_plan_id')->nullable();
            $t->boolean('active')->default(true); $t->date('start_date')->nullable(); $t->date('end_date')->nullable();
            $t->timestamp('trial_ends_at')->nullable(); $t->string('override_type')->default('none');
            $t->timestamp('override_until')->nullable(); $t->timestamp('override_granted_at')->nullable(); $t->timestamps();
        });
        $planId = DB::table('pricing_plans')->insertGetId(['name' => 'Basic', 'pharmacy_enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('subscriptions')->insert(['company_id' => self::COMPANY, 'pricing_plan_id' => $planId, 'active' => true, 'end_date' => now()->addYear()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        $this->resetFeatureCaches();
        $this->assertFalse(PosFeatureService::pharmacyLive(\App\Models\Company::find(self::COMPANY)));
        $res = $this->controller()->add($this->jsonPost('/fbr-pos/pharmacy/catalogue/add', ['ids' => [$e1->id]]));
        $this->assertSame(403, $res->getStatusCode());
        $this->assertSame(0, Product::count());
    }

    private function resetFeatureCaches(): void
    {
        foreach (['planGateCache', 'pharmacyAllowedCache'] as $prop) {
            $ref = new \ReflectionProperty(PosFeatureService::class, $prop);
            $ref->setAccessible(true);
            $ref->setValue(null, []);
        }
    }

    // ── MRP notices ──────────────────────────────────────────────────────

    public function test_mrp_change_raises_one_notice_per_linked_product_and_apply_honours_sale_price_rule(): void
    {
        $svc = app(MedicineCatalogueSyncService::class);
        $rows = $this->fixtureRows();
        $svc->ingestPage($rows);
        $owner = $this->owner();
        $e1 = MedicineCatalogueEntry::whereNotNull('mrp')->orderBy('id')->first();
        $e2 = MedicineCatalogueEntry::whereNotNull('mrp')->orderBy('id')->skip(1)->first();
        $res = $this->controller()->add($this->jsonPost('/fbr-pos/pharmacy/catalogue/add', ['ids' => [$e1->id, $e2->id]]));
        $this->assertSame(200, $res->getStatusCode(), $res->getContent());
        $p1 = Product::where('medicine_catalogue_id', $e1->id)->first();
        $p2 = Product::where('medicine_catalogue_id', $e2->id)->first();
        // Shop sells p2 below MRP — its sale price must NOT follow.
        $p2->update(['default_price' => round((float) $p2->mrp - 5, 2)]);
        // An unlinked product with the same reg no is irrelevant to notices.
        Product::create(['company_id' => self::OTHER_COMPANY, 'name' => 'Loose copy', 'default_price' => 1]);

        $changed = collect($rows)->map(function ($r) use ($e1, $e2) {
            if ($r['drap_reg_no'] === $e1->drap_reg_no && $r['pack_size'] === $e1->pack_size) $r['mrp'] = (float) $r['mrp'] + 20;
            if ($r['drap_reg_no'] === $e2->drap_reg_no && $r['pack_size'] === $e2->pack_size) $r['mrp'] = (float) $r['mrp'] + 30;
            return $r;
        })->all();
        $stats = $svc->ingestPage($changed);
        $this->assertSame(2, $stats['price_changes']);
        $this->assertSame(2, MedicinePriceNotice::where('company_id', self::COMPANY)->where('status', 'pending')->count());
        $this->assertSame(0, MedicinePriceNotice::where('company_id', self::OTHER_COMPANY)->count());
        // Re-ingesting the SAME changed page raises nothing new.
        $svc->ingestPage($changed);
        $this->assertSame(2, MedicinePriceNotice::where('company_id', self::COMPANY)->count());

        // Products untouched until the shop acts.
        $this->assertEquals((float) $e1->mrp, (float) $p1->fresh()->mrp);

        // Apply p1: MRP + sale price move (sale equalled old MRP).
        $n1 = MedicinePriceNotice::where('product_id', $p1->id)->first();
        $res = $this->controller()->apply($this->jsonPost("/fbr-pos/pharmacy/price-updates/{$n1->id}/apply", []), $n1->id);
        $this->assertSame(200, $res->getStatusCode());
        $p1->refresh();
        $this->assertEquals((float) $e1->mrp + 20, (float) $p1->mrp);
        $this->assertEquals((float) $e1->mrp + 20, (float) $p1->default_price);
        $this->assertSame('applied', $n1->fresh()->status);
        $this->assertSame($owner->id, (int) $n1->fresh()->acted_by);
        // Applying twice is refused (409), never double-moves.
        $res = $this->controller()->apply($this->jsonPost("/fbr-pos/pharmacy/price-updates/{$n1->id}/apply", []), $n1->id);
        $this->assertSame(409, $res->getStatusCode());

        // Apply-all: p2's MRP moves, sale price stays (it did not equal old MRP).
        $oldSale = (float) $p2->fresh()->default_price;
        $res = $this->controller()->applyAll($this->jsonPost('/fbr-pos/pharmacy/price-updates/apply-all', []));
        $this->assertSame(1, $res->getData(true)['applied']);
        $p2->refresh();
        $this->assertEquals((float) $e2->mrp + 30, (float) $p2->mrp);
        $this->assertEquals($oldSale, (float) $p2->default_price);
        $this->assertSame(0, MedicinePriceNotice::pendingCountFor(self::COMPANY));
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'medicine_mrp_notice_applied')->count());

        // A later change on p1 while an older notice is pending supersedes it (one live notice per product).
        $again = collect($changed)->map(function ($r) use ($e1) {
            if ($r['drap_reg_no'] === $e1->drap_reg_no && $r['pack_size'] === $e1->pack_size) $r['mrp'] = (float) $r['mrp'] + 5;
            return $r;
        })->all();
        $svc->ingestPage($again);
        $n = MedicinePriceNotice::where('product_id', $p1->id)->where('status', 'pending')->first();
        $this->assertNotNull($n);
        $res = $this->controller()->dismiss($this->jsonPost("/fbr-pos/pharmacy/price-updates/{$n->id}/dismiss", []), $n->id);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('dismissed', $n->fresh()->status);
        $this->assertEquals((float) $e1->mrp + 20, (float) $p1->fresh()->mrp, 'dismiss leaves the product alone');

        // Manager cannot apply.
        $svc->ingestPage($changed); // e1 back to +20 → new pending notice for p1
        $n = MedicinePriceNotice::where('product_id', $p1->id)->where('status', 'pending')->first();
        $this->assertNotNull($n);
        $this->owner(self::COMPANY, 'user', 'pos_manager');
        $res = $this->controller()->apply($this->jsonPost("/fbr-pos/pharmacy/price-updates/{$n->id}/apply", []), $n->id);
        $this->assertSame(403, $res->getStatusCode());
        $this->assertSame('pending', $n->fresh()->status);
    }

    public function test_supplementary_import_never_overwrites_a_drap_mrp_unless_told_to(): void
    {
        $svc = app(MedicineCatalogueSyncService::class);
        $svc->ingestPage($this->fixtureRows());
        $e = MedicineCatalogueEntry::whereNotNull('mrp')->orderBy('id')->first();
        $drapMrp = (float) $e->mrp;
        $historyBefore = DB::table('medicine_catalogue_prices')->count();
        $row = ['brand_name' => $e->brand_name, 'drap_reg_no' => $e->drap_reg_no, 'pack_size' => $e->pack_size,
            'manufacturer' => $e->manufacturer, 'mrp' => $drapMrp + 99, 'composition' => $e->composition];

        // Distributor price list (import) hits the same key, no overwrite flag → MRP stays DRAP's, source stays drap.
        $r = $svc->upsertRow($row, MedicineCatalogueEntry::SOURCE_IMPORT, null, false);
        $this->assertSame('updated', $r['outcome']);
        $this->assertFalse($r['price_changed']);
        $e->refresh();
        $this->assertEquals($drapMrp, (float) $e->mrp);
        $this->assertSame(MedicineCatalogueEntry::SOURCE_DRAP, $e->source);
        $this->assertSame($historyBefore, DB::table('medicine_catalogue_prices')->count());

        // Admin explicitly ticks overwrite → MRP moves and the change is history, never a delete.
        $r = $svc->upsertRow($row, MedicineCatalogueEntry::SOURCE_IMPORT, null, true);
        $this->assertTrue($r['price_changed']);
        $this->assertEquals($drapMrp + 99, (float) $e->fresh()->mrp);
        $this->assertSame($historyBefore + 1, DB::table('medicine_catalogue_prices')->count());

        // A brand-new import row (no DRAP twin) is created with source=import.
        $r = $svc->upsertRow(['brand_name' => 'Distributor Only Syrup', 'pack_size' => '120ml', 'manufacturer' => 'Local Labs', 'mrp' => 150], MedicineCatalogueEntry::SOURCE_IMPORT, null, false);
        $this->assertSame('created', $r['outcome']);
        $this->assertSame(MedicineCatalogueEntry::SOURCE_IMPORT, $r['entry']->source);
    }

    public function test_resume_watchdog_redispatches_only_a_stalled_run(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        // No run at all → nothing dispatched (the watchdog never starts a crawl).
        $this->artisan('catalogue:sync-drap --resume')->assertSuccessful();
        \Illuminate\Support\Facades\Queue::assertNothingPushed();

        $run = \App\Models\MedicineCatalogueSync::create([
            'state' => 'running', 'trigger' => 'schedule', 'phase_index' => 0, 'next_page' => 412,
            'total_pages' => 1069, 'pages_done' => 411, 'started_at' => now()->subHour(), 'last_progress_at' => now()->subMinutes(2),
        ]);
        $this->artisan('catalogue:sync-drap --resume')->assertSuccessful();
        \Illuminate\Support\Facades\Queue::assertNothingPushed();

        // Progress older than the stall threshold (deploy restarted the worker) → re-dispatched from its cursor.
        $run->forceFill(['last_progress_at' => now()->subMinutes(\App\Models\MedicineCatalogueSync::STALE_AFTER_MINUTES + 1)])->save();
        $this->artisan('catalogue:sync-drap --resume')->assertSuccessful();
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SyncMedicineCatalogueJob::class, 1);
        $run->refresh();
        $this->assertSame('running', $run->state);
        $this->assertSame(412, (int) $run->next_page, 'resume keeps the cursor, never page 1');
        $this->assertSame(1, \App\Models\MedicineCatalogueSync::count(), 'no second run row');

        // A run the worker FAILED (job timeout) is continued from its cursor as a
        // new 'resume' run — bounded per day so a DRAP outage cannot loop all week.
        $run->forceFill(['state' => 'failed', 'completed_at' => now(), 'last_error' => 'job failed: timed out', 'next_page' => 618, 'pages_done' => 617])->save();
        $this->artisan('catalogue:sync-drap --resume')->assertSuccessful();
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SyncMedicineCatalogueJob::class, 2);
        $cont = \App\Models\MedicineCatalogueSync::latest('id')->first();
        $this->assertNotSame($run->id, $cont->id);
        $this->assertSame('resume', $cont->trigger);
        $this->assertSame(618, (int) $cont->next_page, 'continuation starts where the failed run stopped');
        $this->assertSame(617, (int) $cont->pages_done);

        for ($i = 0; $i < \App\Console\Commands\SyncMedicineCatalogue::MAX_AUTO_RESUMES_PER_DAY; $i++) {
            \App\Models\MedicineCatalogueSync::latest('id')->first()->forceFill(['state' => 'failed', 'completed_at' => now()])->save();
            $this->artisan('catalogue:sync-drap --resume')->assertSuccessful();
        }
        $this->assertSame(1 + \App\Console\Commands\SyncMedicineCatalogue::MAX_AUTO_RESUMES_PER_DAY,
            \App\Models\MedicineCatalogueSync::count(), 'auto-resume stops at the daily cap');
        $this->assertSame('failed', \App\Models\MedicineCatalogueSync::latest('id')->first()->state);
    }

    public function test_slice_never_starts_a_page_it_cannot_finish(): void
    {
        // A slow DRAP must not push a slice past the job timeout: with almost no
        // budget left the slice returns "more to do" WITHOUT touching the network.
        \Illuminate\Support\Facades\Http::fake(fn () => throw new \LogicException('network must not be hit'));
        $run = \App\Models\MedicineCatalogueSync::create([
            'state' => 'running', 'trigger' => 'schedule', 'phase_index' => 0, 'next_page' => 5,
            'total_pages' => 1069, 'pages_done' => 4, 'started_at' => now(), 'last_progress_at' => now(),
        ]);
        $done = app(\App\Services\Pharmacy\MedicineCatalogueSyncService::class)->runSlice($run, 3);
        $this->assertFalse($done);
        $this->assertSame(5, (int) $run->fresh()->next_page);
        $this->assertSame('running', $run->fresh()->state);
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_product_name_carries_the_pack_once(): void
    {
        $e = new MedicineCatalogueEntry(['brand_name' => 'Panadol Tablets 500mg', 'pack_size' => "200's"]);
        $this->assertSame("Panadol Tablets 500mg (200's)", FbrPosCatalogueController::productNameFor($e));
        $e = new MedicineCatalogueEntry(['brand_name' => "Brufen 400mg (30's)", 'pack_size' => "30's"]);
        $this->assertSame("Brufen 400mg (30's)", FbrPosCatalogueController::productNameFor($e));
        $e = new MedicineCatalogueEntry(['brand_name' => 'Augmentin', 'pack_size' => '']);
        $this->assertSame('Augmentin', FbrPosCatalogueController::productNameFor($e));
        $e = new MedicineCatalogueEntry(['brand_name' => 'Mep-Med infusion 40 mg', 'pack_size' => '1']);
        $this->assertSame('Mep-Med infusion 40 mg', FbrPosCatalogueController::productNameFor($e));
        $e = new MedicineCatalogueEntry(['brand_name' => 'Ceftro 1g', 'pack_size' => "1's"]);
        $this->assertSame('Ceftro 1g', FbrPosCatalogueController::productNameFor($e));
    }

    /**
     * Concurrency contract of the picker's add endpoint: the duplicate check
     * and the plan-cap check both run AFTER the company row is locked, so a
     * rival writer that lands between "request arrives" and "lock acquired"
     * is seen — no second linked copy, no overspent product cap — and the
     * (company_id, medicine_catalogue_id) unique index stands behind it.
     */
    public function test_concurrent_adds_cannot_duplicate_a_link_or_overspend_the_product_cap(): void
    {
        app(MedicineCatalogueSyncService::class)->ingestPage($this->fixtureRows());
        $this->owner();
        $e1 = MedicineCatalogueEntry::whereNotNull('mrp')->orderBy('id')->first();
        $e2 = MedicineCatalogueEntry::whereNotNull('mrp')->orderBy('id')->skip(1)->first();

        // 1) The DB fence: a second linked copy is impossible even for a
        //    writer that never takes the lock.
        DB::table('products')->insert(['company_id' => self::COMPANY, 'name' => 'first', 'medicine_catalogue_id' => $e2->id, 'created_at' => now(), 'updated_at' => now()]);
        try {
            DB::table('products')->insert(['company_id' => self::COMPANY, 'name' => 'second', 'medicine_catalogue_id' => $e2->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->fail('unique (company_id, medicine_catalogue_id) must reject a second link');
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // expected
        }
        // Another company may link the same catalogue row; NULL links repeat freely.
        DB::table('products')->insert(['company_id' => self::OTHER_COMPANY, 'name' => 'theirs', 'medicine_catalogue_id' => $e2->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('products')->insert(['company_id' => self::COMPANY, 'name' => 'plain a', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('products')->insert(['company_id' => self::COMPANY, 'name' => 'plain b', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('products')->where('company_id', self::COMPANY)->delete();
        DB::table('products')->where('company_id', self::OTHER_COMPANY)->delete();

        // 2) A plan with room for exactly ONE more product.
        Schema::create('pricing_plans', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('product_type')->default('fbrpos');
            $t->boolean('is_trial')->default(false); $t->integer('max_products')->nullable(); $t->timestamps();
        });
        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('pricing_plan_id')->nullable();
            $t->boolean('active')->default(true); $t->string('override_type')->default('none');
            $t->timestamp('override_until')->nullable(); $t->timestamps();
        });
        DB::table('pricing_plans')->insert(['id' => 1, 'name' => 'Tiny', 'product_type' => 'fbrpos', 'max_products' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('subscriptions')->insert(['company_id' => self::COMPANY, 'pricing_plan_id' => 1, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('companies')->where('id', self::COMPANY)->update(['is_internal_account' => false]);
        $this->assertSame(1, PlanLimitService::remainingProductAllowance(self::COMPANY, 'fbr'));

        // 3) The rival: the moment our request takes the company-row lock, a
        //    second tab's add for the SAME row has just committed and used the
        //    last slot. (SQLite has no FOR UPDATE, so the lock statement is the
        //    plain single-row company SELECT inside the transaction.)
        $fired = false;
        DB::listen(function ($q) use (&$fired, $e1) {
            if ($fired || !str_contains($q->sql, 'from "companies"') || !DB::transactionLevel()) {
                return;
            }
            $fired = true;
            DB::table('products')->insert(['company_id' => self::COMPANY, 'name' => 'rival tab', 'medicine_catalogue_id' => $e1->id, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        });

        $res = $this->controller()->add($this->jsonPost('/fbr-pos/pharmacy/catalogue/add', ['ids' => [$e1->id]]));
        $this->assertTrue($fired, 'the rival must have written while we held the lock');
        $j = $res->getData(true);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertCount(0, $j['created'], 'the rival copy is seen as "already", never re-created');
        $this->assertSame('already', $j['skipped'][0]['reason']);
        $this->assertSame(1, Product::where('company_id', self::COMPANY)->where('medicine_catalogue_id', $e1->id)->count());

        // 4) Cap: the plan has no room left, so a DIFFERENT row is refused
        //    with the quota answer — the pre-lock world never gets to decide.
        $res = $this->controller()->add($this->jsonPost('/fbr-pos/pharmacy/catalogue/add', ['ids' => [$e2->id]]));
        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame(0, $res->getData(true)['remaining']);
        $this->assertSame(1, Product::where('company_id', self::COMPANY)->count(), 'cap never overspent');
    }

    /**
     * Interleaving: tab A opens the notice (still pending), tab B applies it
     * (product repriced), then tab A's dismiss arrives. The stale dismiss
     * must lose — the notice stays "applied", no dismissal audit row, and
     * the controller answers 409 because the DB transition was conditional.
     */
    public function test_stale_dismiss_cannot_overwrite_an_applied_notice(): void
    {
        $svc = app(MedicineCatalogueSyncService::class);
        $rows = $this->fixtureRows();
        $svc->ingestPage($rows);
        $owner = $this->owner();
        $e1 = MedicineCatalogueEntry::whereNotNull('mrp')->orderBy('id')->first();
        $this->controller()->add($this->jsonPost('/fbr-pos/pharmacy/catalogue/add', ['ids' => [$e1->id]]));
        $p1 = Product::where('medicine_catalogue_id', $e1->id)->firstOrFail();
        $svc->ingestPage(collect($rows)->map(function ($r) use ($e1) {
            if ($r['drap_reg_no'] === $e1->drap_reg_no && $r['pack_size'] === $e1->pack_size) $r['mrp'] = (float) $r['mrp'] + 20;
            return $r;
        })->all());
        $notice = MedicinePriceNotice::where('product_id', $p1->id)->where('status', 'pending')->firstOrFail();

        // Tab A has the notice in hand (pending). Tab B applies meanwhile.
        $staleCopy = MedicinePriceNotice::find($notice->id);
        $this->assertTrue($svc->applyNotice(MedicinePriceNotice::find($notice->id), $owner->id));
        $this->assertEquals((float) $e1->mrp + 20, (float) $p1->fresh()->mrp);

        // Tab A's dismiss with its stale (pending) copy.
        $this->assertFalse($svc->dismissNotice($staleCopy, $owner->id));
        $this->assertSame('applied', $notice->fresh()->status, 'a stale dismiss must never hide an applied reprice');
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'medicine_mrp_notice_dismissed')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'medicine_mrp_notice_applied')->count());

        // Through the HTTP path the same race answers 409, not a fake "dismissed".
        $res = $this->controller()->dismiss($this->jsonPost("/fbr-pos/pharmacy/price-updates/{$notice->id}/dismiss", []), $notice->id);
        $this->assertSame(409, $res->getStatusCode());
        $this->assertSame('applied', $notice->fresh()->status);

        // Mirror image: a dismiss that wins first makes a later apply a no-op on the product.
        $svc->ingestPage(collect($rows)->map(function ($r) use ($e1) {
            if ($r['drap_reg_no'] === $e1->drap_reg_no && $r['pack_size'] === $e1->pack_size) $r['mrp'] = (float) $r['mrp'] + 25;
            return $r;
        })->all());
        $n2 = MedicinePriceNotice::where('product_id', $p1->id)->where('status', 'pending')->firstOrFail();
        $staleApply = MedicinePriceNotice::find($n2->id);
        $this->assertTrue($svc->dismissNotice(MedicinePriceNotice::find($n2->id), $owner->id));
        $this->assertFalse($svc->applyNotice($staleApply, $owner->id));
        $this->assertSame('dismissed', $n2->fresh()->status);
        $this->assertEquals((float) $e1->mrp + 20, (float) $p1->fresh()->mrp, 'product untouched after a dismissed notice');
    }
}
