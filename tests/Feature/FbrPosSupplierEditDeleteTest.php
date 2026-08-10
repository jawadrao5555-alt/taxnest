<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * FBR POS STOCK — Supplier edit/delete/reactivate safety rules (Aug 2026, Task 420).
 *
 * Locks the invariants of the supplier endpoints added by Task 415:
 *
 *   1. update changes name/phone/city; a supplier belonging to ANOTHER company
 *      404s (company-scoped lookup) and the foreign row stays untouched.
 *   2. delete with NO purchase history = row hard-deleted.
 *      delete WITH purchase history = is_active flipped false ONLY — the
 *      supplier row AND its purchase order rows must stay intact.
 *   3. reactivate flips is_active back to true.
 *   4. pos_cashier / local_viewer get 403 on all three endpoints
 *      (stock/suppliers are owner-manager territory).
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create
 * (copied from FbrPosStockPurchasesSearchTest).
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/FbrPosSupplierEditDeleteTest.php
 */
class FbrPosSupplierEditDeleteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // PosFeatureService caches plan-gate verdicts statically per company
        // id — flush on both ends so throwaway ids never poison other files.
        \App\Services\PosFeatureService::flushGateCaches();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->string('default_language')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->boolean('inventory_enabled')->default(true);
            $table->boolean('fbr_pos_enabled')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('language')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        // Queried on the fbrpos auth path (head-office branch resolution).
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name')->nullable();
            $table->boolean('is_head_office')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('city')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('po_number');
            $table->string('status')->nullable();
            $table->date('order_date')->nullable();
            $table->date('expected_date')->nullable();
            $table->date('received_date')->nullable();
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        // plan.limit:inventory middleware reads these on every supplier POST.
        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('product_type')->nullable();
            $table->boolean('is_trial')->default(false);
            $table->decimal('price', 12, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->boolean('active')->default(false);
            $table->string('override_type')->nullable();
            $table->timestamp('override_until')->nullable();
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->timestamp('override_granted_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        \App\Services\PosFeatureService::flushGateCaches();
        parent::tearDown();
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function makeCompany(string $name = 'Supplier Test Co'): int
    {
        $id = (int) DB::table('companies')->insertGetId([
            'name' => $name,
            'product_type' => 'fbrpos',
            'status' => 'active',
            'inventory_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // plan.limit:inventory — a lifetime override bypasses per-resource caps
        // (like live QA companies), so the middleware never blocks these tests.
        DB::table('subscriptions')->insert([
            'company_id' => $id,
            'active' => true,
            'override_type' => 'lifetime',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function makeUser(int $companyId, ?string $posRole = null): \App\Models\User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Shop Owner',
            'email' => 'owner' . $companyId . ($posRole ?? 'admin') . '@supplier.test',
            'password' => bcrypt('Secret@12345'),
            'company_id' => $companyId,
            'role' => 'company_admin',
            'pos_role' => $posRole,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return \App\Models\User::find($id);
    }

    private function makeSupplier(int $companyId, string $name, bool $active = true): int
    {
        return (int) DB::table('suppliers')->insertGetId([
            'company_id' => $companyId,
            'name' => $name,
            'phone' => '0300-1111111',
            'city' => 'Lahore',
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makePurchase(int $companyId, int $supplierId, string $poNumber = 'PO-SUP-1'): int
    {
        return (int) DB::table('purchase_orders')->insertGetId([
            'company_id' => $companyId,
            'supplier_id' => $supplierId,
            'po_number' => $poNumber,
            'status' => 'received',
            'received_date' => now()->toDateString(),
            'total_amount' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ── 1. Update ────────────────────────────────────────────────────────────

    public function test_update_changes_name_phone_city(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makeUser($companyId);
        $supId = $this->makeSupplier($companyId, 'Purana Naam');

        $res = $this->actingAs($user, 'fbrpos')->post("/fbr-pos/stock/supplier/{$supId}/update", [
            'name' => 'Naya Naam Traders',
            'phone' => '0321-9999999',
            'city' => 'Karachi',
        ]);
        $res->assertRedirect(route('fbrpos.stock'));
        $res->assertSessionHas('success');

        $row = DB::table('suppliers')->find($supId);
        $this->assertSame('Naya Naam Traders', $row->name);
        $this->assertSame('0321-9999999', $row->phone);
        $this->assertSame('Karachi', $row->city);
    }

    public function test_update_is_company_scoped_404_and_foreign_row_untouched(): void
    {
        $companyA = $this->makeCompany('Shop A');
        $companyB = $this->makeCompany('Shop B');
        $userA = $this->makeUser($companyA);
        $supB = $this->makeSupplier($companyB, 'Company B Supplier');

        $res = $this->actingAs($userA, 'fbrpos')->post("/fbr-pos/stock/supplier/{$supB}/update", [
            'name' => 'Hijacked Name',
            'phone' => null,
            'city' => null,
        ]);
        // 404 abort on fbr-pos/* paths is rendered as a redirect to the dashboard.
        $this->assertTrue(
            in_array($res->getStatusCode(), [302, 404], true),
            'foreign-supplier update must not succeed (got ' . $res->getStatusCode() . ')'
        );

        $row = DB::table('suppliers')->find($supB);
        $this->assertSame('Company B Supplier', $row->name, 'foreign supplier row was modified');
    }

    // ── 2. Delete ────────────────────────────────────────────────────────────

    public function test_delete_without_purchase_history_hard_deletes_row(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makeUser($companyId);
        $supId = $this->makeSupplier($companyId, 'Naya Supplier');

        $res = $this->actingAs($user, 'fbrpos')->post("/fbr-pos/stock/supplier/{$supId}/delete");
        $res->assertRedirect(route('fbrpos.stock'));
        $res->assertSessionHas('success');

        $this->assertNull(DB::table('suppliers')->find($supId), 'supplier with no history should be hard-deleted');
    }

    public function test_delete_with_purchase_history_deactivates_and_keeps_po_rows(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makeUser($companyId);
        $supId = $this->makeSupplier($companyId, 'History Wala Supplier');
        $poId = $this->makePurchase($companyId, $supId);

        $res = $this->actingAs($user, 'fbrpos')->post("/fbr-pos/stock/supplier/{$supId}/delete");
        $res->assertRedirect(route('fbrpos.stock'));
        $res->assertSessionHas('success');

        $row = DB::table('suppliers')->find($supId);
        $this->assertNotNull($row, 'supplier WITH purchase history must never be hard-deleted');
        $this->assertSame(0, (int) $row->is_active, 'supplier should be deactivated instead');

        $po = DB::table('purchase_orders')->find($poId);
        $this->assertNotNull($po, 'purchase order row must stay intact');
        $this->assertSame($supId, (int) $po->supplier_id);
    }

    public function test_delete_is_company_scoped(): void
    {
        $companyA = $this->makeCompany('Shop A');
        $companyB = $this->makeCompany('Shop B');
        $userA = $this->makeUser($companyA);
        $supB = $this->makeSupplier($companyB, 'Company B Supplier');

        $res = $this->actingAs($userA, 'fbrpos')->post("/fbr-pos/stock/supplier/{$supB}/delete");
        $this->assertTrue(in_array($res->getStatusCode(), [302, 404], true));
        $this->assertNotNull(DB::table('suppliers')->find($supB), 'foreign supplier must not be deleted');
    }

    // ── 3. Reactivate ────────────────────────────────────────────────────────

    public function test_reactivate_flips_is_active_back(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makeUser($companyId);
        $supId = $this->makeSupplier($companyId, 'Band Supplier', active: false);

        $res = $this->actingAs($user, 'fbrpos')->post("/fbr-pos/stock/supplier/{$supId}/reactivate");
        $res->assertRedirect(route('fbrpos.stock'));
        $res->assertSessionHas('success');

        $this->assertSame(1, (int) DB::table('suppliers')->find($supId)->is_active);
    }

    public function test_reactivate_is_company_scoped(): void
    {
        $companyA = $this->makeCompany('Shop A');
        $companyB = $this->makeCompany('Shop B');
        $userA = $this->makeUser($companyA);
        $supB = $this->makeSupplier($companyB, 'Company B Supplier', active: false);

        $res = $this->actingAs($userA, 'fbrpos')->post("/fbr-pos/stock/supplier/{$supB}/reactivate");
        $this->assertTrue(in_array($res->getStatusCode(), [302, 404], true));
        $this->assertSame(0, (int) DB::table('suppliers')->find($supB)->is_active, 'foreign supplier must stay deactivated');
    }

    // ── 4. Cashier / viewer blocked ──────────────────────────────────────────

    public function test_cashier_and_viewer_are_403_blocked_on_all_three_endpoints(): void
    {
        $companyId = $this->makeCompany();
        $supId = $this->makeSupplier($companyId, 'Guard Test Supplier');
        $poId = $this->makePurchase($companyId, $supId, 'PO-GUARD-1');

        foreach (['pos_cashier', 'local_viewer'] as $role) {
            $user = $this->makeUser($companyId, $role);

            $this->actingAs($user, 'fbrpos')
                ->post("/fbr-pos/stock/supplier/{$supId}/update", ['name' => 'Hack', 'phone' => null, 'city' => null])
                ->assertStatus(403);
            $this->actingAs($user, 'fbrpos')
                ->post("/fbr-pos/stock/supplier/{$supId}/delete")
                ->assertStatus(403);
            $this->actingAs($user, 'fbrpos')
                ->post("/fbr-pos/stock/supplier/{$supId}/reactivate")
                ->assertStatus(403);
        }

        // Nothing changed underneath.
        $row = DB::table('suppliers')->find($supId);
        $this->assertSame('Guard Test Supplier', $row->name);
        $this->assertSame(1, (int) $row->is_active);
        $this->assertNotNull(DB::table('purchase_orders')->find($poId));
    }
}
