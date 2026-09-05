<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\HealthMedicine;
use App\Models\HealthMedicineBatch;
use App\Models\HealthPharmacySale;
use App\Models\HealthPrescription;
use App\Models\HealthPrescriptionItem;
use App\Models\User;
use App\Services\BranchStockService;
use App\Services\HealthModuleService;
use App\Services\HealthPharmacyCheckoutService;
use App\Services\HealthScopeService;
use App\Support\HealthPanel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * PHARMACY BRANCH BOUNDARY (Task 1549).
 *
 * A hospital with two pharmacies is two counters, two stock rooms and two sets
 * of patients. The list screens are branch-scoped, but a list is not a lock:
 * every one of these records is also reachable by putting an id in the URL.
 *
 * This file holds that boundary shut on the by-id paths — the bill, the
 * receipt, the refund, the prescription and the lot — and proves the refusal
 * changes nothing. It also pins the quieter of the two holes: a dispensing line
 * id is claimed by the request, so it must be bound to the slip being filled,
 * not merely to the company, or one patient's authorisation can be spent
 * against another patient's bill.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/HealthPharmacyBranchAccessTest.php --testdox
 */
class HealthPharmacyBranchAccessTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $owner;
    private User $pharmacistA;
    private Branch $branchA;
    private Branch $branchB;
    private HealthMedicine $medicine;

    /** Prescription numbers are unique per company — never reuse one. */
    private static int $rxCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // These services memo "which branches may this person touch" and "what
        // does the plan sell" in STATIC properties. Ids repeat across tests, so
        // a memo left by an earlier test answers for this company's user and
        // the boundary under test is measured against someone else's branches.
        BranchStockService::flushMemo();
        HealthModuleService::forget();
        HealthScopeService::forget();

        $this->company = Company::create([
            'name' => 'Two Branch Hospital',
            'ntn' => 'PH-BRANCH-1',
            'product_type' => HealthPanel::PRODUCT_TYPE,
            'status' => 'approved',
            'company_status' => 'active',
            'health_org_type' => 'hospital',
            'health_modules' => json_encode(['opd', 'pharmacy']),
        ]);

        $this->branchA = Branch::create([
            'company_id' => $this->company->id,
            'name' => 'Main Pharmacy',
            'is_head_office' => true,
            'is_active' => true,
        ]);

        $this->branchB = Branch::create([
            'company_id' => $this->company->id,
            'name' => 'City Pharmacy',
            'is_head_office' => false,
            'is_active' => true,
        ]);

        // Module access is the company switch AND what the package sells. With
        // no plan behind it the panel correctly refuses every pharmacy screen,
        // which would make these branch tests pass for the wrong reason.
        // The plan table has grown columns over the years; only the ones that
        // exist are written, so this fixture does not break every time the
        // pricing schema moves.
        $plan = array_filter([
            'name' => 'Hospital Test',
            'product_type' => 'health',
            'price' => 0,
            'invoice_limit' => 0,
            'health_modules' => json_encode(['opd', 'pharmacy']),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], fn ($value, $column) => Schema::hasColumn('pricing_plans', $column), ARRAY_FILTER_USE_BOTH);

        $planId = DB::table('pricing_plans')->insertGetId($plan);

        DB::table('subscriptions')->insert(array_filter([
            'company_id' => $this->company->id,
            'pricing_plan_id' => $planId,
            'active' => true,
            'billing_cycle' => 'annual',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ], fn ($value, $column) => Schema::hasColumn('subscriptions', $column), ARRAY_FILTER_USE_BOTH));

        $this->owner = $this->makeUser('phowner@example.test', 'health_owner');

        // A pharmacist posted to the main pharmacy only — the branch pivot is
        // the platform's own notion of "where do you work".
        $this->pharmacistA = $this->makeUser('pharmacista@example.test', 'health_pharmacist');
        DB::table('branch_user')->insert([
            'branch_id' => $this->branchA->id,
            'user_id' => $this->pharmacistA->id,
        ]);

        $this->medicine = HealthMedicine::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'Amoxil 500mg',
            'generic_name' => 'Amoxicillin',
            'form' => 'capsule',
            'unit_uom' => 'strip',
            'sale_price' => 100,
            'purchase_price' => 70,
            'tax_rate' => 0,
            'is_active' => true,
        ]);
    }

    private function makeUser(string $email, string $role): User
    {
        return User::create([
            'name' => $role,
            'email' => $email,
            'password' => Hash::make('Passw0rd!2026'),
            'company_id' => $this->company->id,
            'role' => 'user',
            'health_role' => $role,
            'is_active' => true,
        ]);
    }

    /** Stock sitting in a named branch, ready to be sold from it. */
    private function batchAt(Branch $branch, float $quantity = 50): HealthMedicineBatch
    {
        return HealthMedicineBatch::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'branch_id' => $branch->id,
            'medicine_id' => $this->medicine->id,
            'batch_no' => 'B-' . $branch->id,
            'expiry_date' => now()->addYear()->toDateString(),
            'quantity' => $quantity,
            'received_quantity' => $quantity,
            'cost_price' => 70,
            'sale_price' => 100,
            'status' => HealthMedicineBatch::STATUS_ACTIVE,
        ]);
    }

    /** A bill rung up at the other branch's counter. */
    private function saleAt(Branch $branch): HealthPharmacySale
    {
        $this->batchAt($branch);

        return HealthPharmacyCheckoutService::sell(
            $this->company->id,
            [
                'patient_name' => 'Walk In',
                'lines' => [['medicine_id' => $this->medicine->id, 'quantity' => 2]],
            ],
            $branch->id,
            $this->owner->id,
            $this->company
        );
    }

    /** A prescription filed against a branch, with one unfilled line. */
    private function prescriptionAt(Branch $branch): HealthPrescription
    {
        $prescription = HealthPrescription::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'branch_id' => $branch->id,
            'prescription_no' => 'RX-' . (++self::$rxCounter),
            'patient_name' => 'Branch Patient',
            'prescribed_on' => now()->toDateString(),
            'status' => HealthPrescription::STATUS_ISSUED,
            'issued_at' => now(),
            'dispense_status' => HealthPrescription::DISPENSE_PENDING,
        ]);

        HealthPrescriptionItem::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'health_prescription_id' => $prescription->id,
            'line_no' => 1,
            'medicine_id' => $this->medicine->id,
            'medicine_name' => $this->medicine->name,
            'quantity' => 10,
            'dispensed_quantity' => 0,
        ]);

        return $prescription;
    }

    /* ─────────────── Reads by id ─────────────── */

    public function test_a_pharmacist_cannot_open_another_branchs_bill_or_receipt(): void
    {
        $sale = $this->saleAt($this->branchB);

        $this->actingAs($this->pharmacistA, HealthPanel::GUARD);

        $this->get('/health/pharmacy/sales/' . $sale->id)->assertStatus(403);
        $this->get('/health/pharmacy/sales/' . $sale->id . '/receipt')->assertStatus(403);
    }

    public function test_a_pharmacist_can_still_open_their_own_branchs_bill(): void
    {
        $sale = $this->saleAt($this->branchA);

        $this->actingAs($this->pharmacistA, HealthPanel::GUARD);

        $this->get('/health/pharmacy/sales/' . $sale->id)->assertStatus(200);
    }

    public function test_a_pharmacist_cannot_open_another_branchs_prescription(): void
    {
        $prescription = $this->prescriptionAt($this->branchB);

        $this->actingAs($this->pharmacistA, HealthPanel::GUARD);

        $this->get('/health/pharmacy/prescriptions/' . $prescription->id)->assertStatus(403);
    }

    /* ─────────────── Writes by id ─────────────── */

    public function test_a_pharmacist_cannot_refund_another_branchs_bill(): void
    {
        $sale = $this->saleAt($this->branchB);
        $item = $sale->items()->first();

        $this->actingAs($this->pharmacistA, HealthPanel::GUARD);

        $this->post('/health/pharmacy/sales/' . $sale->id . '/return', [
            'lines' => [['sale_item_id' => $item->id, 'quantity' => 1]],
        ])->assertStatus(403);

        $this->assertEquals(0, (float) $sale->fresh()->refunded_amount, 'A refused refund must not move money.');
        $this->assertSame(0, DB::table('health_pharmacy_returns')->count());
    }

    public function test_a_pharmacist_cannot_cancel_or_reopen_another_branchs_prescription(): void
    {
        $prescription = $this->prescriptionAt($this->branchB);

        $this->actingAs($this->pharmacistA, HealthPanel::GUARD);

        $this->post('/health/pharmacy/prescriptions/' . $prescription->id . '/cancel')->assertStatus(403);
        $this->post('/health/pharmacy/prescriptions/' . $prescription->id . '/reopen')->assertStatus(403);

        $this->assertSame(
            HealthPrescription::DISPENSE_PENDING,
            $prescription->fresh()->dispense_status,
            'A refused write must leave the slip exactly as it was.'
        );
    }

    public function test_a_pharmacist_cannot_dispense_against_another_branchs_prescription(): void
    {
        $prescription = $this->prescriptionAt($this->branchB);
        $item = $prescription->items()->first();

        $this->actingAs($this->pharmacistA, HealthPanel::GUARD);

        $this->post('/health/pharmacy/prescriptions/' . $prescription->id . '/dispense', [
            'lines' => [['prescription_item_id' => $item->id, 'quantity' => 5]],
        ])->assertStatus(403);

        $this->assertEquals(0, (float) $item->fresh()->dispensed_quantity);
        $this->assertSame(0, HealthPharmacySale::withoutGlobalScopes()->count());
    }

    public function test_a_pharmacist_cannot_move_write_off_or_quarantine_another_branchs_stock(): void
    {
        $batch = $this->batchAt($this->branchB);

        $this->actingAs($this->pharmacistA, HealthPanel::GUARD);

        $this->post('/health/pharmacy/stock/' . $batch->id . '/adjust', [
            'quantity' => 5, 'reason' => 'count',
        ])->assertStatus(403);

        $this->post('/health/pharmacy/stock/' . $batch->id . '/write-off', [
            'quantity' => 5, 'reason' => 'damaged',
        ])->assertStatus(403);

        $this->post('/health/pharmacy/stock/' . $batch->id . '/quarantine', [
            'reason' => 'recall',
        ])->assertStatus(403);

        $this->post('/health/pharmacy/stock/' . $batch->id . '/transfer', [
            'to_branch_id' => $this->branchA->id, 'quantity' => 5,
        ])->assertStatus(403);

        $fresh = $batch->fresh();
        $this->assertEquals(50, (float) $fresh->quantity, 'A refused stock write must not change the lot.');
        $this->assertSame(HealthMedicineBatch::STATUS_ACTIVE, $fresh->status);
    }

    /* ─────────────── The prescribed line belongs to its own slip ─────────────── */

    public function test_a_prescribed_line_cannot_be_spent_against_a_different_prescription(): void
    {
        $mine = $this->prescriptionAt($this->branchA);
        $someoneElses = $this->prescriptionAt($this->branchA);
        $theirLine = $someoneElses->items()->first();

        $this->expectException(ValidationException::class);

        try {
            HealthPharmacyCheckoutService::sell(
                $this->company->id,
                [
                    'prescription_id' => $mine->id,
                    'lines' => [[
                        'medicine_id' => $this->medicine->id,
                        'quantity' => 5,
                        'prescription_item_id' => $theirLine->id,
                    ]],
                ],
                $this->branchA->id,
                $this->owner->id,
                $this->company
            );
        } finally {
            // The whole sale rolls back: no allowance spent, no stock gone.
            $this->assertEquals(0, (float) $theirLine->fresh()->dispensed_quantity);
            $this->assertSame(0, HealthPharmacySale::withoutGlobalScopes()->count());
        }
    }

    public function test_a_counter_sale_cannot_quietly_consume_a_prescribed_line(): void
    {
        $prescription = $this->prescriptionAt($this->branchA);
        $line = $prescription->items()->first();

        $this->expectException(ValidationException::class);

        try {
            HealthPharmacyCheckoutService::sell(
                $this->company->id,
                [
                    // No prescription named at all — a walk-in bill.
                    'lines' => [[
                        'medicine_id' => $this->medicine->id,
                        'quantity' => 5,
                        'prescription_item_id' => $line->id,
                    ]],
                ],
                $this->branchA->id,
                $this->owner->id,
                $this->company
            );
        } finally {
            $this->assertEquals(0, (float) $line->fresh()->dispensed_quantity);
        }
    }
}
