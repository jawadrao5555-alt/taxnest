<?php

namespace Tests\Feature;

use App\Services\PosPlanComparisonService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PosPlanMergeMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->default('pos');
            $table->decimal('price', 12, 2);
            $table->decimal('price_quarterly', 12, 2)->nullable();
            $table->integer('invoice_limit')->default(0);
            $table->integer('user_limit')->nullable();
            $table->integer('branch_limit')->nullable();
            $table->integer('max_terminals')->nullable();
            $table->integer('max_users')->nullable();
            $table->integer('max_products')->nullable();
            $table->boolean('is_trial')->default(false);
            foreach ([
                'inventory_enabled', 'reports_enabled', 'restaurant_enabled',
                'deals_enabled', 'riders_enabled', 'hazri_enabled',
                'analytics_enabled', 'rider_tracking_enabled',
                'custom_access_enabled', 'qr_menu_enabled', 'offline_enabled',
                'excel_enabled', 'khata_enabled', 'loyalty_enabled',
                'kot_enabled', 'caller_id_enabled', 'whatsapp_enabled',
            ] as $column) {
                $table->boolean($column)->default(false);
            }
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id');
            $table->boolean('active')->default(true);
            $table->string('billing_cycle')->nullable();
            $table->decimal('final_price', 12, 2)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamps();
        });

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('requested_plan_id')->nullable();
            $table->string('status')->nullable();
            $table->string('company_status')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_proofs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->string('status')->default('pending');
            $table->decimal('amount', 12, 2)->nullable();
            $table->timestamps();
        });
    }

    public function test_the_four_plan_ladder_and_existing_pro_max_accounts_are_migrated_safely(): void
    {
        $starterId = $this->insertPlan('Starter', 14999, 2000, 2, 1, 1);
        $businessId = $this->insertPlan('Business', 24999, 5000, 5, 1, 3);
        $proId = $this->insertPlan('Pro', 34999, 10000, 10, 2, -1, [
            'price_quarterly' => 9999,
            'hazri_enabled' => true,
        ]);
        $proMaxId = $this->insertPlan('Pro Max', 49999, -1, 20, 3, -1, [
            'price_quarterly' => 14399,
            'hazri_enabled' => true,
            'restaurant_enabled' => true,
            'riders_enabled' => true,
            'qr_menu_enabled' => true,
            'custom_access_enabled' => true,
            'max_users' => 20,
            'max_products' => -1,
        ]);
        $unlimitedId = $this->insertPlan('Unlimited', 69999, -1, -1, 5, -1);

        DB::table('companies')->insert([
            'id' => 101,
            'requested_plan_id' => $proMaxId,
            'status' => 'pending',
            'company_status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('companies')->insert([
            'id' => 102,
            'requested_plan_id' => $proMaxId,
            'status' => 'approved',
            'company_status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $activeSubscriptionId = DB::table('subscriptions')->insertGetId([
            'company_id' => 101,
            'pricing_plan_id' => $proMaxId,
            'active' => true,
            'billing_cycle' => 'annual',
            'final_price' => 45500,
            'start_date' => '2026-05-01',
            'end_date' => '2027-05-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $historicalSubscriptionId = DB::table('subscriptions')->insertGetId([
            'company_id' => 101,
            'pricing_plan_id' => $proMaxId,
            'active' => false,
            'billing_cycle' => 'annual',
            'final_price' => 42000,
            'start_date' => '2025-05-01',
            'end_date' => '2026-05-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pendingProofId = DB::table('payment_proofs')->insertGetId([
            'pricing_plan_id' => $proMaxId,
            'status' => 'pending',
            'amount' => 49999,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $verifiedProofId = DB::table('payment_proofs')->insertGetId([
            'pricing_plan_id' => $proMaxId,
            'status' => 'verified',
            'amount' => 49999,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runMigration();

        $business = DB::table('pricing_plans')->where('id', $businessId)->first();
        $pro = DB::table('pricing_plans')->where('id', $proId)->first();
        $activeSubscription = DB::table('subscriptions')->where('id', $activeSubscriptionId)->first();

        $this->assertSame(-1, (int) $business->invoice_limit);
        $this->assertSame(34999, (int) $pro->price, 'The current Pro price must be preserved.');
        $this->assertSame(9999, (int) $pro->price_quarterly, 'The current Pro quarterly price must be preserved.');
        $this->assertSame(-1, (int) $pro->invoice_limit);
        $this->assertSame(20, (int) $pro->user_limit);
        $this->assertSame(3, (int) $pro->branch_limit);
        $this->assertSame(-1, (int) $pro->max_terminals);
        $this->assertSame(1, (int) $pro->restaurant_enabled);
        $this->assertSame(1, (int) $pro->hazri_enabled);

        $this->assertSame($proId, (int) $activeSubscription->pricing_plan_id);
        $this->assertSame('2026-05-01', $activeSubscription->start_date);
        $this->assertSame('2027-05-01', $activeSubscription->end_date);
        $this->assertSame(45500, (int) $activeSubscription->final_price);
        $this->assertSame($proMaxId, (int) DB::table('subscriptions')->where('id', $historicalSubscriptionId)->value('pricing_plan_id'));

        $this->assertSame($proId, (int) DB::table('companies')->where('id', 101)->value('requested_plan_id'));
        $this->assertSame($proMaxId, (int) DB::table('companies')->where('id', 102)->value('requested_plan_id'));
        $this->assertSame($proId, (int) DB::table('payment_proofs')->where('id', $pendingProofId)->value('pricing_plan_id'));
        $this->assertSame($proMaxId, (int) DB::table('payment_proofs')->where('id', $verifiedProofId)->value('pricing_plan_id'));
        $this->assertNotNull(DB::table('pricing_plans')->where('id', $proMaxId)->first(), 'Retired row must remain for history.');

        // Sellable list (23 Aug 2026): Pro was later merged INTO Business, so
        // this migration's own row stays in the table for the shops on it but
        // is no longer offered. The retirement is asserted by its own test.
        $this->assertSame(
            ['Starter', 'Business', 'Unlimited'],
            PosPlanComparisonService::plans()->pluck('name')->all()
        );
        $this->assertSame([$starterId, $businessId, $unlimitedId], PosPlanComparisonService::plans()->pluck('id')->all());
        $this->assertNotNull(DB::table('pricing_plans')->where('id', $proId)->first(),
            'Retired Pro row must remain for the shops still on it.');

        $this->runMigration();
        $this->assertSame($proId, (int) DB::table('subscriptions')->where('id', $activeSubscriptionId)->value('pricing_plan_id'));
        $this->assertSame($proMaxId, (int) DB::table('subscriptions')->where('id', $historicalSubscriptionId)->value('pricing_plan_id'));
        $this->assertSame($proMaxId, (int) DB::table('companies')->where('id', 102)->value('requested_plan_id'));
        $this->assertSame($proMaxId, (int) DB::table('payment_proofs')->where('id', $verifiedProofId)->value('pricing_plan_id'));
    }

    public function test_proof_history_is_not_rewritten_when_status_is_unavailable(): void
    {
        $this->insertPlan('Business', 24999, 5000, 5, 1, 3);
        $this->insertPlan('Pro', 34999, 10000, 10, 2, -1);
        $proMaxId = $this->insertPlan('Pro Max', 49999, -1, 20, 3, -1);

        Schema::drop('payment_proofs');
        Schema::create('payment_proofs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
        });
        $proofId = DB::table('payment_proofs')->insertGetId([
            'pricing_plan_id' => $proMaxId,
            'amount' => 49999,
        ]);

        $this->runMigration();

        $this->assertSame(
            $proMaxId,
            (int) DB::table('payment_proofs')->where('id', $proofId)->value('pricing_plan_id')
        );
    }

    private function insertPlan(
        string $name,
        int $price,
        int $invoiceLimit,
        int $userLimit,
        int $branchLimit,
        int $maxTerminals,
        array $extra = []
    ): int {
        return DB::table('pricing_plans')->insertGetId(array_merge([
            'name' => $name,
            'product_type' => 'pos',
            'price' => $price,
            'price_quarterly' => null,
            'invoice_limit' => $invoiceLimit,
            'user_limit' => $userLimit,
            'branch_limit' => $branchLimit,
            'max_terminals' => $maxTerminals,
            'max_users' => $userLimit,
            'max_products' => -1,
            'is_trial' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));
    }

    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_09_12_140000_merge_pos_pro_max_into_pro_and_unlimit_business_invoices.php');
        $migration->up();
    }
}