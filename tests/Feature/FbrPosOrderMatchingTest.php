<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\FbrPosHeldSale;
use App\Models\FbrPosTransaction;
use App\Http\Controllers\FbrPosController;
use App\Http\Controllers\FbrPosPhase2Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * FBR POS Order Matching — feature coverage (Aug 2026).
 *
 * Locks three invariants added by the Order Matching feature:
 *
 *   A. Token / code allocation on holdSale():
 *        • token style  → token_no=1 on first hold, preserved on re-hold
 *        • code style   → random 5-char uppercase code, preserved on re-hold
 *        • off style    → nothing stored
 *
 *   B. Payment persistence — store() writes token_no / order_code that the JS
 *      cart carries from the held-sale recall into fbr_pos_transactions.
 *
 *   C. KOT route scope:
 *        • kotTicket($id)   / GET /fbr-pos/held/{id}/kitchen-ticket
 *          → own company returns a View; wrong company → ModelNotFoundException
 *        • kotReprint($id)  / GET /fbr-pos/transaction/{id}/kot-reprint
 *          → own company returns a View; wrong company → ModelNotFoundException
 *
 *   D. Blade source invariants: after the resendKitchen() + KOT-link fix, the
 *      FBR universal.blade.php must NOT contain the PRA restaurant routes that
 *      were previously wired to these actions.
 *
 * Pattern: APP_ENV=testing + SQLite :memory: + minimal Schema::create.
 * FBR reporting is OFF → no FBR API calls.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/FbrPosOrderMatchingTest.php --testdox
 */
class FbrPosOrderMatchingTest extends TestCase
{
    protected int $companyId;
    protected int $otherCompanyId;
    protected object $user;

    // ─────────────────────────────────────────────────────────────────────────
    // Setup — minimal in-memory schema mirroring production tables
    // ─────────────────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        // ── companies ─────────────────────────────────────────────────────────
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->nullable();
            $t->string('status')->nullable();
            $t->string('company_status')->nullable();
            $t->boolean('fbr_reporting_enabled')->default(false);
            $t->boolean('agent_enabled')->default(false);
            $t->boolean('inventory_enabled')->default(false);
            $t->boolean('is_internal_account')->default(false);
            $t->boolean('kitchen_printer_enabled')->default(false);
            $t->boolean('auto_print_kot')->default(false);
            // Order Matching
            $t->string('order_match_style')->default('off'); // 'off'|'token'|'code'
            $t->unsignedInteger('pos_token_counter')->default(0);
            $t->string('pos_token_date')->nullable();
            // Receipt / print
            $t->string('print_paper_size')->default('thermal');
            $t->string('invoice_display_prefs')->nullable();
            $t->string('order_match_style_receipt')->nullable();
            $t->integer('invoice_limit_override')->nullable();
            $t->string('pos_invoice_prefix')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        // ── users ─────────────────────────────────────────────────────────────
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('role')->nullable();
            $t->string('pos_role')->nullable();
            $t->boolean('is_active')->default(true);
            $t->rememberToken();
            $t->timestamps();
        });

        // ── fbr_pos_held_sales ────────────────────────────────────────────────
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
            // Order Matching columns
            $t->unsignedSmallInteger('token_no')->nullable();
            $t->string('order_code', 10)->nullable();
            $t->timestamps();
        });

        // ── fbr_pos_transactions ──────────────────────────────────────────────
        // Mirror the real migration closely (all columns store() writes in one INSERT).
        Schema::create('fbr_pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('terminal_id')->nullable();
            $t->unsignedBigInteger('shift_id')->nullable();
            $t->string('invoice_number');
            $t->string('invoice_mode')->nullable();
            $t->string('transaction_type')->nullable();
            $t->string('status')->nullable();
            $t->string('fbr_status')->nullable();
            $t->string('fbr_invoice_number')->nullable();
            $t->string('fbr_response_code')->nullable();
            $t->text('fbr_response')->nullable();
            $t->string('fbr_submission_hash')->nullable();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->string('customer_name')->nullable();
            $t->string('customer_phone')->nullable();
            $t->string('customer_ntn')->nullable();
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->string('discount_type')->nullable();
            $t->decimal('discount_value', 12, 2)->nullable();
            $t->decimal('discount_amount', 12, 2)->default(0);
            $t->unsignedBigInteger('promotion_id')->nullable();
            $t->string('promotion_code')->nullable();
            $t->decimal('tax_rate', 8, 2)->nullable();
            $t->decimal('tax_amount', 12, 2)->default(0);
            $t->decimal('fbr_service_charge', 12, 2)->nullable();
            $t->decimal('loyalty_points_earned', 12, 2)->nullable();
            $t->decimal('loyalty_points_redeemed', 12, 2)->nullable();
            $t->decimal('loyalty_redemption_amount', 12, 2)->nullable();
            $t->decimal('total_amount', 12, 2)->default(0);
            $t->string('payment_method')->nullable();
            $t->text('payment_breakdown')->nullable();
            $t->decimal('cash_received', 12, 2)->nullable();
            $t->decimal('change_due', 12, 2)->nullable();
            $t->string('order_type')->nullable();
            $t->string('delivery_address')->nullable();
            $t->string('offline_uuid', 64)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            // Order Matching
            $t->unsignedSmallInteger('token_no')->nullable();
            $t->string('order_code', 10)->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'offline_uuid'], 'fbr_txn_offline_uuid_unique');
        });

        // ── fbr_pos_transaction_items ─────────────────────────────────────────
        Schema::create('fbr_pos_transaction_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('transaction_id');
            $t->unsignedBigInteger('product_id')->nullable();
            $t->string('item_name');
            $t->string('hs_code')->nullable();
            $t->string('uom')->nullable();
            $t->decimal('quantity', 12, 4)->default(1);
            $t->decimal('unit_price', 12, 2)->default(0);
            $t->decimal('cost_price', 12, 4)->nullable();
            $t->decimal('discount', 12, 2)->nullable();
            $t->decimal('item_discount', 12, 2)->nullable();
            $t->boolean('is_tax_exempt')->default(false);
            $t->decimal('tax_rate', 8, 2)->nullable();
            $t->decimal('tax_amount', 12, 2)->nullable();
            $t->decimal('subtotal', 12, 2)->nullable();
            $t->decimal('total', 12, 2)->nullable();
            $t->timestamps();
        });

        // ── fbr_pos_loyalty_settings ──────────────────────────────────────────
        Schema::create('fbr_pos_loyalty_settings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->unique();
            $t->boolean('is_enabled')->default(false);
            $t->decimal('rs_per_point', 8, 2)->default(100);
            $t->decimal('point_value', 8, 2)->default(1);
            $t->integer('min_redeem_points')->default(50);
            $t->integer('points_expiry_days')->nullable();
            $t->timestamps();
        });

        // ── fbr_pos_shifts ────────────────────────────────────────────────────
        Schema::create('fbr_pos_shifts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('user_id');
            $t->string('status')->default('open');
            $t->decimal('sales_count', 12, 2)->default(0);
            $t->decimal('total_sales', 12, 2)->default(0);
            $t->timestamps();
        });

        // ── products ──────────────────────────────────────────────────────────
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name');
            $t->decimal('default_price', 12, 2)->default(0);
            $t->boolean('is_price_editable')->default(true);
            $t->timestamps();
        });

        // ── seed companies ────────────────────────────────────────────────────
        $company = Company::create([
            'name'                  => 'FBR Order Match Shop',
            'fbr_reporting_enabled' => false,
            'agent_enabled'         => false,
            'kitchen_printer_enabled' => true,
            'order_match_style'     => 'off',
            'pos_token_counter'     => 0,
        ]);
        $this->companyId = $company->id;

        $other = Company::create([
            'name'                  => 'Different Company',
            'fbr_reporting_enabled' => false,
            'order_match_style'     => 'off',
        ]);
        $this->otherCompanyId = $other->id;

        // ── seed user ─────────────────────────────────────────────────────────
        $userId = DB::table('users')->insertGetId([
            'name'       => 'FBR Cashier',
            'email'      => 'cashier@fbrtest.pk',
            'password'   => bcrypt('test'),
            'company_id' => $this->companyId,
            'role'       => 'company_admin',
            'pos_role'   => 'pos_admin',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->user = (object) [
            'id'         => $userId,
            'company_id' => $this->companyId,
            'role'       => 'company_admin',
            'pos_role'   => 'pos_admin',
        ];

        // Loyalty settings pre-seeded (avoids firstOrCreate write inside store)
        DB::table('fbr_pos_loyalty_settings')->insert([
            'company_id'        => $this->companyId,
            'is_enabled'        => false,
            'rs_per_point'      => 100.00,
            'point_value'       => 1.00,
            'min_redeem_points' => 50,
            'created_at'        => now(), 'updated_at' => now(),
        ]);

        // Container bindings used by FBR controllers
        app()->bind('currentCompanyId', fn () => $this->companyId);
        app()->bind('currentBranchId',  fn () => null);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Auth helper — sets the fbrpos guard to use our test user
    // ─────────────────────────────────────────────────────────────────────────

    private function authAsUser(): void
    {
        $userModel = new \App\Models\User();
        $userModel->id         = $this->user->id;
        $userModel->role       = $this->user->role;
        $userModel->company_id = $this->companyId;
        Auth::guard('fbrpos')->setUser($userModel);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Hold helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function callHold(array $cartData = [], array $extra = []): \Illuminate\Http\JsonResponse
    {
        $this->authAsUser();

        $payload = array_merge([
            'hold_name' => 'Test Hold ' . uniqid(),
            'cart_data' => array_merge([
                'items' => [
                    ['item_name' => 'Chai', 'quantity' => 1, 'unit_price' => 50],
                ],
                'total_amount' => 50,
            ], $cartData),
        ], $extra);

        $req = Request::create('/fbr-pos/hold', 'POST', $payload);
        $req->headers->set('Accept', 'application/json');

        return (new FbrPosPhase2Controller())->holdSale($req);
    }

    private function setStyle(string $style): void
    {
        DB::table('companies')->where('id', $this->companyId)
            ->update(['order_match_style' => $style]);
    }

    private function cashPayload(array $override = []): array
    {
        return array_merge([
            'items' => [[
                'item_name'     => 'Burger',
                'quantity'      => 1,
                'unit_price'    => 200,
                'uom'           => 'U',
                'tax_rate'      => 0,
                'is_tax_exempt' => true,
                'item_discount' => 0,
            ]],
            'payment_method' => 'cash',
            'cash_received'  => 200,
            'discount_type'  => 'percentage',
            'discount_value' => 0,
            'tax_inclusive'  => false,
            'offline_uuid'   => 'om-test-' . uniqid(),
        ], $override);
    }

    private function callStore(array $payload): \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
    {
        $this->authAsUser();
        $req = Request::create('/fbr-pos/store', 'POST', $payload);
        $req->headers->set('Accept', 'application/json');
        return (new FbrPosController())->store($req);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // A — Token / code allocation on holdSale()
    // ─────────────────────────────────────────────────────────────────────────

    public function test_hold_assigns_token_no_one_on_first_hold_in_token_mode(): void
    {
        $this->setStyle('token');

        $res  = $this->callHold();
        $data = $res->getData(true);

        $this->assertTrue($data['success'], 'hold succeeded');
        $this->assertSame(1, $data['token_no'], 'first hold gets token_no=1');
        $this->assertNull($data['order_code'],   'order_code must be null in token mode');

        // DB row
        $row = DB::table('fbr_pos_held_sales')->where('id', $data['id'])->first();
        $this->assertSame(1, (int) $row->token_no,  'DB: token_no=1');
        $this->assertNull($row->order_code,          'DB: order_code=NULL');

        // cart_data also carries the token so recall round-trips it
        $cd = json_decode($row->cart_data, true);
        $this->assertSame(1, (int) $cd['token_no'], 'cart_data.token_no=1');
    }

    public function test_second_hold_increments_token_no(): void
    {
        $this->setStyle('token');

        $this->callHold();        // token_no=1
        $res2 = $this->callHold(); // token_no=2
        $data2 = $res2->getData(true);

        $this->assertSame(2, $data2['token_no'], 'second hold gets token_no=2');

        $ctr = DB::table('companies')->where('id', $this->companyId)->value('pos_token_counter');
        $this->assertSame(2, (int) $ctr, 'pos_token_counter=2 after two holds');
    }

    public function test_rehold_preserves_existing_token_no(): void
    {
        $this->setStyle('token');

        // First hold → token_no=1
        $res1 = $this->callHold();
        $id1  = $res1->getData(true)['id'];

        // Simulate recall: extract cart_data including token_no
        $row1  = DB::table('fbr_pos_held_sales')->where('id', $id1)->first();
        $cd    = json_decode($row1->cart_data, true);
        $this->assertSame(1, (int) $cd['token_no'], 'precondition: cart_data has token_no=1');

        // Re-hold: pass the existing token inside cart_data (same as JS recall does)
        $res2  = $this->callHold($cd); // cart_data already contains token_no=1
        $data2 = $res2->getData(true);

        $this->assertSame(1, $data2['token_no'], 're-hold response: token_no still 1');

        // Counter must NOT have advanced past 1
        $ctr = DB::table('companies')->where('id', $this->companyId)->value('pos_token_counter');
        $this->assertSame(1, (int) $ctr, 'pos_token_counter stays 1 — re-hold did not allocate new token');

        // DB row for re-hold
        $row2 = DB::table('fbr_pos_held_sales')->where('id', $data2['id'])->first();
        $this->assertSame(1, (int) $row2->token_no, 'DB: re-hold row has token_no=1');
    }

    public function test_hold_assigns_five_char_uppercase_code_in_code_mode(): void
    {
        $this->setStyle('code');

        $res  = $this->callHold();
        $data = $res->getData(true);

        $code = $data['order_code'];
        $this->assertTrue($data['success'],    'hold succeeded');
        $this->assertNull($data['token_no'],   'token_no must be null in code mode');
        $this->assertNotNull($code,            'order_code must be set');
        $this->assertSame(5, strlen($code),    '5-character code');
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{5}$/', $code, 'uppercase alphanumeric');

        $row = DB::table('fbr_pos_held_sales')->where('id', $data['id'])->first();
        $this->assertSame($code, $row->order_code, 'DB order_code matches response');
        $this->assertNull($row->token_no,           'DB token_no=NULL');
    }

    public function test_rehold_preserves_existing_order_code(): void
    {
        $this->setStyle('code');

        $res1 = $this->callHold();
        $id1  = $res1->getData(true)['id'];
        $cd1  = json_decode(DB::table('fbr_pos_held_sales')->where('id', $id1)->value('cart_data'), true);
        $origCode = $cd1['order_code'];

        // Re-hold with cart_data carrying the existing code
        $res2 = $this->callHold($cd1);
        $data2 = $res2->getData(true);

        $this->assertSame($origCode, $data2['order_code'], 're-hold keeps same order_code');

        $row2 = DB::table('fbr_pos_held_sales')->where('id', $data2['id'])->first();
        $this->assertSame($origCode, $row2->order_code, 'DB: re-hold row has same order_code');
    }

    public function test_hold_in_off_mode_stores_no_token_or_code(): void
    {
        $this->setStyle('off');

        $res  = $this->callHold();
        $data = $res->getData(true);

        $this->assertTrue($data['success']);
        $this->assertNull($data['token_no'],   'token_no null in off mode');
        $this->assertNull($data['order_code'], 'order_code null in off mode');

        $row = DB::table('fbr_pos_held_sales')->where('id', $data['id'])->first();
        $this->assertNull($row->token_no);
        $this->assertNull($row->order_code);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // B — Payment persistence: store() writes token_no / order_code from payload
    // ─────────────────────────────────────────────────────────────────────────

    public function test_store_persists_token_no_into_fbr_pos_transactions(): void
    {
        $res = $this->callStore($this->cashPayload(['token_no' => 3]));
        $data = $res->getData(true);

        $this->assertTrue($data['success'], 'store succeeded');
        $txId = $data['transaction_id'];

        $tokenInDb = DB::table('fbr_pos_transactions')->where('id', $txId)->value('token_no');
        $this->assertSame(3, (int) $tokenInDb, 'token_no=3 persisted in fbr_pos_transactions');
    }

    public function test_store_persists_order_code_into_fbr_pos_transactions(): void
    {
        $res = $this->callStore($this->cashPayload(['order_code' => 'XYZAB']));
        $data = $res->getData(true);

        $this->assertTrue($data['success'], 'store succeeded');
        $txId = $data['transaction_id'];

        $codeInDb = DB::table('fbr_pos_transactions')->where('id', $txId)->value('order_code');
        $this->assertSame('XYZAB', $codeInDb, 'order_code persisted in fbr_pos_transactions');
    }

    public function test_store_with_no_token_or_code_keeps_both_null(): void
    {
        $res = $this->callStore($this->cashPayload());
        $data = $res->getData(true);

        $this->assertTrue($data['success']);
        $txId = $data['transaction_id'];

        $row = DB::table('fbr_pos_transactions')->where('id', $txId)->first();
        $this->assertNull($row->token_no,   'token_no NULL when not provided');
        $this->assertNull($row->order_code, 'order_code NULL when not provided');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // C — KOT route scope: own company → View; wrong company → 404
    // ─────────────────────────────────────────────────────────────────────────

    public function test_kot_held_returns_view_for_own_company(): void
    {
        // Seed a held sale for the correct company
        $heldId = DB::table('fbr_pos_held_sales')->insertGetId([
            'company_id' => $this->companyId,
            'user_id'    => $this->user->id,
            'hold_name'  => 'KOT Test',
            'cart_data'  => json_encode(['items' => [['item_name' => 'Chai', 'quantity' => 1]]]),
            'token_no'   => 5,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app()->bind('currentCompanyId', fn () => $this->companyId);

        $result = (new FbrPosController())->kotTicket($heldId);

        // The slip is rendered inside the controller (so a render failure can
        // give the first-send claim back), so what comes out is the finished
        // ticket, not a lazy View.
        $this->assertInstanceOf(
            SymfonyResponse::class,
            $result,
            'kotTicket() must return the rendered slip for the correct company'
        );
        $this->assertStringContainsString('Chai', $result->getContent(), 'the slip must carry the order');
    }

    public function test_kot_held_throws_not_found_for_wrong_company(): void
    {
        // Held sale belongs to $otherCompanyId
        $heldId = DB::table('fbr_pos_held_sales')->insertGetId([
            'company_id' => $this->otherCompanyId,
            'user_id'    => 99,
            'hold_name'  => 'Other company hold',
            'cart_data'  => json_encode(['items' => []]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Our current company is $companyId — the held sale is NOT ours
        app()->bind('currentCompanyId', fn () => $this->companyId);

        $this->expectException(ModelNotFoundException::class);
        (new FbrPosController())->kotTicket($heldId);
    }

    public function test_kot_txn_reprint_returns_view_for_own_company(): void
    {
        $txnId = DB::table('fbr_pos_transactions')->insertGetId([
            'company_id'     => $this->companyId,
            'user_id'        => $this->user->id,
            'invoice_number' => 'KOT-REPRINT-001',
            'total_amount'   => 250,
            'tax_amount'     => 0,
            'subtotal'       => 250,
            'payment_method' => 'cash',
            'token_no'       => 2,
            'created_at'     => now(), 'updated_at' => now(),
        ]);

        app()->bind('currentCompanyId', fn () => $this->companyId);

        $result = (new FbrPosController())->kotReprint($txnId);

        $this->assertInstanceOf(
            SymfonyResponse::class,
            $result,
            'kotReprint() must return the rendered slip for the correct company'
        );
        $this->assertNotSame('', trim($result->getContent()), 'the slip must actually render');
    }

    public function test_kot_txn_reprint_throws_not_found_for_wrong_company(): void
    {
        // Transaction belongs to $otherCompanyId
        $txnId = DB::table('fbr_pos_transactions')->insertGetId([
            'company_id'     => $this->otherCompanyId,
            'invoice_number' => 'OTHER-CO-001',
            'total_amount'   => 100,
            'tax_amount'     => 0,
            'subtotal'       => 100,
            'payment_method' => 'cash',
            'created_at'     => now(), 'updated_at' => now(),
        ]);

        app()->bind('currentCompanyId', fn () => $this->companyId);

        $this->expectException(ModelNotFoundException::class);
        (new FbrPosController())->kotReprint($txnId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // D — Blade source invariants: resendKitchen() + KOT link use FBR, not PRA
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The resendKitchen() function in fbr-pos/universal.blade.php must NOT call
     * the PRA /pos/restaurant/orders/.../resend-kitchen endpoint.
     *
     * It must call printKitchenTicket with isFbrHeld=true, which opens:
     *   /fbr-pos/held/{id}/kitchen-ticket
     */
    public function test_resend_kitchen_does_not_call_pra_restaurant_resend_endpoint(): void
    {
        $blade = file_get_contents(base_path('resources/views/fbr-pos/universal.blade.php'));

        // Old broken implementation would POST here — must be gone from resendKitchen
        $this->assertStringNotContainsString(
            '/pos/restaurant/orders/',
            $this->extractFunctionBody($blade, 'resendKitchen'),
            'resendKitchen() must not reference /pos/restaurant/orders/ paths'
        );
    }

    /**
     * The resendKitchen() function must delegate to printKitchenTicket() with
     * isFbrHeld=true so the correct FBR KOT URL is used.
     */
    public function test_resend_kitchen_delegates_to_print_kitchen_ticket_fbr_held(): void
    {
        $blade = file_get_contents(base_path('resources/views/fbr-pos/universal.blade.php'));
        $body  = $this->extractFunctionBody($blade, 'resendKitchen');

        $this->assertStringContainsString(
            'printKitchenTicket',
            $body,
            'resendKitchen() must call printKitchenTicket()'
        );
        $this->assertStringContainsString(
            'isFbrHeld',
            $body,
            'resendKitchen() must pass isFbrHeld=true to printKitchenTicket()'
        );
    }

    /**
     * The KOT anchor link in the held-orders panel must point to the FBR held-sale
     * KOT endpoint, not the PRA restaurant kitchen-ticket route.
     */
    public function test_kot_anchor_in_held_list_uses_fbr_held_url_not_pra(): void
    {
        $blade = file_get_contents(base_path('resources/views/fbr-pos/universal.blade.php'));

        // The anchor that links to the KOT view for a held order
        // must reference /fbr-pos/held/ not /pos/restaurant/orders/
        $this->assertStringContainsString(
            "'/fbr-pos/held/' + order.id + '/kitchen-ticket'",
            $blade,
            'KOT anchor in held-list must use /fbr-pos/held/{id}/kitchen-ticket'
        );
    }

    /**
     * The printKitchenTicket() helper must use /fbr-pos/held/ when isFbrHeld=true,
     * confirming the resend chain hits the correct URL.
     *
     * Note: the function also contains a PRA fallback branch
     * (`isRestaurantMode ? '/pos/restaurant/orders/...' : '/fbr-pos/transaction/...'`)
     * which is intentionally kept intact for PRA restaurant companies.  We only
     * assert that the FBR paths are present — NOT that the PRA fallback is absent.
     */
    public function test_print_kitchen_ticket_fbr_held_branch_uses_fbr_url(): void
    {
        $blade = file_get_contents(base_path('resources/views/fbr-pos/universal.blade.php'));
        $body  = $this->extractFunctionBody($blade, 'printKitchenTicket');

        $this->assertStringContainsString(
            '/fbr-pos/held/',
            $body,
            'printKitchenTicket isFbrHeld branch must use /fbr-pos/held/'
        );

        // The isFbrHeld guard must be checked (FBR path runs first, before PRA fallback)
        $this->assertStringContainsString(
            'isFbrHeld',
            $body,
            'printKitchenTicket must branch on isFbrHeld for FBR held-sale path'
        );
    }

    /**
     * The post-pay KOT reprint (K key) must use /fbr-pos/transaction/ — not PRA.
     */
    public function test_kot_reprint_branch_uses_fbr_transaction_url(): void
    {
        $blade = file_get_contents(base_path('resources/views/fbr-pos/universal.blade.php'));
        $body  = $this->extractFunctionBody($blade, 'printKitchenTicket');

        $this->assertStringContainsString(
            '/fbr-pos/transaction/',
            $body,
            'printKitchenTicket must use /fbr-pos/transaction/ for post-pay KOT reprint'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F — FBR Auto-KOT toggle endpoint + blade wiring
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /fbr-pos/api/toggle-auto-kot flips auto_print_kot ON when it was OFF,
     * and returns success=true with enabled=true.
     */
    public function test_fbr_toggle_auto_kot_turns_on_when_off(): void
    {
        DB::table('companies')->where('id', $this->companyId)
            ->update(['kitchen_printer_enabled' => 1, 'auto_print_kot' => 0]);

        $this->authAsUser();
        $req = Request::create('/fbr-pos/api/toggle-auto-kot', 'POST');
        $req->headers->set('Accept', 'application/json');
        $res  = (new FbrPosController())->toggleAutoKot();
        $data = $res->getData(true);

        $this->assertTrue($data['success'], 'toggle returns success');
        $this->assertTrue($data['enabled'],  'enabled=true after flip');

        $dbVal = DB::table('companies')->where('id', $this->companyId)->value('auto_print_kot');
        $this->assertSame(1, (int) $dbVal, 'auto_print_kot=1 persisted in DB');
    }

    /**
     * Second call flips it back OFF.
     */
    public function test_fbr_toggle_auto_kot_turns_off_when_on(): void
    {
        DB::table('companies')->where('id', $this->companyId)
            ->update(['kitchen_printer_enabled' => 1, 'auto_print_kot' => 1]);

        $this->authAsUser();
        $res  = (new FbrPosController())->toggleAutoKot();
        $data = $res->getData(true);

        $this->assertTrue($data['success']);
        $this->assertFalse($data['enabled'], 'enabled=false after second flip');

        $dbVal = DB::table('companies')->where('id', $this->companyId)->value('auto_print_kot');
        $this->assertSame(0, (int) $dbVal, 'auto_print_kot=0 persisted in DB');
    }

    /**
     * FBR toggle returns HTTP 422 when kitchen_printer_enabled is OFF —
     * same gate the PRA side uses for features->kot.
     */
    public function test_fbr_toggle_auto_kot_requires_kitchen_printer_enabled(): void
    {
        DB::table('companies')->where('id', $this->companyId)
            ->update(['kitchen_printer_enabled' => 0]);

        $this->authAsUser();
        $res = (new FbrPosController())->toggleAutoKot();

        $this->assertSame(422, $res->getStatusCode());
        $data = $res->getData(true);
        $this->assertFalse($data['success']);
    }

    /**
     * Non-admin (pos_cashier) must receive HTTP 403.
     */
    public function test_fbr_toggle_auto_kot_blocks_cashier(): void
    {
        DB::table('companies')->where('id', $this->companyId)
            ->update(['kitchen_printer_enabled' => 1]);

        // Override the guard user to a cashier role
        $cashierModel = new \App\Models\User();
        $cashierModel->id         = 999;
        $cashierModel->role       = 'pos_cashier';   // not company_admin
        $cashierModel->company_id = $this->companyId;
        Auth::guard('fbrpos')->setUser($cashierModel);

        $res = (new FbrPosController())->toggleAutoKot();

        $this->assertSame(403, $res->getStatusCode());
        $data = $res->getData(true);
        $this->assertFalse($data['success']);
    }

    /**
     * The blade must initialise autoKotEnabled from the server column
     * (companies.auto_print_kot), NOT hardcoded false.  The hasColumn
     * guard expression must be present to protect against schema drift.
     */
    public function test_blade_auto_kot_enabled_reads_from_server_column(): void
    {
        $blade = file_get_contents(base_path('resources/views/fbr-pos/universal.blade.php'));

        // Must reference the auto_print_kot column
        $this->assertStringContainsString(
            'auto_print_kot',
            $blade,
            'blade must read autoKotEnabled from companies.auto_print_kot'
        );

        // hasColumn guard must be present (protects against schema drift on PROD)
        $this->assertStringContainsString(
            "hasColumn('companies', 'auto_print_kot')",
            $blade,
            'blade autoKotEnabled init must have a hasColumn guard for schema drift safety'
        );

        // Must NOT be permanently hardcoded to false
        $this->assertStringNotContainsString(
            'autoKotEnabled: false,',
            $blade,
            'autoKotEnabled must not be hardcoded false — must read from server'
        );
    }

    /**
     * Both Auto-KOT toggle buttons in the FBR blade must POST to the FBR
     * endpoint (fbrpos.api.toggle-auto-kot), never the PRA route
     * (pos.api.toggle-auto-kot) which goes through PosFeatureService and
     * would always return 422 for FBR companies.
     */
    public function test_blade_auto_kot_toggle_uses_fbr_endpoint_not_pra(): void
    {
        $blade = file_get_contents(base_path('resources/views/fbr-pos/universal.blade.php'));

        // FBR route must appear at least twice (two toggle buttons)
        $fbrCount = substr_count($blade, "route('fbrpos.api.toggle-auto-kot')");
        $this->assertGreaterThanOrEqual(
            2,
            $fbrCount,
            "Both Auto-KOT toggle buttons must call route('fbrpos.api.toggle-auto-kot')"
        );

        // PRA route must NOT appear at all in the FBR blade
        $this->assertStringNotContainsString(
            "route('pos.api.toggle-auto-kot')",
            $blade,
            "FBR blade must never call the PRA route pos.api.toggle-auto-kot"
        );
    }

    /**
     * With autoKotEnabled=true AND a transaction_id, processPaymentManual
     * chains auto-KOT via runAutoPrintChain(data.transaction_id, false) →
     * printKitchenTicket → /fbr-pos/transaction/{id}/kot-reprint.
     *
     * This test pins the blade-source invariant: processPaymentManual must
     * pass the transaction_id (not null) so wantsKot can become true.
     */
    public function test_auto_kot_after_fbr_bill_targets_transaction_reprint_url(): void
    {
        $blade = file_get_contents(base_path('resources/views/fbr-pos/universal.blade.php'));

        // processPaymentManual must pass data.transaction_id to runAutoPrintChain
        $this->assertStringContainsString(
            'runAutoPrintChain(data.transaction_id',
            $blade,
            'processPaymentManual must pass data.transaction_id to runAutoPrintChain for auto-KOT'
        );

        // runAutoPrintChain must forward isFbrHeld=false so printKitchenTicket
        // reaches the /fbr-pos/transaction/{id}/kot-reprint branch
        $runBody = $this->extractFunctionBody($blade, 'runAutoPrintChain');
        $this->assertStringContainsString(
            '/fbr-pos/transaction/',
            // printKitchenTicket body that runAutoPrintChain delegates to
            $this->extractFunctionBody($blade, 'printKitchenTicket'),
            'printKitchenTicket must contain /fbr-pos/transaction/ for post-pay KOT reprint'
        );

        // The isFbrHeld=false ensures the non-held branch is taken
        $this->assertStringContainsString(
            'isFbrHeld= */ false',
            $blade,
            'runAutoPrintChain call from processPaymentManual must explicitly pass isFbrHeld=false'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // E — runAutoPrintChain auto-KOT chain correctness
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * runAutoPrintChain() must accept and propagate an isFbrHeld param to both
     * printKitchenTicket() call sites inside it.
     *
     * Before the fix both calls were `printKitchenTicket(orderId)` — no isFbrHeld —
     * so a held-sale ID reaching the chain would silently hit
     * /fbr-pos/transaction/{heldSaleId}/kot-reprint (wrong endpoint).
     */
    public function test_run_auto_print_chain_propagates_is_fbr_held_to_print_kitchen_ticket(): void
    {
        $blade = file_get_contents(base_path('resources/views/fbr-pos/universal.blade.php'));
        $body  = $this->extractFunctionBody($blade, 'runAutoPrintChain');

        // Both inner printKitchenTicket calls must forward isFbrHeld as 3rd arg.
        // A simple string scan is sufficient: the body will contain
        // printKitchenTicket(orderId, null, isFbrHeld) in both branches.
        $occurrences = substr_count($body, 'printKitchenTicket(orderId, null, isFbrHeld)');
        $this->assertSame(
            2,
            $occurrences,
            'runAutoPrintChain() must pass isFbrHeld to printKitchenTicket() in both branches (receipt+kot and kot-only)'
        );
    }

    /**
     * The auto-KOT that fires immediately after holdOrder (forcePrintKot=true, F5
     * "Send to Kitchen") must use the FBR held-sale URL, never the transaction URL.
     *
     * Blade invariant: the holdOrder success path calls
     *   printKitchenTicket(data.id, null, true /* isFbrHeld *\/)
     * which routes to /fbr-pos/held/{id}/kitchen-ticket.
     */
    public function test_hold_auto_kot_uses_fbr_held_url_not_transaction_url(): void
    {
        $blade = file_get_contents(base_path('resources/views/fbr-pos/universal.blade.php'));

        // The forcePrintKot call must pass true (isFbrHeld) as the third argument.
        $this->assertStringContainsString(
            'printKitchenTicket(data.id, null, true',
            $blade,
            'holdOrder forcePrintKot must call printKitchenTicket(data.id, null, true /* isFbrHeld */)'
        );

        // And printKitchenTicket with isFbrHeld=true must route to /fbr-pos/held/
        // (already verified by test_print_kitchen_ticket_fbr_held_branch_uses_fbr_url,
        // but this test pins the CALL SITE in holdOrder, not just the function body).
        $holdBody = $this->extractFunctionBody($blade, 'holdOrder');
        $this->assertStringNotContainsString(
            '/fbr-pos/transaction/',
            $holdBody,
            'holdOrder must never reference the transaction KOT URL directly'
        );
    }

    /**
     * processPaymentManual must pass the transaction_id (not null) to
     * runAutoPrintChain so auto-KOT can actually fire when autoKotEnabled=true.
     *
     * The previous code passed null unconditionally, making wantsKot=false so
     * the KOT never printed after a direct FBR bill or a recalled-then-billed order.
     *
     * Also confirms isFbrHeld=false is passed, because the held row is deleted on
     * recall — post-pay KOT must use /fbr-pos/transaction/{id}/kot-reprint.
     */
    public function test_process_payment_manual_passes_transaction_id_to_run_auto_print_chain(): void
    {
        $blade = file_get_contents(base_path('resources/views/fbr-pos/universal.blade.php'));
        $body  = $this->extractFunctionBody($blade, 'processPaymentManual');

        // Must NOT call runAutoPrintChain(null) — that suppresses auto-KOT forever.
        $this->assertStringNotContainsString(
            'runAutoPrintChain(null)',
            $body,
            'processPaymentManual must not pass null to runAutoPrintChain (blocks auto-KOT)'
        );

        // Must pass the transaction_id so wantsKot=true when autoKotEnabled is set.
        $this->assertStringContainsString(
            'runAutoPrintChain(data.transaction_id',
            $body,
            'processPaymentManual must pass data.transaction_id to runAutoPrintChain'
        );

        // isFbrHeld must be false — held row was deleted on recall, post-pay KOT
        // uses /fbr-pos/transaction/ not /fbr-pos/held/.
        $this->assertStringContainsString(
            'isFbrHeld= */ false',
            $body,
            'processPaymentManual must pass isFbrHeld=false to runAutoPrintChain'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Utility — extract the JS function body from Blade source
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Extract the body of a named JS function from a Blade string.
     *
     * Specifically targets function DEFINITIONS (not call sites like @click="fn()").
     * Searches for patterns like:
     *   async funcName(
     *   funcName(orderId, onAfterPrint, isFbrHeld) {
     * by looking for the name preceded by whitespace/newline at the start of a
     * declaration context — never preceded by @click=" or similar HTML attributes.
     *
     * Falls back to the last occurrence of `funcName(` that has a `{` within 500 chars
     * (function definitions always have an opening brace close by).
     *
     * Returns empty string if not found.
     */
    private function extractFunctionBody(string $source, string $funcName): string
    {
        $len   = strlen($source);
        $start = null;

        // Strategy: find the function DEFINITION (not a call site).
        //
        // Definitions look like (object method shorthand, JS class body):
        //   [whitespace/comma/newline]funcName(
        //   async funcName(
        //
        // Call sites always have a `.` immediately before the function name:
        //   this.funcName(   this.$nextTick(() => this.funcName(
        //
        // We iterate ALL occurrences and pick the first one where the char
        // immediately before `funcName` is NOT a `.` (call-site indicator)
        // AND within 400 chars there is a `{` (function body opener).
        foreach (['async ' . $funcName . '(', $funcName . '('] as $needle) {
            $offset = 0;
            while (($pos = strpos($source, $needle, $offset)) !== false) {
                // The char immediately before `funcName` (or before `async `)
                $checkPos  = $pos;
                $charBefore = $checkPos > 0 ? $source[$checkPos - 1] : ' ';

                // Call-site indicators:
                //   `.`  → this.fn(), obj.fn()
                //   `"`  → @click="fn()", :disabled="!fn()"
                //   `'`  → @click='fn()'
                $isCallSite = $charBefore === '.' || $charBefore === '"' || $charBefore === "'";

                if (!$isCallSite) {
                    // Definition must have a `{` within 400 chars (never true for HTML attrs)
                    $openPos = strpos($source, '{', $pos);
                    if ($openPos !== false && ($openPos - $pos) < 400) {
                        $start = $openPos;
                        break 2;
                    }
                }

                $offset = $pos + 1;
            }
        }

        if ($start === null) {
            return '';
        }

        $depth = 0;

        for ($i = $start; $i < $len; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $i - $start + 1);
                }
            }
        }

        return substr($source, $start); // unterminated — return remainder
    }
}
