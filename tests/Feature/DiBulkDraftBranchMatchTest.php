<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceImportBatch;
use App\Models\InvoiceItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiBulkDraftBranchMatchTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;
    private InvoiceImportBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Branch Review Co',
            'ntn' => '1234567',
            'company_status' => 'active',
            'is_internal_account' => true,
            'province' => 'Punjab',
            'standard_tax_rate' => 18,
        ]);
        $this->user = User::factory()->create([
            'company_id' => $this->company->id,
            'role' => 'company_admin',
            'is_active' => true,
        ]);
        $this->batch = InvoiceImportBatch::create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'original_filename' => 'branches.xlsx',
            'status' => 'completed',
        ]);
    }

    public function test_city_in_buyer_address_matches_branch(): void
    {
        $lahore = $this->branch('Lahore Outlet', 'Lahore');
        $invoice = $this->draft('12 Mall Road, Lahore');

        $this->match()
            ->assertOk()
            ->assertJsonPath('matched', 1)
            ->assertJsonPath('ambiguous', 0);

        $this->assertSame($lahore->id, $invoice->fresh()->branch_id);
    }

    public function test_two_branch_cities_in_address_are_ambiguous(): void
    {
        $this->branch('Lahore Outlet', 'Lahore');
        $this->branch('Multan Outlet', 'Multan');
        $invoice = $this->draft('Multan Road delivery office, Lahore');

        $this->match()
            ->assertOk()
            ->assertJsonPath('matched', 0)
            ->assertJsonPath('ambiguous', 1);

        $this->assertNull($invoice->fresh()->branch_id);
    }

    public function test_existing_branch_is_not_overwritten(): void
    {
        $lahore = $this->branch('Lahore Outlet', 'Lahore');
        $multan = $this->branch('Multan Outlet', 'Multan');
        $invoice = $this->draft('Multan Cantt', ['branch_id' => $lahore->id]);

        $this->match()
            ->assertOk()
            ->assertJsonPath('matched', 0)
            ->assertJsonPath('already_set', 1);

        $this->assertSame($lahore->id, $invoice->fresh()->branch_id);
        $this->assertNotSame($multan->id, $invoice->fresh()->branch_id);
    }

    public function test_submitted_invoice_is_never_touched(): void
    {
        $this->branch('Lahore Outlet', 'Lahore');
        $invoice = $this->draft('Lahore', [
            'status' => 'locked',
            'fbr_invoice_number' => 'FBR-LOCKED-1',
        ]);

        $this->match()
            ->assertOk()
            ->assertJsonPath('matched', 0)
            ->assertJsonPath('locked', 1);

        $this->assertNull($invoice->fresh()->branch_id);
    }

    public function test_matching_is_idempotent(): void
    {
        $lahore = $this->branch('Lahore Outlet', 'Lahore');
        $invoice = $this->draft('Lahore');

        $this->match()->assertJsonPath('matched', 1);
        $this->match()
            ->assertOk()
            ->assertJsonPath('matched', 0)
            ->assertJsonPath('already_set', 1);

        $this->assertSame($lahore->id, $invoice->fresh()->branch_id);
    }

    public function test_per_row_manual_branch_edit_persists_name_as_id(): void
    {
        $branch = $this->branch('Gulberg Branch', 'Lahore');
        $invoice = $this->draft('No matching city here');

        $this->actingAs($this->user)->postJson($this->url('/save'), [
            'invoices' => [[
                'id' => $invoice->id,
                'header' => ['branch' => 'Gulberg Branch'],
                'items' => [],
            ]],
        ])->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonCount(1, 'saved')
            ->assertJsonCount(0, 'skipped');

        $this->assertSame($branch->id, $invoice->fresh()->branch_id);
    }

    public function test_unknown_manual_branch_is_skipped_with_clear_message(): void
    {
        $original = $this->branch('Original Branch', 'Lahore');
        $invoice = $this->draft('Lahore', ['branch_id' => $original->id]);

        $this->actingAs($this->user)->postJson($this->url('/save'), [
            'invoices' => [[
                'id' => $invoice->id,
                'header' => ['branch' => 'Definitely Not A Branch'],
                'items' => [],
            ]],
        ])->assertOk()
            ->assertJsonCount(0, 'saved')
            ->assertJsonPath('skipped.0.id', $invoice->id)
            ->assertJsonPath('skipped.0.message', "Branch 'Definitely Not A Branch' did not match any branch. Available: Original Branch (Lahore).");

        $this->assertSame($original->id, $invoice->fresh()->branch_id);
    }

    public function test_review_page_renders_branch_column_and_match_button(): void
    {
        $this->branch('Lahore Outlet', 'Lahore');
        $this->draft('Lahore');

        $this->actingAs($this->user)
            ->get($this->url())
            ->assertOk()
            ->assertSee('Match branch by city')
            ->assertSee("{ key: 'branch', label: 'Branch'", false);
    }

    private function branch(string $name, string $city): Branch
    {
        return Branch::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => $name,
            'city' => $city,
            'is_active' => true,
            'is_head_office' => false,
        ]);
    }

    private function draft(string $address, array $overrides = []): Invoice
    {
        $invoice = Invoice::withoutGlobalScopes()->create(array_merge([
            'company_id' => $this->company->id,
            'import_batch_id' => $this->batch->id,
            'invoice_number' => 'TEST-' . uniqid(),
            'status' => 'draft',
            'buyer_name' => 'Test Buyer',
            'buyer_address' => $address,
            'buyer_registration_type' => 'Unregistered',
            'destination_province' => 'Punjab',
            'document_type' => 'Sale Invoice',
            'invoice_date' => now()->toDateString(),
            'total_amount' => 118,
            'total_value_excluding_st' => 100,
            'total_sales_tax' => 18,
            'is_fbr_processing' => false,
        ], $overrides));

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'hs_code' => '01012100',
            'description' => 'Test item',
            'quantity' => 1,
            'price' => 100,
            'tax' => 18,
            'tax_rate' => 18,
            'schedule_type' => 'standard',
            'sale_type' => 'Goods at standard rate (default)',
        ]);

        return $invoice;
    }

    private function match()
    {
        return $this->actingAs($this->user)->postJson($this->url('/match-branches'));
    }

    private function url(string $suffix = ''): string
    {
        return '/invoices/review/import/' . $this->batch->id . $suffix;
    }
}