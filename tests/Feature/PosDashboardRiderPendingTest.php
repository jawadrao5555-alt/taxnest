<?php

namespace Tests\Feature;

use Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use App\Http\Controllers\PosController;
use App\Models\User;

/**
 * DASHBOARD "RIDER SETTLEMENT PENDING" ALERT — owner, 25 Aug 2026.
 *
 * Pas-manzar (ZFC): day-close ke waqt ek bill isliye reh gaya ke rider ka cash
 * abhi wasool nahi hua tha. Khata guard aisa bill ARCHIVE karta hai (delete
 * nahi) aur baad mein koi usay dobara nahi sameta, is liye shop ko pata hi
 * nahi chala. Owner: "jis tarah baqi tamam issues dashboard par aa jate hain,
 * is ka bhi alert ho — kis kis rider ki settlement pari hai — aur click par
 * seedha rider settlement khul jaye."
 *
 * Yeh test us alert ka source of truth taala karta hai: PosController::
 * pendingRiderKhata() ka predicate PosRider::openCashBills() ka hu-ba-hu
 * aaina rehna chahiye.
 *
 *   - sirf CASH, sirf unsettled (rider_settlement_id NULL), returned nahi
 *   - ARCHIVED bill phir bhi ginti mein (yehi asal wakia tha)
 *   - rider_partial_paid minus hota hai; poora chukaya hua rider list se bahar
 *   - doosri company ka cash kabhi nahi
 *   - sab se bari raqam sab se upar; sab se purane bill ke din saath
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create,
 * helper reflection se seedha bulaya gaya (baqi rider invariant tests jaisa).
 */
class PosDashboardRiderPendingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('Test Co');
            $table->boolean('is_internal_account')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->string('pos_billing_scope')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_riders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('rider_id')->nullable();
            $table->unsignedBigInteger('rider_settlement_id')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('delivery_status')->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('rider_partial_paid', 12, 2)->nullable();
            $table->string('invoice_mode')->nullable();
            $table->string('pra_status')->nullable();
            $table->string('pra_invoice_number')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamps();
        });

        DB::table('companies')->insert(['id' => 1, 'name' => 'ZFC', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('companies')->insert(['id' => 2, 'name' => 'Doosri', 'created_at' => now(), 'updated_at' => now()]);

        foreach ([
            [1, 1, 'Rizwan'], [2, 1, 'Asif'], [3, 1, 'Shoaib'],
            [4, 1, 'Naveed'], [5, 1, 'Touseef'], [9, 2, 'Ghair'],
        ] as [$id, $cid, $name]) {
            DB::table('pos_riders')->insert([
                'id' => $id, 'company_id' => $cid, 'name' => $name,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** Bill banane ka chhota helper — sirf wohi khaane jo predicate parhta hai. */
    private function bill(array $attrs): void
    {
        DB::table('pos_transactions')->insert(array_merge([
            'company_id'          => 1,
            'rider_id'            => null,
            'rider_settlement_id' => null,
            'payment_method'      => 'cash',
            'delivery_status'     => 'delivered',
            'total_amount'        => 100,
            'rider_partial_paid'  => null,
            'is_archived'         => false,
            'created_at'          => now(),
            'updated_at'          => now(),
        ], $attrs));
    }

    private function pending(): \Illuminate\Support\Collection
    {
        $c = new PosController();
        $m = new \ReflectionMethod($c, 'pendingRiderKhata');
        $m->setAccessible(true);

        return collect($m->invoke($c, 1, \App\Models\Company::find(1)));
    }

    public function test_open_cash_is_grouped_per_rider_biggest_first(): void
    {
        $this->bill(['rider_id' => 1, 'total_amount' => 550]);
        $this->bill(['rider_id' => 1, 'total_amount' => 450]);
        $this->bill(['rider_id' => 2, 'total_amount' => 300]);

        $out = $this->pending();

        $this->assertCount(2, $out, 'Do rider khaate mein hone chahiye');
        $this->assertSame(1, $out[0]['id'], 'Sab se bari raqam sab se upar');
        $this->assertSame('Rizwan', $out[0]['name']);
        $this->assertSame(1000.0, $out[0]['owed']);
        $this->assertSame(2, $out[0]['bills']);
        $this->assertSame(2, $out[1]['id']);
        $this->assertSame(300.0, $out[1]['owed']);
    }

    public function test_archived_bill_still_counts(): void
    {
        // ZFC ka asal wakia: day-close ne bill archive kiya, cash rider ke paas
        // hi raha. Agar yeh chhoot jaye to alert ka koi faida nahi.
        $this->bill(['rider_id' => 1, 'total_amount' => 550, 'is_archived' => true]);

        $out = $this->pending();

        $this->assertCount(1, $out);
        $this->assertSame(550.0, $out[0]['owed']);
    }

    public function test_archived_local_bill_stays_visible_to_pra_scoped_manager_until_settled(): void
    {
        $user = User::create([
            'company_id' => 1,
            'name' => 'Manager',
            'role' => 'user',
            'pos_role' => 'pos_manager',
            'pos_billing_scope' => 'pra',
        ]);
        Auth::guard('pos')->setUser($user);

        // Day close archives this local rider-cash proof. Fiscal stream scope
        // must not hide a real company liability from the reminder.
        $this->bill([
            'rider_id' => 1,
            'total_amount' => 550,
            'invoice_mode' => 'local',
            'pra_status' => 'local',
            'is_archived' => true,
        ]);

        $this->assertSame(550.0, $this->pending()->sum('owed'));

        DB::table('pos_transactions')->update(['rider_settlement_id' => 91]);
        $this->assertTrue($this->pending()->isEmpty());
    }

    public function test_settled_returned_and_card_bills_are_excluded(): void
    {
        $this->bill(['rider_id' => 1, 'total_amount' => 500, 'rider_settlement_id' => 19]);
        $this->bill(['rider_id' => 2, 'total_amount' => 500, 'delivery_status' => 'returned']);
        $this->bill(['rider_id' => 3, 'total_amount' => 500, 'payment_method' => 'debit_card']);
        $this->bill(['rider_id' => 9, 'company_id' => 2, 'total_amount' => 500]);
        $this->bill(['rider_id' => null, 'total_amount' => 500]);

        $this->assertTrue($this->pending()->isEmpty(), 'In mein se koi bhi khata nahi banta');
    }

    public function test_partial_payment_is_deducted_and_fully_paid_rider_drops_off(): void
    {
        $this->bill(['rider_id' => 1, 'total_amount' => 500, 'rider_partial_paid' => 200]);
        $this->bill(['rider_id' => 2, 'total_amount' => 400, 'rider_partial_paid' => 400]);

        $out = $this->pending();

        $this->assertCount(1, $out, 'Poora chukaya hua rider list mein nahi aata');
        $this->assertSame(1, $out[0]['id']);
        $this->assertSame(300.0, $out[0]['owed']);
    }

    public function test_oldest_bill_age_is_reported_in_days(): void
    {
        $this->bill(['rider_id' => 1, 'total_amount' => 100, 'created_at' => Carbon::now()->subDays(3)]);
        $this->bill(['rider_id' => 1, 'total_amount' => 100, 'created_at' => Carbon::now()]);

        $out = $this->pending();

        $this->assertSame(3, $out[0]['days'], 'Sab se purane bill ke din');
    }

    public function test_alert_renders_rider_name_amount_and_deep_link(): void
    {
        $html = view('pos.partials.rider-settlement-pending', [
            'riderPending' => collect([
                ['id' => 7, 'name' => 'Rizwan', 'bills' => 2, 'owed' => 550.0, 'days' => 0],
            ]),
        ])->render();

        $this->assertStringContainsString('Rizwan', $html);
        $this->assertStringContainsString('Rs. 550', $html);
        // Click seedha usi rider ke card par utre — deliveries page ka anchor.
        $this->assertStringContainsString('#rider-7', $html);
    }

    public function test_alert_is_silent_when_nothing_is_pending(): void
    {
        $html = view('pos.partials.rider-settlement-pending', ['riderPending' => collect()])->render();

        $this->assertSame('', trim($html), 'Khali khata par dashboard par kuch nahi aana chahiye');
    }

    /**
     * Reminder company ki liability hai — rider ke naam ke saath bahar para
     * cash. Settle sirf admin/manager kar sakta hai; cashier ko poori dukan ka
     * khata dikhana na zaroori hai na munasib.
     */
    public function test_cashier_is_never_shown_the_company_rider_liability(): void
    {
        $this->bill(['rider_id' => 1, 'total_amount' => 550]);
        $this->assertSame(550.0, $this->pending()->sum('owed'), 'Bina cashier ke khata maujood hai');

        $cashier = User::create([
            'company_id' => 1,
            'name' => 'Cashier',
            'role' => 'user',
            'pos_role' => 'pos_cashier',
        ]);
        Auth::guard('pos')->setUser($cashier);

        $this->assertTrue(
            $this->pending()->isEmpty(),
            'Cashier ko doosron ka rider cash nahi dikhna chahiye'
        );
    }

    public function test_manager_still_sees_the_liability(): void
    {
        $this->bill(['rider_id' => 1, 'total_amount' => 550]);

        $manager = User::create([
            'company_id' => 1,
            'name' => 'Manager',
            'role' => 'user',
            'pos_role' => 'pos_manager',
        ]);
        Auth::guard('pos')->setUser($manager);

        $this->assertSame(550.0, $this->pending()->sum('owed'));
    }
}
