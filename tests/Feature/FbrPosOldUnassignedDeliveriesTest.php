<?php

namespace Tests\Feature;

use App\Http\Controllers\FbrPosController;
use App\Models\Company;
use App\Models\FbrPosTransaction;
use App\Models\PosRider;
use App\Services\PosBusinessDay;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 524 (FBR mirror) — purane (pichhle business days ke) UNASSIGNED delivery
 * bills popup ke alag "Purani deliveries" group mein, badge se bahar.
 *
 * Locked here: FbrPosController::apiProvisionalBills final_deliveries payload
 * mein is_stale_unassigned — TRUE sirf unassigned (rider NULL + status NULL) +
 * business day < current FBR business day. Assigned/dispatched par kabhi TRUE
 * nahi; 7-din se purane unassigned aate hi nahi (Task 517 window as-is).
 * business_date NULL ho to created_at ka business day (forMomentFbr) decide
 * karta hai.
 *
 * Pattern: sqlite :memory: + minimal Schema::create, controller invoked
 * directly with the currentCompanyId binding — mirrors
 * FbrPosPopupDeliveredSettleTest (+ business_date column).
 */
class FbrPosOldUnassignedDeliveriesTest extends TestCase
{
    protected int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('confidential_pin')->nullable();
            $table->boolean('fbr_reporting_enabled')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('fbr_pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number');
            $table->string('business_date')->nullable();
            $table->string('status')->nullable();
            $table->string('invoice_mode')->nullable();
            $table->string('fbr_status')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('order_type')->nullable();
            $table->string('delivery_address')->nullable();
            $table->unsignedBigInteger('rider_id')->nullable();
            $table->string('delivery_status')->nullable();
            $table->timestamp('rider_assigned_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->unsignedBigInteger('rider_settlement_id')->nullable();
            $table->timestamp('rider_settled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('fbr_pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('item_name')->nullable();
            $table->timestamps();
        });

        Schema::create('fbr_day_close_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('report_date');
            $table->timestamps();
        });

        Schema::create('pos_riders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('phone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pos_rider_settlements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('rider_id');
            $table->unsignedBigInteger('settled_by')->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->integer('bill_count')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        $this->companyId = Company::create(['name' => 'FBR Old Unassigned Shop'])->id;
        app()->bind('currentCompanyId', fn () => $this->companyId);
    }

    protected function makeRider(string $name = 'Bilal'): PosRider
    {
        return PosRider::create(['company_id' => $this->companyId, 'name' => $name, 'is_active' => true]);
    }

    /** Final delivery bill — reporting-OFF finals invariant: fbr mode + NULL status. */
    protected function makeFinal(array $attrs = []): FbrPosTransaction
    {
        $bill = FbrPosTransaction::create(array_merge([
            'company_id' => $this->companyId,
            'invoice_number' => 'F-' . uniqid(),
            'status' => 'completed',
            'invoice_mode' => 'fbr',
            'fbr_status' => null,
            'subtotal' => 100,
            'tax_amount' => 16,
            'total_amount' => 116,
            'payment_method' => 'cash',
            'order_type' => 'delivery',
        ], $attrs));
        // created_at is guarded by Eloquent timestamps — force it when given.
        if (isset($attrs['created_at'])) {
            FbrPosTransaction::where('id', $bill->id)->update(['created_at' => $attrs['created_at']]);
            $bill->refresh();
        }
        // business_date may not be $fillable — write it straight to the row.
        if (array_key_exists('business_date', $attrs)) {
            FbrPosTransaction::where('id', $bill->id)->update(['business_date' => $attrs['business_date']]);
            $bill->refresh();
        }
        return $bill;
    }

    private function popup(): array
    {
        $data = (new FbrPosController())->apiProvisionalBills(new Request())->getData(true);
        $this->assertTrue($data['success']);
        return $data;
    }

    public function test_popup_flags_old_unassigned_but_not_fresh_or_assigned(): void
    {
        $rider = $this->makeRider();
        $bizToday = PosBusinessDay::currentFbr($this->companyId);
        $oldDay = \Carbon\Carbon::parse($bizToday)->subDays(3)->toDateString();

        // Fresh unassigned (aaj ka business day) — main list, no flag.
        $fresh = $this->makeFinal(['business_date' => $bizToday, 'rider_id' => null, 'delivery_status' => null]);
        // Purana unassigned (3 din pehle, window ke andar) — flagged.
        $stale = $this->makeFinal([
            'business_date' => $oldDay, 'rider_id' => null, 'delivery_status' => null,
            'created_at' => now()->subDays(3),
        ]);
        // Purana ASSIGNED — asal pending, kabhi stale nahi (behavior unchanged).
        $oldAssigned = $this->makeFinal([
            'business_date' => $oldDay, 'rider_id' => $rider->id, 'delivery_status' => 'assigned',
            'created_at' => now()->subDays(3),
        ]);
        // 7 din se purana unassigned — popup mein aata hi nahi.
        $ancient = $this->makeFinal([
            'business_date' => \Carbon\Carbon::parse($bizToday)->subDays(10)->toDateString(),
            'rider_id' => null, 'delivery_status' => null,
            'created_at' => now()->subDays(10),
        ]);

        $data = $this->popup();
        $finals = collect($data['final_deliveries']);

        $ids = $finals->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$fresh->id, $stale->id, $oldAssigned->id], $ids);
        $this->assertNotContains($ancient->id, $ids);

        $this->assertFalse($finals->firstWhere('id', $fresh->id)['is_stale_unassigned'], 'fresh unassigned must NOT be stale');
        $this->assertTrue($finals->firstWhere('id', $stale->id)['is_stale_unassigned'], 'old unassigned must be stale');
        $this->assertFalse($finals->firstWhere('id', $oldAssigned->id)['is_stale_unassigned'], 'assigned bill must never be stale');

        // Badge parity: business_today = same day the flag was computed against.
        $this->assertSame($bizToday, $data['business_today']);
    }

    /** business_date NULL → created_at ka business day (forMomentFbr) decide karta hai. */
    public function test_popup_flag_falls_back_to_created_at_business_day(): void
    {
        $staleNullBd = $this->makeFinal([
            'business_date' => null, 'rider_id' => null, 'delivery_status' => null,
            'created_at' => now()->subDays(3),
        ]);
        $freshNullBd = $this->makeFinal([
            'business_date' => null, 'rider_id' => null, 'delivery_status' => null,
        ]);

        $finals = collect($this->popup()['final_deliveries']);
        $this->assertTrue($finals->firstWhere('id', $staleNullBd->id)['is_stale_unassigned']);
        $this->assertFalse($finals->firstWhere('id', $freshNullBd->id)['is_stale_unassigned']);
    }
}
