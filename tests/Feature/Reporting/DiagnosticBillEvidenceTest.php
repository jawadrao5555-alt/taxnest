<?php

namespace Tests\Feature\Reporting;

use App\Services\Reporting\DiagnosticBillEvidence;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DiagnosticBillEvidenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('companies', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('product_type'); });
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
            $t->id(); if ($tenant) $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger($foreign); $t->text('private_payload')->nullable();
        });
    }

    public function test_pra_links_are_explicit_scoped_bounded_and_never_expose_payloads(): void
    {
        $this->table('pos_transactions', 'unused');
        DB::table('pos_transactions')->insert(['id' => 10, 'company_id' => 1, 'unused' => 0]);
        $this->table('pos_payments', 'transaction_id', false);
        $this->table('pra_logs', 'transaction_id');
        DB::table('pos_payments')->insert([
            ['id'=>1, 'transaction_id'=>10, 'private_payload'=>'SECRET-PAYMENT'],
            ['id'=>2, 'transaction_id'=>10, 'private_payload'=>'SECRET-PAYMENT'],
            ['id'=>3, 'transaction_id'=>99, 'private_payload'=>'SECRET-FOREIGN'],
        ]);
        DB::table('pra_logs')->insert([
            ['id'=>1, 'company_id'=>1, 'transaction_id'=>10, 'private_payload'=>'SECRET-FISCAL'],
            ['id'=>2, 'company_id'=>2, 'transaction_id'=>10, 'private_payload'=>'SECRET-FOREIGN'],
        ]);
        $before = DB::table('pos_payments')->get()->toJson();
        $args=['company_name'=>'Synthetic PRA','bill_id'=>10,'limit'=>1];
        $service=app(DiagnosticBillEvidence::class);
        $r=$service->read($args,[1]);
        $this->assertSame([['id'=>1]],$r['sources']['payments']['rows']);
        $this->assertTrue($r['sources']['payments']['has_more']);
        $this->assertSame(1,$r['sources']['payments']['next_after_id']);
        $this->assertSame([['id'=>1]],$r['sources']['pra_attempts']['rows']);
        $this->assertSame('UNAVAILABLE',$r['sources']['stock']['status']);
        $this->assertFalse($r['complete']);
        $this->assertSame('not_assessed',$r['fiscal_acceptance']);
        $this->assertStringNotContainsString('SECRET',json_encode($r));
        $page=$service->read($args+['after_id'=>1],[1]);
        $this->assertSame([['id'=>2]],$page['sources']['payments']['rows']);
        $this->assertSame('NO_RETAINED_LINKS',$page['sources']['pra_attempts']['status']);
        $this->assertSame($before,DB::table('pos_payments')->get()->toJson());
        $this->expectException(ValidationException::class);
        $service->read($args,[2]);
    }

    public function test_other_products_follow_actual_parent_links_and_reject_wrong_tenant_bills(): void
    {
        foreach ([
            [2,'Synthetic DI','invoices','fbr_logs','invoice_id',false,'fbr_attempts'],
            [3,'Synthetic FBR','fbr_pos_transactions','fbr_pos_logs','transaction_id',true,'fbr_attempts'],
            [4,'Synthetic Health','health_bills','health_payments','health_bill_id',true,'payments'],
        ] as [$id,$name,$root,$child,$foreign,$tenant,$key]) {
            $this->table($root,'unused'); $this->table($child,$foreign,$tenant);
            DB::table($root)->insert(['id'=>20,'company_id'=>$id,'unused'=>0]);
            $row=['id'=>1,$foreign=>20,'private_payload'=>'SECRET']; if($tenant)$row['company_id']=$id;
            DB::table($child)->insert($row);
            $r=app(DiagnosticBillEvidence::class)->read(['company_name'=>$name,'bill_id'=>20],[$id]);
            $this->assertSame([['id'=>1]],$r['sources'][$key]['rows']);
            $this->assertStringNotContainsString('SECRET',json_encode($r));
        }
        $this->expectException(ValidationException::class);
        app(DiagnosticBillEvidence::class)->read(['company_name'=>'Synthetic DI','bill_id'=>99],[2]);
    }

    public function test_arbitrary_filters_and_ambiguous_identity_are_refused(): void
    {
        try {
            app(DiagnosticBillEvidence::class)->read(['company_name'=>'Synthetic DI','bill_id'=>1,'table'=>'users'],[2]);
            $this->fail('Arbitrary source accepted.');
        } catch (ValidationException) { $this->assertTrue(true); }
        DB::table('companies')->insert(['id'=>5,'name'=>'Synthetic DI','product_type'=>'di']);
        $this->expectException(ValidationException::class);
        app(DiagnosticBillEvidence::class)->read(['company_name'=>'Synthetic DI','bill_id'=>1],null);
    }
}
