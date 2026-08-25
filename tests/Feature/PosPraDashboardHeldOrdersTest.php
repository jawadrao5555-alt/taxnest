<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PosPraDashboardHeldOrdersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();
        Schema::create('restaurant_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('status');
            $table->string('source')->nullable();
            $table->unsignedBigInteger('table_id')->nullable();
            $table->timestamps();
        });
    }

    private function counts(int $companyId, bool $isRestaurant): array
    {
        $controller = new PosController();
        $method = new \ReflectionMethod($controller, 'pendingRestaurantOrderCounts');
        $method->setAccessible(true);

        return $method->invoke($controller, $companyId, $isRestaurant);
    }

    private function order(int $companyId, string $status, ?string $source = null, ?int $tableId = null): void
    {
        DB::table('restaurant_orders')->insert([
            'company_id' => $companyId,
            'status' => $status,
            'source' => $source,
            'table_id' => $tableId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_restaurant_shape_counts_all_day_close_blocking_orders_even_from_prior_days(): void
    {
        $this->order(7, 'held', null, 3);
        $this->order(7, 'preparing', 'waiter', null);
        $this->order(7, 'ready', null, 4);
        $this->order(7, 'completed', null, 5);
        $this->order(8, 'held', null, 6);
        DB::table('restaurant_orders')->where('company_id', 7)->where('status', 'held')
            ->update(['created_at' => now()->subDays(2)]);

        $this->assertSame([3, 1], $this->counts(7, true));
    }

    public function test_non_restaurant_shape_gets_no_held_order_reminder(): void
    {
        $this->order(7, 'held', null, 3);

        $this->assertSame([0, 0], $this->counts(7, false));

        $html = view('pos.partials.pending-bills-tile', [
            'isAdmin' => true,
            'isRestaurant' => false,
            'pendingProvisional' => 0,
            'openOrdersCount' => 1,
        ])->render();
        $this->assertSame('', trim($html));
    }

    public function test_held_order_chips_link_to_actionable_surfaces(): void
    {
        $html = view('pos.partials.pending-bills-tile', [
            'isAdmin' => true,
            'isRestaurant' => true,
            'pendingProvisional' => 0,
            'openOrdersCount' => 2,
            'counterOrdersCount' => 1,
        ])->render();

        $this->assertStringContainsString(route('pos.restaurant.tables'), $html);
        $this->assertStringContainsString(route('pos.invoice.create', ['open_incoming' => 1]), $html);
    }
}