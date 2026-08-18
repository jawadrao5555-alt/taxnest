<?php

namespace Tests\Feature;

use App\Services\PosRepeatCustomerAlert;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 1161: "Purane Customer Khamosh Hain" — repeat-customer inactivity alert.
 *
 * Invariants locked here:
 *   1. Repeat = MIN_ORDERS+ completed orders; fewer never alerts.
 *   2. Khamosh = last order older than INACTIVE_DAYS; recent orderers never alert.
 *   3. Long-gone customers (older than STALE_DAYS) drop off — recent churn only.
 *   4. Phone-matched walk-in bills (customer_id NULL) count via pos_customers.phone.
 *   5. Returns are not orders; deactivated customers are never listed.
 *   6. Restaurant orders count ONLY when unlinked (pos_transaction_id NULL) —
 *      a settled order's bill is already counted from pos_transactions.
 *   7. Company scoping: another company's orders never leak in.
 *   8. mapFor() keys by customer id (chip lookup) off the same cached list.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/PosRepeatCustomerAlertTest.php
 */
class PosRepeatCustomerAlertTest extends TestCase
{
    private const COMPANY = 71;
    private const OTHER_COMPANY = 72;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('pos_customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('status')->default('completed');
            $table->string('transaction_type')->nullable();
            $table->timestamps();
        });

        Schema::create('restaurant_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('pos_transaction_id')->nullable();
            $table->string('status')->default('completed');
            $table->timestamps();
        });

        // No pos_customer_spend_snapshots table — exercises the hasTable guard.

        Cache::flush();
    }

    private function makeCustomer(array $attrs = []): int
    {
        return (int) DB::table('pos_customers')->insertGetId(array_merge([
            'company_id' => self::COMPANY,
            'name' => 'Test Customer',
            'phone' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    private function addTxn(array $attrs = []): void
    {
        DB::table('pos_transactions')->insert(array_merge([
            'company_id' => self::COMPANY,
            'customer_id' => null,
            'customer_phone' => null,
            'status' => 'completed',
            'transaction_type' => null,
            'created_at' => now()->subDays(20),
            'updated_at' => now()->subDays(20),
        ], $attrs));
    }

    private function fresh(): \Illuminate\Support\Collection
    {
        Cache::flush(); // bypass the per-company cache between scenario tweaks

        return PosRepeatCustomerAlert::listFor(self::COMPANY);
    }

    public function test_repeat_customer_gone_quiet_is_listed_with_count_and_days(): void
    {
        $cid = $this->makeCustomer(['name' => 'Khamosh Regular', 'phone' => '0300-1234567']);
        foreach ([40, 30, 20] as $daysAgo) {
            $this->addTxn(['customer_id' => $cid, 'created_at' => now()->subDays($daysAgo)]);
        }

        $list = $this->fresh();

        $this->assertCount(1, $list);
        $row = $list->first();
        $this->assertSame($cid, $row['id']);
        $this->assertSame('Khamosh Regular', $row['name']);
        $this->assertSame('0300-1234567', $row['phone']);
        $this->assertSame(3, $row['orders']);
        $this->assertSame(20, $row['days']);

        // Chip map keys by customer id off the same definition.
        $map = PosRepeatCustomerAlert::mapFor(self::COMPANY);
        $this->assertArrayHasKey($cid, $map);
        $this->assertSame(3, $map[$cid]['orders']);
    }

    public function test_below_min_orders_or_recent_last_order_is_not_listed(): void
    {
        // Only 2 orders — not a repeat customer yet.
        $two = $this->makeCustomer(['name' => 'Two Orders']);
        $this->addTxn(['customer_id' => $two, 'created_at' => now()->subDays(30)]);
        $this->addTxn(['customer_id' => $two, 'created_at' => now()->subDays(20)]);

        // 3 orders but the latest is recent — still active.
        $active = $this->makeCustomer(['name' => 'Still Active']);
        foreach ([30, 20, 2] as $daysAgo) {
            $this->addTxn(['customer_id' => $active, 'created_at' => now()->subDays($daysAgo)]);
        }

        $this->assertCount(0, $this->fresh());
    }

    public function test_long_gone_customers_drop_off_after_stale_window(): void
    {
        $gone = $this->makeCustomer(['name' => 'Long Gone']);
        foreach ([120, 110, 100] as $daysAgo) {
            $this->addTxn(['customer_id' => $gone, 'created_at' => now()->subDays($daysAgo)]);
        }

        $this->assertCount(0, $this->fresh());
    }

    public function test_phone_matched_walkin_bills_count_and_returns_do_not(): void
    {
        $cid = $this->makeCustomer(['name' => 'Phone Match', 'phone' => '0311-9998877']);
        // Two linked + one phone-only walk-in bill = 3 orders.
        $this->addTxn(['customer_id' => $cid, 'created_at' => now()->subDays(35)]);
        $this->addTxn(['customer_id' => $cid, 'created_at' => now()->subDays(25)]);
        $this->addTxn(['customer_phone' => '0311-9998877', 'created_at' => now()->subDays(15)]);
        // A return is NOT an order — without it the count stays 3 (not 4).
        $this->addTxn(['customer_id' => $cid, 'transaction_type' => 'return', 'created_at' => now()->subDays(14)]);

        $list = $this->fresh();

        $this->assertCount(1, $list);
        $this->assertSame(3, $list->first()['orders']);
        $this->assertSame(15, $list->first()['days']);
    }

    public function test_deactivated_customers_and_other_companies_never_listed(): void
    {
        $off = $this->makeCustomer(['name' => 'Deactivated', 'is_active' => false]);
        foreach ([40, 30, 20] as $daysAgo) {
            $this->addTxn(['customer_id' => $off, 'created_at' => now()->subDays($daysAgo)]);
        }

        // Same shape but for another company — must not leak into COMPANY's list.
        $foreign = (int) DB::table('pos_customers')->insertGetId([
            'company_id' => self::OTHER_COMPANY, 'name' => 'Foreign', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([40, 30, 20] as $daysAgo) {
            $this->addTxn(['company_id' => self::OTHER_COMPANY, 'customer_id' => $foreign, 'created_at' => now()->subDays($daysAgo)]);
        }

        $this->assertCount(0, $this->fresh());
    }

    public function test_restaurant_orders_count_only_when_unlinked(): void
    {
        $cid = $this->makeCustomer(['name' => 'Dine In Regular']);
        // Two unlinked completed restaurant orders + one linked pair (order +
        // its bill row): total must be 3, not 4.
        $txnId = (int) DB::table('pos_transactions')->insertGetId([
            'company_id' => self::COMPANY, 'customer_id' => $cid, 'status' => 'completed',
            'created_at' => now()->subDays(18), 'updated_at' => now()->subDays(18),
        ]);
        foreach ([['days' => 30, 'txn' => null], ['days' => 25, 'txn' => null], ['days' => 18, 'txn' => $txnId]] as $o) {
            DB::table('restaurant_orders')->insert([
                'company_id' => self::COMPANY,
                'customer_id' => $cid,
                'pos_transaction_id' => $o['txn'],
                'status' => 'completed',
                'created_at' => now()->subDays($o['days']),
                'updated_at' => now()->subDays($o['days']),
            ]);
        }

        $list = $this->fresh();

        $this->assertCount(1, $list);
        $this->assertSame(3, $list->first()['orders']);
    }
}
