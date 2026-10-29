<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\FbrPosTransaction;
use App\Models\PosTransaction;
use App\Models\User;
use App\Services\OptionalLocalBillArchive;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Optional support copies never replace or re-create fiscal/accounting records. */
class OptionalLocalBillArchiveTest extends TestCase
{
    private string $keyRoot;
    private OptionalLocalBillArchive $archive;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32))]);
        $this->keyRoot = sys_get_temp_dir().'/taxnest-optional-copy-test-'.bin2hex(random_bytes(8));
        config(['local_bill_archive.key_root' => $this->keyRoot]);
        Schema::create('companies', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('product_type');
            $t->boolean('pra_reporting_enabled')->default(false); $t->boolean('fbr_reporting_enabled')->default(false);
            $t->string('settings_sentinel')->default('KEEP-SAVED-DESIGN'); $t->softDeletes(); $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('role'); $t->string('pos_role')->nullable();
            $t->string('name'); $t->string('email')->nullable(); $t->string('password')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps();
        });
        foreach (['pos_transactions' => 'pra', 'fbr_pos_transactions' => 'fbr'] as $table => $prefix) {
            Schema::create($table, function (Blueprint $t) use ($prefix) {
                $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('branch_id')->nullable();
                $t->string('invoice_number'); $t->string('invoice_mode')->nullable(); $t->string('status')->default('completed');
                $t->string($prefix.'_status')->nullable(); $t->string($prefix.'_invoice_number')->nullable();
                $t->string('transaction_type')->default('sale'); $t->unsignedBigInteger('parent_transaction_id')->nullable();
                $t->date('business_date')->nullable(); $t->decimal('subtotal', 12, 2)->default(100);
                $t->decimal('tax_amount', 12, 2)->default(0); $t->decimal('total_amount', 12, 2)->default(100);
                $t->string('payment_method')->default('cash'); $t->text('customer_name')->nullable();
                $t->text('customer_phone')->nullable(); $t->text('delivery_address')->nullable();
                $t->text('notes')->nullable(); $t->text($prefix.'_response')->nullable(); $t->timestamps();
            });
        }
        (require database_path('migrations/2026_12_20_090000_create_optional_local_bill_archive.php'))->up();
        DB::table('companies')->insert([
            ['id' => 1, 'name' => 'Fictional PRA owner', 'product_type' => 'pos'],
            ['id' => 2, 'name' => 'Fictional second PRA', 'product_type' => 'pos'],
            ['id' => 3, 'name' => 'Fictional FBR owner', 'product_type' => 'fbrpos'],
        ]);
        $this->archive = app(OptionalLocalBillArchive::class);
    }

    protected function tearDown(): void
    {
        try { if (isset($this->keyRoot) && is_dir($this->keyRoot) && !is_link($this->keyRoot)) File::deleteDirectory($this->keyRoot); }
        finally { \Illuminate\Support\Carbon::setTestNow(); parent::tearDown(); }
    }

    private function owner(int $company = 1): User
    {
        return (new User)->forceFill(['id' => 100 + $company, 'company_id' => $company, 'role' => 'company_admin', 'name' => 'Fictional owner', 'is_active' => true]);
    }

    private function bill(array $attrs = [], string $product = 'pos')
    {
        $prefix = $product === 'pos' ? 'pra' : 'fbr';
        $model = $product === 'pos' ? new PosTransaction : new FbrPosTransaction;
        $row = array_merge(['company_id' => $product === 'pos' ? 1 : 3, 'branch_id' => 10,
            'invoice_number' => 'L-'.bin2hex(random_bytes(4)), 'invoice_mode' => 'local', $prefix.'_status' => 'local',
            'status' => 'completed', 'transaction_type' => 'sale', 'subtotal' => 100, 'tax_amount' => 0,
            'total_amount' => 100, 'payment_method' => 'cash', 'customer_name' => 'PRIVATE CUSTOMER',
            'customer_phone' => 'PRIVATE PHONE', 'delivery_address' => 'PRIVATE ADDRESS', 'notes' => 'PRIVATE NOTE',
            $prefix.'_response' => 'PRIVATE REGULATOR PAYLOAD', 'created_at' => now(), 'updated_at' => now()], $attrs);
        $id = DB::table($model->getTable())->insertGetId($row);
        return $model->newFromBuilder((array) DB::table($model->getTable())->find($id));
    }

    private function items(): array
    {
        return [(object) ['item_name' => 'Fictional exempt meal', 'item_type' => 'product', 'item_id' => 25,
            'quantity' => '2.000', 'unit_price' => '50.00', 'subtotal' => '100.00', 'tax_amount' => '0.00',
            'total_amount' => '100.00', 'is_exempt' => true, 'private_note' => 'PRIVATE ITEM NOTE']];
    }

    private function capture($bill, string $product = 'pos'): ?int
    {
        return DB::transaction(fn () => $this->archive->capture($bill, $product, $this->items()));
    }

    private function grant(int $company = 1, int $days = 30): int
    {
        return $this->archive->grant($company, $this->owner($company), $days, now()->addDays(7));
    }

    public function test_default_off_and_prospective_consent_do_not_copy_live_history_or_change_settings(): void
    {
        $bill = $this->bill();
        $original = (array) DB::table('companies')->find(1);
        $this->assertNull($this->capture($bill));
        $this->assertSame(0, DB::table('support_bill_archives')->count());
        $grant = $this->grant();
        $this->assertGreaterThan(0, $grant);
        $this->assertSame(0, DB::table('support_bill_archives')->count());
        $this->assertSame($original, (array) DB::table('companies')->find(1));
        $this->assertSame(1, DB::table('pos_transactions')->count());
        $this->assertFalse((bool) (DB::table('support_bill_archive_prefs')->where('company_id', 2)->value('enabled') ?? false));
    }

    public function test_capture_encrypts_minimized_facts_and_repeated_capture_keeps_one_original_copy(): void
    {
        $this->grant(); $bill = $this->bill();
        $id = $this->capture($bill);
        $this->assertGreaterThan(0, $id);
        $row = DB::table('support_bill_archives')->find($id);
        $before = (array) $row;
        $this->assertStringNotContainsString($bill->invoice_number, $row->encrypted_payload);
        $payload = $this->archive->readPayload($row);
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString($bill->invoice_number, $json);
        $this->assertStringContainsString('Fictional exempt meal', $json);
        foreach (['PRIVATE CUSTOMER', 'PRIVATE PHONE', 'PRIVATE ADDRESS', 'PRIVATE NOTE', 'PRIVATE ITEM NOTE', 'PRIVATE REGULATOR PAYLOAD'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
        $this->assertSame($id, $this->capture($bill));
        $this->assertSame($before, (array) DB::table('support_bill_archives')->find($id));
        $this->assertSame(1, DB::table('support_bill_archives')->count());
        $this->assertSame('100.00', (string) $bill->total_amount);
    }

    public function test_fiscal_pipeline_returns_and_nonconsenting_tenants_are_never_copied(): void
    {
        $this->grant();
        foreach ([['pra_invoice_number' => 'FISCAL-IDENTITY'], ['pra_status' => 'pending'], ['pra_status' => 'failed'],
            ['pra_status' => 'submitted'], ['pra_status' => 'verification_pending'], ['transaction_type' => 'return'],
            ['company_id' => 2], ['status' => 'draft']] as $changes) {
            $this->assertNull($this->capture($this->bill($changes)));
        }
        $this->assertSame(0, DB::table('support_bill_archives')->count());
        // Existing reporting-OFF finals retain their original persisted mode/status.
        $final = $this->bill(['invoice_mode' => 'pra', 'pra_status' => null]);
        $this->assertGreaterThan(0, $this->capture($final));
        $this->assertSame('pra', DB::table('pos_transactions')->where('id', $final->id)->value('invoice_mode'));
        $legacyLocal = $this->bill(['invoice_mode' => 'local', 'pra_status' => null]);
        $this->assertGreaterThan(0, $this->capture($legacyLocal));
        $this->assertNull(DB::table('pos_transactions')->where('id', $legacyLocal->id)->value('pra_status'));
        $this->grant(3);
        $this->assertGreaterThan(0, $this->capture($this->bill([], 'fbrpos'), 'fbrpos'));
        $this->assertNull($this->capture($this->bill(['fbr_invoice_number' => 'FBR-FISCAL'], 'fbrpos'), 'fbrpos'));
    }

    public function test_capture_and_deletion_rollback_as_one_transaction(): void
    {
        $this->grant(); $bill = $this->bill();
        try {
            DB::transaction(function () use ($bill) {
                $this->archive->capture($bill, 'pos', $this->items());
                DB::table('pos_transactions')->where('id', $bill->id)->delete();
                throw new \RuntimeException('Synthetic downstream stock/quota failure');
            });
            $this->fail('Synthetic failure must escape');
        } catch (\RuntimeException $e) { $this->assertSame('Synthetic downstream stock/quota failure', $e->getMessage()); }
        $this->assertSame(0, DB::table('support_bill_archives')->count());
        $this->assertTrue(DB::table('pos_transactions')->where('id', $bill->id)->exists());
    }

    public function test_missing_promised_key_prevents_deletion_instead_of_silently_dropping_support_copy(): void
    {
        $this->grant(); $bill = $this->bill();
        foreach (glob($this->keyRoot.'/*') ?: [] as $file) unlink($file);
        try {
            DB::transaction(function () use ($bill) {
                $this->archive->capture($bill, 'pos', $this->items());
                DB::table('pos_transactions')->where('id', $bill->id)->delete();
            });
            $this->fail('Missing key must fail closed');
        } catch (\RuntimeException $e) { $this->assertNotSame('', $e->getMessage()); }
        $this->assertTrue(DB::table('pos_transactions')->where('id', $bill->id)->exists());
        $this->assertSame(0, DB::table('support_bill_archives')->count());
    }

    public function test_stop_preserves_original_expiry_purge_revokes_keys_and_sql_restore_cannot_revive_copy(): void
    {
        $this->grant(); $id = $this->capture($this->bill());
        $copy = (array) DB::table('support_bill_archives')->find($id);
        $oldKeyring = [];
        foreach (glob($this->keyRoot.'/*.key') as $keyFile) $oldKeyring[basename($keyFile)] = file_get_contents($keyFile);
        $prefs = (array) DB::table('support_bill_archive_prefs')->where('company_id', 1)->first();
        $this->archive->stop(1, $this->owner());
        $this->assertNull($this->capture($this->bill()));
        $this->assertSame($copy['expires_at'], DB::table('support_bill_archives')->find($id)->expires_at);
        $this->assertNotEmpty($this->archive->readPayload((object) $copy));
        $this->archive->purge(1, $this->owner());
        $this->assertSame(0, DB::table('support_bill_archives')->count());
        DB::table('support_bill_archives')->insert($copy);
        DB::table('support_bill_archive_prefs')->where('company_id', 1)->update($prefs);
        try { $this->archive->readPayload((object) $copy); $this->fail('SQL restore must not restore a destroyed external key'); }
        catch (\RuntimeException $e) { $this->assertNotSame('', $e->getMessage()); }
        // A restored whole-volume image can bring back old keys as well as
        // ciphertext. Prove the documented pre-exposure quarantine, rather than
        // implying SQL-only crypto-erasure protects an arbitrary old image.
        foreach ($oldKeyring as $name => $contents) {
            file_put_contents($this->keyRoot.'/'.$name, $contents);
            chmod($this->keyRoot.'/'.$name, 0600);
        }
        $this->assertNotEmpty($this->archive->readPayload((object) $copy));
        $financialBefore = DB::table('pos_transactions')->get()->toJson();
        $this->archive->quarantineAfterRestore();
        $this->assertSame([], glob($this->keyRoot.'/*.key'));
        $this->assertSame($financialBefore, DB::table('pos_transactions')->get()->toJson());
        $this->assertSame(0, DB::table('support_bill_archives')->count());
        $this->assertFalse((bool) DB::table('support_bill_archive_prefs')->where('company_id', 1)->value('enabled'));
        $this->assertGreaterThanOrEqual(3, DB::table('support_bill_archive_consents')->where('company_id', 1)->count());
    }

    public function test_expiry_is_immediate_cleanup_bounded_and_retry_safe(): void
    {
        $this->grant(1, 1);
        $ids = [$this->capture($this->bill()), $this->capture($this->bill()), $this->capture($this->bill())];
        $this->travel(2)->days();
        $this->assertSame(0, $this->archive->available(1)->count());
        try { $this->archive->readPayload(DB::table('support_bill_archives')->find($ids[0])); $this->fail('Expired copy must not export'); }
        catch (\RuntimeException $e) { $this->assertNotSame('', $e->getMessage()); }
        $this->assertSame(1, $this->archive->cleanup(1));
        $this->assertSame(2, DB::table('support_bill_archives')->count());
        $this->assertSame(2, $this->archive->cleanup(100));
        $this->assertSame(0, $this->archive->cleanup(100));
        $this->assertSame(3, DB::table('pos_transactions')->count());
    }

    public function test_expired_consent_stops_new_capture_without_changing_existing_copy(): void
    {
        $this->grant(); $id = $this->capture($this->bill());
        $this->travel(8)->days();
        $this->assertNull($this->capture($this->bill()));
        $this->assertSame(1, $this->archive->available(1)->count());
        $this->assertNotEmpty($this->archive->readPayload(DB::table('support_bill_archives')->find($id)));
    }

    public function test_ciphertext_tamper_does_not_fall_back_to_unencrypted_facts(): void
    {
        $this->grant(); $id = $this->capture($this->bill());
        $row = DB::table('support_bill_archives')->find($id);
        DB::table('support_bill_archives')->where('id', $id)->update(['encrypted_payload' => 'corrupted']);
        $this->expectException(\RuntimeException::class);
        $this->archive->readPayload($row);
    }

    public function test_owner_scope_rejects_other_company_manager_and_admin_impersonation(): void
    {
        foreach ([$this->owner(2), (new User)->forceFill(['id' => 7, 'company_id' => 1, 'role' => 'employee', 'pos_role' => 'manager'])] as $user) {
            try { $this->archive->grant(1, $user, 30, now()->addDays(7)); $this->fail('Only the real tenant owner may consent'); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        }
        $this->actingAs((new AdminUser)->forceFill(['id' => 900, 'role' => 'super_admin']), 'admin');
        try { $this->grant(); $this->fail('Admin impersonation cannot grant consent'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        $this->assertSame(0, DB::table('support_bill_archive_consents')->count());
    }
    private function request(string $path, array $input = []): \Illuminate\Http\Request
    {
        $request = \Illuminate\Http\Request::create($path, $input ? 'POST' : 'GET', $input);
        $request->setLaravelSession(app('session.store'));
        return $request;
    }

    public function test_owner_update_requires_explicit_confirmation_and_explicit_valid_retention(): void
    {
        auth('pos')->setUser($this->owner());
        $controller = new \App\Http\Controllers\OptionalLocalBillArchiveController;
        foreach ([['action' => 'grant', 'retention_days' => 30, 'valid_until' => now()->addDays(7)->toDateString()],
            ['action' => 'grant', 'confirm' => 1],
            ['action' => 'grant', 'confirm' => 1, 'retention_days' => 366, 'valid_until' => now()->addDays(7)->toDateString()],
            ['action' => 'grant', 'confirm' => 1, 'retention_days' => 30, 'valid_until' => now()->subDay()->toDateString()]] as $input) {
            try { $controller->update($this->request('/pos/local-support-copies', $input), $this->archive); $this->fail('Invalid owner choice accepted'); }
            catch (\Illuminate\Validation\ValidationException $e) { $this->assertNotEmpty($e->errors()); }
        }
        $this->assertSame(0, DB::table('support_bill_archive_consents')->count());
        $response = $controller->update($this->request('/pos/local-support-copies', [
            'action' => 'grant', 'confirm' => 1, 'retention_days' => 90, 'valid_until' => now()->addDays(7)->toDateString(),
        ]), $this->archive);
        $this->assertSame(302, $response->getStatusCode());
        $event = DB::table('support_bill_archive_consents')->where('action', 'grant')->first();
        $this->assertSame(101, (int) $event->actor_id);
        $this->assertSame(90, (int) $event->retention_days);
        $this->assertSame(OptionalLocalBillArchive::POLICY, $event->policy_version);
        $this->assertSame(OptionalLocalBillArchive::WORDING, $event->wording);
    }

    public function test_controller_exports_are_owner_product_tenant_branch_scoped_and_admin_access_audited(): void
    {
        $this->grant(); $id = $this->capture($this->bill());
        $this->grant(2); $otherId = $this->capture($this->bill(['company_id' => 2]));
        $this->grant(3); $fbrId = $this->capture($this->bill([], 'fbrpos'), 'fbrpos');
        $differentBranchId = $this->capture($this->bill(['branch_id' => 20]));
        auth('pos')->setUser($this->owner());
        $this->mock(\App\Services\BranchContextService::class)->shouldReceive('applyToQuery')
            ->andReturnUsing(fn ($query, $column) => $query->where($column, 10));
        $controller = new \App\Http\Controllers\OptionalLocalBillArchiveController;
        $request = $this->request('/pos/local-support-copies/'.$id.'/download');
        $before = DB::table('pos_transactions')->get()->map(fn ($row) => (array) $row)->all();
        $response = $controller->download($request, $id, $this->archive);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertSame(1, json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR)['company_id']);
        foreach ([$otherId, $fbrId, $differentBranchId] as $denied) {
            try { $controller->download($request, $denied, $this->archive); $this->fail('Wrong scope exported'); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(404, $e->getStatusCode()); }
        }
        auth('admin')->setUser((new AdminUser)->forceFill(['id' => 900, 'role' => 'super_admin']));
        $response = $controller->adminDownload(2, $otherId, $this->archive);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR)['company_id']);
        try { $controller->adminDownload(1, $otherId, $this->archive); $this->fail('Admin mismatched company copy exported'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(404, $e->getStatusCode()); }
        $this->assertSame(1, DB::table('support_bill_archive_access')->where('actor_guard', 'admin')->where('action', 'export')->where('company_id', 2)->count());
        $this->assertSame($before, DB::table('pos_transactions')->get()->map(fn ($row) => (array) $row)->all());
        auth('admin')->setUser((new AdminUser)->forceFill(['id' => 901, 'role' => 'support']));
        try { $controller->adminDownload(1, $id, $this->archive); $this->fail('Non-superadmin read allowed'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
    }

    public function test_shared_form_has_no_implicit_opt_in_and_separates_stop_from_purge(): void
    {
        $html = view('shared.optional-local-bill-archive', [
            'company' => (new \App\Models\Company)->forceFill(['id' => 1, 'name' => 'Fictional owner']),
            'product' => 'pos', 'enabled' => false, 'grant' => null, 'ownerCanChange' => true,
            'copies' => new \Illuminate\Pagination\LengthAwarePaginator([], 0, 25), 'consents' => collect(),
            'errors' => new \Illuminate\Support\ViewErrorBag,
        ])->render();
        $this->assertStringContainsString('Retention <strong>OFF</strong>', $html);
        $this->assertStringContainsString('Stop future copies; keep existing until expiry', $html);
        $this->assertStringContainsString('Stop future copies and purge existing optional copies', $html);
        $this->assertStringNotContainsString(' checked', $html);
        $this->assertStringContainsString('No old deleted records can be recovered', $html);
    }

    public function test_oversized_copy_aborts_capture_and_keyring_symlink_is_rejected(): void
    {
        $this->grant(); $bill = $this->bill();
        config(['local_bill_archive.max_payload_bytes' => 10]);
        try { $this->capture($bill); $this->fail('Size limit silently ignored'); }
        catch (\RuntimeException $e) { $this->assertNotSame('', $e->getMessage()); }
        $this->assertSame(0, DB::table('support_bill_archives')->count());
        $this->assertTrue(DB::table('pos_transactions')->where('id', $bill->id)->exists());
        $alias = $this->keyRoot.'-alias';
        if (!function_exists('symlink') || !@symlink($this->keyRoot, $alias)) {
            $this->markTestSkipped('Environment cannot create a symlink fixture');
        }
        try {
            config(['local_bill_archive.key_root' => $alias]);
            try { $this->grant(); $this->fail('Symlinked keyring accepted'); }
            catch (\RuntimeException $e) { $this->assertNotSame('', $e->getMessage()); }
        } finally { unlink($alias); }
        $this->assertSame(1, DB::table('support_bill_archive_consents')->where('action', 'grant')->count());
    }

    public function test_unavailable_export_is_audited_and_never_returns_raw_ciphertext(): void
    {
        $this->grant(); $id = $this->capture($this->bill());
        auth('pos')->setUser($this->owner());
        foreach (glob($this->keyRoot.'/*') ?: [] as $file) unlink($file);
        $controller = new \App\Http\Controllers\OptionalLocalBillArchiveController;
        $response = $controller->download($this->request('/pos/local-support-copies/'.$id.'/download'), $id, $this->archive);
        $this->assertSame(410, $response->getStatusCode());
        $this->assertStringNotContainsString('encrypted_payload', $response->getContent());
        $this->assertStringNotContainsString('PRIVATE', $response->getContent());
        $this->assertSame(1, DB::table('support_bill_archive_access')->where('action', 'unavailable')->where('archive_id', $id)->count());
        $this->assertSame(0, DB::table('support_bill_archive_access')->where('action', 'export')->count());
    }

    public function test_capture_rejects_nontransactional_call_and_wrong_product_table(): void
    {
        $this->grant(); $bill = $this->bill();
        try { $this->archive->capture($bill, 'pos', $this->items()); $this->fail('Non-atomic capture allowed'); }
        catch (\RuntimeException $e) { $this->assertNotSame('', $e->getMessage()); }
        try { $this->capture($bill, 'fbrpos'); $this->fail('Wrong product source allowed'); }
        catch (\RuntimeException $e) { $this->assertNotSame('', $e->getMessage()); }
        $this->assertSame(0, DB::table('support_bill_archives')->count());
    }

    public function test_stale_bill_and_stale_copy_objects_cannot_override_current_fiscal_or_purge_state(): void
    {
        $this->grant(); $bill = $this->bill();
        DB::table('pos_transactions')->where('id', $bill->id)->update(['pra_invoice_number' => 'CALLBACK-WON']);
        $this->assertNull($this->capture($bill));
        $this->assertSame(0, DB::table('support_bill_archives')->count());
        $id = $this->capture($this->bill());
        $staleCopy = DB::table('support_bill_archives')->find($id);
        $this->archive->purge(1, $this->owner());
        try { $this->archive->readPayload($staleCopy); $this->fail('Purge bypassed through stale object'); }
        catch (\RuntimeException $e) { $this->assertNotSame('', $e->getMessage()); }
    }

    public function test_cleanup_limits_and_restore_command_confirmation_are_enforced(): void
    {
        $this->grant(); $id = $this->capture($this->bill());
        $this->artisan('local-support-copies:cleanup', ['--limit' => 0])->assertExitCode(1);
        $this->artisan('local-support-copies:restore-quarantine')->assertExitCode(1);
        $this->assertNotEmpty($this->archive->readPayload(DB::table('support_bill_archives')->find($id)));
        $this->artisan('local-support-copies:restore-quarantine', ['--confirm' => 'Quarantine optional copies'])->assertExitCode(0);
        $this->assertSame(0, DB::table('support_bill_archives')->count());
        $this->assertFalse((bool) DB::table('support_bill_archive_prefs')->where('company_id', 1)->value('enabled'));
        $this->assertSame(1, DB::table('pos_transactions')->count());
    }

}
