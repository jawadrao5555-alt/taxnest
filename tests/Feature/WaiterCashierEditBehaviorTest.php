<?php

namespace Tests\Feature;

use App\Http\Controllers\RestaurantWaiterController;
use App\Models\Company;
use App\Models\RestaurantOrder;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Executable cashier edit regressions. These intentionally use the same
 * minimal in-memory schema style as the waiter append feature tests and call
 * the controller with a real POS-authenticated user.
 */
class WaiterCashierEditBehaviorTest extends TestCase
{
    protected function setUp(): void
    {
        // The isolated worktree intentionally reuses the workspace vendor
        // tree. Prepend its app PSR-4 path so this behavioral suite executes
        // the branch under test rather than the parent checkout's classes.
        foreach (spl_autoload_functions() ?: [] as $loader) {
            if (is_array($loader) && $loader[0] instanceof \Composer\Autoload\ClassLoader) {
                $loader[0]->addPsr4('App\\', dirname(__DIR__, 2).'/app', true);
            }
        }
        require_once dirname(__DIR__, 2).'/app/Http/Controllers/RestaurantWaiterController.php';
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('companies', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('product_type')->nullable();
            $t->boolean('restaurant_mode')->default(true); $t->text('feature_flags')->nullable();
            $t->text('pos_printer_settings')->nullable(); $t->boolean('agent_enabled')->default(false);
            $t->timestamp('agent_last_seen')->nullable(); $t->boolean('is_internal_account')->default(false);
            $t->timestamp('deleted_at')->nullable();
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id')->nullable(); $t->string('name');
            $t->string('email')->nullable(); $t->string('pos_role')->nullable(); $t->string('role')->nullable();
            $t->boolean('is_active')->default(true); $t->timestamps();
        });
        Schema::create('restaurant_orders', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('order_number');
            $t->string('order_type')->default('dine_in'); $t->string('status')->default('held');
            $t->string('source')->default('waiter'); $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('assigned_cashier_id')->nullable(); $t->unsignedBigInteger('pos_transaction_id')->nullable();
            $t->string('payment_method')->nullable(); $t->decimal('subtotal', 12, 2)->default(0);
            $t->decimal('tax_amount', 12, 2)->default(0); $t->decimal('discount_amount', 12, 2)->default(0);
            $t->decimal('total_amount', 12, 2)->default(0); $t->unsignedInteger('edit_revision')->default(0);
            $t->string('last_edit_uuid', 64)->nullable(); $t->timestamp('kot_sent_at')->nullable();
            $t->timestamps();
        });
        Schema::create('restaurant_order_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('order_id'); $t->string('item_type')->default('manual');
            $t->unsignedBigInteger('item_id')->nullable(); $t->string('item_name'); $t->decimal('quantity', 8, 2);
            $t->decimal('unit_price', 12, 2); $t->decimal('subtotal', 12, 2); $t->string('special_notes')->nullable();
            $t->boolean('is_tax_exempt')->default(false); $t->timestamp('kot_printed_at')->nullable(); $t->timestamps();
        });
        Schema::create('restaurant_order_edit_attempts', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('order_id');
            $t->string('edit_uuid', 64); $t->unsignedInteger('revision'); $t->string('kot_status', 32)->default('pending');
            $t->json('add_payload')->nullable(); $t->json('void_payload')->nullable(); $t->text('kot_error')->nullable(); $t->timestamps();
            $t->unique(['company_id', 'order_id', 'edit_uuid']);
        });
        Schema::create('pos_products', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('name'); $t->decimal('price', 12, 2);
            $t->boolean('is_active')->default(true); $t->boolean('is_tax_exempt')->default(false); $t->timestamps();
        });
        Schema::create('pos_transactions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('payment_method')->nullable();
            $t->boolean('is_archived')->default(false); $t->timestamps();
        });
        app()->instance('currentCompanyId', 1);
    }

    private function cashier(int $companyId, string $name = 'Cashier'): User
    {
        $user = User::create(['company_id' => $companyId, 'name' => $name, 'pos_role' => 'pos_cashier', 'is_active' => true]);
        Auth::guard('pos')->setUser($user);
        return $user;
    }

    private function order(Company $company, User $creator, array $attrs = []): RestaurantOrder
    {
        return RestaurantOrder::create(array_merge([
            'company_id' => $company->id, 'order_number' => 'W-1', 'status' => 'held',
            'source' => 'waiter', 'created_by' => $creator->id, 'subtotal' => 100,
            'total_amount' => 100, 'edit_revision' => 0,
        ], $attrs));
    }

    private function edit(RestaurantOrder $order, array $items, string $uuid, int $revision = 0)
    {
        $request = Request::create('/pos/restaurant/orders/'.$order->id.'/edit', 'POST', [
            'items' => $items, 'edit_uuid' => $uuid, 'revision' => $revision,
        ]);
        return app(RestaurantWaiterController::class)->updateIncomingOrder($request, $order->id);
    }

    private function line(RestaurantOrder $order, float $qty = 1, string $notes = 'old', bool $printed = true): int
    {
        return (int) $order->items()->create([
            'item_type' => 'manual', 'item_name' => 'Burger', 'quantity' => $qty,
            'unit_price' => 100, 'subtotal' => $qty * 100, 'special_notes' => $notes,
            'kot_printed_at' => $printed ? now() : null,
        ])->id;
    }

    public function test_increase_adds_only_increment_and_keeps_order_id(): void
    {
        $company = Company::create(['name' => 'A']); $waiter = $this->cashier($company->id, 'Waiter');
        $order = $this->order($company, $waiter); $lineId = $this->line($order, 2);
        $response = $this->edit($order, [['line_id' => $lineId, 'name' => 'Burger', 'quantity' => 5, 'unit_price' => 100, 'special_notes' => 'old']], 'inc-1');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($order->id, RestaurantOrder::first()->id);
        $this->assertSame(2.0, (float) $order->fresh()->items()->whereKey($lineId)->value('quantity'));
        $this->assertSame(1, $order->fresh()->items()->whereNull('kot_printed_at')->count());
        $this->assertSame(3.0, (float) $order->fresh()->items()->whereNull('kot_printed_at')->value('quantity'));
    }

    public function test_modifier_and_decrease_emit_one_full_void_not_two(): void
    {
        $company = Company::create(['name' => 'A']); $cashier = $this->cashier($company->id);
        $order = $this->order($company, $cashier); $lineId = $this->line($order, 3, 'no onions');
        $response = $this->edit($order, [['line_id' => $lineId, 'name' => 'Burger', 'quantity' => 1, 'unit_price' => 100, 'special_notes' => 'extra cheese']], 'mod-1');
        $this->assertSame(200, $response->getStatusCode());
        $payload = DB::table('restaurant_order_edit_attempts')->where('edit_uuid', 'mod-1')->value('void_payload');
        $this->assertCount(1, json_decode($payload, true));
        $this->assertSame(3.0, (float) json_decode($payload, true)[0]['qty']);
    }

    public function test_unchanged_snapshot_creates_no_add_or_void_payload(): void
    {
        $company = Company::create(['name' => 'A']); $cashier = $this->cashier($company->id);
        $order = $this->order($company, $cashier); $lineId = $this->line($order);
        $this->assertSame(200, $this->edit($order, [['line_id' => $lineId, 'name' => 'Burger', 'quantity' => 1, 'unit_price' => 100, 'special_notes' => 'old']], 'same-1')->getStatusCode());
        $attempt = DB::table('restaurant_order_edit_attempts')->where('edit_uuid', 'same-1')->first();
        $this->assertNull($attempt->add_payload); $this->assertNull($attempt->void_payload); $this->assertSame('unchanged', $attempt->kot_status);
    }

    public function test_duplicate_uuid_is_one_attempt_and_stale_revision_is_conflict(): void
    {
        $company = Company::create(['name' => 'A']); $cashier = $this->cashier($company->id);
        $order = $this->order($company, $cashier); $lineId = $this->line($order, 1);
        $items = [['line_id' => $lineId, 'name' => 'Burger', 'quantity' => 2, 'unit_price' => 100, 'special_notes' => 'old']];
        $this->edit($order, $items, 'dup-1');
        $replay = $this->edit($order, $items, 'dup-1');
        $this->assertTrue($replay->getData(true)['replayed']);
        $this->assertSame(1, DB::table('restaurant_order_edit_attempts')->count());
        $this->assertContains(DB::table('restaurant_order_edit_attempts')->value('kot_status'), ['error', 'completed', 'unchanged']);
        $stale = $this->edit($order, $items, 'stale-1', 0);
        $this->assertSame(409, $stale->getStatusCode());
    }

    public function test_pending_or_error_retry_resumes_existing_attempt_without_new_edit(): void
    {
        $company = Company::create(['name' => 'A']); $cashier = $this->cashier($company->id);
        $order = $this->order($company, $cashier); $lineId = $this->line($order);
        $items = [['line_id' => $lineId, 'name' => 'Burger', 'quantity' => 2, 'unit_price' => 100, 'special_notes' => 'old']];
        $this->edit($order, $items, 'resume-1');
        DB::table('restaurant_order_edit_attempts')->where('edit_uuid', 'resume-1')->update(['kot_status' => 'pending']);
        $retry = $this->edit($order, $items, 'resume-1', 1);
        $this->assertTrue($retry->getData(true)['replayed']);
        $this->assertSame(1, DB::table('restaurant_order_edit_attempts')->where('edit_uuid', 'resume-1')->count());
        $this->assertSame(2.0, (float) $order->fresh()->items()->sum('quantity'));
    }

    public function test_assigned_other_cashier_cross_tenant_and_terminal_are_rejected(): void
    {
        $company = Company::create(['name' => 'A']); $owner = $this->cashier($company->id, 'Owner');
        $other = $this->cashier($company->id, 'Other'); $order = $this->order($company, $owner, ['assigned_cashier_id' => $owner->id]);
        $this->assertSame(403, $this->edit($order, [['name' => 'Burger', 'quantity' => 1, 'unit_price' => 1]], 'bad')->getStatusCode());
        $foreign = Company::create(['name' => 'B']); $foreignOrder = $this->order($foreign, $other);
        app()->instance('currentCompanyId', $company->id);
        $this->assertSame(404, $this->edit($foreignOrder, [['name' => 'Burger', 'quantity' => 1, 'unit_price' => 1]], 'foreign')->getStatusCode());
        $this->cashier($company->id, 'Owner again');
        $order->update(['status' => 'completed']);
        $this->assertSame(409, $this->edit($order, [['name' => 'Burger', 'quantity' => 1, 'unit_price' => 1]], 'terminal')->getStatusCode());
    }

    public function test_payment_rejects_stale_revision_and_settles_same_order_at_revision(): void
    {
        $company = Company::create(['name' => 'A']); $cashier = $this->cashier($company->id);
        $order = $this->order($company, $cashier); $tx = \App\Models\PosTransaction::create([
            'company_id' => $company->id, 'payment_method' => 'cash',
        ]);
        $this->assertFalse(RestaurantWaiterController::settleWaiterOrder($company->id, $order->id, $tx, $cashier, false, 9));
        $this->assertTrue(RestaurantWaiterController::settleWaiterOrder($company->id, $order->id, $tx, $cashier, false, 0));
        $fresh = RestaurantOrder::find($order->id);
        $this->assertSame('completed', $fresh->status);
        $this->assertSame($tx->id, (int) $fresh->pos_transaction_id);
    }

    public function test_product_metadata_is_authoritative_over_client_name_price_and_tax_flag(): void
    {
        $company = Company::create(['name' => 'A']); $cashier = $this->cashier($company->id);
        $product = DB::table('pos_products')->insertGetId([
            'company_id' => $company->id, 'name' => 'Canonical Burger', 'price' => 175,
            'is_active' => true, 'is_tax_exempt' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $order = $this->order($company, $cashier);
        $response = $this->edit($order, [[
            'item_id' => $product, 'item_type' => 'product', 'name' => 'Tampered Name',
            'quantity' => 1, 'unit_price' => 1, 'special_notes' => '',
        ]], 'metadata-1');
        $this->assertSame(200, $response->getStatusCode());
        $line = $order->fresh()->items()->first();
        $this->assertSame('Canonical Burger', $line->item_name);
        $this->assertSame(175.0, (float) $line->unit_price);
        $this->assertTrue((bool) $line->is_tax_exempt);
    }
}