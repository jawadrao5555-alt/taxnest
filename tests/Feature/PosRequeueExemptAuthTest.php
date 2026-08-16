<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 818 — RE-QUEUE EXEMPT BILL: authorization boundary locked.
 *
 * POST /pos/transaction/{id}/requeue-exempt flips a never-reported
 * exempt_internal bill into the live PRA fiscal pipeline. That is stricter
 * than ordinary admin actions: the gate is User::canRequeueExemptPra() —
 * owner (base role company_admin) or pos_admin ONLY.
 *
 * Invariants under lock:
 *   1. Owner and pos_admin CAN re-queue (bill → 'pending', success flash).
 *   2. pos_manager CANNOT — isPosAdmin() treats managers as admin-equivalent
 *      elsewhere, but this action must refuse them (error flash, bill
 *      untouched). This is the exact regression the Task 818 review caught.
 *   3. pos_cashier CANNOT (same refusal, bill untouched).
 *   4. Safety double-check: a bill that already has a fiscal number is
 *      refused even for the owner (never overwrite a submitted row).
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create,
 * HTTP through the real routes/middleware (same as PosMonthlyBillQuotaPathsTest).
 * Company is Agent-Sync (agent_enabled + agent_submits_pra) so a successful
 * re-queue returns the agent flash with ZERO network calls.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/PosRequeueExemptAuthTest.php --testdox
 */
class PosRequeueExemptAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->string('default_language')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->boolean('agent_enabled')->default(false);
            $table->boolean('agent_submits_pra')->nullable();
            $table->string('pra_connection_mode')->nullable();
            $table->string('pra_environment')->nullable();
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
            $table->boolean('is_active')->default(true);
            $table->string('language')->nullable();
            $table->boolean('pra_reporting_enabled')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_head_office')->default(false);
            $table->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number');
            $table->string('status');
            $table->string('invoice_mode')->nullable();
            $table->string('pra_status')->nullable();
            $table->string('pra_invoice_number')->nullable();
            $table->string('pra_response_code')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    private function makeCompany(): int
    {
        return (int) DB::table('companies')->insertGetId([
            'name' => 'Requeue Auth Co',
            'product_type' => 'pos',
            'status' => 'active',
            'is_internal_account' => false,
            'pra_reporting_enabled' => true,
            // Agent-Sync: re-queue only flips status; agent submits later.
            'agent_enabled' => true,
            'agent_submits_pra' => true,
            'pra_connection_mode' => 'cloud',
            'pra_environment' => 'production',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeUser(int $companyId, array $attrs = []): \App\Models\User
    {
        static $seq = 0;
        $id = DB::table('users')->insertGetId(array_merge([
            'name' => 'POS User',
            'email' => 'requeue' . $companyId . '-' . (++$seq) . '@taxnest.test',
            'password' => bcrypt('Secret@12345'),
            'company_id' => $companyId,
            'role' => 'company_admin',
            'pos_role' => null,
            'is_active' => true,
            'language' => 'en',
            'pra_reporting_enabled' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));

        return \App\Models\User::find($id);
    }

    /** A historical all-exempt bill — never reported, no fiscal number. */
    private function makeExemptBill(int $companyId, array $attrs = []): int
    {
        return (int) DB::table('pos_transactions')->insertGetId(array_merge([
            'company_id' => $companyId,
            'invoice_number' => 'POS-2026-00001',
            'status' => 'completed',
            'invoice_mode' => 'pra',
            'pra_status' => 'exempt_internal',
            'pra_invoice_number' => null,
            'total_amount' => 500,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    private function requeueAs(\App\Models\User $user, int $billId)
    {
        return $this->actingAs($user, 'pos')
            ->from("/pos/transaction/{$billId}")
            ->post("/pos/transaction/{$billId}/requeue-exempt");
    }

    // ── Allowed: owner + pos_admin ──────────────────────────────────────────

    public function test_owner_can_requeue_exempt_bill(): void
    {
        $companyId = $this->makeCompany();
        $billId = $this->makeExemptBill($companyId);

        $response = $this->requeueAs($this->makeUser($companyId), $billId);

        $response->assertRedirect("/pos/transaction/{$billId}")
            ->assertSessionHas('success', __('pos.requeue_exempt_success_agent', ['invoice' => 'POS-2026-00001']));
        $this->assertSame('pending', DB::table('pos_transactions')->where('id', $billId)->value('pra_status'));
    }

    public function test_pos_admin_staff_can_requeue_exempt_bill(): void
    {
        $companyId = $this->makeCompany();
        $billId = $this->makeExemptBill($companyId);
        $admin = $this->makeUser($companyId, ['role' => 'employee', 'pos_role' => 'pos_admin']);

        $response = $this->requeueAs($admin, $billId);

        $response->assertSessionHas('success', __('pos.requeue_exempt_success_agent', ['invoice' => 'POS-2026-00001']));
        $this->assertSame('pending', DB::table('pos_transactions')->where('id', $billId)->value('pra_status'));
    }

    // ── Refused: manager + cashier (bill untouched) ─────────────────────────

    public function test_pos_manager_cannot_requeue_exempt_bill(): void
    {
        $companyId = $this->makeCompany();
        $billId = $this->makeExemptBill($companyId);
        $manager = $this->makeUser($companyId, ['role' => 'employee', 'pos_role' => 'pos_manager']);

        $response = $this->requeueAs($manager, $billId);

        $response->assertRedirect("/pos/transaction/{$billId}")
            ->assertSessionHas('error', __('pos.only_owner_requeue_exempt'))
            ->assertSessionMissing('success');
        $this->assertSame('exempt_internal', DB::table('pos_transactions')->where('id', $billId)->value('pra_status'));
    }

    public function test_pos_cashier_cannot_requeue_exempt_bill(): void
    {
        $companyId = $this->makeCompany();
        $billId = $this->makeExemptBill($companyId);
        $cashier = $this->makeUser($companyId, ['role' => 'employee', 'pos_role' => 'pos_cashier']);

        $response = $this->requeueAs($cashier, $billId);

        $response->assertSessionHas('error', __('pos.only_owner_requeue_exempt'))
            ->assertSessionMissing('success');
        $this->assertSame('exempt_internal', DB::table('pos_transactions')->where('id', $billId)->value('pra_status'));
    }

    // ── Safety double-check survives even for the owner ─────────────────────

    public function test_already_submitted_bill_is_refused_even_for_owner(): void
    {
        $companyId = $this->makeCompany();
        $billId = $this->makeExemptBill($companyId, [
            'pra_invoice_number' => '1234567890123456789012345',
        ]);

        $response = $this->requeueAs($this->makeUser($companyId), $billId);

        $response->assertSessionHas('error', __('pos.requeue_exempt_not_eligible'));
        $row = DB::table('pos_transactions')->where('id', $billId)->first();
        $this->assertSame('exempt_internal', $row->pra_status);
        $this->assertSame('1234567890123456789012345', $row->pra_invoice_number);
    }
}
