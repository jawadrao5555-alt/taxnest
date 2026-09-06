<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\PosTransaction;
use App\Models\PosTransactionItem;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderItem;
use App\Support\PosBillNumberStyle;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily local-number style — printed receipt/KOT layout (ZFC, Sep 2026).
 *
 * Storage keeps invoice_number + bill_token for search/returns/reports.
 * Printed Daily slips must show only the large L00x calling number:
 * no Daily Token/TOKEN label and no Bill Serial/Ref/Local Invoice line.
 * Token and serial styles keep their existing dual-number print behavior.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/PosDailyNumberPrintLayoutTest.php --testdox
 */
class PosDailyNumberPrintLayoutTest extends TestCase
{
    private const COMPANY_ID = 9101;
    private const TXN_ID = 9201;
    private const ORDER_NUMBER = 'ORD-260906-DAILY1';
    private const INVOICE_NUMBER = 'L-000209';
    private const BILL_TOKEN = 3;
    private const DAILY_DISPLAY = 'L003';

    private const RECEIPT_TEMPLATES = [
        'pos.receipts.receipt_80mm',
        'pos.receipts.receipt_58mm',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        Schema::create('pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('invoice_number')->nullable();
            $t->string('invoice_mode')->nullable();
            $t->string('pra_status')->nullable();
            $t->string('pra_invoice_number')->nullable();
            $t->unsignedInteger('bill_token')->nullable();
            $t->boolean('is_archived')->default(false);
            $t->timestamps();
        });

        Schema::create('restaurant_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('pos_transaction_id')->nullable();
            $t->string('order_number')->nullable();
            $t->unsignedInteger('token_no')->nullable();
        });
    }

    private function makeCompany(array $attrs = []): Company
    {
        $company = new Company();
        $company->id = self::COMPANY_ID;
        $company->name = 'Daily Print Co';
        $company->order_match_style = 'off';
        $company->local_number_style = 'daily';
        $company->pra_number_style = 'serial';
        $company->invoice_display_prefs = [
            'pos_style' => ['show_menu_qr' => false, 'bold' => false],
        ];
        foreach ($attrs as $k => $v) {
            $company->{$k} = $v;
        }

        return $company;
    }

    private function makeTxn(Company $company, array $attrs = []): PosTransaction
    {
        $txn = new PosTransaction(array_merge([
            'invoice_number' => self::INVOICE_NUMBER,
            'invoice_mode' => 'local',
            'pra_status' => null,
            'pra_invoice_number' => null,
            'payment_method' => 'cash',
            'subtotal' => 500,
            'tax_rate' => 16,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 500,
            'bill_token' => self::BILL_TOKEN,
        ], $attrs));
        $txn->id = self::TXN_ID;
        $txn->company_id = $company->id;
        $txn->created_at = now();

        $item = new PosTransactionItem([
            'item_type' => 'product',
            'item_name' => 'Biryani',
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

    private function insertTxn(array $attrs = []): void
    {
        PosTransaction::withoutGlobalScope('hide_archived')->insert(array_merge([
            'id' => self::TXN_ID,
            'company_id' => self::COMPANY_ID,
            'invoice_number' => self::INVOICE_NUMBER,
            'invoice_mode' => 'local',
            'pra_status' => null,
            'pra_invoice_number' => null,
            'bill_token' => self::BILL_TOKEN,
            'is_archived' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    private function makePaidOrder(): RestaurantOrder
    {
        $order = new RestaurantOrder([
            'order_number' => self::ORDER_NUMBER,
            'order_type' => 'takeaway',
            'customer_name' => null,
        ]);
        $order->exists = true;
        $order->company_id = self::COMPANY_ID;
        $order->pos_transaction_id = self::TXN_ID;
        $order->created_at = now();
        $order->kot_print_count = 1;
        $order->priority = false;
        $order->kitchen_notes = null;
        $order->setRelation('table', null);
        $order->setRelation('creator', null);

        return $order;
    }

    private function renderReceiptBody(string $template, Company $company, PosTransaction $txn): string
    {
        $html = view($template, [
            'transaction' => $txn,
            'company' => $company,
        ])->render();

        $pos = strpos($html, '</head>');
        $this->assertNotFalse($pos, "{$template} has </head>");

        return substr($html, $pos);
    }

    private function renderKotBody(Company $company, RestaurantOrder $order, ?string $shimBillToken = null): string
    {
        $item = new RestaurantOrderItem([
            'item_type' => 'manual',
            'item_name' => 'Biryani',
            'quantity' => 1,
            'unit_price' => 500,
            'special_notes' => null,
        ]);
        $item->id = 1;
        $items = collect([$item]);

        $payload = [
            'order' => $order,
            'company' => $company,
            'ticketItems' => $items,
            'grouped' => collect(['ALL' => $items]),
            'stationLabel' => null,
            'delta' => false,
            'kotBatchNo' => null,
            'newItemIds' => collect(),
        ];
        if ($shimBillToken !== null) {
            $payload['shimBillToken'] = $shimBillToken;
        }

        $html = view('pos.restaurant.kitchen-ticket', $payload)->render();
        $pos = strpos($html, '</head>');
        $this->assertNotFalse($pos, 'KOT has </head>');

        return substr($html, $pos);
    }

    public function test_daily_receipts_print_large_l00x_without_label_or_bill_serial(): void
    {
        $company = $this->makeCompany(['local_number_style' => 'daily']);
        $txn = $this->makeTxn($company);

        $this->assertSame(self::DAILY_DISPLAY, PosBillNumberStyle::bigNumber($company, $txn));
        $this->assertSame(self::INVOICE_NUMBER, $txn->invoice_number);
        $this->assertSame(self::BILL_TOKEN, (int) $txn->bill_token);

        foreach (self::RECEIPT_TEMPLATES as $template) {
            $body = $this->renderReceiptBody($template, $company, $txn);

            $this->assertStringContainsString(self::DAILY_DISPLAY, $body, "large daily token prints ({$template})");
            $this->assertStringNotContainsString(__('pos.daily_token_label'), $body, "no Daily Token label ({$template})");
            $this->assertStringNotContainsString(__('pos.bill_serial_label'), $body, "no Bill Serial label ({$template})");
            $this->assertStringNotContainsString(__('pos.receipt_local_invoice'), $body, "no Local Invoice print row ({$template})");
            $this->assertStringNotContainsString(self::INVOICE_NUMBER, $body, "invoice_number not printed on daily slip ({$template})");
        }
    }

    public function test_token_style_receipts_still_print_label_and_bill_serial(): void
    {
        $company = $this->makeCompany(['local_number_style' => 'token']);
        $txn = $this->makeTxn($company, ['bill_token' => 7]);

        foreach (self::RECEIPT_TEMPLATES as $template) {
            $body = $this->renderReceiptBody($template, $company, $txn);

            $this->assertStringContainsString('7', $body, "token value prints ({$template})");
            $this->assertStringContainsString(__('pos.daily_token_label'), $body, "Daily Token label remains for token style ({$template})");
            $this->assertStringContainsString(__('pos.bill_serial_label'), $body, "Bill Serial remains for token style ({$template})");
            $this->assertStringContainsString(self::INVOICE_NUMBER, $body, "invoice_number still printed for token style ({$template})");
        }
    }

    public function test_serial_style_receipts_still_print_invoice_number(): void
    {
        $company = $this->makeCompany(['local_number_style' => 'serial']);
        $txn = $this->makeTxn($company, ['bill_token' => 7]);

        foreach (self::RECEIPT_TEMPLATES as $template) {
            $body = $this->renderReceiptBody($template, $company, $txn);

            $this->assertStringContainsString(self::INVOICE_NUMBER, $body, "serial style still prints invoice_number ({$template})");
            $this->assertStringNotContainsString(self::DAILY_DISPLAY, $body, "no L00x when style is serial ({$template})");
            $this->assertStringNotContainsString(__('pos.daily_token_label'), $body, "no Daily Token label in serial style ({$template})");
        }
    }

    public function test_daily_paid_kot_prints_l00x_without_token_label_or_bill_ref(): void
    {
        $this->insertTxn(['bill_token' => self::BILL_TOKEN]);
        $company = $this->makeCompany(['local_number_style' => 'daily']);
        $body = $this->renderKotBody($company, $this->makePaidOrder());

        $this->assertStringContainsString(self::DAILY_DISPLAY, $body, 'paid KOT prints large daily L00x');
        $this->assertStringNotContainsString(__('pos.order_match_token_label'), $body, 'no TOKEN label on daily KOT');
        $this->assertStringNotContainsString(__('pos.bill_ref_label'), $body, 'no Bill Serial/Ref line on daily KOT');
        $this->assertStringNotContainsString(self::INVOICE_NUMBER, $body, 'invoice_number not printed on daily KOT');
        $this->assertStringNotContainsString(self::ORDER_NUMBER, $body, 'ORD line replaced by daily token');

        $stored = PosTransaction::withoutGlobalScope('hide_archived')->find(self::TXN_ID);
        $this->assertNotNull($stored);
        $this->assertSame(self::INVOICE_NUMBER, $stored->invoice_number);
        $this->assertSame(self::BILL_TOKEN, (int) $stored->bill_token);
    }

    public function test_daily_shim_kot_prints_l00x_without_token_label_or_bill_ref(): void
    {
        $company = $this->makeCompany(['local_number_style' => 'daily']);
        $order = new RestaurantOrder([
            'order_number' => self::INVOICE_NUMBER,
            'order_type' => 'delivery',
            'customer_name' => null,
        ]);
        $order->exists = false;
        $order->created_at = now();
        $order->kot_print_count = 1;
        $order->priority = false;
        $order->kitchen_notes = null;
        $order->setRelation('table', null);
        $order->setRelation('creator', null);

        $html = $this->renderKotBody($company, $order, self::DAILY_DISPLAY);

        $this->assertStringContainsString(self::DAILY_DISPLAY, $html, 'shim KOT prints large daily L00x');
        $this->assertStringNotContainsString(__('pos.order_match_token_label'), $html, 'no TOKEN label on daily shim KOT');
        $this->assertStringNotContainsString(__('pos.bill_ref_label'), $html, 'no Bill Serial/Ref on daily shim KOT');
        $this->assertStringNotContainsString(self::INVOICE_NUMBER, $html, 'invoice_number not printed on daily shim KOT');
    }

    public function test_token_style_paid_kot_still_prints_token_label_and_bill_ref(): void
    {
        $this->insertTxn(['bill_token' => 4]);
        $company = $this->makeCompany(['local_number_style' => 'token']);
        $body = $this->renderKotBody($company, $this->makePaidOrder());

        $this->assertStringContainsString(__('pos.order_match_token_label') . ' 4', $body);
        $this->assertStringContainsString(__('pos.bill_ref_label') . ': ' . self::INVOICE_NUMBER, $body);
    }
}
