<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DiInvoiceApiFreeLineValidationTest extends TestCase
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
            'name' => 'DI API Test Company',
            'status' => 'approved',
            'company_status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->apiKey = "dik_{$companyId}_free_line_validation";
        DB::table('companies')->where('id', $companyId)->update([
            'di_api_key_hash' => hash('sha256', $this->apiKey),
        ]);
    }

    public function test_api_rejects_free_lines_for_every_sale_type(): void
    {
        foreach (['standard', 'exempt', 'zero_rated'] as $scheduleType) {
            $response = $this->withHeader('Authorization', "Bearer {$this->apiKey}")
                ->postJson('/api/di/v1/invoices', $this->payload($scheduleType));

            $response->assertStatus(422)
                ->assertJsonPath('error', 'validation_failed')
                ->assertJsonValidationErrors(['items.0.price']);
            $this->assertStringContainsString(
                'FBR rejects free/bonus lines',
                $response->json('errors')['items.0.price'][0]
            );
        }
    }

    private function payload(string $scheduleType): array
    {
        return [
            'client_reference' => 'free-line-' . $scheduleType . '-' . uniqid(),
            'mode' => 'draft',
            'buyer_name' => 'API Buyer',
            'buyer_address' => 'Lahore',
            'document_type' => 'Sale Invoice',
            'destination_province' => 'Punjab',
            'items' => [[
                'hs_code' => '33049900',
                'description' => 'Free bonus item',
                'quantity' => 1,
                'price' => 0,
                'tax' => 0,
                'schedule_type' => $scheduleType,
                'tax_rate' => 0,
            ]],
        ];
    }
}