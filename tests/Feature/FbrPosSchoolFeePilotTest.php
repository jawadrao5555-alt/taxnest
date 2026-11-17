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
            $table->string('confidential_pin')->nullable();
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
        (require database_path('migrations/2026_11_15_010000_extend_school_fee_collection_details.php'))->up();
        (require database_path('migrations/2026_11_15_030000_extend_school_collection_ledger.php'))->up();
        (require database_path('migrations/2027_01_01_000000_add_pilot_fiscal_route_snapshots.php'))->up();
        (require database_path('migrations/2026_11_16_130000_add_school_rates_and_payment_references.php'))->up();
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
            'student_reference' => 'SYN-001', 'fee_head' => 'tuition', 'fee_period' => '2026-11',
            'amount' => '1250.50', 'payment_method' => 'cash', 'cash_confirmed' => '1',
            'expected_branch_key' => app('currentBranchId') ?? 0,
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
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            $this->assertSame(404, $status);
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
        // Icons are not fiscal evidence. Reject the actual retail fiscal receipt markers.
        foreach (['alt="FBR QR Code"', 'class="fbr-number"', 'Tax Asaan', 'invoice.fbr.gov.pk', 'data-fiscal-qr', 'data-fbr-invoice-number'] as $marker) {
            $this->assertStringNotContainsString($marker, $reprint);
        }
        $this->assertStringContainsString(__('pos.school_local_receipt'), $reprint);
        $this->assertStringContainsString('Fiscal OFF', $reprint);
        $this->assertFalse($this->school->fresh()->fbr_reporting_enabled);
        // Even stray fiscal-shaped properties must never become a school identifier or QR.
        $canary = clone $row;
        $canary->fbr_invoice_number = 'SYNTHETIC-FISCAL-CANARY-DO-NOT-RENDER';
        $canary->fbr_status = 'submitted';
        $canary->qr_code = 'SYNTHETIC-FISCAL-QR-DO-NOT-RENDER';
        $canaryHtml = view('fbr-pos.school-fees.receipt', ['company' => $this->school, 'receipt' => $canary])->render();
        $this->assertStringNotContainsString($canary->fbr_invoice_number, $canaryHtml);
        $this->assertStringNotContainsString($canary->qr_code, $canaryHtml);
        $this->assertStringNotContainsString('tnMadadgar(', $reprint);
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
        $assertDashboard(0, 0, 'No day close recorded yet');
        $this->collect($this->payload());
        $assertDashboard(1, 125050, 'No day close recorded yet');
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
            $this->assertStringContainsString('tnMadadgar(', $html);
            foreach (['products', 'stock', 'deals', 'loyalty', 'tax-reports', 'fail-queue', 'services', 'pharmacy', 'tables', 'kitchen'] as $retail) {
                $this->assertDoesNotMatchRegularExpression('~href=["\'][^"\']*/fbr-pos/'.$retail.'(?:[/?#"\'])~i', $html);
            }
            foreach (['Collect Fee', 'Receipts / Reprint', 'Cash Day Close'] as $label) {
                $this->assertStringContainsString($label, $html);
            }
        }
    }

    public function test_stale_campus_form_cannot_post_cash_into_a_different_active_campus(): void
    {
        app()->bind('currentBranchId', fn () => 17);
        $campusAForm = $this->payload();
        $this->assertSame(17, $campusAForm['expected_branch_key']);
        // Another tab changes the shared session to campus B before A submits.
        app()->bind('currentBranchId', fn () => 18);
        try {
            $this->collect($campusAForm);
            $this->fail('Stale campus form must not create a receipt in another campus.');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertArrayHasKey('expected_branch_key', $exception->errors());
        }
        $this->assertSame(0, DB::table('school_fee_receipts')->count());
        app()->bind('currentBranchId', fn () => 17);
        $id = $this->collect($campusAForm)->getData(true)['receipt_id'];
        $this->assertSame(17, (int) DB::table('school_fee_receipts')->where('id', $id)->value('branch_id'));
        Http::assertNothingSent();
    }

    public function test_stale_campus_close_cannot_snapshot_another_campus_with_identical_totals(): void
    {
        $this->school->forceFill(['pos_cashier_dayclose' => true])->saveQuietly();
        app()->bind('currentBranchId', fn () => 17);
        $this->collect($this->payload());
        app()->bind('currentBranchId', fn () => 18);
        $this->collect($this->payload());
        try {
            $this->closeSchoolDay(['expected_branch_key' => 17]);
            $this->fail('Stale close must not close another campus with matching totals.');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertArrayHasKey('expected_branch_key', $exception->errors());
        }
        $this->assertSame(0, DB::table('school_fee_day_closes')->count());
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

    private function legacySchema(): void
    {
        Schema::create('fbr_pos_transactions', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('company_id'); $table->unsignedBigInteger('branch_id')->nullable();
            foreach (['invoice_number', 'invoice_mode', 'fbr_status', 'fbr_invoice_number', 'payment_method', 'status', 'customer_ntn', 'fbr_response', 'fbr_submission_hash', 'share_token'] as $name) $table->string($name)->nullable();
            foreach (['subtotal', 'tax_amount', 'total_amount', 'discount_amount', 'fbr_service_charge'] as $name) $table->decimal($name, 15, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('fbr_pos_transaction_items', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('transaction_id'); $table->string('item_name');
            foreach (['quantity', 'unit_price', 'tax_amount', 'subtotal', 'total'] as $name) $table->decimal($name, 15, 2)->default(0);
            $table->timestamps();
        });
    }

    private function legacyInvoice(array $overrides = []): int
    {
        $id = DB::table('fbr_pos_transactions')->insertGetId(array_replace([
            'company_id' => $this->school->id, 'branch_id' => 17, 'invoice_number' => 'SYN-OLD-001',
            'invoice_mode' => 'fbr', 'fbr_status' => 'submitted', 'fbr_invoice_number' => 'SYNTHETIC-OLD-FISCAL',
            'payment_method' => 'cash', 'status' => 'completed', 'subtotal' => 1000, 'tax_amount' => 180, 'total_amount' => 1180,
            'customer_ntn' => 'PRIVATE-ID-CANARY', 'fbr_response' => '{"secret":"PRIVATE-RESPONSE-CANARY"}',
            'fbr_submission_hash' => 'PRIVATE-HASH-CANARY', 'share_token' => 'PRIVATE-SHARE-CANARY',
            'created_at' => now()->subMonth(), 'updated_at' => now()->subMonth(),
        ], $overrides));
        DB::table('fbr_pos_transaction_items')->insert(['transaction_id' => $id, 'item_name' => 'Synthetic original item',
            'quantity' => 1, 'unit_price' => 1000, 'subtotal' => 1000, 'tax_amount' => 180, 'total' => 1180]);
        return $id;
    }

    public function test_school_legacy_history_and_invoice_are_read_only_private_and_campus_scoped(): void
    {
        $this->legacySchema();
        app()->bind('currentBranchId', fn () => 17);
        $id = $this->legacyInvoice();
        $otherCampus = $this->legacyInvoice(['branch_id' => 18, 'invoice_number' => 'OTHER-CAMPUS-CANARY']);
        $otherTenant = $this->legacyInvoice(['company_id' => 999, 'invoice_number' => 'OTHER-TENANT-CANARY']);
        $before = DB::table('fbr_pos_transactions')->orderBy('id')->get()->toJson();
        $itemsBefore = DB::table('fbr_pos_transaction_items')->orderBy('id')->get()->toJson();
        $request = Request::create('/fbr-pos/school-fees/legacy-retail', 'GET');
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        $controller = app(FbrPosSchoolFeeController::class);
        foreach ([$controller->legacyIndex($request), $controller->legacyShow($request, $id)] as $response) {
            $html = $response->getContent();
            $this->assertStringContainsString('SYN-OLD-001', $html);
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            foreach (['OTHER-CAMPUS-CANARY', 'OTHER-TENANT-CANARY', 'PRIVATE-ID-CANARY', 'PRIVATE-RESPONSE-CANARY', 'PRIVATE-HASH-CANARY', 'PRIVATE-SHARE-CANARY'] as $secret) $this->assertStringNotContainsString($secret, $html);
            $this->assertStringNotContainsString('retry-fbr', $html);
            $this->assertStringNotContainsString('update-and-retry', $html);
            $this->assertStringNotContainsString('edit-failed', $html);
        }
        $detail = $controller->legacyShow($request, $id)->getContent();
        foreach (['Synthetic original item', '1180.00', '180.00', 'SYNTHETIC-OLD-FISCAL'] as $value) $this->assertStringContainsString($value, $detail);
        $this->expectHttpStatus(404, fn () => $controller->legacyShow($request, $otherCampus));
        $this->expectHttpStatus(404, fn () => $controller->legacyShow($request, $otherTenant));
        $this->assertSame($before, DB::table('fbr_pos_transactions')->orderBy('id')->get()->toJson());
        $this->assertSame($itemsBefore, DB::table('fbr_pos_transaction_items')->orderBy('id')->get()->toJson());
        $this->assertSame(0, DB::table('school_fee_receipts')->count());
        Http::assertNothingSent();
    }

    public function test_legacy_local_pin_is_scoped_to_company_user_and_expiry_not_global_flags(): void
    {
        $this->legacySchema();
        $this->school->forceFill(['confidential_pin' => \Illuminate\Support\Facades\Hash::make('4567')])->saveQuietly();
        app()->bind('currentBranchId', fn () => 17);
        $id = $this->legacyInvoice(['invoice_mode' => 'local', 'invoice_number' => 'SYN-LOCAL-SECRET']);
        $controller = app(FbrPosSchoolFeeController::class);
        $request = Request::create('/fbr-pos/school-fees/legacy-retail', 'GET');
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        session(['fbr_pos_pin_verified' => true, 'fbr_pos_pin_verified_at' => now()->timestamp]);
        $this->assertStringNotContainsString('SYN-LOCAL-SECRET', $controller->legacyIndex($request)->getContent());
        $this->expectHttpStatus(403, fn () => $controller->legacyShow($request, $id));
        $unlock = Request::create('/fbr-pos/school-fees/legacy-retail/unlock', 'POST', ['pin' => '4567']);
        $unlock->setLaravelSession(app('session.store'));
        app()->instance('request', $unlock);
        app('session.store')->forget(['fbr_pos_pin_verified', 'fbr_pos_pin_verified_at']);
        app()->instance('currentCompanyId', 999);
        $this->expectHttpStatus(403, fn () => $controller->legacyUnlock($unlock));
        app()->instance('currentCompanyId', $this->school->id);
        $controller->legacyUnlock($unlock);
        $this->assertFalse(session()->exists('fbr_pos_pin_verified'));
        $this->assertFalse(session()->exists('fbr_pos_pin_verified_at'));
        $this->assertStringContainsString('SYN-LOCAL-SECRET', $controller->legacyShow($request, $id)->getContent());
        $key = 'school_legacy_pin.'.$this->school->id.'.'.$this->cashier->id;
        $proof = session($key);
        session([$key => array_replace($proof, ['at' => now()->subHour()->timestamp])]);
        $this->expectHttpStatus(403, fn () => $controller->legacyShow($request, $id));
        session([$key => $proof]);
        $otherUser = User::withoutEvents(fn () => User::create([
            'company_id' => $this->school->id, 'name' => 'Other Cashier', 'email' => 'other@example.test',
            'password' => 'not-real', 'role' => 'employee', 'pos_role' => 'pos_cashier', 'is_active' => true,
        ]));
        $this->actingAs($otherUser, 'fbrpos');
        $this->expectHttpStatus(403, fn () => $controller->legacyShow($request, $id));
        $this->assertSame(0, DB::table('school_fee_receipts')->count());
        Http::assertNothingSent();
    }

    public function test_only_owner_can_read_unassigned_legacy_rows_separately_from_current_campus(): void
    {
        $this->legacySchema();
        app()->bind('currentBranchId', fn () => 17);
        $id = $this->legacyInvoice(['branch_id' => null]);
        $controller = app(FbrPosSchoolFeeController::class);
        $normal = Request::create('/fbr-pos/school-fees/legacy-retail', 'GET');
        $unassigned = Request::create('/fbr-pos/school-fees/legacy-retail', 'GET', ['unassigned' => '1']);
        $this->expectHttpStatus(404, fn () => $controller->legacyShow($normal, $id));
        $this->expectHttpStatus(403, fn () => $controller->legacyShow($unassigned, $id));
        $this->cashier->role = 'company_admin'; $this->cashier->pos_role = 'pos_admin';
        $this->actingAs($this->cashier, 'fbrpos');
        $this->assertStringContainsString('SYN-OLD-001', $controller->legacyShow($unassigned, $id)->getContent());
        $this->expectHttpStatus(404, fn () => $controller->legacyShow($normal, $id));
        Http::assertNothingSent();
    }

    public function test_unknown_method_fee_and_invalid_amounts_are_rejected(): void
    {
        foreach ([['payment_method' => 'crypto'], ['fee_head' => 'canteen'], ['amount' => '0'], ['amount' => '-1'], ['amount' => '1.001'], ['amount' => '1e3']] as $invalid) {
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
            'expected_cash_paisa' => 125050, 'expected_receipt_count' => 1, 'counted_cash' => '1250.50',
            'expected_branch_key' => app('currentBranchId') ?? 0,
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
        $this->assertTrue($school->fbr_reporting_enabled);
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
    private function schoolSettingsPost(string $method, array $data)
    {
        $request = Request::create('/fbr-pos/school-fees/settings', 'POST', $data);
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        return app(FbrPosSchoolFeeController::class)->$method($request);
    }

    public function test_fee_month_is_required_for_new_cash_but_legacy_saved_replay_remains_valid(): void
    {
        $data = $this->payload(['fee_period' => null]);
        try { $this->collect($data); $this->fail('Month required.'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('fee_period', $e->errors()); }
        $data['fee_period'] = '2026-11';
        $id = $this->collect($data)->getData(true)['receipt_id'];
        try { $this->collect(array_replace($data, ['fee_period' => '2026-12'])); $this->fail('Month immutable.'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('request_key', $e->errors()); }
        DB::table('school_fee_receipts')->where('id', $id)->update(['fee_period' => null]);
        unset($data['fee_period']);
        $this->assertSame($id, $this->collect($data)->getData(true)['receipt_id']);
        $this->assertSame(1, DB::table('school_fee_receipts')->count());
    }

    public function test_custom_fee_heads_are_tenant_scoped_and_receipt_labels_are_frozen(): void
    {
        $this->expectHttpStatus(403, fn () => $this->schoolSettingsPost('saveFeeHead', ['label' => 'Transport', 'active' => 1]));
        $this->cashier->role = 'company_admin';
        $this->cashier->pos_role = 'pos_admin';
        $this->actingAs($this->cashier, 'fbrpos');
        $this->schoolSettingsPost('saveFeeHead', ['label' => 'Transport', 'active' => 1]);
        $code = DB::table('school_fee_heads')->value('code');
        $data = $this->payload(['fee_head' => $code]);
        $id = $this->collect($data)->getData(true)['receipt_id'];
        $this->schoolSettingsPost('saveFeeHead', ['code' => $code, 'label' => 'Bus transport', 'active' => 0]);
        $this->assertSame($id, $this->collect($data)->getData(true)['receipt_id']);
        $receipt = app(FbrPosSchoolFeeController::class)->receipt($id)->getContent();
        $this->assertStringContainsString('Transport', $receipt);
        $this->assertStringNotContainsString('Bus transport', $receipt);
        try { $this->collect($this->payload(['fee_head' => $code])); $this->fail('Disabled head rejected.'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('fee_head', $e->errors()); }
        DB::table('school_fee_heads')->insert(['company_id' => 999, 'code' => 'foreign', 'label' => 'Private head', 'active' => true]);
        $this->expectHttpStatus(404, fn () => $this->schoolSettingsPost('saveFeeHead', ['code' => 'foreign', 'label' => 'Changed', 'active' => 1]));
        try { $this->collect($this->payload(['fee_head' => 'foreign'])); $this->fail('Foreign head rejected.'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('fee_head', $e->errors()); }
        Http::assertNothingSent();
    }

    public function test_counted_cash_variance_is_saved_once_and_changed_count_rejected(): void
    {
        $this->school->forceFill(['pos_cashier_dayclose' => true])->saveQuietly();
        $this->collect($this->payload());
        try { $this->closeSchoolDay(['counted_cash' => null]); $this->fail('Actual count required.'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('counted_cash', $e->errors()); }
        $closed = $this->closeSchoolDay(['variance_reason' => 'Synthetic cash shortage', 'counted_cash' => '1200.00'])->getData(true);
        $this->assertSame(120000, (int) $closed['counted_cash_paisa']);
        $this->assertSame(-5050, (int) $closed['variance_paisa']);
        $this->assertSame($closed['report_id'], $this->closeSchoolDay(['variance_reason' => 'Synthetic cash shortage', 'counted_cash' => '1200'])->getData(true)['report_id']);
        try { $this->closeSchoolDay(['counted_cash' => '1250.50']); $this->fail('Changed count rejected.'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('counted_cash', $e->errors()); }
        $this->assertSame(-5050, (int) DB::table('school_fee_day_closes')->value('variance_paisa'));
    }

    public function test_receipt_search_spans_dates_but_does_not_cross_campus_or_change_closing_totals(): void
    {
        $id = $this->collect($this->payload(['student_reference' => 'FIND-ME']))->getData(true)['receipt_id'];
        DB::table('school_fee_receipts')->where('id', $id)->update(['collected_at' => now()->subDays(10)]);
        $this->collect($this->payload(['student_reference' => 'TODAY-ONLY']));
        $request = Request::create('/fbr-pos/transactions', 'GET', ['search' => 'SF-'.$id, 'fee_period' => '2026-11']);
        app()->instance('request', $request);
        $html = app(FbrPosSchoolFeeController::class)->history($request)->getContent();
        $this->assertStringContainsString('FIND-ME', $html);
        $this->assertStringNotContainsString('TODAY-ONLY', $html);
        $this->school->forceFill(['pos_cashier_dayclose' => true])->saveQuietly();
        $html = app(FbrPosSchoolFeeController::class)->dayClose($request)->getContent();
        $this->assertStringNotContainsString('FIND-ME', $html);
        $this->assertStringContainsString('TODAY-ONLY', $html);
        $this->assertStringContainsString('data-paisa="125050"', $html);
    }

    public function test_school_campus_setup_is_owner_only_and_receipt_actor_and_campus_are_snapshotted(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('company_id'); $table->string('name');
            $table->string('city')->nullable(); $table->text('address')->nullable();
            $table->boolean('is_active')->default(true); $table->boolean('is_head_office')->default(false); $table->timestamps();
        });
        $this->expectHttpStatus(403, fn () => $this->schoolSettingsPost('addCampus', ['name' => 'New Campus']));
        $this->cashier->role = 'company_admin';
        $this->cashier->pos_role = 'pos_admin';
        $this->actingAs($this->cashier, 'fbrpos');
        $this->schoolSettingsPost('addCampus', ['name' => 'New Campus', 'city' => 'Synthetic City']);
        $branch = DB::table('branches')->first();
        $this->assertSame($this->school->id, (int) $branch->company_id);
        app()->forgetInstance(\App\Services\BranchContextService::class);
        $id = $this->collect($this->payload(['expected_branch_key' => $branch->id]))->getData(true)['receipt_id'];
        DB::table('branches')->where('id', $branch->id)->update(['name' => 'Changed Campus']);
        $this->cashier->name = 'Changed Cashier';
        $this->cashier->saveQuietly();
        $html = app(FbrPosSchoolFeeController::class)->receipt($id)->getContent();
        $this->assertStringContainsString('New Campus', $html);
        $this->assertStringContainsString('Synthetic Cashier', $html);
        $row = DB::table('school_fee_receipts')->where('id', $id)->first();
        $this->assertSame('New Campus', $row->campus_name);
        $this->assertSame('Synthetic Cashier', $row->cashier_name);
        app('session.store')->put('impersonation', ['readonly' => true]);
        $this->expectHttpStatus(403, fn () => $this->schoolSettingsPost('addCampus', ['name' => 'Forbidden']));
        $this->assertSame(1, DB::table('branches')->count());
    }

    public function test_first_campus_keeps_owner_readonly_access_to_unassigned_receipts_and_closed_snapshot(): void
    {
        $this->school->forceFill(['pos_cashier_dayclose' => true])->saveQuietly();
        $this->cashier->role = 'company_admin';
        $this->cashier->pos_role = 'pos_admin';
        $this->actingAs($this->cashier, 'fbrpos');
        $oldId = $this->collect($this->payload())->getData(true)['receipt_id'];
        $oldClose = $this->closeSchoolDay()->getData(true)['report_id'];
        Schema::create('branches', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('company_id'); $table->string('name');
            $table->string('city')->nullable(); $table->text('address')->nullable();
            $table->boolean('is_active')->default(true); $table->boolean('is_head_office')->default(false); $table->timestamps();
        });
        $this->schoolSettingsPost('addCampus', ['name' => 'First Campus']);
        app()->forgetInstance(\App\Services\BranchContextService::class);
        $controller = app(FbrPosSchoolFeeController::class);
        $request = Request::create('/fbr-pos/transactions', 'GET', ['unassigned' => '1']);
        app()->instance('request', $request);
        $html = $controller->history($request)->getContent();
        $this->assertStringContainsString('SF-'.$oldId, $html);
        $this->assertStringContainsString('/receipt?unassigned=1', $html);
        $this->assertSame(200, $controller->receipt($oldId)->getStatusCode());
        $html = $controller->dayClose($request)->getContent();
        $this->assertStringContainsString('SC-'.$oldClose, $html);
        $this->assertStringNotContainsString('id="school-close-form"', $html);
        $this->assertSame($oldClose, $this->closeSchoolDay(['unassigned' => '1', 'expected_branch_key' => 0])->getData(true)['report_id']);
        $this->expectHttpStatus(403, fn () => $this->collect($this->payload(['unassigned' => '1'])));
        app()->instance('request', Request::create('/fbr-pos/school-fees/'.$oldId.'/receipt'));
        $this->expectHttpStatus(404, fn () => $controller->receipt($oldId));
        $this->cashier->role = 'employee';
        $this->cashier->pos_role = 'pos_cashier';
        $this->actingAs($this->cashier, 'fbrpos');
        app()->forgetInstance(\App\Services\BranchContextService::class);
        app()->instance('request', $request);
        $this->expectHttpStatus(403, fn () => $controller->history($request));
        $this->assertNull(DB::table('school_fee_receipts')->where('id', $oldId)->value('branch_id'));
        $this->assertSame(0, (int) DB::table('school_fee_day_closes')->where('id', $oldClose)->value('branch_key'));
    }

    public function test_campus_setup_respects_existing_entitlement_limit(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('company_id'); $table->string('name');
            $table->boolean('is_active')->default(true); $table->boolean('is_head_office')->default(false); $table->timestamps();
        });
        Schema::table('companies', fn (Blueprint $table) => $table->integer('branch_limit_override')->nullable());
        $this->school->forceFill(['is_internal_account' => false, 'branch_limit_override' => 0])->saveQuietly();
        $this->cashier->role = 'company_admin';
        $this->cashier->pos_role = 'pos_admin';
        $this->actingAs($this->cashier, 'fbrpos');
        try { $this->schoolSettingsPost('addCampus', ['name' => 'Over quota']); $this->fail('Campus quota required.'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('name', $e->errors()); }
        $this->assertSame(0, DB::table('branches')->count());
    }

    public function test_owner_can_close_unassigned_cash_after_first_campus_without_touching_active_cash(): void
    {
        $this->cashier->role = 'company_admin';
        $this->cashier->pos_role = 'pos_admin';
        $this->actingAs($this->cashier, 'fbrpos');
        $oldId = $this->collect($this->payload())->getData(true)['receipt_id'];
        Schema::create('branches', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('company_id'); $table->string('name');
            $table->string('city')->nullable(); $table->text('address')->nullable();
            $table->boolean('is_active')->default(true); $table->boolean('is_head_office')->default(false); $table->timestamps();
        });
        $this->schoolSettingsPost('addCampus', ['name' => 'New Active Campus']);
        app()->forgetInstance(\App\Services\BranchContextService::class);
        $branchId = DB::table('branches')->value('id');
        $activeId = $this->collect($this->payload(['expected_branch_key' => $branchId, 'amount' => '200.00']))->getData(true)['receipt_id'];
        $request = Request::create('/fbr-pos/day-close', 'GET', ['unassigned' => '1']);
        app()->instance('request', $request);
        $html = app(FbrPosSchoolFeeController::class)->dayClose($request)->getContent();
        $this->assertStringContainsString('id="school-close-form"', $html);
        $this->assertStringContainsString('name="unassigned" value="1"', $html);
        $this->assertStringContainsString('name="expected_branch_key" value="0"', $html);
        $this->assertStringContainsString('data-paisa="125050"', $html);
        try { $this->closeSchoolDay(['unassigned' => '1', 'expected_branch_key' => $branchId]); $this->fail('Historical scope must be explicit.'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('expected_branch_key', $e->errors()); }
        $closed = $this->closeSchoolDay(['unassigned' => '1', 'expected_branch_key' => 0, 'variance_reason' => 'Synthetic cash shortage', 'counted_cash' => '1250.00'])->getData(true);
        $snapshot = (array) DB::table('school_fee_day_closes')->first();
        $this->assertSame(0, (int) $snapshot['branch_key']);
        $this->assertSame(125050, (int) $snapshot['cash_amount_paisa']);
        $this->assertSame(-50, (int) $snapshot['variance_paisa']);
        $this->assertSame(1, (int) $snapshot['receipt_count']);
        $this->assertSame($closed['report_id'], $this->closeSchoolDay(['unassigned' => '1', 'expected_branch_key' => 0, 'variance_reason' => 'Synthetic cash shortage', 'counted_cash' => '1250.00'])->getData(true)['report_id']);
        $this->assertNull(DB::table('school_fee_receipts')->where('id', $oldId)->value('branch_id'));
        $this->assertSame((int) $branchId, (int) DB::table('school_fee_receipts')->where('id', $activeId)->value('branch_id'));
        $this->assertSame(0, DB::table('school_fee_day_closes')->where('branch_key', $branchId)->count());
        app()->instance('request', Request::create('/fbr-pos/day-close'));
        $html = app(FbrPosSchoolFeeController::class)->dayClose(request())->getContent();
        $this->assertStringContainsString('data-paisa="20000"', $html);
        $this->assertStringContainsString('id="school-close-form"', $html);
        app('session.store')->put('impersonation', ['readonly' => true]);
        $this->expectHttpStatus(403, fn () => $this->closeSchoolDay(['unassigned' => '1', 'expected_branch_key' => 0]));
        app('session.store')->forget('impersonation');
        $this->cashier->role = 'employee'; $this->cashier->pos_role = 'pos_cashier';
        $this->actingAs($this->cashier, 'fbrpos');
        app()->forgetInstance(\App\Services\BranchContextService::class);
        $this->expectHttpStatus(403, fn () => $this->closeSchoolDay(['unassigned' => '1', 'expected_branch_key' => 0]));
        $this->assertSame($snapshot, (array) DB::table('school_fee_day_closes')->first());
    }


    public function test_multihead_partial_recorded_payments_keep_immutable_receipts_and_cash_only_close(): void
    {
        $this->school->forceFill(['pos_cashier_dayclose' => true])->saveQuietly();
        $payload = $this->payload(['student_name' => 'Synthetic Learner', 'amount' => '400', 'payment_method' => 'card', 'payment_reference' => 'SYN-CARD-001',
            'fee_lines' => [['fee_head'=>'tuition','amount'=>'1000'], ['fee_head'=>'exam','amount'=>'200']]]);
        $first = $this->collect($payload)->getData(true)['receipt_id'];
        $row = DB::table('school_fee_receipts')->find($first);
        $this->assertSame(120000, (int) $row->payable_paisa);
        $this->assertSame(80000, (int) $row->balance_paisa);
        $this->assertSame($first, $this->collect($payload)->getData(true)['receipt_id']);
        foreach (['payment_method'=>'bank', 'amount'=>'401', 'student_name'=>'Changed', 'account_id'=>$row->account_id] as $field=>$value) {
            try { $this->collect(array_replace($payload, [$field=>$value])); $this->fail('Changed retry accepted'); }
            catch (ValidationException $e) { $this->assertArrayHasKey('request_key', $e->errors()); }
        }
        $secondPayload = array_replace($payload, ['request_key'=>(string) Str::uuid(), 'account_id'=>$row->account_id, 'amount'=>'600', 'payment_method'=>'bank']);
        $second = $this->collect($secondPayload)->getData(true)['receipt_id'];
        $withoutAccount = $secondPayload; unset($withoutAccount['account_id']);
        try { $this->collect($withoutAccount); $this->fail('Dropped account accepted'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('request_key', $e->errors()); }
        try { $this->collect(array_replace($secondPayload, ['request_key'=>(string) Str::uuid(), 'amount'=>'201'])); $this->fail('Overpayment accepted'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('amount', $e->errors()); }
        $thirdPayload = array_replace($secondPayload, ['request_key'=>(string) Str::uuid(), 'amount'=>'200', 'payment_method'=>'cash']);
        $third = $this->collect($thirdPayload)->getData(true)['receipt_id'];
        $this->assertSame(80000, (int) DB::table('school_fee_receipts')->find($first)->balance_paisa);
        $this->assertSame(0, (int) DB::table('school_fee_receipts')->find($third)->balance_paisa);
        $close = $this->closeSchoolDay(['expected_cash_paisa'=>20000,'expected_receipt_count'=>3,'counted_cash'=>'199','variance_reason'=>'Synthetic shortage','handover_to'=>'Synthetic Supervisor','handover_note'=>'Sealed envelope'])->getData(true);
        $this->assertSame(20000, $close['cash_amount_paisa']); $this->assertSame(3, $close['receipt_count']);
        $snapshot = (array) DB::table('school_fee_day_closes')->first();
        $this->assertSame($second, $this->collect($secondPayload)->getData(true)['receipt_id']);
        $this->assertSame($snapshot, (array) DB::table('school_fee_day_closes')->first());
        $this->assertSame(3, DB::table('school_fee_receipts')->count());
        Http::assertNothingSent();
    }

    public function test_student_lookup_is_campus_and_company_scoped_and_variance_needs_reason(): void
    {
        $this->school->forceFill(['pos_cashier_dayclose' => true])->saveQuietly();
        $this->collect($this->payload(['student_reference'=>'SYN-LOOKUP','student_name'=>'Synthetic Learner']));
        $id = DB::table('school_fee_accounts')->value('id');
        $request = Request::create('/fbr-pos/school-fees', 'GET', ['student_search'=>'SYN-LOOKUP']);
        $html = app(FbrPosSchoolFeeController::class)->index($request)->getContent();
        $this->assertStringContainsString('Synthetic Learner', $html);
        DB::table('school_fee_accounts')->where('id',$id)->update(['company_id'=>$this->school->id+99]);
        $accounts = app(FbrPosSchoolFeeController::class)->index($request)->original->getData()['accounts'];
        $this->assertCount(0,$accounts);
        try { $this->closeSchoolDay(['counted_cash'=>'1']); $this->fail('Variance without reason accepted'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('variance_reason',$e->errors()); }
    }

    public function test_read_only_recovery_returns_settled_receipt_and_rejects_other_scope(): void
    {
        $payload = $this->payload(['payment_method'=>'bank', 'payment_reference'=>'SYN-BANK-001']);
        $id = $this->collect($payload)->getData(true)['receipt_id'];
        $snapshot = (array) DB::table('school_fee_receipts')->find($id);
        $request = Request::create('/fbr-pos/school-fees/recover','GET',['request_key'=>$payload['request_key'],'expected_branch_key'=>0]);
        $response = app(FbrPosSchoolFeeController::class)->recover($request);
        $this->assertSame($id, $response->getData(true)['receipt_id']);
        $this->assertSame($snapshot,(array) DB::table('school_fee_receipts')->find($id));
        DB::table('school_fee_receipts')->where('id',$id)->update(['branch_id'=>999]);
        $this->expectHttpStatus(404, fn()=>app(FbrPosSchoolFeeController::class)->recover($request));
        DB::table('school_fee_receipts')->where('id',$id)->update(['branch_id'=>null,'company_id'=>$this->school->id+1]);
        $this->expectHttpStatus(404, fn()=>app(FbrPosSchoolFeeController::class)->recover($request));
    }

    public function test_legacy_archive_report_sums_only_authorized_stored_filtered_records(): void
    {
        $this->legacySchema();
        app()->bind('currentBranchId', fn () => 17);
        $id = $this->legacyInvoice(['created_at'=>'2026-10-02 10:00:00','total_amount'=>200,'tax_amount'=>20]);
        $this->legacyInvoice(['created_at'=>'2026-09-02 10:00:00','total_amount'=>800,'tax_amount'=>80]);
        $this->legacyInvoice(['created_at'=>'2026-10-02 10:00:00','total_amount'=>900,'company_id'=>$this->school->id+99]);
        $request = Request::create('/fbr-pos/school-fees/legacy-retail','GET',['from'=>'2026-10-01','to'=>'2026-10-31']);
        $data = app(FbrPosSchoolFeeController::class)->legacyIndex($request)->original->getData();
        $this->assertSame(1,$data['archiveSummary']['count']);
        $this->assertEquals(200,$data['archiveSummary']['total']);
        $this->assertEquals(20,$data['archiveSummary']['tax']);
        $this->assertEquals(200,DB::table('fbr_pos_transactions')->where('id',$id)->value('total_amount'));
    }
    public function test_new_school_route_is_frozen_off_and_replay_never_backfills_legacy(): void
    {
        $data=$this->payload(['fiscal_route'=>'ims','sandbox_verified'=>true]);
        $id=$this->collect($data)->getData(true)['receipt_id'];
        $original=DB::table('school_fee_receipts')->find($id);
        $snapshot=json_decode($original->fiscal_route_snapshot,true);
        $this->assertSame('off',$snapshot['route']);
        $this->assertSame($this->school->id,$snapshot['company_id']);
        $this->assertSame('school',$snapshot['category']);
        $this->collect(array_replace($data,['fiscal_route'=>'di']));
        $this->assertEquals($original,DB::table('school_fee_receipts')->find($id));
        DB::table('school_fee_receipts')->where('id',$id)->update(['fiscal_route_snapshot'=>null]);
        $legacy=DB::table('school_fee_receipts')->find($id);
        $this->collect($data);
        $this->assertEquals($legacy,DB::table('school_fee_receipts')->find($id));
        Http::assertNothingSent();
    }


    public function test_non_cash_reference_is_required_and_immutable_but_legacy_retry_is_recoverable(): void
    {
        foreach (['bank', 'card'] as $method) {
            $payload = $this->payload(['payment_method' => $method]);
            try { $this->collect($payload); $this->fail('New noncash payment without reference accepted.'); }
            catch (ValidationException $e) { $this->assertArrayHasKey('payment_reference', $e->errors()); }
            $this->assertSame(0, DB::table('school_fee_receipts')->where('request_key', $payload['request_key'])->count());
            $payload['payment_reference'] = 'SYN-'.strtoupper($method);
            $id = $this->collect($payload)->getData(true)['receipt_id'];
            $before = (array) DB::table('school_fee_receipts')->find($id);
            $route = json_decode($before['fiscal_route_snapshot'], true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('off', $route['route']);
            $this->assertSame($this->school->id, $route['company_id']);
            $this->assertFalse($route['eligible']);
            $account = (array) DB::table('school_fee_accounts')->find($before['account_id']);
            $this->assertSame($id, $this->collect($payload)->getData(true)['receipt_id']);
            try { $this->collect(array_replace($payload, ['payment_reference' => 'CHANGED'])); $this->fail('Changed reference accepted.'); }
            catch (ValidationException $e) { $this->assertArrayHasKey('request_key', $e->errors()); }
            $this->assertSame($before, (array) DB::table('school_fee_receipts')->find($id));
            $this->assertSame($account, (array) DB::table('school_fee_accounts')->find($before['account_id']));
            $this->assertStringContainsString($payload['payment_reference'], app(FbrPosSchoolFeeController::class)->receipt($id)->getContent());
            // Simulate a pre-migration reference-less receipt, not a new payment.
            DB::table('school_fee_receipts')->where('id', $id)->update(['payment_reference'=>null]);
            unset($payload['payment_reference']);
            $this->assertSame($id, $this->collect($payload)->getData(true)['receipt_id']);
        }
        $this->assertSame(2, DB::table('school_fee_receipts')->count());
        Http::assertNothingSent();
    }

    public function test_default_fee_rate_is_future_only_and_additive_migration_preserves_history(): void
    {
        $id = $this->collect($this->payload())->getData(true)['receipt_id'];
        $before = (array) DB::table('school_fee_receipts')->find($id);
        $account = (array) DB::table('school_fee_accounts')->first();
        $this->cashier->role = 'company_admin'; $this->cashier->pos_role = 'pos_admin';
        $this->actingAs($this->cashier, 'fbrpos');
        $this->schoolSettingsPost('saveFeeHead', ['code'=>'tuition','label'=>'Tuition','active'=>1,'default_amount'=>'2500.75']);
        $this->assertSame(250075, (int) DB::table('school_fee_heads')->value('default_amount_paisa'));
        $this->schoolSettingsPost('saveFeeHead', ['code'=>'tuition','label'=>'Tuition revised','active'=>1]);
        $this->assertSame(250075, (int) DB::table('school_fee_heads')->value('default_amount_paisa'));
        $migration = require database_path('migrations/2026_11_16_130000_add_school_rates_and_payment_references.php');
        $migration->down(); $migration->up();
        $this->assertSame($before, (array) DB::table('school_fee_receipts')->find($id));
        $this->assertSame($account, (array) DB::table('school_fee_accounts')->first());
        $this->assertStringContainsString('default_amount_paisa', app(FbrPosSchoolFeeController::class)->index()->getContent());
    }

    public function test_owner_can_save_optional_ims_fields_without_rewriting_saved_receipts(): void
    {
        (require database_path('migrations/2027_01_01_000001_add_school_ims_fee_head_settings.php'))->up();
        $id = $this->collect($this->payload())->getData(true)['receipt_id'];
        $receipt = (array) DB::table('school_fee_receipts')->find($id);
        $settings = ['code' => 'tuition', 'label' => 'Tuition', 'active' => 1];
        $this->expectHttpStatus(403, fn () => $this->schoolSettingsPost('saveFeeHead', $settings + ['ims_pct_code' => '1234.5678']));

        $this->cashier->role = 'company_admin';
        $this->cashier->pos_role = 'pos_admin';
        $this->actingAs($this->cashier, 'fbrpos');
        $this->schoolSettingsPost('saveFeeHead', $settings + ['ims_pct_code' => '1234.5678', 'ims_tax_rate' => '0']);
        $this->schoolSettingsPost('saveFeeHead', $settings);

        $head = DB::table('school_fee_heads')->where('code', 'tuition')->first();
        $this->assertSame('12345678', $head->ims_pct_code);
        $this->assertNotNull($head->ims_tax_rate);
        $this->assertEquals(0, $head->ims_tax_rate);
        $this->schoolSettingsPost('saveFeeHead', $settings + ['ims_pct_code' => null, 'ims_tax_rate' => null]);
        $head = DB::table('school_fee_heads')->where('code', 'tuition')->first();
        $this->assertNull($head->ims_pct_code);
        $this->assertNull($head->ims_tax_rate);
        $this->assertSame($receipt, (array) DB::table('school_fee_receipts')->find($id));
    }

    public function test_school_dashboard_separates_total_cash_non_cash_and_all_date_outstanding(): void
    {
        $this->collect($this->payload(['amount'=>'100']));
        $this->collect($this->payload(['amount'=>'40','payment_method'=>'bank','payment_reference'=>'SYN-BANK', 'fee_lines'=>[['fee_head'=>'tuition','amount'=>'200']]]));
        $data = app(FbrPosSchoolFeeController::class)->dashboard()->original->getData();
        $this->assertSame(14000, $data['totalAmountPaisa']);
        $this->assertSame(10000, $data['cashAmountPaisa']);
        $this->assertSame(4000, $data['bankAmountPaisa']);
        $this->assertSame(0, $data['cardAmountPaisa']);
        $this->assertSame(16000, $data['outstandingPaisa']);
        DB::table('school_fee_accounts')->where('paid_paisa',4000)->update(['company_id'=>999]);
        $this->assertSame(0, app(FbrPosSchoolFeeController::class)->dashboard()->original->getData()['outstandingPaisa']);
    }

    public function test_school_history_filters_match_itemized_head_and_student_accounts_are_paginated(): void
    {
        $id = $this->collect($this->payload(['payment_method'=>'bank','payment_reference'=>'SYN-FILTER','fee_lines'=>[['fee_head'=>'tuition','amount'=>'1250'],['fee_head'=>'exam','amount'=>'0.50']]]))->getData(true)['receipt_id'];
        $this->collect($this->payload());
        $request = Request::create('/fbr-pos/transactions', 'GET', ['from'=>today()->toDateString(),'to'=>today()->toDateString(),'fee_head'=>'exam','payment_method'=>'bank','cashier'=>'Synthetic']);
        app()->instance('request', $request);
        $data = app(FbrPosSchoolFeeController::class)->history($request)->original->getData();
        $this->assertSame([$id], $data['receipts']->pluck('id')->all());
        for ($i=0; $i<31; $i++) $this->collect($this->payload(['student_reference'=>'SYN-PAGE-'.$i]));
        $request = Request::create('/fbr-pos/school-fees', 'GET', ['student_search'=>'SYN-PAGE']);
        app()->instance('request', $request);
        $data = app(FbrPosSchoolFeeController::class)->index($request)->original->getData();
        $this->assertSame(31,$data['accounts']->total());
        $this->assertCount(30,$data['accounts']);
        $request = Request::create('/fbr-pos/school-fees', 'GET', ['student_search'=>'SYN-PAGE','accounts_page'=>2]);
        app()->instance('request', $request);
        $this->assertCount(1,app(FbrPosSchoolFeeController::class)->index($request)->original->getData()['accounts']);
        Http::assertNothingSent();
    }
}
