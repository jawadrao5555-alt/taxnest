<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Company;
use App\Models\SupportCase;
use App\Services\CompanyProductId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class CompanyProductIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_products_have_independent_series_without_changing_legacy_identity(): void
    {
        $firstDigital = $this->next('D');
        $di = $this->company('Digital first', 'di');
        $pra = $this->company('PRA first', 'pos');
        $fbr = $this->company('IMS first', 'fbrpos', ['pos_type' => 'hospital']);
        $standalone = $this->company('Standalone first', 'pos', ['pos_integration_mode' => 'standalone']);
        $erp = $this->company('General ERP', 'erps');
        $hospital = $this->company('Hospital ERP', 'health', ['health_org_type' => 'hospital']);
        $school = $this->company('School ERP', 'erps', ['erps_vertical' => 'school']);
        $lab = $this->company('Lab ERP', 'erps', ['erps_vertical' => 'health', 'health_org_type' => 'lab']);

        foreach ([$this->code('D', $firstDigital) => $di, 'P01' => $pra, 'F01' => $fbr, 'S01' => $standalone, 'E01' => $erp,
            'EH01' => $hospital, 'ES01' => $school, 'EL01' => $lab] as $identifier => $company) {
            $this->assertSame($identifier, $company->product_company_id);
            $this->assertTrue($company->is(CompanyProductId::resolve(strtolower($identifier))));
            $this->assertTrue($company->is(CompanyProductId::resolve((string) $company->id)));
            $this->assertTrue($company->is(CompanyProductId::resolve($company->account_code)));
        }

        $this->assertSame($this->code('D', $firstDigital + 1), $this->company('Digital second', 'di')->product_company_id);
        $this->assertSame('P02', $this->company('PRA second', 'pos')->product_company_id);
        $this->assertNull(CompanyProductId::resolve('99999999999999999999'));
        $this->assertNull(CompanyProductId::resolve('000'));
        DB::table('company_product_id_counters')->where('series', 'D')->update(['next_serial' => 100]);
        $this->assertSame('D100', $this->company('Digital above 99', 'di')->product_company_id);
    }

    public function test_edition_changes_preserve_old_aliases_and_deleted_serials_are_not_recycled(): void
    {
        $company = $this->company('Changed edition', 'pos', ['pos_integration_mode' => 'standalone']);
        $legacy = $company->account_code;
        $company->update(['pra_reporting_enabled' => true]);
        $this->assertSame('S01', $company->product_company_id);

        $company->update(['pos_integration_mode' => 'pra']);
        $this->assertSame('P01', $company->product_company_id);
        $this->assertSame($legacy, $company->account_code);
        $this->assertTrue($company->is(CompanyProductId::resolve('S01')));

        $company->update(['product_type' => 'fbrpos']);
        $this->assertSame('F01', $company->product_company_id);
        $this->assertTrue($company->is(CompanyProductId::resolve('P01')));
        $company->update(['product_type' => 'pos']);
        $this->assertSame('P01', $company->product_company_id);

        $company->delete();
        $this->assertNull(CompanyProductId::resolve('P01'));
        $company->restore();
        $this->assertTrue($company->is(CompanyProductId::resolve('P01')));
        $company->forceDelete();
        $this->assertSame('P02', $this->company('PRA after deletion', 'pos')->product_company_id);
        $this->assertNull(CompanyProductId::resolve('S01'));
    }

    public function test_backfill_orders_legacy_companies_including_archived_rows_and_reruns_safely(): void
    {
        $digitalCode = $this->code('D', $this->next('D'));
        $older = DB::table('companies')->insertGetId(['name' => 'Older', 'ntn' => 'backfill-old', 'product_type' => 'pos',
            'account_code' => 'PRA-09001', 'deleted_at' => now()]);
        $other = DB::table('companies')->insertGetId(['name' => 'Interleaved', 'ntn' => 'backfill-other', 'product_type' => 'di']);
        $later = DB::table('companies')->insertGetId(['name' => 'Newer', 'ntn' => 'backfill-young', 'product_type' => 'pos',
            'account_code' => 'PRA-09003']);

        $migration = require database_path('migrations/2027_01_01_000030_create_company_product_ids.php');
        $migration->up();
        $migration->up();
        $this->assertDatabaseHas('companies', ['id' => $older, 'account_code' => 'PRA-09001', 'product_company_id' => 'P01']);
        $this->assertDatabaseHas('companies', ['id' => $other, 'product_company_id' => $digitalCode]);
        $this->assertDatabaseHas('companies', ['id' => $later, 'account_code' => 'PRA-09003', 'product_company_id' => 'P02']);
        $this->assertSame('P03', $this->company('Fresh PRA after backfill', 'pos')->product_company_id);

        $migration->down();
        $migration->up();
        $this->assertSame('P04', $this->company('Fresh PRA after rollback', 'pos')->product_company_id);

        $createdByOldCode = DB::table('companies')->insertGetId(['name' => 'Created during code rollback',
            'ntn' => 'backfill-old-code', 'product_type' => 'pos']);
        DB::table('companies')->where('id', $later)->update(['product_type' => 'fbrpos']);
        $this->artisan('companies:sync-product-ids')->assertSuccessful();
        $this->artisan('companies:sync-product-ids')->assertSuccessful();
        $this->assertDatabaseHas('companies', ['id' => $createdByOldCode, 'product_company_id' => 'P05']);
        $this->assertDatabaseHas('companies', ['id' => $later, 'product_company_id' => 'F01']);
        $this->assertSame($later, CompanyProductId::resolve('P02')?->id);
    }

    public function test_failed_company_transaction_keeps_both_registry_and_counter_consistent(): void
    {
        $firstDigital = $this->code('D', $this->next('D'));
        try {
            DB::transaction(function () use ($firstDigital) {
                $this->assertSame($firstDigital, $this->company('Rolled back', 'di')->product_company_id);
                throw new RuntimeException('Force rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Force rollback', $exception->getMessage());
        }

        $this->assertNull(CompanyProductId::resolve($firstDigital));
        $this->assertSame($firstDigital, $this->company('Committed', 'di')->product_company_id);
    }

    public function test_admin_search_and_company_detail_accept_new_and_previous_ids(): void
    {
        $company = $this->company('Synthetic Company ID Search', 'di');
        $this->company('Unrelated Company', 'pos');
        $admin = AdminUser::create(['name' => 'Synthetic Root', 'email' => 'product-id@example.test',
            'password' => bcrypt('fixture-only-password'), 'role' => 'super_admin']);
        $this->actingAs($admin, 'admin');

        foreach ([strtolower($company->product_company_id), $company->account_code, $company->id] as $identifier) {
            $this->get(route('saas.admin.companies', ['search' => $identifier]))->assertOk()
                ->assertSee($company->name)->assertSee($company->product_company_id)->assertDontSee('Unrelated Company');
        }

        $this->get(route('saas.admin.companies.show', $company->id))->assertOk()->assertSee('Company ID')->assertSee($company->product_company_id)
            ->assertSee('Legacy account code')->assertSee($company->account_code);
    }

    public function test_usage_support_and_live_ops_use_the_current_code_and_accept_an_older_alias(): void
    {
        $company = $this->company('Synthetic Company History', 'di');
        $previousCode = $company->product_company_id;
        $company->update(['product_type' => 'pos']);
        $other = $this->company('Unrelated Company History', 'pos');
        $admin = AdminUser::create(['name' => 'Synthetic Support', 'email' => 'product-id-support@example.test',
            'password' => bcrypt('fixture-only-password'), 'role' => 'super_admin']);

        foreach ([$company, $other] as $index => $caseCompany) {
            SupportCase::create(['company_id' => $caseCompany->id, 'opened_by_admin_id' => $admin->id,
                'source_box' => 'inbox', 'source_uid' => $index + 1, 'subject' => 'Serial support case '.$index,
                'status' => SupportCase::STATUS_OPEN, 'opened_at' => now()]);
        }

        $this->actingAs($admin, 'admin');
        foreach (['saas.admin.usage' => 'company_id', 'saas.admin.live-ops' => 'search', 'saas.admin.support-cases' => 'company_id'] as $route => $filter) {
            $this->get(route($route, [$filter => $previousCode]))->assertOk()->assertSee($company->name)
                ->assertSee($company->product_company_id)->assertDontSee($other->name);
        }
    }

    private function next(string $series): int
    {
        return (int) DB::table('company_product_id_counters')->where('series', $series)->value('next_serial');
    }

    private function code(string $series, int $serial): string
    {
        return sprintf('%s%02d', $series, $serial);
    }

    private function company(string $label, string $product, array $attributes = []): Company
    {
        return Company::create($attributes + ['name' => $label, 'ntn' => 'serial-'.str($label)->slug(), 'product_type' => $product]);
    }
}
