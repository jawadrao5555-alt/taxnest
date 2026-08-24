<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The DI push API used to stamp every invoice with today's date with no way to
 * override it. Its default mode is "submit", so an ERP pushing a back-dated
 * sale had that wrong date filed to FBR irreversibly.
 *
 * These tests pin the validation half of the fix (a future date must never be
 * accepted) — the persistence half is exercised by the panel-side paths.
 */
class DiInvoiceApiInvoiceDateTest extends TestCase
{
    private string $apiKey;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('status')->default('approved');
            $table->string('company_status')->default('active');
            $table->string('di_api_key_hash')->nullable();
            $table->timestamp('di_api_key_last_used_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        $companyId = DB::table('companies')->insertGetId([
            'name' => 'DI API Date Test Company',
            'status' => 'approved',
            'company_status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->apiKey = "dik_{$companyId}_invoice_date";
        DB::table('companies')->where('id', $companyId)->update([
            'di_api_key_hash' => hash('sha256', $this->apiKey),
        ]);
    }

    public function test_api_rejects_a_future_invoice_date(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->apiKey}")
            ->postJson('/api/di/v1/invoices', $this->payload(now()->addDays(3)->toDateString()));

        $response->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonValidationErrors(['invoice_date']);

        $this->assertStringContainsString(
            'cannot be in the future',
            $response->json('errors')['invoice_date'][0]
        );
    }

    public function test_api_rejects_an_unparseable_invoice_date(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->apiKey}")
            ->postJson('/api/di/v1/invoices', $this->payload('kal'));

        $response->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonValidationErrors(['invoice_date']);
    }

    private function payload(string $invoiceDate): array
    {
        return [
            'client_reference' => 'invoice-date-' . uniqid(),
            'mode' => 'draft',
            'buyer_name' => 'API Buyer',
            'buyer_address' => 'Lahore',
            'document_type' => 'Sale Invoice',
            'destination_province' => 'Punjab',
            'invoice_date' => $invoiceDate,
            'items' => [[
                'hs_code' => '33049900',
                'description' => 'Shampoo 200ml',
                'quantity' => 1,
                'price' => 500,
                'tax' => 90,
                'schedule_type' => 'standard',
                'tax_rate' => 18,
            ]],
        ];
    }
}
