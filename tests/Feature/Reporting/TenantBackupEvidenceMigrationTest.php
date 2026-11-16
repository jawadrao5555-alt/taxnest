<?php
namespace Tests\Feature\Reporting;
use Illuminate\Support\Facades\{DB,Schema};
use Tests\TestCase;
class TenantBackupEvidenceMigrationTest extends TestCase
{
    public function test_backup_time_columns_use_explicit_datetimes_without_implicit_defaults(): void
    {
        Schema::dropAllTables();
        $migration=require database_path('migrations/2026_12_25_030000_create_tenant_backup_evidence.php');$migration->up();
        $columns=collect(Schema::getColumns('tenant_backup_evidence'))->keyBy('name');
        foreach(['event_at','received_at','reported_retained_until','evidence_expires_at'] as $name) {
            $this->assertSame('datetime',$columns[$name]['type_name']);
            $this->assertNull($columns[$name]['default']);
            $this->assertSame($name==='reported_retained_until',$columns[$name]['nullable']);
        }
        $row=['company_id'=>1,'source_id'=>'11111111-1111-4111-8111-111111111111','event_id'=>'22222222-2222-4222-8222-222222222222',
            'reported_status'=>'failed','event_at'=>'2026-11-14 10:00:00','received_at'=>'2026-11-15 11:00:00',
            'evidence_expires_at'=>'2027-02-13 11:00:00','content_hash'=>str_repeat('0',64)];
        DB::table('tenant_backup_evidence')->insert($row);$saved=(array)DB::table('tenant_backup_evidence')->first();
        $this->assertSame($row,array_intersect_key($saved,$row));
        unset($row['received_at']);$row['event_id']='33333333-3333-4333-8333-333333333333';
        try{DB::table('tenant_backup_evidence')->insert($row);$this->fail('Required receipt timestamp cannot be generated.');}
        catch(\Illuminate\Database\QueryException $e){$this->assertStringContainsString('received_at',$e->getMessage());}
        $this->assertSame(1,DB::table('tenant_backup_evidence')->count());
        $migration->down();$this->assertFalse(Schema::hasTable('tenant_backup_evidence'));
    }
}
