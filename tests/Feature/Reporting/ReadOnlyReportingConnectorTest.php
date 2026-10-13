<?php

namespace Tests\Feature\Reporting;

use App\Models\AdminUser;
use App\Services\Reporting\CompanyReadReports;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReadOnlyReportingConnectorTest extends TestCase
{
    private AdminUser $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        config(['app.url' => 'http://localhost', 'app.timezone' => 'Asia/Karachi']);
        Schema::create('admin_users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email');
            $t->string('password');
            $t->string('role');
            $t->timestamps();
        });
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type');
            $t->softDeletes();
        });
        foreach (['pos_transactions', 'fbr_pos_transactions', 'invoices'] as $table) {
            Schema::create($table, function (Blueprint $t) use ($table) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('invoice_number');
                $t->string('status');
                $t->string('transaction_type')->nullable();
                $t->string('document_type')->nullable();
                $t->string('payment_method')->nullable();
                $t->string($table === 'pos_transactions' ? 'pra_status' : 'fbr_status')->nullable();
                $t->string($table === 'pos_transactions' ? 'pra_invoice_number' : 'fbr_invoice_number')->nullable();
                $t->decimal('total_amount', 14, 2);
                $t->decimal($table === 'invoices' ? 'total_sales_tax' : 'tax_amount', 14, 2);
                $t->decimal('cash_received', 14, 2)->default(0);
                $t->decimal('change_due', 14, 2)->default(0);
                $t->string('customer_phone')->nullable();
                $t->string('fbr_response')->nullable();
                $t->date('business_date')->nullable();
                $t->dateTime('created_at');
            });
        }
        foreach (['pos_transaction_items', 'fbr_pos_transaction_items', 'invoice_items'] as $table) {
            Schema::create($table, function (Blueprint $t) use ($table) {
                $di = $table === 'invoice_items';
                $t->id();
                $t->unsignedBigInteger($di ? 'invoice_id' : 'transaction_id');
                $t->string($di ? 'description' : 'item_name');
                $t->decimal('quantity', 12, 3);
                $t->decimal('tax_rate', 8, 2)->nullable();
                $t->decimal($di ? 'tax' : 'tax_amount', 14, 2);
                $t->boolean('is_tax_exempt')->nullable();
                $t->string('schedule_type')->nullable();
                $t->string('uom')->nullable();
                $t->decimal('unit_price', 14, 2)->nullable();
            });
        }
        (require database_path('migrations/2026_10_13_190001_create_readonly_reporting_connector.php'))->up();
        $this->owner = AdminUser::create(['name' => 'Synthetic owner', 'email' => 'owner@reports.invalid', 'password' => 'only-test-password', 'role' => 'super_admin']);
        DB::table('companies')->insert([
            ['id' => 1, 'name' => 'Pizza Master Test', 'product_type' => 'pos'], ['id' => 2, 'name' => 'Pizza Master Twin', 'product_type' => 'pos'],
            ['id' => 3, 'name' => 'FBR Test', 'product_type' => 'fbrpos'], ['id' => 4, 'name' => 'DI Test', 'product_type' => 'di'],
        ]);
        $this->bill('pos_transactions', 1, 1, 100, 16, 'submitted', 'PRA-1');
        $this->bill('pos_transactions', 2, 1, 200, 32, null, null);
        $this->bill('pos_transactions', 3, 1, 50, 8, 'submitted', 'PRA-3', 'return');
        $this->bill('pos_transactions', 4, 1, 500, 80, 'submitted', '');
        $this->bill('pos_transactions', 5, 1, 1000, 160, 'submitted', 'PRA-CANCEL', 'sale', 'cancelled');
        $this->bill('pos_transactions', 6, 2, 99999, 100, 'submitted', 'OTHER');
        $this->bill('pos_transactions', 7, 1, 800, 128, 'submitted', 'OLD', 'sale', 'completed', '2026-10-12 23:59:59');
        $this->bill('fbr_pos_transactions', 1, 3, 108, 8, 'submitted', 'FBR-1');
        $this->bill('invoices', 1, 4, 118, 18, 'production', 'DI-1', 'sale', 'locked');
        $this->bill('invoices', 2, 4, 59, 9, 'production', 'DI-2', 'return', 'locked');
        $this->bill('invoices', 3, 4, 999, 99, 'sandbox', 'SANDBOX', 'sale', 'locked');
        DB::table('pos_transaction_items')->insert([
            ['id' => 1, 'transaction_id' => 1, 'item_name' => 'Taxed item', 'quantity' => 2, 'tax_rate' => 16, 'tax_amount' => 16, 'is_tax_exempt' => 0, 'uom' => 'NOS'],
            ['id' => 2, 'transaction_id' => 2, 'item_name' => 'Exempt item', 'quantity' => 1, 'tax_rate' => 0, 'tax_amount' => 0, 'is_tax_exempt' => 1, 'uom' => 'KG'],
            ['id' => 3, 'transaction_id' => 3, 'item_name' => 'Returned taxed', 'quantity' => 1, 'tax_rate' => 16, 'tax_amount' => 8, 'is_tax_exempt' => 0, 'uom' => 'NOS'],
            ['id' => 4, 'transaction_id' => 4, 'item_name' => 'Zero rate', 'quantity' => 1, 'tax_rate' => 0, 'tax_amount' => 0, 'is_tax_exempt' => 0, 'uom' => 'NOS'],
            ['id' => 5, 'transaction_id' => 6, 'item_name' => 'Other tenant item', 'quantity' => 1, 'tax_rate' => 16, 'tax_amount' => 100, 'is_tax_exempt' => 0, 'uom' => 'NOS'],
        ]);
    }

    private function bill($table, $id, $company, $amount, $tax, $state, $fiscal, $kind = 'sale', $status = 'completed', $date = '2026-10-13 12:00:00'): void
    {
        DB::table($table)->insert(['id' => $id, 'company_id' => $company, 'invoice_number' => 'LOCAL-'.$id, 'status' => $status,
            'transaction_type' => $kind, 'document_type' => $kind === 'return' ? 'Credit Note' : 'Sale Invoice', 'payment_method' => 'cash',
            $table === 'pos_transactions' ? 'pra_status' : 'fbr_status' => $state, $table === 'pos_transactions' ? 'pra_invoice_number' : 'fbr_invoice_number' => $fiscal,
            'total_amount' => $amount, $table === 'invoices' ? 'total_sales_tax' : 'tax_amount' => $tax, 'business_date' => '2026-10-13',
            'customer_phone' => 'SECRET-PHONE', 'fbr_response' => 'SECRET-RESPONSE', 'created_at' => $date]);
    }

    private function period($company = 'Pizza Master Test'): array
    {
        return ['company_name' => $company, 'date_from' => '2026-10-13'];
    }

    private function authorize(array $scope = [1]): array
    {
        $registered = $this->postJson('/api/reporting/oauth/register', ['client_name' => 'Synthetic reporting client',
            'redirect_uris' => ['https://chatgpt.com/connector_platform_oauth_redirect'], 'token_endpoint_auth_method' => 'none'])->assertCreated()->json();
        $verifier = str_repeat('a', 64);
        $params = ['client_id' => $registered['client_id'], 'redirect_uri' => $registered['redirect_uris'][0], 'response_type' => 'code',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256',
            'resource' => 'http://localhost/api/reporting/mcp', 'scope' => 'taxnest.reports.read', 'state' => 'synthetic-state'];
        $this->actingAs($this->owner, 'admin')->get('/admin/reporting/oauth/authorize?'.http_build_query($params))->assertOk()->assertSee('Allow read-only access');
        $response = $this->post('/admin/reporting/oauth/authorize', $params + ['decision' => 'approve', 'company_scope' => 'selected', 'company_ids' => $scope])->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $callback);
        $this->assertSame('http://localhost', $callback['iss']);
        $this->assertSame('synthetic-state', $callback['state']);
        $input = ['grant_type' => 'authorization_code', 'client_id' => $params['client_id'], 'redirect_uri' => $params['redirect_uri'],
            'resource' => $params['resource'], 'code' => $callback['code'], 'code_verifier' => $verifier];

        return [$params, $input];
    }

    private function connection(array $scope = [1]): array
    {
        [$params,$input] = $this->authorize($scope);

        return [$params, $this->postJson('/api/reporting/oauth/token', $input)->assertOk()->json()];
    }

    private function toolCall($token, $tool, $args)
    {
        return $this->withToken($token)->postJson('/api/reporting/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $args]]);
    }

    public function test_billing_splits_return_reporting_tax_and_excludes_other_tenant_dates_and_cancelled(): void
    {
        [$p,$tokens] = $this->connection();
        $before = DB::table('pos_transactions')->orderBy('id')->get()->toJson();
        $r = $this->toolCall($tokens['access_token'], 'get_company_billing_summary', $this->period())->assertOk()->assertJsonPath('result.isError', false)->json('result.structuredContent');
        $this->assertSame(4, $r['totals']['completed_documents']);
        $this->assertSame(3, $r['totals']['sales_documents']);
        $this->assertSame(1, $r['totals']['return_documents']);
        $this->assertEquals(750, $r['totals']['net_billing']);
        $this->assertEquals(120, $r['totals']['net_tax']);
        $this->assertSame(2, $r['totals']['reported_bills']);
        $this->assertSame(2, $r['totals']['unreported_bills']);
        $this->assertEquals(50, $r['totals']['reported_net_amount']);
        $this->assertEquals(700, $r['totals']['unreported_net_amount']);
        $groups = collect($r['tax_items']['groups'])->keyBy('classification');
        $this->assertSame(1, $groups['exempt']['item_rows']);
        $this->assertSame(1, $groups['zero_rate']['item_rows']);
        $this->assertEquals(8, $groups['taxable']['net_item_tax']);
        $this->assertSame($before, DB::table('pos_transactions')->orderBy('id')->get()->toJson());
        $this->assertSame(1, DB::table('reporting_read_audits')->count());
        $this->assertStringNotContainsString('SECRET', $this->toolCall($tokens['access_token'], 'get_bill_details', $this->period() + ['bill_id' => 1])->assertOk()->getContent());
    }

    public function test_null_fiscal_status_is_unreported_and_filter_pagination_is_safe(): void
    {
        [$p,$t] = $this->connection();
        $r = $this->toolCall($t['access_token'], 'list_company_bills', $this->period() + ['reported' => 'unreported', 'status' => 'completed', 'limit' => 1])->assertOk()->json('result.structuredContent');
        $this->assertSame(2, $r['bills'][0]['id']);
        $this->assertTrue($r['has_more']);
        $this->assertSame(2, $r['next_after_id']);
        $r = $this->toolCall($t['access_token'], 'list_company_bills', $this->period() + ['reported' => 'unreported', 'status' => 'completed', 'after_id' => 2])->json('result.structuredContent');
        $this->assertSame([4], array_column($r['bills'], 'id'));
        $this->toolCall($t['access_token'], 'get_company_billing_summary', $this->period('Pizza Master Twin'))->assertJsonPath('result.isError', true);
        $this->toolCall($t['access_token'], 'get_bill_details', $this->period() + ['bill_id' => 6])->assertJsonPath('result.isError', true);
    }

    public function test_di_production_and_sandbox_are_not_conflated_and_fbr_pos_works(): void
    {
        [$p,$t] = $this->connection([3, 4]);
        $r = $this->toolCall($t['access_token'], 'get_company_billing_summary', $this->period('DI Test'))->assertOk()->json('result.structuredContent');
        $this->assertSame(2, $r['totals']['reported_bills']);
        $this->assertSame(1, $r['totals']['unreported_bills']);
        $this->assertEquals(59, $r['totals']['reported_net_amount']);
        $this->toolCall($t['access_token'], 'get_company_billing_summary', $this->period('FBR Test'))->assertJsonPath('result.structuredContent.totals.reported_bills', 1);
    }

    public function test_scope_ambiguity_date_bounds_tax_item_evidence_and_no_mutators(): void
    {
        [$p,$t] = $this->connection([1, 2]);
        $this->toolCall($t['access_token'], 'get_company_billing_summary', $this->period('Pizza Master'))->assertJsonPath('result.isError', true);
        $this->toolCall($t['access_token'], 'get_company_billing_summary', ['company_name' => 'Pizza Master Test', 'date_from' => '2026-01-01', 'date_to' => '2026-10-13'])->assertJsonPath('result.isError', true);
        $this->toolCall($t['access_token'], 'delete_invoice', $this->period())->assertJsonPath('error.code', -32602);
        $this->toolCall($t['access_token'], 'get_company_tax_items', $this->period() + ['classification' => 'exempt'])->assertJsonPath('result.structuredContent.items.0.description', 'Exempt item');
        $this->toolCall($t['access_token'], 'get_company_payment_summary', $this->period())->assertJsonPath('result.structuredContent.actual_collected_total', null);
        $tools = $this->withToken($t['access_token'])->postJson('/api/reporting/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])->json('result.tools');
        $this->assertCount(7, $tools);
        foreach ($tools as $tool) {
            $this->assertTrue($tool['annotations']['readOnlyHint']);
            $this->assertFalse($tool['annotations']['destructiveHint']);
        }
    }

    public function test_pkce_resource_code_replay_and_refresh_replay_fail_closed(): void
    {
        [$p,$input] = $this->authorize();
        $this->postJson('/api/reporting/oauth/token', array_replace($input, ['code_verifier' => str_repeat('b', 64)]))->assertStatus(400);
        $this->postJson('/api/reporting/oauth/token', array_replace($input, ['resource' => 'https://wrong.invalid/mcp']))->assertStatus(400);
        $t = $this->postJson('/api/reporting/oauth/token', $input)->assertOk()->json();
        $this->postJson('/api/reporting/oauth/token', $input)->assertStatus(400);
        $this->assertFalse(DB::table('reporting_oauth_access_tokens')->where('hash', $t['access_token'])->exists());
        $refresh = ['grant_type' => 'refresh_token', 'client_id' => $p['client_id'], 'resource' => $p['resource'], 'refresh_token' => $t['refresh_token']];
        $next = $this->postJson('/api/reporting/oauth/token', $refresh)->assertOk()->json();
        $this->postJson('/api/reporting/oauth/token', $refresh)->assertStatus(400);
        $this->toolCall($next['access_token'], 'get_company_billing_summary', $this->period())->assertUnauthorized();
        $this->assertNotNull(DB::table('reporting_oauth_grants')->first()->revoked_at);
    }

    public function test_owner_revocation_and_owner_demotion_block_existing_tokens(): void
    {
        [$p,$t] = $this->connection();
        $grant = DB::table('reporting_oauth_grants')->first();
        $this->get('/admin/reporting/connections')->assertOk()->assertSee('Synthetic reporting client')->assertDontSee($t['access_token']);
        $this->post('/admin/reporting/connections/'.$grant->id.'/revoke')->assertRedirect();
        $this->toolCall($t['access_token'], 'get_company_billing_summary', $this->period())->assertUnauthorized();
        [$p,$t] = $this->connection();
        $this->owner->update(['role' => 'support']);
        $this->toolCall($t['access_token'], 'get_company_billing_summary', $this->period())->assertUnauthorized();
        $this->get('/admin/reporting/connections')->assertForbidden();
    }

    public function test_metadata_redirect_origin_bearer_and_protocol_guards(): void
    {
        $this->getJson('/.well-known/oauth-authorization-server')->assertJsonPath('code_challenge_methods_supported.0', 'S256')->assertJsonPath('authorization_response_iss_parameter_supported', true);
        $this->getJson('/.well-known/oauth-protected-resource/api/reporting/mcp')->assertJsonPath('resource', 'http://localhost/api/reporting/mcp');
        $this->postJson('/api/reporting/oauth/register', ['client_name' => 'Bad callback', 'redirect_uris' => ['https://evil.invalid/callback']])->assertStatus(400);
        $this->postJson('/api/reporting/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized()->assertHeader('WWW-Authenticate');
        [$p,$t] = $this->connection();
        $this->withToken($t['access_token'])->withHeader('Origin', 'https://evil.invalid')->postJson('/api/reporting/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertForbidden();
        $this->flushHeaders();
        $this->withToken($t['access_token'])->get('/api/reporting/mcp')->assertStatus(405);
        $this->withToken($t['access_token'])->postJson('/api/reporting/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])->assertStatus(202);
        $this->withToken($t['access_token'])->postJson('/api/reporting/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26']])->assertJsonPath('result.protocolVersion', '2025-03-26');
        $this->travel(61)->minutes();
        $this->toolCall($t['access_token'], 'get_company_billing_summary', $this->period())->assertUnauthorized();
        $this->travelBack();
    }

    public function test_exact_name_resolution_survives_many_partial_matches(): void
    {
        for ($i = 10; $i < 25; $i++) {
            DB::table('companies')->insert(['id' => $i, 'name' => 'Pizza Master Test '.$i, 'product_type' => 'pos']);
        }
        $this->assertSame(1, app(CompanyReadReports::class)->company('Pizza Master Test', null)->id);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(CompanyReadReports::class)->company('Pizza Master', null);
    }

    public function test_empty_period_missing_evidence_and_business_dates_are_explicit(): void
    {
        [$p,$t] = $this->connection();
        $r = $this->toolCall($t['access_token'], 'get_company_billing_summary', ['company_name' => 'Pizza Master Test', 'date_from' => '2026-10-14'])->assertOk()->json('result.structuredContent');
        $this->assertSame(0, $r['totals']['completed_documents']);
        $this->assertEquals(0, $r['totals']['net_billing']);
        $r = $this->toolCall($t['access_token'], 'get_company_billing_summary', $this->period() + ['date_basis' => 'business'])->json('result.structuredContent');
        $this->assertEquals(1550, $r['totals']['net_billing']);
        Schema::drop('pos_transaction_items');
        $this->toolCall($t['access_token'], 'get_company_billing_summary', $this->period())->assertJsonPath('result.structuredContent.tax_items.available', false);
    }

    public function test_literal_company_search_and_deleted_company_do_not_leak(): void
    {
        DB::table('companies')->insert(['id' => 8, 'name' => 'Literal %_ Cafe', 'product_type' => 'pos']);
        $service = app(CompanyReadReports::class);
        $this->assertSame([8], array_column($service->companies('%_', null), 'id'));
        DB::table('companies')->where('id', 1)->update(['deleted_at' => now()]);
        [$p,$t] = $this->connection([2]);
        $this->toolCall($t['access_token'], 'get_company_billing_summary', $this->period())->assertJsonPath('result.isError', true);
    }

    public function test_di_item_classification_and_zero_missing_metadata_are_distinct(): void
    {
        DB::table('invoice_items')->insert([
            ['id' => 1, 'invoice_id' => 1, 'description' => 'DI exempt', 'quantity' => 1, 'tax_rate' => 0, 'tax' => 0, 'schedule_type' => 'exempt'],
            ['id' => 2, 'invoice_id' => 1, 'description' => 'DI zero', 'quantity' => 1, 'tax_rate' => 0, 'tax' => 0, 'schedule_type' => 'zero_rated'],
            ['id' => 3, 'invoice_id' => 1, 'description' => 'DI unknown', 'quantity' => 1, 'tax_rate' => 0, 'tax' => 0, 'schedule_type' => null],
        ]);
        [$p,$t] = $this->connection([4]);
        $r = $this->toolCall($t['access_token'], 'get_company_tax_items', $this->period('DI Test'))->json('result.structuredContent');
        $this->assertSame(['exempt', 'zero_rate', 'unknown'], array_column($r['items'], 'classification'));
    }

    public function test_hotel_cash_and_refund_streams_are_separate_and_tenant_scoped(): void
    {
        Schema::create('hotel_folio_entries', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('entry_type');
            $t->string('payment_method');
            $t->decimal('amount', 14, 2);
            $t->dateTime('created_at');
        });
        DB::table('hotel_folio_entries')->insert([
            ['company_id' => 1, 'entry_type' => 'payment', 'payment_method' => 'cash', 'amount' => 80, 'created_at' => '2026-10-13 12:00:00'],
            ['company_id' => 1, 'entry_type' => 'refund', 'payment_method' => 'cash', 'amount' => 20, 'created_at' => '2026-10-13 12:00:00'],
            ['company_id' => 2, 'entry_type' => 'payment', 'payment_method' => 'cash', 'amount' => 9999, 'created_at' => '2026-10-13 12:00:00'],
        ]);
        [$p,$t] = $this->connection();
        $r = $this->toolCall($t['access_token'], 'get_company_payment_summary', $this->period())->json('result.structuredContent');
        $this->assertCount(2, $r['hotel_folio_movements']);
        $this->assertEquals(100, array_sum(array_column($r['hotel_folio_movements'], 'stored_amount')));
        $this->assertNull($r['actual_collected_total']);
    }

    public function test_denial_nonowner_untrusted_resource_and_expired_grant_block_access(): void
    {
        [$p,$input] = $this->authorize();
        $denied = $this->post('/admin/reporting/oauth/authorize', $p + ['decision' => 'deny', 'company_scope' => 'selected'])->assertRedirect();
        parse_str(parse_url($denied->headers->get('Location'), PHP_URL_QUERY), $callback);
        $this->assertSame('access_denied', $callback['error']);
        $this->assertArrayNotHasKey('code', $callback);
        $this->assertSame('http://localhost', $callback['iss']);
        $this->post('/admin/reporting/oauth/authorize', array_replace($p, ['resource' => 'https://wrong.invalid']) + ['decision' => 'approve', 'company_scope' => 'all'])->assertStatus(400);
        $t = $this->postJson('/api/reporting/oauth/token', $input)->assertOk()->json();
        DB::table('reporting_oauth_grants')->update(['expires_at' => now()->subMinute()]);
        $this->toolCall($t['access_token'], 'get_company_billing_summary', $this->period())->assertUnauthorized();
        $this->owner->update(['role' => 'support']);
        $this->get('/admin/reporting/oauth/authorize?'.http_build_query($p))->assertForbidden();
    }

    public function test_branch_counter_payment_filters_and_safe_operational_sources(): void
    {
        Schema::table('pos_transactions', function (Blueprint $t) {
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('terminal_id')->nullable();
        });
        DB::table('pos_transactions')->where('id', 1)->update(['branch_id' => 3, 'terminal_id' => 8]);
        Schema::create('inventory_stocks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->decimal('quantity', 12, 2);
        });
        DB::table('inventory_stocks')->insert([['company_id' => 1, 'quantity' => -1], ['company_id' => 2, 'quantity' => 100]]);
        [$p,$t] = $this->connection();
        $this->toolCall($t['access_token'], 'get_company_billing_summary', $this->period() + ['branch_id' => 3, 'terminal_id' => 8, 'payment_method' => 'cash'])->assertJsonPath('result.structuredContent.totals.completed_documents', 1);
        $this->toolCall($t['access_token'], 'get_company_operational_summary', $this->period())->assertJsonPath('result.structuredContent.branch_inventory_summary.branch_stock_rows', 1);
    }

    public function test_duplicate_legal_names_can_be_selected_by_scoped_id_without_guessing(): void
    {
        DB::table('companies')->where('id', 2)->update(['name' => 'Pizza Master Test']);
        [$p,$t] = $this->connection([1, 2]);
        $this->toolCall($t['access_token'], 'get_company_billing_summary', $this->period())->assertJsonPath('result.isError', true);
        $this->toolCall($t['access_token'], 'get_company_billing_summary', $this->period() + ['company_id' => 2])->assertJsonPath('result.structuredContent.company.id', 2)->assertJsonPath('result.structuredContent.totals.net_billing', 99999);
        $this->toolCall($t['access_token'], 'get_company_billing_summary', $this->period() + ['company_id' => 3])->assertJsonPath('result.isError', true);
    }
}
