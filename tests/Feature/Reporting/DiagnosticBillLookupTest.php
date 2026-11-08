<?php

namespace Tests\Feature\Reporting;

use App\Services\Reporting\DiagnosticBillLookup;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DiagnosticBillLookupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        config(['app.timezone' => 'Asia/Karachi']);
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type');
            $t->string('bill_number_style')->nullable();
        });
        Schema::create('pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('branch_id');
            $t->string('invoice_number');
            $t->unsignedInteger('bill_token');
            $t->dateTime('created_at');
            $t->string('invoice_mode');
            $t->boolean('archived')->default(false);
            $t->text('payload')->nullable();
        });
        DB::table('companies')->insert([
            ['id' => 1, 'name' => 'Synthetic token shop', 'product_type' => 'pos', 'bill_number_style' => 'serial'],
            ['id' => 2, 'name' => 'Foreign shop', 'product_type' => 'pos', 'bill_number_style' => 'daily'],
        ]);
        DB::table('pos_transactions')->insert([
            ['id' => 1, 'company_id' => 1, 'branch_id' => 1, 'invoice_number' => 'IMMUTABLE-500', 'bill_token' => 42, 'created_at' => '2026-11-08 05:59:59', 'invoice_mode' => 'pra', 'archived' => true, 'payload' => 'PRIVATE-OWN'],
            ['id' => 2, 'company_id' => 2, 'branch_id' => 1, 'invoice_number' => 'IMMUTABLE-500', 'bill_token' => 42, 'created_at' => '2026-11-07 22:13:32', 'invoice_mode' => 'local', 'archived' => false, 'payload' => 'PRIVATE-FOREIGN'],
            ['id' => 3, 'company_id' => 1, 'branch_id' => 1, 'invoice_number' => 'IMMUTABLE-501', 'bill_token' => 42, 'created_at' => '2026-11-08 06:00:00', 'invoice_mode' => 'pra', 'archived' => false, 'payload' => 'PRIVATE-NEXT'],
        ]);
    }

    private function args(): array
    {
        return ['company_name' => 'Synthetic token shop', 'reference_kind' => 'pra_daily_token', 'bill_reference' => 'L042', 'counter_date' => '2026-11-07'];
    }

    public function test_daily_token_uses_frozen_token_and_exact_counter_boundaries_without_current_style_or_archive_filters(): void
    {
        $before = DB::table('pos_transactions')->get()->toJson();
        $service = app(DiagnosticBillLookup::class);
        $r = $service->read($this->args(), [1]);
        $this->assertSame('RESOLVED', $r['status']);
        $this->assertSame(1, $r['bill_id']);
        $this->assertSame(3, $service->read(array_replace($this->args(), ['counter_date' => '2026-11-08']), [1])['bill_id']);
        $this->assertSame('unknown', $r['physical_print']);
        $this->assertFalse($r['complete']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($r));
        $this->assertSame($before, DB::table('pos_transactions')->get()->toJson());
        $this->assertSame('NOT_FOUND', $service->read(['company_name' => 'Synthetic token shop', 'reference_kind' => 'invoice_serial', 'bill_reference' => 'L042'], [1])['status']);
        $this->assertSame(1, $service->read(['company_name' => 'Synthetic token shop', 'reference_kind' => 'invoice_serial', 'bill_reference' => 'IMMUTABLE-500'], [1])['bill_id']);
    }

    public function test_reused_tokens_and_duplicate_serials_stay_ambiguous_until_explicit_branch_scope(): void
    {
        foreach ([4, 5] as $id) {
            DB::table('pos_transactions')->insert(['id' => $id, 'company_id' => 1, 'branch_id' => 2, 'invoice_number' => 'IMMUTABLE-500', 'bill_token' => 42, 'created_at' => '2026-11-07 22:13:32', 'invoice_mode' => 'local']);
        }
        $service = app(DiagnosticBillLookup::class);
        $r = $service->read($this->args(), [1]);
        $this->assertSame('AMBIGUOUS', $r['status']);
        $this->assertNull($r['bill_id']);
        $this->assertCount(2, $r['candidates']);
        $this->assertTrue($r['has_more']);
        $this->assertSame(1, $service->read($this->args() + ['branch_id' => 1], [1])['bill_id']);
        $this->assertSame('AMBIGUOUS', $service->read(['company_name' => 'Synthetic token shop', 'reference_kind' => 'invoice_serial', 'bill_reference' => 'IMMUTABLE-500'], [1])['status']);
        $this->expectException(ValidationException::class);
        $service->read($this->args(), [2]);
    }

    public function test_daily_token_requires_explicit_valid_date_and_reference(): void
    {
        $this->expectException(ValidationException::class);
        app(DiagnosticBillLookup::class)->read(array_replace($this->args(), ['counter_date' => null]), [1]);
    }
}
