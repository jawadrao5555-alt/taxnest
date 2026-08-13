<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\User;
use App\Models\FbrPosHeldSale;
use App\Services\PosFeatureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Task 641 — FBR POS identity-autofill note discard on the held-sale path
 * (parity with PRA Task 636).
 *
 * FbrPosPhase2Controller::holdSale is the ONLY server write path for FBR item
 * notes / order notes (billing store() never persists them; the parked
 * cart_data feeds the KOT print directly). Locked guarantees, over real HTTP:
 *
 *   1. cart_data item special_notes that EXACTLY match the punching user's
 *      login identity (name/email/email-prefix/phone) are discarded.
 *   2. cart_data.kitchen_notes gets the same discard.
 *   3. Genuine kitchen instructions (incl. notes merely CONTAINING an identity
 *      word) pass through untouched.
 *
 * Pattern: sqlite :memory: + minimal Schema::create (mirrors
 * FbrPosHazriCashierGateTest).
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/FbrPosHeldNoteIdentityStripTest.php
 */
class FbrPosHeldNoteIdentityStripTest extends TestCase
{
    private Company $company;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        PosFeatureService::flushGateCaches();
        Schema::dropAllTables();
        $this->buildSchema();
        [$this->company, $this->cashier] = $this->seedShop();
    }

    protected function tearDown(): void
    {
        PosFeatureService::flushGateCaches();
        parent::tearDown();
    }

    private function hold(array $cartData)
    {
        return $this->actingAs($this->cashier, 'fbrpos')->postJson('/fbr-pos/api/hold', [
            'hold_name' => 'Test hold',
            'cart_data' => $cartData,
        ]);
    }

    public function test_identity_item_and_order_notes_are_discarded_on_hold(): void
    {
        $res = $this->hold([
            'items' => [
                ['item_name' => 'Coke',   'quantity' => 1, 'unit_price' => 100, 'special_notes' => 'Ali Cashier'],          // name
                ['item_name' => 'Burger', 'quantity' => 1, 'unit_price' => 350, 'special_notes' => 'ali.cash@gmail.com'],   // email
                ['item_name' => 'Fries',  'quantity' => 1, 'unit_price' => 150, 'special_notes' => ' ali.cash '],           // email prefix, padded
                ['item_name' => 'Shake',  'quantity' => 1, 'unit_price' => 200, 'special_notes' => '03007654321'],          // phone
            ],
            'kitchen_notes' => 'ali cashier', // case-insensitive name match
        ]);
        $res->assertOk()->assertJson(['success' => true]);

        $held = FbrPosHeldSale::findOrFail($res->json('id'));
        foreach ($held->cart_data['items'] as $it) {
            $this->assertNull($it['special_notes'], $it['item_name'] . ' note should be discarded');
        }
        $this->assertNull($held->cart_data['kitchen_notes']);
    }

    public function test_real_kitchen_instructions_pass_through_on_hold(): void
    {
        $res = $this->hold([
            'items' => [
                ['item_name' => 'Coke',   'quantity' => 1, 'unit_price' => 100, 'special_notes' => 'thanda ho'],
                // contains the user's name as a WORD — must be kept
                ['item_name' => 'Burger', 'quantity' => 1, 'unit_price' => 350, 'special_notes' => 'Ali Cashier ko bolna spicy ho'],
                ['item_name' => 'Fries',  'quantity' => 1, 'unit_price' => 150, 'special_notes' => null],
            ],
            'kitchen_notes' => 'kam mirch',
        ]);
        $res->assertOk()->assertJson(['success' => true]);

        $held = FbrPosHeldSale::findOrFail($res->json('id'));
        $items = $held->cart_data['items'];
        $this->assertSame('thanda ho', $items[0]['special_notes']);
        $this->assertSame('Ali Cashier ko bolna spicy ho', $items[1]['special_notes']);
        $this->assertNull($items[2]['special_notes']);
        $this->assertSame('kam mirch', $held->cart_data['kitchen_notes']);
    }

    public function test_absent_kitchen_notes_key_stays_absent(): void
    {
        $res = $this->hold([
            'items' => [
                ['item_name' => 'Coke', 'quantity' => 1, 'unit_price' => 100, 'special_notes' => 'spicy'],
            ],
        ]);
        $res->assertOk()->assertJson(['success' => true]);

        $held = FbrPosHeldSale::findOrFail($res->json('id'));
        $this->assertArrayNotHasKey('kitchen_notes', $held->cart_data);
        $this->assertSame('spicy', $held->cart_data['items'][0]['special_notes']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Fixtures (mirror FbrPosHazriCashierGateTest)
    // ─────────────────────────────────────────────────────────────────────────

    private function seedShop(): array
    {
        $planId = DB::table('pricing_plans')->insertGetId([
            'name' => 'FBR Business', 'product_type' => 'fbrpos',
            'is_trial' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $company = Company::create([
            'name' => 'Note Strip FBR Shop', 'product_type' => 'fbrpos',
            'status' => 'active', 'company_status' => 'active',
            'is_internal_account' => false,
            'fbr_reporting_enabled' => false,
            'fbr_pos_enabled' => true,
        ]);

        DB::table('subscriptions')->insert([
            'company_id' => $company->id, 'pricing_plan_id' => $planId,
            'active' => true, 'override_type' => 'none',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $cashier = User::create([
            'name' => 'Ali Cashier', 'email' => 'ali.cash@gmail.com',
            'phone' => '03007654321',
            'password' => bcrypt('secret'), 'company_id' => $company->id,
            'role' => 'user', 'pos_role' => 'pos_cashier',
            'is_active' => true,
        ]);

        return [$company, $cashier];
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
            $t->string('fbr_connection_mode')->nullable();
            $t->string('fbr_environment')->nullable();
            $t->string('pos_dashboard_style')->nullable();
            $t->string('default_language')->nullable();
            $t->string('order_match_style')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->nullable()->unique();
            $t->string('phone')->nullable();
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

        Schema::create('fbr_pos_held_sales', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('terminal_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('hold_name')->nullable();
            $t->string('customer_name')->nullable();
            $t->string('customer_phone')->nullable();
            $t->json('cart_data')->nullable();
            $t->text('notes')->nullable();
            $t->unsignedInteger('token_no')->nullable();
            $t->string('order_code')->nullable();
            $t->timestamps();
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

        Schema::create('pos_user_sessions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('user_id');
            $t->timestamp('login_at')->nullable();
            $t->timestamp('logout_at')->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();
        });
    }
}
