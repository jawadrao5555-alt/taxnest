<?php

namespace Tests\Feature;

use App\Http\Controllers\DiFbrReferenceController;
use App\Models\Branch;
use App\Models\Company;
use App\Services\DiFbrReferences;
use App\Services\FbrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DiFbrReferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_options_follow_invoice_date_origin_and_selected_parents(): void
    {
        $this->fakeReferences();
        $company = $this->company();
        $references = app(DiFbrReferences::class);
        $line = ['hs' => '01012100', 'sale_type' => 'Goods at Reduced Rate',
            'rate' => '5.00%', 'schedule' => 'Synthetic Schedule'];

        $choices = $references->options($company, '2026-08-01', 'Punjab', $line);
        $this->assertSame(['5%'], array_column($choices['rates'], 'label'));
        $this->assertSame(['Synthetic Schedule'], array_column($choices['schedules'], 'label'));
        $this->assertSame(['70'], array_column($choices['serials'], 'label'));
        $this->assertSame(['Kilogram'], array_column($choices['units'], 'label'));
        $this->assertSame('Kilogram', $references->payloadUnit($company, '01012100', 'Kilograms'));

        Http::assertSentCount(6);
        $this->assertSame($choices, $references->options($company, '2026-08-01', 'Punjab', $line));
        Http::assertSentCount(6);

        foreach ([['2026-08-01', 'Sindh'], ['2026-08-02', 'Punjab']] as [$date, $province]) {
            $changed = $references->options($company, $date, $province, $line);
            $this->assertSame(['7%'], array_column($changed['rates'], 'label'));
            $this->assertSame([], $changed['schedules']);
            $this->assertSame([], $changed['serials']);
        }

        $payload = ['hsCode' => '0101.2100', 'saleType' => 'Goods at Reduced Rate', 'rate' => '5%',
            'uoM' => 'Kilogram', 'sroScheduleNo' => 'Synthetic Schedule', 'sroItemSerialNo' => '70'];
        $this->assertSame([], $references->errors($company, '2026-08-01', 'Punjab', $payload));
        $payload['sroItemSerialNo'] = '71';
        $payload['uoM'] = 'Liter';
        $this->assertSame([
            'The reporting unit is not confirmed for this HS code.',
            'The serial is not confirmed for this Schedule/SRO.',
        ], $references->errors($company, '2026-08-01', 'Punjab', $payload));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'hs_code=0101.2100')
            && str_contains($request->url(), 'annexure_id=3'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'sro_id=21')
            && str_contains($request->url(), 'date=2026-08-01'));
    }

    public function test_reference_controller_uses_owned_branch_and_refuses_foreign_branch(): void
    {
        $this->fakeReferences();
        $company = $this->company();
        $other = $this->company('7654321');
        $branch = Branch::forceCreate(['company_id' => $company->id, 'name' => 'Synthetic Sindh', 'province' => 'Sindh']);
        $foreign = Branch::forceCreate(['company_id' => $other->id, 'name' => 'Synthetic Other', 'province' => 'Punjab']);
        app()->instance('currentCompanyId', $company->id);

        $controller = app(DiFbrReferenceController::class);
        $lookup = fn ($branchId) => $controller(
            Request::create('/invoice/fbr-reference-options', 'GET', [
                'invoice_date' => '2026-08-01', 'branch_id' => $branchId,
                'schedule_type' => 'reduced', 'tax_rate' => 5, 'hs' => '01012100',
            ]),
            app(DiFbrReferences::class),
            app(FbrService::class)
        );

        $response = $lookup($branch->id)->getData(true);
        $this->assertSame('Sindh', $response['province']);
        $this->assertSame(['7%'], array_column($response['options']['rates'], 'label'));
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $lookup($foreign->id);
    }

    public function test_failed_lookup_is_distinct_from_no_choices_and_retries(): void
    {
        $company = $this->company();
        $company->fbr_sandbox_token = null;
        $references = app(DiFbrReferences::class);
        $line = ['hs' => '01012100', 'sale_type' => 'Goods at Reduced Rate', 'rate' => '5%'];
        $this->assertSame(['rates' => null, 'schedules' => null, 'serials' => null, 'units' => null],
            $references->options($company, '2026-08-01', 'Punjab', $line));
        Http::assertNothingSent();

        $company->fbr_sandbox_token = Crypt::encryptString('fictional-reference-token');
        $available = false;
        Http::fake(function ($request) use (&$available) {
            if (!$available) {
                return Http::response([], 503);
            }

            return Http::response(match (parse_url($request->url(), PHP_URL_PATH)) {
                '/pdi/v1/provinces' => [['stateProvinceCode' => 7, 'stateProvinceDesc' => 'Punjab']],
                '/pdi/v1/transtypecode' => [['transaction_TYPE_ID' => 12, 'transaction_DESC' => 'Goods at Reduced Rate']],
                default => [],
            });
        });
        $this->assertNull($references->options($company, '2026-08-01', 'Punjab', $line)['rates']);
        $available = true;
        $this->assertSame([], $references->options($company, '2026-08-01', 'Punjab', $line)['rates']);
    }

    private function company(string $ntn = '1234567'): Company
    {
        return Company::forceCreate([
            'name' => 'Synthetic reference seller '.$ntn, 'ntn' => $ntn, 'product_type' => 'di',
            'province' => 'Punjab', 'fbr_environment' => 'sandbox',
            'fbr_sandbox_token' => Crypt::encryptString('fictional-reference-token'),
        ]);
    }

    private function fakeReferences(): void
    {
        Http::fake(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
            return Http::response(match (parse_url($request->url(), PHP_URL_PATH)) {
                '/pdi/v1/provinces' => [
                    ['stateProvinceCode' => 7, 'stateProvinceDesc' => 'PUNJAB'],
                    ['stateProvinceCode' => 8, 'stateProvinceDesc' => 'SINDH'],
                ],
                '/pdi/v1/transtypecode' => [['transaction_TYPE_ID' => 12, 'transaction_DESC' => 'Goods at Reduced Rate']],
                '/pdi/v2/SaleTypeToRate' => ($query['date'] ?? null) === '01-Aug-2026'
                    && ($query['originationSupplier'] ?? null) === '7'
                    ? [['ratE_ID' => 14, 'ratE_DESC' => '5%']]
                    : [['ratE_ID' => 15, 'ratE_DESC' => '7%']],
                '/pdi/v1/SroSchedule' => ($query['rate_id'] ?? null) === '14'
                    ? [['sro_ID' => 21, 'sro_DESC' => 'Synthetic Schedule']]
                    : [],
                '/pdi/v2/SROItem' => [['sro_ITEM_ID' => 30, 'sro_ITEM_DESC' => '70']],
                '/pdi/v2/HS_UOM' => [['uoM_ID' => 4, 'description' => 'Kilogram']],
            });
        });
    }
}
