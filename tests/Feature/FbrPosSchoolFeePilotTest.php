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
        app('view')->share('errors', new \Illuminate\Support\ViewErrorBag());
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
            $table->boolean('pos_cashier_dayclose')->default(false);
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
        Schema::create('school_fee_day_closes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_key')->default(0);
            $table->date('report_date');
            $table->unsignedBigInteger('cash_amount_paisa');
            $table->unsignedInteger('receipt_count');
            $table->unsignedBigInteger('closed_by');
            $table->timestamp('closed_at');
            $table->timestamps();
            $table->unique(['company_id', 'branch_key', 'report_date']);
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
            'amount' => '1250.50', 'payment_method' => 'cash', 'cash_confirmed' => '1',
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
        $before = (array) DB::table('school_fee_receipts')->first();
        $controller = app(FbrPosSchoolFeeController::class);
        $originalReceipt = $controller->receipt($before['id'])->getContent();
        foreach ([['amount' => '999.00'], ['fee_head' => 'exam'], ['student_reference' => 'SYN-CHANGED']] as $change) {
            try {
                $this->collect(array_replace($payload, $change));
                $this->fail('A changed retry must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertSame(422, $exception->status);
                $this->assertSame([__('pos.school_retry_conflict')], $exception->errors()['request_key']);
            }
            $this->assertSame(1, DB::table('school_fee_receipts')->count());
            $this->assertSame(125050, (int) DB::table('school_fee_receipts')->sum('amount_paisa'));
            $this->assertSame($before, (array) DB::table('school_fee_receipts')->first());
            $this->assertSame($originalReceipt, $controller->receipt($before['id'])->getContent());
        }
        Http::assertNothingSent();
    }

    public function test_new_cash_requires_explicit_confirmation_but_saved_retry_does_not(): void
    {
        foreach ([null, '0'] as $confirmation) {
            try {
                $this->collect($this->payload(['cash_confirmed' => $confirmation]));
                $this->fail('New cash must require explicit confirmation.');
            } catch (ValidationException $exception) {
                $this->assertSame(422, $exception->status);
                $this->assertArrayHasKey('cash_confirmed', $exception->errors());
            }
        }
        $this->assertSame(0, DB::table('school_fee_receipts')->count());
        $payload = $this->payload();
        $id = $this->collect($payload)->getData(true)['receipt_id'];
        unset($payload['cash_confirmed']);
        $this->assertSame($id, $this->collect($payload)->getData(true)['receipt_id']);
        $this->assertSame(1, DB::table('school_fee_receipts')->count());
    }

    public function test_school_dashboard_totals_and_close_status_follow_native_cash_ledger(): void
    {
        $controller = app(FbrPosSchoolFeeController::class);
        $assertDashboard = function (int $count, int $paisa, string $status) use ($controller) {
            $html = $controller->dashboard()->getContent();
            $this->assertMatchesRegularExpression('/id="school-today-count"[^>]*>\s*'.$count.'\s*</', $html);
            $this->assertMatchesRegularExpression('/id="school-today-cash"[^>]*data-paisa="'.$paisa.'"/', $html);
            $this->assertMatchesRegularExpression('/id="school-today-status"[^>]*>\s*'.$status.'\s*</', $html);
        };
        $assertDashboard(0, 0, 'Open');
        $this->collect($this->payload());
        $assertDashboard(1, 125050, 'Open');
        $this->school->forceFill(['pos_cashier_dayclose' => true])->saveQuietly();
        $this->closeSchoolDay();
        $assertDashboard(1, 125050, 'Closed');
    }

    public function test_campus_switcher_shows_only_multiple_authorized_active_campuses(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('company_id'); $table->string('name');
            $table->boolean('is_active')->default(true); $table->boolean('is_head_office')->default(false);
            $table->timestamps();
        });
        Schema::create('branch_user', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id'); $table->unsignedBigInteger('user_id');
        });
        foreach ([[17, $this->school->id, true], [18, $this->school->id, true],
            [19, $this->school->id, false], [20, 999, true]] as [$id, $company, $active]) {
            DB::table('branches')->insert(['id' => $id, 'company_id' => $company,
                'name' => 'Campus '.$id, 'is_active' => $active]);
        }
        $this->cashier->pos_role = 'pos_manager';
        $this->actingAs($this->cashier, 'fbrpos');
        DB::table('branch_user')->insert(['branch_id' => 17, 'user_id' => $this->cashier->id]);
        $controller = app(FbrPosSchoolFeeController::class);
        $this->assertStringNotContainsString('id="school-campus-switcher"', $controller->index()->getContent());
        $this->assertSame(17, app('currentBranchId'));
        foreach ([18, 19, 20] as $id) DB::table('branch_user')->insert(['branch_id' => $id, 'user_id' => $this->cashier->id]);
        $html = $controller->index()->getContent();
        $this->assertStringContainsString('id="school-campus-switcher"', $html);
        $this->assertStringContainsString('Campus 17', $html);
        $this->assertStringContainsString('Campus 18', $html);
        $this->assertStringNotContainsString('Campus 19', $html);
        $this->assertStringNotContainsString('Campus 20', $html);
        $switch = function ($id) use ($controller) {
            $request = Request::create('/fbr-pos/school-fees/campus', 'POST', ['branch_id' => $id]);
            $request->setLaravelSession(app('session.store'));
            return $controller->switchCampus($request);
        };
        $this->assertSame('/fbr-pos/school-fees', $switch(18)->headers->get('Location'));
        $this->assertSame(18, app('currentBranchId'));
        foreach ([19, 20] as $id) $this->expectHttpStatus(403, fn () => $switch($id));
        try {
            $switch('all');
            $this->fail('School cash must never switch to all campuses.');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->status);
        }
        $id = $this->collect($this->payload())->getData(true)['receipt_id'];
        $this->assertSame(18, (int) DB::table('school_fee_receipts')->where('id', $id)->value('branch_id'));
        // Owner-level ALL is a reporting scope elsewhere, never a valid
        // school cash posting scope. Normalize it to one authorized campus.
        $this->cashier->pos_role = 'pos_admin';
        $this->cashier->role = 'company_admin';
        $this->actingAs($this->cashier, 'fbrpos');
        $branches = app(\App\Services\BranchContextService::class);
        $branches->useGuard('fbrpos');
        $this->assertTrue($branches->setActiveBranch('all'));
        $this->assertNull(app('currentBranchId'));
        $controller->index();
        $this->assertContains(app('currentBranchId'), [17, 18]);
        $this->assertNotSame('all', app('session.store')->get(\App\Services\BranchContextService::SESSION_KEY));
    }

    public function test_school_native_pages_never_render_retail_menu_links(): void
    {
        $controller = app(FbrPosSchoolFeeController::class);
        $this->school->forceFill(['pos_cashier_dayclose' => true])->saveQuietly();
        $request = Request::create('/fbr-pos/transactions', 'GET');
        app()->instance('request', $request);
        foreach ([$controller->dashboard(), $controller->index(), $controller->history($request), $controller->dayClose($request)] as $response) {
            $html = $response->getContent();
            foreach (['products', 'stock', 'deals', 'loyalty', 'tax-reports', 'fail-queue', 'services', 'pharmacy', 'tables', 'kitchen'] as $retail) {
                $this->assertDoesNotMatchRegularExpression('~href=["\'][^"\']*/fbr-pos/'.$retail.'(?:[/?#"\'])~i', $html);
            }
            foreach (['Collect Fee', 'Receipts / Reprint', 'Cash Day Close'] as $label) {
                $this->assertStringContainsString($label, $html);
            }
        }
    }

    public function test_negative_campus_sentinel_fails_closed(): void
    {
        app()->bind('currentBranchId', fn () => -1);
        $controller = app(FbrPosSchoolFeeController::class);
        $this->expectHttpStatus(403, fn () => $controller->index());
        $this->expectHttpStatus(403, fn () => $controller->dashboard());
        $this->expectHttpStatus(403, fn () => $this->collect($this->payload()));
        $this->assertSame(0, DB::table('school_fee_receipts')->count());
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

    public function test_campus_cashier_can_reprint_own_saved_receipt_but_not_other_campus_or_tenant(): void
    {
        app()->bind('currentBranchId', fn () => 17);
        $payload = $this->payload();
        $id = $this->collect($payload)->getData(true)['receipt_id'];
        $row = DB::table('school_fee_receipts')->find($id);
        $this->assertSame(17, (int) $row->branch_id);
        $controller = app(FbrPosSchoolFeeController::class);
        $receipt = $controller->receipt($id)->getContent();
        foreach (['Synthetic School', 'SF-'.$id, 'SYN-001', 'Tuition', 'Cash', 'PKR 1,250.50',
            (string) $row->collected_at, 'LOCAL RECEIPT ONLY', 'Not submitted to FBR.'] as $field) {
            $this->assertStringContainsString($field, $receipt);
        }
        // The save succeeded even if its response was lost. Reconstructing a
        // fresh request with the same key must return the same immutable receipt.
        $this->assertSame($id, $this->collect($payload)->getData(true)['receipt_id']);
        $this->assertSame($receipt, $controller->receipt($id)->getContent());
        app()->bind('currentBranchId', fn () => 18);
        $this->expectHttpStatus(404, fn () => $controller->receipt($id));
        app()->bind('currentBranchId', fn () => 17);
        $other = Company::withoutEvents(fn () => Company::create([
            'name' => 'Other Campus Tenant', 'product_type' => 'fbrpos', 'pos_type' => 'education',
        ]));
        $this->cashier->company_id = $other->id;
        $this->cashier->saveQuietly();
        $this->actingAs($this->cashier, 'fbrpos');
        app()->instance('currentCompanyId', $other->id);
        $this->expectHttpStatus(404, fn () => $controller->receipt($id));
        $this->assertSame(1, DB::table('school_fee_receipts')->count());
        $this->assertSame(125050, (int) DB::table('school_fee_receipts')->sum('amount_paisa'));
    }

    public function test_history_and_authorized_cash_preview_count_saved_payment_once_and_scope_campus_and_date(): void
    {
        app()->bind('currentBranchId', fn () => 17);
        $this->school->forceFill(['pos_cashier_dayclose' => true])->saveQuietly();
        $controller = app(FbrPosSchoolFeeController::class);
        $assertReports = function (int $count, int $paisa) use ($controller) {
            foreach (['history', 'dayClose'] as $action) {
                $request = Request::create('/fbr-pos/'.($action === 'history' ? 'transactions' : 'day-close'), 'GET');
                app()->instance('request', $request);
                $html = $controller->$action($request)->getContent();
                $this->assertStringContainsString('id="school-receipt-count">'.$count.'</dd>', $html);
                $this->assertStringContainsString('data-paisa="'.$paisa.'"', $html);
            }
        };
        $assertReports(0, 0);
        $payload = $this->payload();
        $first = $this->collect($payload)->getData(true);
        $assertReports(1, 125050);
        $this->assertSame($first['receipt_id'], $this->collect($payload)->getData(true)['receipt_id']);
        foreach ([['amount' => '999.00'], ['fee_head' => 'exam'], ['student_reference' => 'CHANGED']] as $change) {
            try {
                $this->collect(array_replace($payload, $change));
                $this->fail('Changed saved-payment retry must fail.');
            } catch (ValidationException $exception) {
                $this->assertSame(422, $exception->status);
            }
            $assertReports(1, 125050);
        }
        app()->bind('currentBranchId', fn () => 18);
        $this->collect($this->payload(['amount' => '42.00']));
        app()->bind('currentBranchId', fn () => 17);
        $old = $this->collect($this->payload(['amount' => '8.00']))->getData(true);
        DB::table('school_fee_receipts')->where('id', $old['receipt_id'])->update(['collected_at' => now()->subDay()]);
        // Another campus and yesterday's collection must not inflate today's
        // campus-specific cash preview or sales history.
        $assertReports(1, 125050);
        Http::assertNothingSent();
    }

    public function test_cashier_day_close_preview_is_denied_unless_configured(): void
    {
        $controller = app(FbrPosSchoolFeeController::class);
        $request = Request::create('/fbr-pos/day-close', 'GET');
        app()->instance('request', $request);
        $this->expectHttpStatus(403, fn () => $controller->dayClose($request));
        $this->school->forceFill(['pos_cashier_dayclose' => true])->saveQuietly();
        $this->assertSame(200, $controller->dayClose($request)->getStatusCode());
    }

    private function closeSchoolDay(array $overrides = [])
    {
        $request = Request::create('/fbr-pos/day-close/close', 'POST', array_replace([
            'date' => today()->format('Y-m-d'), 'confirm' => '1',
            'expected_cash_paisa' => 125050, 'expected_receipt_count' => 1,
        ], $overrides), [], [], ['HTTP_ACCEPT' => 'application/json']);
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        return app(FbrPosSchoolFeeController::class)->close($request);
    }

    public function test_final_close_is_immutable_blocks_late_cash_but_preserves_saved_retry(): void
    {
        $this->school->forceFill(['pos_cashier_dayclose' => true])->saveQuietly();
        $payload = $this->payload();
        $id = $this->collect($payload)->getData(true)['receipt_id'];
        $closed = $this->closeSchoolDay()->getData(true);
        $snapshot = (array) DB::table('school_fee_day_closes')->first();
        $this->assertTrue($closed['success']);
        $this->assertSame(125050, (int) $snapshot['cash_amount_paisa']);
        $this->assertSame(1, (int) $snapshot['receipt_count']);
        $this->assertSame(0, (int) $snapshot['branch_key']);
        $this->assertSame($this->cashier->id, (int) $snapshot['closed_by']);
        $this->assertSame($closed['report_id'], $this->closeSchoolDay()->getData(true)['report_id']);
        $this->assertSame($id, $this->collect($payload)->getData(true)['receipt_id']);
        foreach ([$this->payload(), array_replace($payload, ['amount' => '9.00']),
            array_replace($payload, ['fee_head' => 'exam']), array_replace($payload, ['student_reference' => 'CHANGED'])] as $rejected) {
            try {
                $this->collect($rejected);
                $this->fail('Closed-day mutation must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertSame(422, $exception->status);
            }
            $this->assertSame($snapshot, (array) DB::table('school_fee_day_closes')->first());
            $this->assertSame(1, DB::table('school_fee_receipts')->count());
            $this->assertSame(125050, (int) DB::table('school_fee_receipts')->sum('amount_paisa'));
        }
        app()->bind('currentBranchId', fn () => 27);
        $this->collect($this->payload());
        $campusClose = $this->closeSchoolDay()->getData(true);
        $this->assertNotSame($closed['report_id'], $campusClose['report_id']);
        $this->assertSame(2, DB::table('school_fee_day_closes')->count());
        Http::assertNothingSent();
    }

    public function test_stale_close_summary_and_unapproved_cashier_or_readonly_close_are_rejected(): void
    {
        $this->collect($this->payload());
        $this->expectHttpStatus(403, fn () => $this->closeSchoolDay());
        $this->school->forceFill(['pos_cashier_dayclose' => true])->saveQuietly();
        foreach ([['expected_cash_paisa' => 0], ['expected_receipt_count' => 0]] as $stale) {
            try {
                $this->closeSchoolDay($stale);
                $this->fail('Stale close must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertSame(422, $exception->status);
            }
        }
        app('session.store')->put('impersonation', ['readonly' => true]);
        $this->expectHttpStatus(403, fn () => $this->closeSchoolDay());
        $this->assertSame(0, DB::table('school_fee_day_closes')->count());
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

    public function test_school_bulk_and_background_paths_cannot_create_retail_day_close(): void
    {
        $controller = app(\App\Http\Controllers\FbrPosController::class);
        foreach (['closeAllPriorDays'] as $method) {
            try {
                $controller->$method(Request::create('/fbr-pos/day-close', 'POST'));
                $this->fail('School retail closing must be rejected.');
            } catch (HttpExceptionInterface $error) {
                $this->assertSame(422, $error->getStatusCode());
                $this->assertNotEmpty($error->getMessage());
            }
        }
        $this->assertNull($controller->performDayClose($this->school->id, today()->toDateString(), $this->cashier->id));
        Http::assertNothingSent();
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
