<?php

namespace Tests\Feature;

use App\Http\Controllers\FbrPosSchoolFeeController;
use App\Models\Company;
use App\Models\FbrPosTransaction;
use App\Models\User;
use App\Services\FbrService;
use App\Services\PosFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/** Synthetic records only. These checks do not constitute FBR sandbox acceptance. */
class FbrPosSchoolFeePilotTest extends TestCase
{
    private Company $school;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Http::fake();
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type');
            $table->string('pos_type');
            $table->string('business_category')->nullable();
            $table->boolean('fbr_reporting_enabled')->default(false);
            $table->boolean('fbr_pos_enabled')->default(true);
            $table->boolean('agent_enabled')->default(false);
            $table->boolean('is_internal_account')->default(false);
            $table->integer('invoice_limit_override')->nullable();
            $table->string('fbr_connection_mode')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->string('role');
            $table->string('pos_role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('school_fee_receipts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('collected_by');
            $table->string('student_reference');
            $table->string('fee_head');
            $table->unsignedBigInteger('amount_paisa');
            $table->uuid('request_key');
            $table->timestamp('collected_at');
            $table->timestamps();
            $table->unique(['company_id', 'request_key']);
        });
        $this->school = Company::withoutEvents(fn () => Company::create([
            'name' => 'Synthetic School', 'product_type' => 'fbrpos',
            'pos_type' => 'education', 'business_category' => 'education',
            'fbr_reporting_enabled' => false, 'is_internal_account' => true,
        ]));
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->boolean('active')->default(true);
            $table->string('override_type')->nullable();
            $table->integer('free_invoice_limit')->nullable();
            $table->timestamps();
        });
        DB::table('subscriptions')->insert([
            'company_id' => $this->school->id, 'active' => true, 'override_type' => 'lifetime',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->cashier = User::withoutEvents(fn () => User::create([
            'company_id' => $this->school->id, 'name' => 'Synthetic Cashier',
            'email' => 'cashier@example.test', 'password' => 'not-a-real-password',
            'role' => 'employee', 'pos_role' => 'pos_cashier', 'is_active' => true,
        ]));
        $this->actingAs($this->cashier, 'fbrpos');
        app()->instance('currentCompanyId', $this->school->id);
        app()->bind('currentBranchId', fn () => null);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'student_reference' => 'SYN-001', 'fee_head' => 'tuition',
            'amount' => '1250.50', 'payment_method' => 'cash',
            'request_key' => (string) Str::uuid(),
        ], $overrides);
    }

    private function collect(array $data)
    {
        $request = Request::create('/fbr-pos/school-fees', 'POST', $data, [], [], ['HTTP_ACCEPT' => 'application/json']);
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        return app(FbrPosSchoolFeeController::class)->store($request);
    }

    private function expectHttpStatus(int $status, callable $call): void
    {
        try {
            $call();
            $this->fail('Expected an HTTP exception.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }

    public function test_cash_collection_and_reprint_preserve_one_non_fiscal_receipt(): void
    {
        $payload = $this->payload();
        $first = $this->collect($payload)->getData(true);
        $second = $this->collect($payload)->getData(true);
        $this->assertTrue($first['success']);
        $this->assertSame($first['receipt_id'], $second['receipt_id']);
        $this->assertSame(1, DB::table('school_fee_receipts')->count());
        $row = DB::table('school_fee_receipts')->first();
        $this->assertSame(125050, (int) $row->amount_paisa);
        $this->assertSame('SYN-001', $row->student_reference);
        $this->assertSame('tuition', $row->fee_head);
        $this->assertSame($this->cashier->id, (int) $row->collected_by);

        $controller = app(FbrPosSchoolFeeController::class);
        $original = $controller->receipt($row->id)->getContent();
        $reprint = $controller->receipt($row->id)->getContent();
        $this->assertSame($original, $reprint);
        $this->assertStringContainsString('SYN-001', $reprint);
        $this->assertStringContainsString('1,250.50', $reprint);
        $this->assertStringNotContainsString('fbr_invoice_number', $reprint);
        $this->assertStringNotContainsString('<svg', $reprint);
        Http::assertNothingSent();
    }

    public function test_all_three_fee_heads_collect_cash_without_tax_submission(): void
    {
        foreach (['tuition', 'admission', 'exam'] as $head) {
            $this->assertTrue($this->collect($this->payload(['fee_head' => $head]))->getData(true)['success']);
        }
        $this->assertSame(3, DB::table('school_fee_receipts')->count());
        Http::assertNothingSent();
    }

    public function test_changed_retry_payload_cannot_silently_reuse_or_duplicate_payment(): void
    {
        $payload = $this->payload();
        $this->collect($payload);
        try {
            $this->collect(array_replace($payload, ['amount' => '999.00']));
            $this->fail('A changed retry must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->status);
        }
        $this->assertSame(1, DB::table('school_fee_receipts')->count());
        $this->assertSame(125050, (int) DB::table('school_fee_receipts')->value('amount_paisa'));
    }

    public function test_non_cash_unknown_fee_and_invalid_amounts_are_rejected(): void
    {
        foreach ([['payment_method' => 'card'], ['fee_head' => 'canteen'], ['amount' => '0'], ['amount' => '-1'], ['amount' => '1.001'], ['amount' => '1e3']] as $invalid) {
            try {
                $this->collect($this->payload($invalid));
                $this->fail('Invalid school fee payload was accepted: '.json_encode($invalid));
            } catch (ValidationException $exception) {
                $this->assertSame(422, $exception->status);
            }
        }
        $this->assertSame(0, DB::table('school_fee_receipts')->count());
    }

    public function test_receipt_access_is_tenant_and_branch_scoped(): void
    {
        $id = $this->collect($this->payload())->getData(true)['receipt_id'];
        app()->bind('currentBranchId', fn () => 9);
        $this->expectHttpStatus(404, fn () => app(FbrPosSchoolFeeController::class)->receipt($id));
        app()->bind('currentBranchId', fn () => null);
        $other = Company::withoutEvents(fn () => Company::create([
            'name' => 'Other School', 'product_type' => 'fbrpos', 'pos_type' => 'education',
        ]));
        $this->cashier->company_id = $other->id;
        $this->cashier->saveQuietly();
        $this->actingAs($this->cashier, 'fbrpos');
        app()->instance('currentCompanyId', $other->id);
        $this->expectHttpStatus(404, fn () => app(FbrPosSchoolFeeController::class)->receipt($id));
    }

    public function test_retail_company_cannot_use_school_collection_endpoint(): void
    {
        $this->school->forceFill(['pos_type' => 'retail', 'business_category' => 'retail'])->saveQuietly();
        $this->expectHttpStatus(404, fn () => $this->collect($this->payload()));
        $this->assertSame(0, DB::table('school_fee_receipts')->count());
    }

    public function test_viewer_and_read_only_impersonation_cannot_collect_cash(): void
    {
        $this->cashier->pos_role = 'local_viewer';
        $this->actingAs($this->cashier, 'fbrpos');
        $this->expectHttpStatus(403, fn () => $this->collect($this->payload()));
        $this->cashier->pos_role = 'pos_cashier';
        $this->actingAs($this->cashier, 'fbrpos');
        app('session.store')->put('impersonation', ['readonly' => true]);
        $this->expectHttpStatus(403, fn () => $this->collect($this->payload()));
        $this->assertSame(0, DB::table('school_fee_receipts')->count());
    }

    public function test_monthly_cap_counts_school_receipts_but_allows_saved_request_replay(): void
    {
        $this->school->forceFill(['is_internal_account' => false, 'invoice_limit_override' => 1])->saveQuietly();
        $payload = $this->payload();
        $first = $this->collect($payload)->getData(true);
        $this->expectHttpStatus(403, fn () => $this->collect($this->payload()));
        $replay = $this->collect($payload)->getData(true);
        $this->assertSame($first['receipt_id'], $replay['receipt_id']);
        $this->assertSame(1, DB::table('school_fee_receipts')->count());
    }

    public function test_trial_style_allowance_blocks_new_collection_but_not_exact_saved_retry(): void
    {
        $payload = $this->payload();
        $first = $this->collect($payload)->getData(true);
        DB::table('subscriptions')->where('company_id', $this->school->id)->update([
            'override_type' => 'usage_free', 'free_invoice_limit' => 1,
        ]);
        $throughGate = function (array $data) {
            $request = Request::create('/fbr-pos/school-fees', 'POST', $data, [], [], ['HTTP_ACCEPT' => 'application/json']);
            $request->setLaravelSession(app('session.store'));
            $request->setRouteResolver(fn () => app('router')->getRoutes()->getByName('fbrpos.school-fees.store'));
            app()->instance('request', $request);
            return app(\App\Http\Middleware\CheckPlanLimit::class)->handle(
                $request,
                fn ($request) => app(FbrPosSchoolFeeController::class)->store($request),
                'invoices'
            );
        };
        $this->assertSame(403, $throughGate($this->payload())->getStatusCode());
        $replay = $throughGate($payload)->getData(true);
        $this->assertSame($first['receipt_id'], $replay['receipt_id']);
        $this->assertSame(1, DB::table('school_fee_receipts')->count());
    }

    public function test_school_routes_keep_existing_authentication_and_approval_middleware(): void
    {
        foreach (['index', 'store', 'receipt'] as $action) {
            $route = app('router')->getRoutes()->getByName('fbrpos.school-fees.'.$action);
            $this->assertNotNull($route);
            $this->assertContains('fbrpos.auth', $route->gatherMiddleware());
            $this->assertContains('company.approval', $route->gatherMiddleware());
            if ($action === 'store') {
                $this->assertContains('plan.limit:invoices', $route->gatherMiddleware());
            }
        }
    }

    public function test_fee_receipt_response_is_not_cacheable(): void
    {
        $id = $this->collect($this->payload())->getData(true)['receipt_id'];
        $response = app(FbrPosSchoolFeeController::class)->receipt($id);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_school_fiscal_guard_blocks_direct_submission_even_with_reporting_flag_on(): void
    {
        DB::table('companies')->where('id', $this->school->id)->update(['fbr_reporting_enabled' => true]);
        $school = $this->school->fresh();
        $this->assertFalse($school->fbr_reporting_enabled);
        $this->assertFalse($school->fbrPosIntegrationConfigured());
        $transaction = new FbrPosTransaction(['company_id' => $school->id]);
        $transaction->setRelation('company', $school);
        $result = app(FbrService::class)->submitFbrPosTransaction($transaction);
        $this->assertSame('blocked', $result['status']);
        Http::assertNothingSent();
    }

    public function test_school_agent_and_di_paths_fail_closed_with_stale_configuration(): void
    {
        $this->school->forceFill([
            'fbr_reporting_enabled' => true, 'fbr_pos_enabled' => true,
            'agent_enabled' => true, 'fbr_connection_mode' => 'fiscal_device',
        ])->saveQuietly();
        $school = $this->school->fresh();
        $this->assertFalse($school->agentServesFbr());
        $this->assertFalse($school->agentHandlesPra());
        $result = app(FbrService::class)->submitPosDiPayload([], 'synthetic-unused', 'sandbox', $school->id);
        $this->assertSame('blocked', $result['state']);
        $this->assertNull($result['reference']);
        Http::assertNothingSent();
    }

    public function test_manual_retry_and_scheduled_queue_do_not_dispatch_school_transactions(): void
    {
        Schema::create('fbr_pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_mode')->nullable();
            $table->string('fbr_status')->nullable();
            $table->string('fbr_invoice_number')->nullable();
            $table->string('fbr_submission_hash')->nullable();
            $table->unsignedInteger('fbr_auto_retry_count')->default(0);
            $table->timestamps();
        });
        Schema::create('fbr_pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
        });
        DB::table('companies')->where('id', $this->school->id)->update(['fbr_reporting_enabled' => true]);
        $id = DB::table('fbr_pos_transactions')->insertGetId([
            'company_id' => $this->school->id, 'invoice_mode' => 'fbr', 'fbr_status' => 'failed',
            'fbr_auto_retry_count' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);
        (new \App\Jobs\RetryFbrPosSubmissionJob($id))->handle();
        (new \App\Jobs\SyncFbrPosOfflineInvoicesJob())->handle();
        $row = DB::table('fbr_pos_transactions')->find($id);
        $this->assertSame('failed', $row->fbr_status);
        $this->assertSame(2, (int) $row->fbr_auto_retry_count);
        $this->assertNull($row->fbr_submission_hash);
        $this->assertNull($row->fbr_invoice_number);
        Http::assertNothingSent();
    }

    public function test_school_desk_redirect_and_browser_receipt_redirect_remain_relative(): void
    {
        $response = app(\App\Http\Controllers\FbrPosController::class)->create();
        $this->assertSame('/fbr-pos/school-fees', $response->headers->get('Location'));
        $request = Request::create('/fbr-pos/school-fees', 'POST', $this->payload());
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        $receipt = app(FbrPosSchoolFeeController::class)->store($request);
        $this->assertStringStartsWith('/fbr-pos/school-fees/', $receipt->headers->get('Location'));
    }

    public function test_school_category_does_not_change_pra_education_or_retail_reporting(): void
    {
        $this->assertContains('education', PosFeatureService::categories('fbr'));
        $this->assertContains('education', PosFeatureService::categories('pra'));
        $pra = new Company(['product_type' => 'pos', 'pos_type' => 'education', 'fbr_reporting_enabled' => true]);
        $retail = new Company(['product_type' => 'fbrpos', 'pos_type' => 'retail', 'fbr_reporting_enabled' => true]);
        $this->assertFalse($pra->isSchoolFeePilot());
        $this->assertFalse($retail->isSchoolFeePilot());
        $this->assertTrue($retail->fbr_reporting_enabled);
    }
}
