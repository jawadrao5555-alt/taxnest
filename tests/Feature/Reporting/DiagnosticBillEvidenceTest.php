<?php

namespace Tests\Feature\Reporting;

use App\Services\Reporting\DiagnosticBillEvidence;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DiagnosticBillEvidenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type');
        });
        DB::table('companies')->insert([
            ['id' => 1, 'name' => 'Synthetic PRA', 'product_type' => 'pos'],
            ['id' => 2, 'name' => 'Synthetic DI', 'product_type' => 'di'],
            ['id' => 3, 'name' => 'Synthetic FBR', 'product_type' => 'fbrpos'],
            ['id' => 4, 'name' => 'Synthetic Health', 'product_type' => 'erps'],
        ]);
    }

    private function table(string $name, string $foreign, bool $tenant = true): void
    {
        Schema::create($name, function (Blueprint $t) use ($foreign, $tenant) {
            $t->id();
            if ($tenant) {
                $t->unsignedBigInteger('company_id');
            }
            $t->unsignedBigInteger($foreign);
            $t->text('private_payload')->nullable();
        });
    }

    public function test_pra_links_are_explicit_scoped_bounded_and_never_expose_payloads(): void
    {
        $this->table('pos_transactions', 'unused');
        DB::table('pos_transactions')->insert(['id' => 10, 'company_id' => 1, 'unused' => 0]);
        $this->table('pos_payments', 'transaction_id', false);
        $this->table('pra_logs', 'transaction_id');
        DB::table('pos_payments')->insert([
            ['id' => 1, 'transaction_id' => 10, 'private_payload' => 'SECRET-PAYMENT'],
            ['id' => 2, 'transaction_id' => 10, 'private_payload' => 'SECRET-PAYMENT'],
            ['id' => 3, 'transaction_id' => 99, 'private_payload' => 'SECRET-FOREIGN'],
        ]);
        DB::table('pra_logs')->insert([
            ['id' => 1, 'company_id' => 1, 'transaction_id' => 10, 'private_payload' => 'SECRET-FISCAL'],
            ['id' => 2, 'company_id' => 2, 'transaction_id' => 10, 'private_payload' => 'SECRET-FOREIGN'],
        ]);
        $before = DB::table('pos_payments')->get()->toJson();
        $args = ['company_name' => 'Synthetic PRA', 'bill_id' => 10, 'limit' => 1];
        $service = app(DiagnosticBillEvidence::class);
        $r = $service->read($args, [1]);
        $this->assertSame([['id' => 1]], $r['sources']['payments']['rows']);
        $this->assertTrue($r['sources']['payments']['has_more']);
        $this->assertSame(1, $r['sources']['payments']['next_after_id']);
        $this->assertSame([['id' => 1]], $r['sources']['pra_attempts']['rows']);
        $this->assertSame('UNAVAILABLE', $r['sources']['stock']['status']);
        $this->assertFalse($r['complete']);
        $this->assertSame('not_assessed', $r['fiscal_acceptance']);
        $this->assertStringNotContainsString('SECRET', json_encode($r));
        $page = $service->read($args + ['after_id' => 1], [1]);
        $this->assertSame([['id' => 2]], $page['sources']['payments']['rows']);
        $this->assertSame('NO_RETAINED_LINKS', $page['sources']['pra_attempts']['status']);
        $this->assertSame($before, DB::table('pos_payments')->get()->toJson());
        $this->expectException(ValidationException::class);
        $service->read($args, [2]);
    }

    public function test_print_jobs_and_projected_offline_events_require_tenant_product_and_server_identity(): void
    {
        $this->table('pos_transactions', 'unused');
        DB::table('pos_transactions')->insert(['id' => 10, 'company_id' => 1, 'unused' => 0]);
        Schema::create('pos_print_jobs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('transaction_id')->nullable();
            $t->unsignedBigInteger('restaurant_order_id')->nullable();
            $t->string('type');
            $t->text('error')->nullable();
        });
        $this->table('restaurant_orders', 'pos_transaction_id');
        DB::table('restaurant_orders')->insert(['id' => 7, 'company_id' => 1, 'pos_transaction_id' => 10]);
        DB::table('pos_print_jobs')->insert([
            ['id' => 1, 'company_id' => 1, 'transaction_id' => 10, 'restaurant_order_id' => null, 'type' => 'kot', 'error' => 'PRIVATE'],
            ['id' => 2, 'company_id' => 1, 'transaction_id' => 10, 'restaurant_order_id' => null, 'type' => 'fbr_kot', 'error' => 'PRIVATE'],
            ['id' => 3, 'company_id' => 2, 'transaction_id' => 10, 'restaurant_order_id' => null, 'type' => 'kot', 'error' => 'PRIVATE'],
            ['id' => 4, 'company_id' => 1, 'transaction_id' => null, 'restaurant_order_id' => 7, 'type' => 'kot', 'error' => 'PRIVATE'],
        ]);
        Schema::create('agent_core_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('event_type');
            $t->string('projection_status');
            $t->json('projection_result');
            $t->json('payload');
        });
        foreach ([
            [1, 1, 'sale.created', 'projected', 10], [2, 2, 'sale.created', 'projected', 10],
            [3, 1, 'sale.created', 'accepted', 10], [4, 1, 'sale.created', 'projected', 99],
            [5, 1, 'order.settled', 'projected', 10], [6, 1, 'print.completed', 'projected', 10],
        ] as [$id,$company,$type,$status,$transaction]) {
            DB::table('agent_core_events')->insert(['id' => $id, 'company_id' => $company, 'event_type' => $type,
                'projection_status' => $status, 'projection_result' => json_encode(['transaction_id' => $transaction, 'secret' => 'PRIVATE']),
                'payload' => json_encode(['transaction_id' => 10, 'secret' => 'PRIVATE'])]);
        }
        $before = DB::table('agent_core_events')->get()->toJson();
        $r = app(DiagnosticBillEvidence::class)->read(['company_name' => 'Synthetic PRA', 'bill_id' => 10, 'limit' => 1], [1]);
        $this->assertSame([['id' => 1]], $r['sources']['print_jobs']['rows']);
        $this->assertSame([['id' => 4]], $r['sources']['order_only_print_jobs']['rows']);
        $this->assertSame([['id' => 1]], $r['sources']['projected_offline_events']['rows']);
        $this->assertTrue($r['sources']['projected_offline_events']['has_more']);
        $next = app(DiagnosticBillEvidence::class)->read(['company_name' => 'Synthetic PRA', 'bill_id' => 10, 'after_id' => 1], [1]);
        $this->assertSame([['id' => 5]], $next['sources']['projected_offline_events']['rows']);
        $this->assertSame([], $next['sources']['print_jobs']['rows']);
        $this->assertSame('unknown', $r['physical_print']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($r));
        $this->assertSame($before, DB::table('agent_core_events')->get()->toJson());
    }

    public function test_other_products_follow_actual_parent_links_and_reject_wrong_tenant_bills(): void
    {
        foreach ([
            [2, 'Synthetic DI', 'invoices', 'fbr_logs', 'invoice_id', false, 'fbr_attempts'],
            [3, 'Synthetic FBR', 'fbr_pos_transactions', 'fbr_pos_logs', 'transaction_id', true, 'fbr_attempts'],
            [4, 'Synthetic Health', 'health_bills', 'health_payments', 'health_bill_id', true, 'payments'],
        ] as [$id,$name,$root,$child,$foreign,$tenant,$key]) {
            $this->table($root, 'unused');
            $this->table($child, $foreign, $tenant);
            DB::table($root)->insert(['id' => 20, 'company_id' => $id, 'unused' => 0]);
            $row = ['id' => 1, $foreign => 20, 'private_payload' => 'SECRET'];
            if ($tenant) {
                $row['company_id'] = $id;
            }
            DB::table($child)->insert($row);
            $r = app(DiagnosticBillEvidence::class)->read(['company_name' => $name, 'bill_id' => 20], [$id]);
            $this->assertSame([['id' => 1]], $r['sources'][$key]['rows']);
            $this->assertStringNotContainsString('SECRET', json_encode($r));
        }
        $this->expectException(ValidationException::class);
        app(DiagnosticBillEvidence::class)->read(['company_name' => 'Synthetic DI', 'bill_id' => 99], [2]);
    }

    public function test_arbitrary_filters_and_ambiguous_identity_are_refused(): void
    {
        try {
            app(DiagnosticBillEvidence::class)->read(['company_name' => 'Synthetic DI', 'bill_id' => 1, 'table' => 'users'], [2]);
            $this->fail('Arbitrary source accepted.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        DB::table('companies')->insert(['id' => 5, 'name' => 'Synthetic DI', 'product_type' => 'di']);
        $this->expectException(ValidationException::class);
        app(DiagnosticBillEvidence::class)->read(['company_name' => 'Synthetic DI', 'bill_id' => 1], null);
    }

    public function test_dual_federal_and_order_only_print_links_require_both_parent_and_company(): void
    {
        $this->table('pos_transactions', 'unused');
        DB::table('pos_transactions')->insert(['id' => 10, 'company_id' => 1, 'unused' => 0]);
        foreach (['pos_ims_submissions', 'pos_di_sandbox_submissions'] as $table) {
            $this->table($table, 'source_transaction_id');
            DB::table($table)->insert([
                ['id' => 1, 'company_id' => 1, 'source_transaction_id' => 10, 'private_payload' => 'PRIVATE'],
                ['id' => 2, 'company_id' => 2, 'source_transaction_id' => 10, 'private_payload' => 'PRIVATE-FOREIGN'],
                ['id' => 3, 'company_id' => 1, 'source_transaction_id' => 99, 'private_payload' => 'PRIVATE-OTHER'],
            ]);
        }
        $this->table('restaurant_orders', 'pos_transaction_id');
        DB::table('restaurant_orders')->insert([
            ['id' => 100, 'company_id' => 1, 'pos_transaction_id' => 10],
            ['id' => 101, 'company_id' => 2, 'pos_transaction_id' => 10],
            ['id' => 102, 'company_id' => 1, 'pos_transaction_id' => 99],
        ]);
        Schema::create('pos_print_evidence', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('product_type');
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('transaction_id')->nullable();
        });
        DB::table('pos_print_evidence')->insert([
            ['id' => 1, 'company_id' => 1, 'product_type' => 'pos', 'order_id' => 100, 'transaction_id' => null],
            ['id' => 2, 'company_id' => 1, 'product_type' => 'pos', 'order_id' => 101, 'transaction_id' => null],
            ['id' => 3, 'company_id' => 1, 'product_type' => 'pos', 'order_id' => 102, 'transaction_id' => null],
            ['id' => 4, 'company_id' => 2, 'product_type' => 'pos', 'order_id' => 100, 'transaction_id' => null],
            ['id' => 5, 'company_id' => 1, 'product_type' => 'fbrpos', 'order_id' => 100, 'transaction_id' => null],
            ['id' => 6, 'company_id' => 1, 'product_type' => 'pos', 'order_id' => 100, 'transaction_id' => 10],
        ]);
        $r = app(DiagnosticBillEvidence::class)->read(['company_name' => 'Synthetic PRA', 'bill_id' => 10], [1]);
        foreach (['federal_ims_attempts', 'federal_di_attempts', 'order_only_print_events'] as $key) {
            $this->assertSame([['id' => 1]], $r['sources'][$key]['rows']);
        }
        $this->assertSame([['id' => 6]], $r['sources']['print_events']['rows']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($r));
        $this->assertFalse($r['complete']);
        $this->assertSame('not_assessed', $r['fiscal_acceptance']);
    }

    public function test_fbr_callbacks_and_health_bill_sources_use_explicit_identities(): void
    {
        foreach ([
            [3, 'Synthetic FBR', 'fbr_pos_transactions', 'fbr_pos_callback_diagnostics', 'transaction_id', 'callbacks'],
            [4, 'Synthetic Health', 'health_bills', 'health_fbr_submissions', 'health_bill_id', 'fbr_attempts'],
            [4, 'Synthetic Health', 'health_bills', 'health_charges', 'health_bill_id', 'charges'],
            [4, 'Synthetic Health', 'health_bills', 'health_bill_lines', 'health_bill_id', 'bill_lines'],
        ] as [$company,$name,$root,$child,$foreign,$key]) {
            if (! Schema::hasTable($root)) {
                $this->table($root, 'unused');
                DB::table($root)->insert(['id' => 10, 'company_id' => $company, 'unused' => 0]);
            }
            $this->table($child, $foreign);
            DB::table($child)->insert([['id' => 1, 'company_id' => $company, $foreign => 10], ['id' => 2, 'company_id' => 99, $foreign => 10]]);
            $r = app(DiagnosticBillEvidence::class)->read(['company_name' => $name, 'bill_id' => 10], [$company]);
            $this->assertSame([['id' => 1]], $r['sources'][$key]['rows']);
        }
        $this->table('health_audit_events', 'entity_id');
        Schema::table('health_audit_events', fn (Blueprint $t) => $t->string('entity_type'));
        DB::table('health_audit_events')->insert([
            ['id' => 1, 'company_id' => 4, 'entity_id' => 10, 'entity_type' => 'health_bills'],
            ['id' => 2, 'company_id' => 4, 'entity_id' => 10, 'entity_type' => 'health_patients'],
            ['id' => 3, 'company_id' => 99, 'entity_id' => 10, 'entity_type' => 'health_bills'],
        ]);
        $r = app(DiagnosticBillEvidence::class)->read(['company_name' => 'Synthetic Health', 'bill_id' => 10], [4]);
        $this->assertSame([['id' => 1]], $r['sources']['bill_audit_events']['rows']);
    }

    public function test_health_clinical_and_pharmacy_sources_require_explicit_charge_links_and_both_tenants(): void
    {
        $this->table('health_bills', 'unused');
        DB::table('health_bills')->insert(['id' => 10, 'company_id' => 4, 'unused' => 0]);
        Schema::create('health_charges', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('health_bill_id');
            $t->string('source_type');
            $t->unsignedBigInteger('source_id');
            $t->text('private_payload')->nullable();
        });
        foreach ([
            ['health_visits', 'visit', 'clinical_visits'],
            ['health_pharmacy_sales', 'pharmacy_sale', 'pharmacy_sales'],
            ['health_admission_charges', 'admission_charge', 'admission_charges'],
            ['health_operations', 'operation', 'operations'],
        ] as [$table, $sourceType, $key]) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->text('private_payload')->nullable();
            });
            DB::table($table)->insert([
                ['id' => 100, 'company_id' => 4, 'private_payload' => 'PRIVATE-OWN'],
                ['id' => 101, 'company_id' => 99, 'private_payload' => 'PRIVATE-FOREIGN'],
                ['id' => 102, 'company_id' => 4, 'private_payload' => 'PRIVATE-UNLINKED'],
            ]);
            DB::table('health_charges')->insert([
                ['company_id' => 4, 'health_bill_id' => 10, 'source_type' => $sourceType, 'source_id' => 100, 'private_payload' => 'PRIVATE-CHARGE'],
                ['company_id' => 4, 'health_bill_id' => 10, 'source_type' => $sourceType, 'source_id' => 100, 'private_payload' => 'PRIVATE-DUPLICATE'],
                ['company_id' => 99, 'health_bill_id' => 10, 'source_type' => $sourceType, 'source_id' => 101, 'private_payload' => 'PRIVATE-FOREIGN'],
                ['company_id' => 4, 'health_bill_id' => 99, 'source_type' => $sourceType, 'source_id' => 102, 'private_payload' => 'PRIVATE-OTHER-BILL'],
                ['company_id' => 4, 'health_bill_id' => 10, 'source_type' => 'wrong_'.$sourceType, 'source_id' => 102, 'private_payload' => 'PRIVATE-WRONG-TYPE'],
            ]);
            $sourceBefore = DB::table($table)->orderBy('id')->get()->toJson();
            $chargesBefore = DB::table('health_charges')->orderBy('id')->get()->toJson();
            $report = app(DiagnosticBillEvidence::class)->read(['company_name' => 'Synthetic Health', 'bill_id' => 10], [4]);
            $this->assertSame([['id' => 100]], $report['sources'][$key]['rows'], $key);
            $this->assertSame('PARTIAL', $report['sources'][$key]['status']);
            $this->assertStringNotContainsString('PRIVATE', json_encode($report));
            $this->assertSame(5, $report['version']);
            $this->assertSame($sourceBefore, DB::table($table)->orderBy('id')->get()->toJson());
            $this->assertSame($chargesBefore, DB::table('health_charges')->orderBy('id')->get()->toJson());
        }
        Schema::create('health_batch_movements', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('reference_type');
            $t->unsignedBigInteger('reference_id');
            $t->text('private_payload')->nullable();
        });
        DB::table('health_batch_movements')->insert([
            ['id' => 200, 'company_id' => 4, 'reference_type' => 'health_pharmacy_sale', 'reference_id' => 100, 'private_payload' => 'PRIVATE-STOCK'],
            ['id' => 201, 'company_id' => 99, 'reference_type' => 'health_pharmacy_sale', 'reference_id' => 100, 'private_payload' => 'PRIVATE-FOREIGN'],
            ['id' => 202, 'company_id' => 4, 'reference_type' => 'health_pharmacy_sale', 'reference_id' => 102, 'private_payload' => 'PRIVATE-UNLINKED'],
            ['id' => 203, 'company_id' => 4, 'reference_type' => 'health_pharmacy_return', 'reference_id' => 100, 'private_payload' => 'PRIVATE-WRONG-TYPE'],
        ]);
        $movementsBefore = DB::table('health_batch_movements')->orderBy('id')->get()->toJson();
        $report = app(DiagnosticBillEvidence::class)->read(['company_name' => 'Synthetic Health', 'bill_id' => 10], [4]);
        $this->assertSame([['id' => 200]], $report['sources']['pharmacy_stock']['rows']);
        $this->assertSame('PARTIAL', $report['sources']['pharmacy_stock']['status']);
        $this->assertSame(5, $report['version']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($report));
        $this->assertSame($movementsBefore, DB::table('health_batch_movements')->orderBy('id')->get()->toJson());
        $report = app(DiagnosticBillEvidence::class)->read([
            'company_name' => 'Synthetic Health', 'bill_id' => 10, 'after_id' => 100,
        ], [4]);
        foreach (['clinical_visits', 'pharmacy_sales', 'admission_charges', 'operations'] as $key) {
            $this->assertSame('NO_RETAINED_LINKS', $report['sources'][$key]['status']);
            $this->assertSame([], $report['sources'][$key]['rows']);
        }
        $this->assertSame([['id' => 200]], $report['sources']['pharmacy_stock']['rows']);
        $report = app(DiagnosticBillEvidence::class)->read([
            'company_name' => 'Synthetic Health', 'bill_id' => 10, 'after_id' => 200,
        ], [4]);
        $this->assertSame('NO_RETAINED_LINKS', $report['sources']['pharmacy_stock']['status']);
        $this->assertSame(20, DB::table('health_charges')->count());
    }

    public function test_one_failed_source_does_not_erase_other_bill_links_or_leak_sql(): void
    {
        $this->table('pos_transactions', 'unused');
        $this->table('pra_logs', 'transaction_id');
        $this->table('pos_ims_submissions', 'source_transaction_id');
        DB::table('pos_transactions')->insert(['id' => 10, 'company_id' => 1, 'unused' => 0]);
        DB::table('pra_logs')->insert(['id' => 1, 'company_id' => 1, 'transaction_id' => 10]);
        DB::connection()->beforeExecuting(function ($sql) {
            if (str_contains($sql, 'from "pos_ims_submissions"')) {
                throw new \Illuminate\Database\QueryException('sqlite', $sql, [], new \RuntimeException('PRIVATE-FAILURE'));
            }
        });
        $r = app(DiagnosticBillEvidence::class)->read(['company_name' => 'Synthetic PRA', 'bill_id' => 10], [1]);
        $this->assertSame('SOURCE_QUERY_FAILED', $r['sources']['federal_ims_attempts']['status']);
        $this->assertSame([['id' => 1]], $r['sources']['pra_attempts']['rows']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($r));
        $this->assertStringNotContainsString('select', json_encode($r));
    }
}
