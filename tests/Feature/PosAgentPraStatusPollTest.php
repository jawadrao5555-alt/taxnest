<?php

namespace Tests\Feature;

use App\Http\Controllers\FbrPosController;
use App\Http\Controllers\PosController;
use App\Models\Company;
use App\Models\PosTransaction;
use App\Models\PosTransactionItem;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Agent-mode PRA popup + first-print fiscal number — Task 655 (13 Aug 2026).
 *
 * Agent-handled companies (Company::agentHandlesPra) save finals as
 * pra_status='pending'; the Desktop Agent submits within seconds. The sale
 * screen popup polls a tiny status endpoint to flip the badge to PRA VERIFIED,
 * show the fiscal number and reload the receipt iframe — and the first print
 * gets a bounded grace. Server pieces under lock here:
 *
 *   1. GET /pos/transaction/{id}/pra-status (apiPraStatus): returns the live
 *      pra_status + pra_invoice_number; company-scoped (cross-company = 404).
 *   2. FBR twin GET /fbr-pos/transaction/{id}/fbr-status (apiFbrStatus) for
 *      fiscal_device companies (same agent-pending pattern).
 *   3. Receipt templates (80mm / 58mm / invoice-pdf): a bill printed while
 *      still 'pending' carries the localized "being reported to PRA" clarifier
 *      so the slip is never mistaken for a local bill; submitted / local /
 *      provisional branches stay clarifier-free.
 *   4. Both endpoints are no-store (a SW/browser-cached 'pending' would wedge
 *      the poll forever).
 *
 * Pattern: sqlite :memory: + minimal Schema (PosPraReturnFlowTest) for the
 * endpoints; unsaved-shim rendered views (PosNeverReportedReceiptDisplayTest)
 * for the templates.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/PosAgentPraStatusPollTest.php --testdox
 */
class PosAgentPraStatusPollTest extends TestCase
{
    protected int $companyId;
    protected int $otherCompanyId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();
        User::flushScopeColumnCache();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->string('pra_connection_mode')->nullable();
            $table->boolean('agent_enabled')->default(false);
            $table->boolean('agent_submits_pra')->default(false);
            $table->boolean('pos_setup_completed')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number');
            $table->string('status')->default('completed');
            $table->string('invoice_mode')->nullable();
            $table->string('pra_status')->nullable();
            $table->string('pra_invoice_number')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('fbr_pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number');
            $table->string('status')->default('completed');
            $table->string('invoice_mode')->nullable();
            $table->string('fbr_status')->nullable();
            $table->string('fbr_invoice_number')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->timestamps();
        });

        // Order-match hasColumn lookup on rendered receipts.
        Schema::create('restaurant_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pos_transaction_id')->nullable();
            $table->string('order_number');
            $table->unsignedInteger('token_no')->nullable();
        });

        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Agent Mode Shop',
            'pra_connection_mode' => 'fiscal_device',
            'agent_enabled' => true,
            'agent_submits_pra' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->otherCompanyId = DB::table('companies')->insertGetId([
            'name' => 'Someone Else',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app()->bind('currentCompanyId', fn () => $this->companyId);

        DB::table('users')->insert([
            'company_id' => $this->companyId,
            'name' => 'Cashier',
            'role' => 'user',
            'pos_role' => 'pos_cashier',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Auth::guard('pos')->setUser(User::orderByDesc('id')->first());
    }

    protected function tearDown(): void
    {
        Auth::guard('pos')->logout();
        parent::tearDown();
    }

    // ── 1. PRA status endpoint ────────────────────────────────────────────

    public function test_pra_status_endpoint_reflects_pending_then_submitted_flip(): void
    {
        $id = DB::table('pos_transactions')->insertGetId([
            'company_id' => $this->companyId,
            'invoice_number' => 'INV-00001',
            'invoice_mode' => 'pra',
            'pra_status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $controller = new PosController();

        $res = $controller->apiPraStatus($id);
        $this->assertSame(200, $res->getStatusCode());
        $data = $res->getData(true);
        $this->assertTrue($data['success']);
        $this->assertSame('pending', $data['pra_status']);
        $this->assertNull($data['pra_invoice_number']);

        // Desktop Agent submits (AgentController::submitResult equivalent flip).
        DB::table('pos_transactions')->where('id', $id)->update([
            'pra_status' => 'submitted',
            'pra_invoice_number' => '186358FHGW43207623',
        ]);

        $data = $controller->apiPraStatus($id)->getData(true);
        $this->assertSame('submitted', $data['pra_status']);
        $this->assertSame('186358FHGW43207623', $data['pra_invoice_number']);
    }

    public function test_pra_status_endpoint_is_company_scoped_and_no_store(): void
    {
        $foreignId = DB::table('pos_transactions')->insertGetId([
            'company_id' => $this->otherCompanyId,
            'invoice_number' => 'INV-X',
            'invoice_mode' => 'pra',
            'pra_status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $controller = new PosController();

        $res = $controller->apiPraStatus($foreignId);
        $this->assertSame(404, $res->getStatusCode(), 'another company\'s bill must be invisible');

        $ownId = DB::table('pos_transactions')->insertGetId([
            'company_id' => $this->companyId,
            'invoice_number' => 'INV-00002',
            'invoice_mode' => 'pra',
            'pra_status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $res = $controller->apiPraStatus($ownId);
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'),
            'a cached pending response would wedge the popup poll forever');
    }

    // ── 2. FBR twin endpoint ──────────────────────────────────────────────

    public function test_fbr_status_endpoint_reflects_flip_and_is_company_scoped(): void
    {
        $id = DB::table('fbr_pos_transactions')->insertGetId([
            'company_id' => $this->companyId,
            'invoice_number' => 'FBR-00001',
            'invoice_mode' => 'fbr',
            'fbr_status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $controller = new FbrPosController();

        $data = $controller->apiFbrStatus($id)->getData(true);
        $this->assertTrue($data['success']);
        $this->assertSame('pending', $data['fbr_status']);

        DB::table('fbr_pos_transactions')->where('id', $id)->update([
            'fbr_status' => 'submitted',
            'fbr_invoice_number' => '123456789012',
        ]);
        $data = $controller->apiFbrStatus($id)->getData(true);
        $this->assertSame('submitted', $data['fbr_status']);
        $this->assertSame('123456789012', $data['fbr_invoice_number']);

        $foreignId = DB::table('fbr_pos_transactions')->insertGetId([
            'company_id' => $this->otherCompanyId,
            'invoice_number' => 'FBR-X',
            'fbr_status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(404, $controller->apiFbrStatus($foreignId)->getStatusCode());
    }

    // ── 3. Pending clarifier on rendered receipts ─────────────────────────

    private function shimCompany(): Company
    {
        $company = new Company();
        $company->id = 901;
        $company->name = 'Clarifier Shop';
        $company->order_match_style = 'off';
        $company->invoice_display_prefs = [
            'pos_style' => ['show_menu_qr' => false, 'bold' => false],
        ];
        return $company;
    }

    private function shimTransaction(Company $company, array $attrs = []): PosTransaction
    {
        $txn = new PosTransaction(array_merge([
            'invoice_number' => 'INV-777',
            'order_type' => null,
            'payment_method' => 'cash',
            'invoice_mode' => 'pra',
            'pra_status' => 'pending',
            'pra_invoice_number' => null,
            'subtotal' => 200,
            'tax_rate' => 16,
            'tax_amount' => 32,
            'discount_amount' => 0,
            'total_amount' => 232,
        ], $attrs));
        $txn->id = 9201;
        $txn->company_id = $company->id;
        $txn->created_at = now();

        $item = new PosTransactionItem([
            'item_type' => 'product',
            'item_name' => 'Pizza Slice',
            'quantity' => 1,
            'unit_price' => 200,
            'subtotal' => 200,
            'is_tax_exempt' => false,
            'is_third_schedule' => false,
        ]);
        $item->id = 1;

        $txn->setRelation('items', collect([$item]));
        $txn->setRelation('payments', collect());
        $txn->setRelation('company', $company);
        $txn->setRelation('terminal', null);
        $txn->setRelation('creator', null);
        $txn->setRelation('rider', null);
        return $txn;
    }

    public function test_pending_bill_receipts_carry_the_reporting_clarifier(): void
    {
        $note = __('pos.receipt_pending_pra_note');
        $this->assertNotSame('pos.receipt_pending_pra_note', $note, 'lang key must exist');

        foreach (['pos.receipts.receipt_80mm', 'pos.receipts.receipt_58mm', 'pos.invoice-pdf'] as $template) {
            $company = $this->shimCompany();
            $txn = $this->shimTransaction($company); // pra_status = pending
            $html = view($template, ['transaction' => $txn, 'company' => $company])->render();
            $this->assertStringContainsString($note, $html, "pending clarifier must print ({$template})");
        }
    }

    public function test_submitted_and_local_bills_stay_clarifier_free(): void
    {
        $note = __('pos.receipt_pending_pra_note');

        $variants = [
            'submitted' => ['pra_status' => 'submitted', 'pra_invoice_number' => '1234567890123456789012345'],
            'reporting-off final' => ['pra_status' => null],
            'provisional' => ['invoice_mode' => 'local', 'pra_status' => null],
        ];

        foreach (['pos.receipts.receipt_80mm', 'pos.receipts.receipt_58mm', 'pos.invoice-pdf'] as $template) {
            foreach ($variants as $label => $attrs) {
                $company = $this->shimCompany();
                $txn = $this->shimTransaction($company, $attrs);
                $html = view($template, ['transaction' => $txn, 'company' => $company])->render();
                $this->assertStringNotContainsString($note, $html,
                    "{$label} bill must NOT carry the pending clarifier ({$template})");
            }
        }
    }
}
