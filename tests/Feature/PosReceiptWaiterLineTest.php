<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\PosTransaction;
use App\Models\PosTransactionItem;
use App\Models\RestaurantOrder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RECEIPT WAITER LINE LOCK — Task 646 (Aug 2026).
 *
 * Task 620 locked the KOT side ("Waiter: <name>" prints for source='waiter'
 * orders regardless of the kot_show_orderby toggle). This file locks the
 * CUSTOMER receipt side: bills settled from a waiter-sent order print a
 * "Waiter" row in the info table on BOTH thermal templates (80mm + 58mm);
 * cashier-punched bills never gain the row.
 *
 * The templates look the waiter up from restaurant_orders via
 * pos_transaction_id + source='waiter' and eager-load the creator, so a
 * minimal restaurant_orders + users pair is created (rendered-view, sqlite
 * :memory: — same pattern as PosReceiptOrderMatchLayoutTest).
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/PosReceiptWaiterLineTest.php --testdox
 */
class PosReceiptWaiterLineTest extends TestCase
{
    private const TXN_ID      = 9101;
    private const COMPANY_ID  = 511;
    private const WAITER_ID   = 71;
    private const WAITER_NAME = 'Waiter Aslam Bhatti';

    /** Both live receipt templates — every assertion runs against each. */
    private const TEMPLATES = ['pos.receipts.receipt_80mm', 'pos.receipts.receipt_58mm'];

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        // Minimal restaurant_orders — needs source (waiter gate) + created_by
        // (creator eager load) + token_no (om lookup hasColumn gate elsewhere).
        Schema::create('restaurant_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('pos_transaction_id')->nullable();
            $t->string('order_number');
            $t->string('source')->default('pos');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedInteger('token_no')->nullable();
        });

        // Minimal users — the creator relation's target.
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
        });
        DB::table('users')->insert(['id' => self::WAITER_ID, 'name' => self::WAITER_NAME]);
    }

    // ── Fixture builders (mirrors PosController::receipt() variable set) ──

    private function makeCompany(): Company
    {
        $company = new Company();
        $company->id = self::COMPANY_ID;
        $company->name = 'Waiter Line Co';
        $company->order_match_style = 'off';
        $company->invoice_display_prefs = ['pos_style' => ['show_menu_qr' => false, 'bold' => false]];
        return $company;
    }

    private function makeTransaction(Company $company, array $attrs = []): PosTransaction
    {
        $txn = new PosTransaction(array_merge([
            'invoice_number' => 'INV-000811',
            'order_type' => 'dine_in',
            'payment_method' => 'cash',
            'invoice_mode' => 'pra',
            'pra_status' => null,
            'pra_invoice_number' => null,
            'subtotal' => 500,
            'tax_rate' => 16,
            'tax_amount' => 80,
            'discount_amount' => 0,
            'total_amount' => 580,
        ], $attrs));
        $txn->id = self::TXN_ID;
        $txn->company_id = $company->id;
        $txn->created_at = now();

        $item = new PosTransactionItem([
            'item_type' => 'product',
            'item_name' => 'Chicken Karahi',
            'quantity' => 1,
            'unit_price' => 500,
            'subtotal' => 500,
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

    private function linkOrder(string $source, ?int $createdBy): void
    {
        RestaurantOrder::query()->insert([
            'company_id' => self::COMPANY_ID,
            'pos_transaction_id' => self::TXN_ID,
            'order_number' => 'ORD-260813-WA1TR',
            'source' => $source,
            'created_by' => $createdBy,
            'token_no' => null,
        ]);
    }

    private function render(string $template, Company $company, PosTransaction $transaction): string
    {
        return view($template, ['transaction' => $transaction, 'company' => $company])->render();
    }

    // ── 1. Waiter-settled bill → "Waiter: <name>" row on BOTH papers ─────

    public function test_waiter_linked_bill_prints_waiter_name_on_both_templates(): void
    {
        $this->linkOrder('waiter', self::WAITER_ID);
        $company = $this->makeCompany();
        $txn = $this->makeTransaction($company);

        foreach (self::TEMPLATES as $template) {
            $html = $this->render($template, $company, $txn);
            $this->assertStringContainsString(__('pos.receipt_waiter'), $html, "$template: waiter label missing");
            $this->assertStringContainsString(self::WAITER_NAME, $html, "$template: waiter name missing");
        }
    }

    // ── 2. Cashier-punched restaurant bill (counter order) → NO waiter row ──

    public function test_counter_order_bill_prints_no_waiter_row(): void
    {
        $this->linkOrder('pos', self::WAITER_ID);
        $company = $this->makeCompany();
        $txn = $this->makeTransaction($company);

        foreach (self::TEMPLATES as $template) {
            $html = $this->render($template, $company, $txn);
            $this->assertStringNotContainsString(__('pos.receipt_waiter') . ':', $html, "$template: unexpected waiter row");
            $this->assertStringNotContainsString(self::WAITER_NAME, $html, "$template: unexpected waiter name");
        }
    }

    // ── 3. Order-less bill (retail / manual cart) → NO waiter row ────────

    public function test_orderless_bill_prints_no_waiter_row(): void
    {
        $company = $this->makeCompany();
        $txn = $this->makeTransaction($company);

        foreach (self::TEMPLATES as $template) {
            $html = $this->render($template, $company, $txn);
            $this->assertStringNotContainsString(__('pos.receipt_waiter') . ':', $html, "$template: unexpected waiter row");
        }
    }

    // ── 4. Retail bill (no order_type) skips the lookup entirely ─────────

    public function test_retail_bill_without_order_type_prints_no_waiter_row(): void
    {
        $this->linkOrder('waiter', self::WAITER_ID);
        $company = $this->makeCompany();
        $txn = $this->makeTransaction($company, ['order_type' => null]);

        foreach (self::TEMPLATES as $template) {
            $html = $this->render($template, $company, $txn);
            $this->assertStringNotContainsString(self::WAITER_NAME, $html, "$template: retail bill must not print waiter");
        }
    }
}
