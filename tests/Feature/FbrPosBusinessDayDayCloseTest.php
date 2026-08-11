<?php

namespace Tests\Feature;

use App\Http\Controllers\FbrPosController;
use App\Models\FbrPosTransaction;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * FBR POS business day (Task 492 — FBR mirror of the PRA rule).
 *
 * Locks the trading-day grouping contract:
 *
 *   1. The creating hook stamps fbr_pos_transactions.business_date via
 *      PosBusinessDay::forMomentFbr — a pre-cutoff (01:30) bill carries
 *      YESTERDAY while yesterday has no fbr_day_close_reports row, and
 *      TODAY once yesterday is closed. The "already closed" check reads
 *      fbr_day_close_reports, NEVER pos_day_close_reports.
 *   2. performDayClose (the persisted Z-report writer) selects by
 *      business_date: a 1 AM sale stamped with the prior trading day is
 *      COUNTED in that day's report totals and EXCLUDED from the next
 *      calendar day's close — same bucket as the preview/PDF paths.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create,
 * private performDayClose invoked via reflection (mirrors
 * FbrPosDayCloseAutoFinalizeTest).
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/FbrPosBusinessDayDayCloseTest.php
 */
class FbrPosBusinessDayDayCloseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('fbr_reporting_enabled')->default(false);
            $table->boolean('agent_enabled')->default(false);
            $table->string('pos_dayclose_provisional_action')->nullable();
            $table->string('pos_business_day_cutoff')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('fbr_pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number');
            $table->string('status')->nullable();
            $table->string('invoice_mode')->nullable();
            $table->string('fbr_status')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('fbr_service_charge', 12, 2)->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->date('business_date')->nullable();
            $table->timestamps();
        });

        Schema::create('fbr_pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('item_name')->nullable();
            $table->timestamps();
        });

        Schema::create('fbr_day_close_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('report_date');
            $table->string('report_number');
            $table->integer('total_invoices')->default(0);
            $table->integer('fbr_invoices')->default(0);
            $table->integer('local_invoices')->default(0);
            $table->integer('failed_invoices')->default(0);
            $table->decimal('gross_sales', 14, 2)->default(0);
            $table->decimal('total_discount', 14, 2)->default(0);
            $table->decimal('net_sales', 14, 2)->default(0);
            $table->decimal('total_tax', 14, 2)->default(0);
            $table->decimal('total_fbr_fee', 14, 2)->nullable();
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('cash_amount', 14, 2)->default(0);
            $table->decimal('card_amount', 14, 2)->default(0);
            $table->decimal('udhaar_amount', 14, 2)->default(0);
            $table->decimal('other_amount', 14, 2)->default(0);
            $table->string('first_invoice_number')->nullable();
            $table->string('last_invoice_number')->nullable();
            $table->timestamp('first_invoice_time')->nullable();
            $table->timestamp('last_invoice_time')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->text('notes')->nullable();
            $table->string('hash')->nullable();
            $table->timestamps();
        });

        // Report numbering uses MySQL SUBSTRING_INDEX — polyfill for sqlite.
        DB::connection()->getPdo()->sqliteCreateFunction('SUBSTRING_INDEX', function ($str, $delim, $count) {
            $parts = explode((string) $delim, (string) $str);
            return $count < 0
                ? implode($delim, array_slice($parts, (int) $count))
                : implode($delim, array_slice($parts, 0, (int) $count));
        });
    }

    private function makeCompany(): int
    {
        return (int) DB::table('companies')->insertGetId([
            'name' => 'BizDay Test Co',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertBill(int $companyId, string $number, \DateTimeInterface $createdAt, string $businessDate, float $total = 118.0): int
    {
        return (int) DB::table('fbr_pos_transactions')->insertGetId([
            'company_id' => $companyId,
            'invoice_number' => $number,
            'status' => 'completed',
            'invoice_mode' => 'fbr',
            'fbr_status' => 'submitted',
            'subtotal' => round($total / 1.18, 2),
            'tax_amount' => round($total - $total / 1.18, 2),
            'total_amount' => $total,
            'payment_method' => 'cash',
            'business_date' => $businessDate,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function performDayClose(int $companyId, string $date)
    {
        $controller = new FbrPosController();
        $m = new \ReflectionMethod($controller, 'performDayClose');
        $m->setAccessible(true);
        return $m->invoke($controller, $companyId, $date, null, null, null);
    }

    // ── 1. creating hook stamps business_date via the FBR cutoff rule ──────

    public function test_creating_hook_stamps_pre_cutoff_bill_into_yesterday_while_open(): void
    {
        $companyId = $this->makeCompany();
        $at = now()->setTime(1, 30);

        $t = new FbrPosTransaction([
            'company_id' => $companyId,
            'invoice_number' => 'BIZ-1',
            'status' => 'completed',
            'invoice_mode' => 'fbr',
            'subtotal' => 100,
            'tax_amount' => 18,
            'total_amount' => 118,
            'payment_method' => 'cash',
        ]);
        $t->created_at = $at;
        $t->save();
        $t->refresh();

        $this->assertSame($at->copy()->subDay()->toDateString(), (string) $t->business_date);
    }

    public function test_creating_hook_uses_fbr_day_close_reports_for_closed_check(): void
    {
        $companyId = $this->makeCompany();
        $at = now()->setTime(1, 30);

        // Yesterday CLOSED in fbr_day_close_reports → pre-cutoff bill is TODAY.
        DB::table('fbr_day_close_reports')->insert([
            'company_id' => $companyId,
            'report_date' => $at->copy()->subDay()->toDateString(),
            'report_number' => 'Z-0001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $t = new FbrPosTransaction([
            'company_id' => $companyId,
            'invoice_number' => 'BIZ-2',
            'status' => 'completed',
            'invoice_mode' => 'fbr',
            'subtotal' => 100,
            'tax_amount' => 18,
            'total_amount' => 118,
            'payment_method' => 'cash',
        ]);
        $t->created_at = $at;
        $t->save();
        $t->refresh();

        $this->assertSame($at->toDateString(), (string) $t->business_date);
    }

    // ── 2. performDayClose persists totals by business_date ────────────────

    public function test_day_close_report_includes_post_midnight_bill_of_its_trading_day(): void
    {
        $companyId = $this->makeCompany();
        $yesterday = now()->subDay()->toDateString();
        $today = now()->toDateString();

        // Yesterday evening bill + a 1 AM bill stamped into yesterday's trading
        // day + a normal bill of today.
        $this->insertBill($companyId, 'Y-EVENING', now()->subDay()->setTime(20, 0), $yesterday, 118.0);
        $this->insertBill($companyId, 'Y-LATE', now()->setTime(1, 0), $yesterday, 236.0);
        $this->insertBill($companyId, 'T-DAY', now()->setTime(10, 0), $today, 118.0);

        $report = $this->performDayClose($companyId, $yesterday);

        $this->assertNotNull($report);
        $this->assertSame(2, (int) $report->total_invoices);
        $this->assertSame(354.0, (float) $report->total_amount);
        $this->assertSame('Y-EVENING', $report->first_invoice_number);
        $this->assertSame('Y-LATE', $report->last_invoice_number);

        // Closing TODAY must exclude the 1 AM bill (already counted yesterday).
        $todayReport = $this->performDayClose($companyId, $today);
        $this->assertNotNull($todayReport);
        $this->assertSame(1, (int) $todayReport->total_invoices);
        $this->assertSame(118.0, (float) $todayReport->total_amount);
        $this->assertSame('T-DAY', $todayReport->first_invoice_number);
    }
}
