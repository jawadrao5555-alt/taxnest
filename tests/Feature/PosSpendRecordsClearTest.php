<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use App\Models\User;
use App\Services\PosFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Clearing the customer-spend record lines left behind by deleted local bills
 * (owner, 25 Aug 2026: "phir daily delete ka faida kya, record to reh jata hai").
 *
 * The spend switch only stops NEW lines; this action removes the ones already
 * sitting in customer history.
 *
 * Locked here:
 *  1. The Customize card reports the real count.
 *  2. The clear removes THIS company's lines only, and reports how many went.
 *  3. Real bills are never touched — these rows are not transactions.
 *  4. ADMIN/OWNER only: a cashier posting the URL directly is refused (403) and
 *     nothing is deleted.
 */
class PosSpendRecordsClearTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        User::flushScopeColumnCache();
        PosFeatureService::flushGateCaches();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->string('default_language')->nullable();
            $table->boolean('pos_customer_spend_persist')->default(true);
            $table->boolean('is_internal_account')->default(false);
            $table->boolean('restaurant_mode')->default(false);
            $table->text('feature_flags')->nullable();
            $table->integer('invoice_limit_override')->nullable();
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('password')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->text('pos_custom_access')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('language')->nullable();
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number');
            $table->string('status')->nullable();
            $table->string('invoice_mode')->nullable();
            $table->string('pra_status')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('pos_customer_spend_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('invoice_number')->nullable();
            $table->string('bill_kind')->nullable();
            $table->string('payment_method')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->timestamp('sold_at')->nullable();
            $table->unsignedBigInteger('dayclose_report_id')->nullable();
            $table->timestamps();
        });
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function makeCompany(array $attrs = []): int
    {
        return (int) DB::table('companies')->insertGetId(array_merge([
            'name' => 'ZFC Pizza Point',
            'product_type' => 'pos',
            'status' => 'active',
            'is_internal_account' => true,
            'invoice_limit_override' => -1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    private function makeUser(int $companyId, ?string $posRole = null): User
    {
        static $seq = 0;
        $id = DB::table('users')->insertGetId([
            'name' => $posRole ?? 'Owner',
            'email' => ($posRole ?? 'owner') . $companyId . '-' . (++$seq) . '@spendrec.test',
            'password' => bcrypt('Secret@12345'),
            'company_id' => $companyId,
            'role' => $posRole === null ? 'company_admin' : 'user',
            'pos_role' => $posRole,
            'is_active' => true,
            'language' => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::find($id);
    }

    private function makeRecord(int $companyId, string $invoiceNumber, float $amount = 500): int
    {
        return (int) DB::table('pos_customer_spend_snapshots')->insertGetId([
            'company_id' => $companyId,
            'customer_id' => 42,
            'customer_phone' => '03001234567',
            'customer_name' => 'Bilal',
            'invoice_number' => $invoiceNumber,
            'bill_kind' => 'final_local',
            'payment_method' => 'cash',
            'total_amount' => $amount,
            'sold_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function clear(User $user)
    {
        return $this->actingAs($user, 'pos')
            ->postJson('/pos/settings/local-billing/clear-spend-records');
    }

    private function recordCount(int $companyId): int
    {
        $ref = new \ReflectionMethod(PosController::class, 'customerSpendRecordCount');
        $ref->setAccessible(true);

        return $ref->invokeArgs(new PosController(), [$companyId]);
    }

    // ── 1. the count the card shows ──────────────────────────────────────────

    public function test_card_counts_the_leftover_record_lines(): void
    {
        $cid = $this->makeCompany();
        $other = $this->makeCompany(['name' => 'Someone Else']);
        $this->makeRecord($cid, 'L-001');
        $this->makeRecord($cid, 'L-002');
        $this->makeRecord($other, 'L-001');

        $this->assertSame(2, $this->recordCount($cid));
        $this->assertSame(1, $this->recordCount($other));
    }

    public function test_count_is_zero_when_nothing_was_ever_kept(): void
    {
        $this->assertSame(0, $this->recordCount($this->makeCompany()));
    }

    // ── 2/3. the clear ───────────────────────────────────────────────────────

    public function test_clear_removes_only_this_shops_record_lines(): void
    {
        $cid = $this->makeCompany();
        $other = $this->makeCompany(['name' => 'Someone Else']);
        $this->makeRecord($cid, 'L-001');
        $this->makeRecord($cid, 'L-002');
        $kept = $this->makeRecord($other, 'L-009');

        $res = $this->clear($this->makeUser($cid));

        $res->assertStatus(200)->assertJson(['success' => true, 'deleted' => 2]);
        $this->assertSame(trans('pos.spend_records_cleared', ['count' => 2], 'en'), $res->json('message'));
        $this->assertSame(0, $this->recordCount($cid));
        $this->assertDatabaseHas('pos_customer_spend_snapshots', ['id' => $kept]);
    }

    public function test_clear_never_touches_real_bills(): void
    {
        $cid = $this->makeCompany();
        $this->makeRecord($cid, 'L-001');
        $billId = DB::table('pos_transactions')->insertGetId([
            'company_id' => $cid,
            'invoice_number' => 'L-002',
            'status' => 'completed',
            'invoice_mode' => 'local',
            'pra_status' => 'local',
            'total_amount' => 500,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->clear($this->makeUser($cid))->assertStatus(200)->assertJson(['deleted' => 1]);

        $this->assertDatabaseHas('pos_transactions', ['id' => $billId]);
    }

    public function test_clear_with_nothing_to_remove_is_a_harmless_noop(): void
    {
        $cid = $this->makeCompany();

        $this->clear($this->makeUser($cid))
            ->assertStatus(200)
            ->assertJson(['success' => true, 'deleted' => 0]);
    }

    // ── 4. admin only ────────────────────────────────────────────────────────

    public function test_cashier_cannot_clear_the_records(): void
    {
        $cid = $this->makeCompany();
        $this->makeRecord($cid, 'L-001');

        $this->clear($this->makeUser($cid, 'pos_cashier'))
            ->assertStatus(403)
            ->assertJson(['success' => false]);

        $this->assertSame(1, $this->recordCount($cid), 'a refused clear must delete nothing');
    }

    /**
     * pos_manager is admin-equivalent inside the POS panel (owner rule Jul 2026),
     * exactly as on the sibling clear-archived action — the two housekeeping
     * buttons sit in the same card and must not disagree about who may press them.
     */
    public function test_manager_may_clear_like_on_the_sibling_action(): void
    {
        $cid = $this->makeCompany();
        $this->makeRecord($cid, 'L-001');

        $this->clear($this->makeUser($cid, 'pos_manager'))
            ->assertStatus(200)
            ->assertJson(['success' => true, 'deleted' => 1]);

        $this->assertSame(0, $this->recordCount($cid));
    }
}
