<?php

namespace Tests\Feature\LiveOps;

use App\Exceptions\LiveOpsCompanyResolutionException;
use App\Services\LiveOps\LiveOpsCompanyResolver;
use App\Services\LiveOps\LiveOpsDiagnosticsService;
use Illuminate\Support\Facades\DB;

class LiveOpsCompanyResolverTest extends LiveOpsTestCase
{
    public function test_exact_name_and_account_code(): void
    {
        $id = $this->makeCompany('Pizza Master', ['account_code' => 'PM-01']);
        $r = app(LiveOpsCompanyResolver::class);

        $byName = $r->resolve('Pizza Master');
        $this->assertSame($id, $byName['company_id']);
        $this->assertSame('exact_name', $byName['match_type']);

        $byCode = $r->resolve('PM-01');
        $this->assertSame($id, $byCode['company_id']);
        $this->assertSame('exact_account_code', $byCode['match_type']);
    }

    public function test_case_insensitive_and_normalized_punctuation(): void
    {
        $id = $this->makeCompany('United Bakers');
        $r = app(LiveOpsCompanyResolver::class);

        $this->assertSame($id, $r->resolve('united bakers')['company_id']);
        $this->assertSame($id, $r->resolve('  United   Bakers  ')['company_id']);
        $this->assertSame($id, $r->resolve('United-Bakers')['company_id']);
        $this->assertSame($id, $r->resolve('United, Bakers!')['company_id']);
    }

    public function test_safe_prefix_and_partial_when_unique(): void
    {
        $zfc = $this->makeCompany('ZFC Pizza Point');
        $this->makeCompany('Pizza Master');
        $r = app(LiveOpsCompanyResolver::class);

        $this->assertSame($zfc, $r->resolve('ZFC')['company_id']);
        $this->assertSame($zfc, $r->resolve('zfc pizza')['company_id']);
    }

    public function test_ambiguous_partial_returns_names_and_does_not_pick(): void
    {
        $this->makeCompany('Pizza Master');
        $this->makeCompany('Pizza Point');
        $r = app(LiveOpsCompanyResolver::class);

        try {
            $r->resolve('Pizza');
            $this->fail('expected ambiguity');
        } catch (LiveOpsCompanyResolutionException $e) {
            $this->assertSame(LiveOpsCompanyResolutionException::AMBIGUOUS, $e->reason);
            $names = array_column($e->matches, 'name');
            $this->assertContains('Pizza Master', $names);
            $this->assertContains('Pizza Point', $names);
            $this->assertStringContainsString('Pizza Master', $e->ownerMessage());
            $this->assertStringContainsString('Pizza Point', $e->ownerMessage());
            $this->assertStringNotContainsString('use company_id', $e->ownerMessage());
        }
    }

    public function test_unknown_company_fails_closed(): void
    {
        $this->makeCompany('Pizza Master');
        $this->expectException(LiveOpsCompanyResolutionException::class);
        app(LiveOpsCompanyResolver::class)->resolve('No Such Shop');
    }

    public function test_short_query_does_not_fuzzy_match(): void
    {
        $this->makeCompany('ZFC Pizza Point');
        try {
            app(LiveOpsCompanyResolver::class)->resolve('ZF');
            $this->fail('2-char partial must not match');
        } catch (LiveOpsCompanyResolutionException $e) {
            $this->assertSame(LiveOpsCompanyResolutionException::NOT_FOUND, $e->reason);
        }
    }

    public function test_diagnostics_resolve_company_name_and_stay_isolated(): void
    {
        $a = $this->makeCompany('Alpha Cafe');
        $b = $this->makeCompany('Beta Cafe');
        $this->makeTxn($a, ['total_amount' => 50]);
        $this->makeTxn($b, ['total_amount' => 9999, 'pra_error_message' => 'B-secret-marker']);

        $report = app(LiveOpsDiagnosticsService::class)->run('COMPANY_DIAGNOSTIC', [
            'company_name' => 'alpha cafe',
            'requester' => 'test',
        ]);

        $this->assertSame($a, $report['scope']['company_id']);
        $this->assertSame('Alpha Cafe', $report['data']['company']['name']);
        $json = json_encode($report);
        $this->assertStringNotContainsString('Beta Cafe', $json);
        $this->assertStringNotContainsString('B-secret-marker', $json);
        $this->assertStringNotContainsString('9999', $json);
    }

    public function test_fbr_company_is_out_of_scope_even_by_name(): void
    {
        $this->makeCompany('FBR Shop', ['product_type' => 'fbrpos']);
        $this->expectException(LiveOpsCompanyResolutionException::class);
        app(LiveOpsDiagnosticsService::class)->run('COMPANY_HEALTH', [
            'company_name' => 'FBR Shop',
        ]);
    }

    public function test_numeric_id_still_resolves_internally_without_owner_prompt(): void
    {
        $id = $this->makeCompany('Named Shop');
        $got = app(LiveOpsCompanyResolver::class)->resolve((string) $id);
        $this->assertSame($id, $got['company_id']);
        $this->assertSame('exact_id', $got['match_type']);
    }
}
