<?php
namespace Tests\Feature;

use App\Models\Company;
use App\Models\FbrPosTransaction;
use App\Services\FbrService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StandaloneCollectionFiscalGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables(); Http::fake();
        Schema::create('companies', function (Blueprint $t) {
            $t->id(); $t->string('product_type'); $t->string('pos_type'); $t->string('business_category')->nullable();
            $t->boolean('fbr_reporting_enabled')->default(true); $t->string('fbr_pos_id')->nullable();
            $t->string('fbr_pos_token')->nullable(); $t->boolean('agent_enabled')->default(true);
            $t->string('pra_connection_mode')->default('fiscal_device'); $t->softDeletes();
        });
        Schema::create('fbr_pos_transactions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('invoice_mode')->default('fbr');
            $t->string('fbr_status')->default('failed'); $t->string('fbr_invoice_number')->nullable();
            $t->unsignedInteger('fbr_auto_retry_count')->default(2); $t->string('fbr_submission_hash')->nullable(); $t->timestamps();
        });
        Schema::create('fbr_pos_transaction_items', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('transaction_id'); });
    }

    public function test_standalone_hospital_aliases_block_every_fiscal_dispatch_even_with_stale_settings(): void
    {
        foreach (['clinic', 'hospital'] as $category) {
            $id = DB::table('companies')->insertGetId(['product_type' => 'fbrpos', 'pos_type' => 'retail',
                'business_category' => $category, 'fbr_reporting_enabled' => true, 'fbr_pos_id' => '123', 'fbr_pos_token' => 'synthetic']);
            $company = Company::findOrFail($id);
            $this->assertTrue($company->isLocalCollectionPilot());
            $this->assertFalse($company->fbr_reporting_enabled);
            $this->assertFalse($company->agentServesFbr());
            $this->assertFalse($company->agentHandlesPra());
            $this->assertFalse($company->fbrPosIntegrationConfigured());
            $this->assertFalse($company->fbrIntegrationUndecided());
            $toggle = app(\App\Services\FbrIntegrationDecisionService::class)->setReporting($company, true, null);
            $this->assertFalse($toggle['ok']);
            $txId = DB::table('fbr_pos_transactions')->insertGetId(['company_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
            $before = (array) DB::table('fbr_pos_transactions')->find($txId);
            $this->assertSame('blocked', app(FbrService::class)->submitFbrPosTransaction(FbrPosTransaction::find($txId))['status']);
            $this->assertSame('blocked', app(FbrService::class)->submitPosDiPayload([], 'synthetic', 'sandbox', $id)['state']);
            (new \App\Jobs\RetryFbrPosSubmissionJob($txId))->handle();
            (new \App\Jobs\SyncFbrPosOfflineInvoicesJob())->handle();
            $this->assertSame($before, (array) DB::table('fbr_pos_transactions')->find($txId));
        }
        Http::assertNothingSent();
    }

    public function test_existing_pra_service_categories_and_fbr_retail_are_not_reclassified(): void
    {
        foreach (['clinic', 'education'] as $category) {
            $company = new Company(['product_type' => 'pos', 'pos_type' => $category, 'business_category' => $category,
                'fbr_reporting_enabled' => true, 'pra_connection_mode' => 'fiscal_device']);
            $this->assertFalse($company->isLocalCollectionPilot());
            $this->assertTrue($company->agentHandlesPra());
        }
        $retail = new Company(['product_type' => 'fbrpos', 'pos_type' => 'retail', 'fbr_reporting_enabled' => true]);
        $this->assertFalse($retail->isLocalCollectionPilot()); $this->assertTrue($retail->fbr_reporting_enabled);
    }
}
