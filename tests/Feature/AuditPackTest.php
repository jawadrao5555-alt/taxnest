<?php

namespace Tests\Feature;

use App\Jobs\BuildAuditPackJob;
use App\Models\AuditPack;
use App\Models\Invoice;
use App\Models\User;
use App\Services\AuditPackBuilderService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * FBR AUDIT PACK (Compliance Pack) — feature coverage
 *
 *  1. Builder service assembles a complete ZIP in resumable chunks
 *     (PDFs + register CSV/XLSX + audit-trail CSV + FBR log CSV + README)
 *     and records integrity pass/fail counts correctly.
 *  2. Role gating: employees get 403 on the compliance page.
 *  3. Store validations: inverted range, empty range, one-active-pack rule.
 *  4. Store dispatches the background job and stamps the file path.
 *  5. Cross-company isolation on the status endpoint (403).
 */
class AuditPackTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('ntn')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('product_type')->nullable();
            $table->string('status')->default('approved');
            $table->string('company_status')->default('active');
            $table->boolean('force_watermark')->default(false);
            $table->boolean('onboarding_completed')->default(true);
            $table->boolean('is_internal_account')->default(false);
            $table->string('fbr_registration_no')->nullable();
            $table->string('logo_path')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('username')->nullable();
            $table->string('phone')->nullable();
            $table->string('password');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('dark_mode')->default(false);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('invoice_number')->nullable();
            $table->string('internal_invoice_number')->nullable();
            $table->string('fbr_invoice_number')->nullable();
            $table->string('share_uuid')->nullable();
            $table->date('invoice_date')->nullable();
            $table->string('status')->default('draft');
            $table->string('fbr_status')->nullable();
            $table->string('document_type')->nullable();
            $table->string('buyer_name')->nullable();
            $table->string('buyer_ntn')->nullable();
            $table->string('buyer_cnic')->nullable();
            $table->string('buyer_address')->nullable();
            $table->string('buyer_phone')->nullable();
            $table->string('buyer_registration_type')->nullable();
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('total_sales_tax', 15, 2)->default(0);
            $table->decimal('total_value_excluding_st', 15, 2)->default(0);
            $table->decimal('wht_rate', 8, 2)->nullable();
            $table->decimal('wht_amount', 15, 2)->nullable();
            $table->decimal('net_receivable', 15, 2)->nullable();
            $table->boolean('wht_locked')->default(false);
            $table->boolean('is_fbr_processing')->default(false);
            $table->timestamp('fbr_submission_date')->nullable();
            $table->text('qr_data')->nullable();
            $table->string('integrity_hash')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id');
            $table->string('item_name')->nullable();
            $table->string('description')->nullable();
            $table->string('hs_code')->nullable();
            $table->string('uom')->nullable();
            $table->decimal('quantity', 15, 2)->default(0);
            $table->decimal('price', 15, 2)->default(0);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('value_excluding_st', 15, 2)->nullable();
            $table->decimal('sales_tax', 15, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->string('entity_type')->nullable();
            $table->string('entity_id')->nullable();
            $table->text('old_values')->nullable();
            $table->text('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('sha256_hash')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('fbr_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('environment_used')->nullable();
            $table->string('status')->nullable();
            $table->string('failure_type')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->unsignedInteger('retry_count')->nullable();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type')->nullable();
            $table->string('title')->nullable();
            $table->text('message')->nullable();
            $table->boolean('read')->default(false);
            $table->string('link')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_packs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->date('date_from');
            $table->date('date_to');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('total_invoices')->default(0);
            $table->unsignedInteger('processed_invoices')->default(0);
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedInteger('integrity_passed')->default(0);
            $table->unsignedInteger('integrity_failed')->default(0);
            $table->unsignedInteger('integrity_missing')->default(0);
            $table->text('integrity_failed_list')->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    protected function makeCompany(array $overrides = []): int
    {
        return (int) DB::table('companies')->insertGetId(array_merge([
            'name' => 'Audit Test Traders',
            'ntn' => '1234567-8',
            'email' => 'audit@test.pk',
            'product_type' => 'di',
            'status' => 'approved',
            'company_status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    protected function makeUser(int $companyId, string $role = 'company_admin', string $email = 'admin@audit.pk'): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => ucfirst($role) . ' User',
            'email' => $email,
            'password' => Hash::make('secret-123'),
            'company_id' => $companyId,
            'role' => $role,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::find($id);
    }

    /**
     * Insert a completed invoice + one item. Returns the invoice id.
     */
    protected function makeInvoice(int $companyId, string $number, string $date, bool $validHash): int
    {
        $createdAt = $date . ' 10:00:00';

        $id = DB::table('invoices')->insertGetId([
            'company_id' => $companyId,
            'invoice_number' => $number,
            'internal_invoice_number' => $number,
            'fbr_invoice_number' => null, // keeps PDF path off the QR/GD branch
            'invoice_date' => $date,
            'status' => 'locked',
            'fbr_status' => 'production',
            'buyer_name' => 'Buyer ' . $number,
            'total_amount' => 1180.00,
            'total_sales_tax' => 180.00,
            'total_value_excluding_st' => 1000.00,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        DB::table('invoice_items')->insert([
            'invoice_id' => $id,
            'item_name' => 'Test Product',
            'description' => 'Test Product',
            'hs_code' => '0101.2100',
            'uom' => 'PCS',
            'quantity' => 10,
            'price' => 100.00,
            'tax' => 180.00,
            'value_excluding_st' => 1000.00,
            'sales_tax' => 180.00,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $invoice = Invoice::withoutGlobalScopes()->find($id);
        $hash = \App\Services\IntegrityHashService::generate($invoice);
        DB::table('invoices')->where('id', $id)->update([
            'integrity_hash' => $validHash ? $hash : 'tampered-' . $hash,
        ]);

        return $id;
    }

    // ---------------------------------------------------------------
    // 1. Builder service end-to-end
    // ---------------------------------------------------------------

    public function test_builder_assembles_complete_zip_in_chunks(): void
    {
        Storage::fake('local');

        $companyId = $this->makeCompany();
        $user = $this->makeUser($companyId);

        $this->makeInvoice($companyId, 'AUDIT-0001', '2026-03-05', true);
        $this->makeInvoice($companyId, 'AUDIT-0002', '2026-03-18', false); // tampered hash

        // Period audit-trail + FBR submission log entries.
        DB::table('audit_logs')->insert([
            ['company_id' => $companyId, 'user_id' => $user->id, 'action' => 'invoice_locked', 'entity_type' => 'Invoice', 'entity_id' => '1', 'old_values' => null, 'new_values' => null, 'ip_address' => '127.0.0.1', 'sha256_hash' => str_repeat('a', 64), 'created_at' => '2026-03-05 10:05:00'],
            ['company_id' => $companyId, 'user_id' => null, 'action' => 'fbr_submission', 'entity_type' => 'Invoice', 'entity_id' => '2', 'old_values' => null, 'new_values' => null, 'ip_address' => null, 'sha256_hash' => str_repeat('b', 64), 'created_at' => '2026-03-18 11:00:00'],
        ]);
        DB::table('fbr_logs')->insert([
            ['invoice_id' => 1, 'company_id' => $companyId, 'environment_used' => 'production', 'status' => 'success', 'failure_type' => null, 'response_time_ms' => 850, 'retry_count' => 0, 'created_at' => '2026-03-05 10:04:00', 'updated_at' => '2026-03-05 10:04:00'],
            ['invoice_id' => 2, 'company_id' => $companyId, 'environment_used' => 'production', 'status' => 'failed', 'failure_type' => 'timeout', 'response_time_ms' => 30000, 'retry_count' => 1, 'created_at' => '2026-03-18 10:59:00', 'updated_at' => '2026-03-18 10:59:00'],
        ]);

        $pack = AuditPack::create([
            'company_id' => $companyId,
            'user_id' => $user->id,
            'date_from' => '2026-03-01',
            'date_to' => '2026-03-31',
            'status' => 'pending',
        ]);

        // Drive the resumable chunk loop exactly like the job / poll fallback.
        $guard = 0;
        do {
            $result = AuditPackBuilderService::processNextChunk($pack->fresh());
            $this->assertNotSame('busy', $result, 'Single-process build must never see a busy claim.');
        } while ($result === 'continue' && ++$guard < 10);

        $this->assertSame('done', $result);

        $pack->refresh();
        $this->assertSame('ready', $pack->status, 'Pack should finish ready. Error: ' . ($pack->error_message ?? '-'));
        $this->assertSame(100, (int) $pack->progress);
        $this->assertSame(2, (int) $pack->total_invoices);
        $this->assertSame(2, (int) $pack->processed_invoices);
        $this->assertSame(1, (int) $pack->integrity_passed);
        $this->assertSame(1, (int) $pack->integrity_failed);
        $this->assertSame(0, (int) $pack->integrity_missing);
        $this->assertStringContainsString('AUDIT-0002', (string) $pack->integrity_failed_list);
        $this->assertNull($pack->error_message, 'No PDF errors expected: ' . ($pack->error_message ?? ''));
        $this->assertNotNull($pack->completed_at);
        $this->assertGreaterThan(0, (int) $pack->file_size);

        // Inspect the ZIP itself.
        $abs = Storage::disk('local')->path($pack->file_path);
        $this->assertFileExists($abs);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($abs));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        $this->assertContains('invoices/AUDIT-0001.pdf', $names);
        $this->assertContains('invoices/AUDIT-0002.pdf', $names);
        $this->assertContains('README.txt', $names);
        $this->assertContains('invoice-register.csv', $names);
        $this->assertContains('invoice-register.xlsx', $names);
        $this->assertContains('audit-trail.csv', $names);
        $this->assertContains('fbr-submission-log.csv', $names);

        $register = $zip->getFromName('invoice-register.csv');
        $this->assertStringContainsString('AUDIT-0001', $register);
        $this->assertStringContainsString('AUDIT-0002', $register);
        $this->assertStringContainsString('1180.00', $register);

        $auditCsv = $zip->getFromName('audit-trail.csv');
        $this->assertStringContainsString('invoice_locked', $auditCsv);
        $this->assertStringContainsString('fbr_submission', $auditCsv);

        $fbrCsv = $zip->getFromName('fbr-submission-log.csv');
        $this->assertStringContainsString('success', $fbrCsv);
        $this->assertStringContainsString('timeout', $fbrCsv);

        $readme = $zip->getFromName('README.txt');
        $this->assertStringContainsString('Passed        : 1', $readme);
        $this->assertStringContainsString('Failed        : 1', $readme);
        $this->assertStringContainsString('AUDIT-0002', $readme);

        // PDFs are non-trivial documents.
        $pdf = $zip->getFromName('invoices/AUDIT-0001.pdf');
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));
        $zip->close();

        // Completion side-effects: notification + immutable audit trail entry.
        $this->assertSame(1, DB::table('notifications')->where('company_id', $companyId)->where('type', 'audit_pack')->where('title', 'FBR Audit Pack ready')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('company_id', $companyId)->where('action', 'audit_pack_generated')->count());
    }

    // ---------------------------------------------------------------
    // 2. Role gating
    // ---------------------------------------------------------------

    public function test_employee_cannot_open_compliance_page(): void
    {
        $companyId = $this->makeCompany();
        $employee = $this->makeUser($companyId, 'employee', 'emp@audit.pk');

        $this->actingAs($employee)->get('/compliance')->assertStatus(403);
    }

    // ---------------------------------------------------------------
    // 3. Store validations
    // ---------------------------------------------------------------

    public function test_store_rejects_inverted_date_range(): void
    {
        $companyId = $this->makeCompany();
        $admin = $this->makeUser($companyId);

        $this->actingAs($admin)
            ->from('/compliance')
            ->post('/compliance/audit-packs', ['date_from' => '2026-03-31', 'date_to' => '2026-03-01'])
            ->assertRedirect('/compliance')
            ->assertSessionHasErrors('date_to');

        $this->assertSame(0, AuditPack::count());
    }

    public function test_store_blocks_empty_range_and_second_active_pack(): void
    {
        Bus::fake();

        $companyId = $this->makeCompany();
        $admin = $this->makeUser($companyId);

        // No completed invoices in range → friendly error, no pack.
        $this->actingAs($admin)
            ->from('/compliance')
            ->post('/compliance/audit-packs', ['date_from' => '2026-03-01', 'date_to' => '2026-03-31'])
            ->assertRedirect('/compliance')
            ->assertSessionHas('error');
        $this->assertSame(0, AuditPack::count());

        // With an invoice the pack is created, stamped and queued.
        $this->makeInvoice($companyId, 'AUDIT-0009', '2026-03-10', true);

        $this->actingAs($admin)
            ->post('/compliance/audit-packs', ['date_from' => '2026-03-01', 'date_to' => '2026-03-31'])
            ->assertRedirect(route('compliance.index'))
            ->assertSessionHas('success');

        $this->assertSame(1, AuditPack::count());
        $pack = AuditPack::first();
        $this->assertSame('pending', $pack->status);
        $this->assertSame('audit-packs/company_' . $companyId . '/fbr-audit-pack-' . $pack->id . '.zip', $pack->file_path);
        Bus::assertDispatched(BuildAuditPackJob::class, fn ($job) => $job->packId === $pack->id);

        // While that pack is active, a second request is refused.
        $this->actingAs($admin)
            ->from('/compliance')
            ->post('/compliance/audit-packs', ['date_from' => '2026-03-01', 'date_to' => '2026-03-31'])
            ->assertRedirect('/compliance')
            ->assertSessionHas('error');
        $this->assertSame(1, AuditPack::count());
    }

    // ---------------------------------------------------------------
    // 4. Cross-company isolation
    // ---------------------------------------------------------------

    public function test_status_endpoint_blocks_other_companies(): void
    {
        $companyA = $this->makeCompany();
        $companyB = $this->makeCompany(['name' => 'Other Co', 'email' => 'other@test.pk']);

        $adminA = $this->makeUser($companyA, 'company_admin', 'a@audit.pk');

        $foreignPack = AuditPack::create([
            'company_id' => $companyB,
            'user_id' => null,
            'date_from' => '2026-03-01',
            'date_to' => '2026-03-31',
            'status' => 'ready',
        ]);

        $this->actingAs($adminA)->get('/compliance/audit-packs/' . $foreignPack->id . '/status')->assertStatus(403);
        $this->actingAs($adminA)->get('/compliance/audit-packs/' . $foreignPack->id . '/download')->assertStatus(403);
    }
}
