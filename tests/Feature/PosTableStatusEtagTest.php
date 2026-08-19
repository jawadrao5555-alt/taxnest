<?php

namespace Tests\Feature;

use App\Http\Controllers\RestaurantTableController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Table Board poll ETag regression coverage.
 *
 * The board polls this endpoint frequently. An unchanged poll must receive an
 * empty 304 response so it retains its existing tableFloors rather than paying
 * for the endpoint's eager-loaded table/order payload.
 */
class PosTableStatusEtagTest extends TestCase
{
    private int $companyId;
    private int $tableId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('restaurant_floors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('restaurant_tables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('floor_id');
            $table->string('table_number');
            $table->integer('seats')->default(4);
            $table->string('status')->default('available');
            $table->unsignedBigInteger('locked_by_user_id')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('occupied_since')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('restaurant_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('order_number');
            $table->unsignedBigInteger('table_id')->nullable();
            $table->string('order_type')->default('dine_in');
            $table->string('source')->default('pos');
            $table->string('status')->default('held');
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('kot_sent_at')->nullable();
            $table->timestamps();
        });

        $now = now();
        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Table ETag Test Cafe',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $floorId = DB::table('restaurant_floors')->insertGetId([
            'company_id' => $this->companyId,
            'name' => 'Main Floor',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->tableId = DB::table('restaurant_tables')->insertGetId([
            'company_id' => $this->companyId,
            'floor_id' => $floorId,
            'table_number' => 'T-1',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        app()->bind('currentCompanyId', fn () => $this->companyId);
    }

    private function tableStatus(?string $ifNoneMatch = null): \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
    {
        $request = Request::create('/pos/restaurant/api/table-status', 'GET');
        if ($ifNoneMatch !== null) {
            $request->headers->set('If-None-Match', $ifNoneMatch);
        }

        return app(RestaurantTableController::class)->tableStatus($request);
    }

    public function test_table_status_returns_empty_304_when_unchanged_and_fresh_200_after_table_or_order_changes(): void
    {
        $first = $this->tableStatus();
        $this->assertSame(200, $first->getStatusCode());
        $firstEtag = $first->headers->get('ETag');
        $this->assertNotEmpty($firstEtag);

        $unchanged = $this->tableStatus($firstEtag);
        $this->assertSame(304, $unchanged->getStatusCode());
        $this->assertSame('', $unchanged->getContent(), 'A 304 must not send a JSON body.');
        $this->assertSame($firstEtag, $unchanged->headers->get('ETag'));

        DB::table('restaurant_tables')->where('id', $this->tableId)->update([
            'status' => 'occupied',
            'occupied_since' => now()->addSecond(),
            'updated_at' => now()->addSecond(),
        ]);
        $afterTableChange = $this->tableStatus($firstEtag);
        $this->assertSame(200, $afterTableChange->getStatusCode());
        $tableEtag = $afterTableChange->headers->get('ETag');
        $this->assertNotSame($firstEtag, $tableEtag);

        DB::table('restaurant_orders')->insert([
            'company_id' => $this->companyId,
            'order_number' => 'R-ETAG-1',
            'table_id' => $this->tableId,
            'status' => 'held',
            'total_amount' => 550,
            'created_at' => now()->addSeconds(2),
            'updated_at' => now()->addSeconds(2),
        ]);
        $afterOrderChange = $this->tableStatus($tableEtag);
        $this->assertSame(200, $afterOrderChange->getStatusCode());
        $this->assertNotSame($tableEtag, $afterOrderChange->headers->get('ETag'));
    }
}