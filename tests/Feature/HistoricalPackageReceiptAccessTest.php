<?php

namespace Tests\Feature;

use App\Http\Controllers\SaasAdmin\HistoricalPackageReceiptController;
use App\Models\AdminUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class HistoricalPackageReceiptAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.receipt_fixture' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        DB::setDefaultConnection('receipt_fixture');
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id(); $table->string('role');
        });
        Schema::create('companies', function (Blueprint $table) {
            $table->id(); $table->softDeletes();
        });
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('company_id'); $table->boolean('active');
        });
        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('company_id'); $table->unsignedBigInteger('subscription_id');
            $table->unsignedBigInteger('payment_proof_id')->nullable(); $table->json('document_snapshot')->nullable();
        });
        Schema::create('payment_proofs', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('company_id'); $table->unsignedBigInteger('subscription_id');
            $table->string('status'); $table->string('request_type')->nullable();
        });
        DB::table('companies')->insert([['id' => 1], ['id' => 2]]);
        DB::table('subscriptions')->insert([
            ['id' => 11, 'company_id' => 1, 'active' => false],
            ['id' => 12, 'company_id' => 2, 'active' => true],
        ]);
        DB::table('admin_users')->insert(['id' => 1, 'role' => 'admin']);
        $this->actingAs(AdminUser::findOrFail(1), 'admin');
        $this->withoutMiddleware(\App\Http\Middleware\AdminAuth::class);
    }

    public function test_history_includes_inactive_terms_and_excludes_other_companies_proofs_and_charges(): void
    {
        DB::table('subscription_invoices')->insert(['id' => 100, 'company_id' => 2, 'subscription_id' => 11]);
        DB::table('payment_proofs')->insert(['id' => 101, 'company_id' => 2, 'subscription_id' => 11, 'status' => 'verified', 'request_type' => 'subscription']);
        $data = app(HistoricalPackageReceiptController::class)->index(1)->getData();
        $this->assertSame([11], $data['terms']->getCollection()->modelKeys());
        $this->assertTrue($data['charges']->isEmpty());
        $this->assertTrue($data['proofs']->isEmpty());
    }

    public function test_payment_method_must_be_selected_even_for_a_previously_assigned_term(): void
    {
        $this->post(route('saas.admin.companies.package-receipts.record', [1, 11]), [
            'verified_received_amount' => '100.00', 'payment_date' => today()->toDateString(),
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ])->assertSessionHasErrors('payment_method');
        $this->assertDatabaseCount('subscription_invoices', 0);
    }

    public function test_company_and_term_must_match_before_recovery_or_manual_collection(): void
    {
        $this->post(route('saas.admin.companies.package-receipts.record', [2, 11]))->assertNotFound();
        DB::table('payment_proofs')->insert(['id' => 5, 'company_id' => 2, 'subscription_id' => 12, 'status' => 'verified', 'request_type' => 'subscription']);
        $this->post(route('saas.admin.companies.package-receipts.recover', [1, 11, 5]))->assertNotFound();
        $this->assertDatabaseCount('subscription_invoices', 0);
    }

    public function test_support_user_cannot_issue_receipts(): void
    {
        DB::table('admin_users')->where('id', 1)->update(['role' => 'support']);
        $this->post(route('saas.admin.companies.package-receipts.record', [1, 11]))->assertForbidden();
    }
}
