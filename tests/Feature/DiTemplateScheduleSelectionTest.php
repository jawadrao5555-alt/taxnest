<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\FbrService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class DiTemplateScheduleSelectionTest extends TestCase
{
    public function test_forms_expose_independent_template_options(): void
    {
        $html = view('invoice.partials.dsi-sro-options')->render();
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $options = new \DOMXPath($dom);

        $schedules = $options->query('//datalist[@id="dsi-sro-schedules"]/option');
        $serials = $options->query('//datalist[@id="dsi-item-serials"]/option');
        $this->assertSame(96, $schedules->length);
        $this->assertSame(729, $serials->length);
        $this->assertSame('01(I)/2022', $schedules->item(0)->getAttribute('value'));
        $this->assertSame(1, $options->query('//datalist[@id="dsi-item-serials"]/option[@value="1(ii)(ii)(b)"]')->length);
    }

    public function test_explicit_pair_wins_over_hs_mapping_in_sandbox(): void
    {
        $payload = $this->buildLine('sandbox', 'Schedule Explicit', '001(B)');
        $this->assertSame('Schedule Explicit', $payload['items'][0]['sroScheduleNo']);
        $this->assertSame('001(B)', $payload['items'][0]['sroItemSerialNo']);
    }

    public function test_matching_partial_pair_can_use_the_configured_hs_serial(): void
    {
        $payload = $this->buildLine('sandbox', 'Schedule Mapped', '');
        $this->assertSame('02(A)', $payload['items'][0]['sroItemSerialNo']);
    }

    public function test_conflicting_partial_pair_identifies_the_missing_serial(): void
    {
        $payload = $this->buildLine('sandbox', 'Schedule Explicit', '');
        $this->assertSame('', $payload['items'][0]['sroItemSerialNo']);
        $errors = (new FbrService())->validatePayloadPreSubmission($payload);
        $this->assertContains('0078', array_column($errors, 'code'));
    }

    public function test_untouched_sandbox_invoice_still_uses_its_automatic_reference(): void
    {
        $payload = $this->buildLine('sandbox', '', '');
        $this->assertSame('Schedule Mapped', $payload['items'][0]['sroScheduleNo']);
        $this->assertSame('02(A)', $payload['items'][0]['sroItemSerialNo']);
    }

    public function test_production_keeps_existing_configured_hs_mapping(): void
    {
        $payload = $this->buildLine('production', 'Schedule Explicit', '001(B)');
        $this->assertSame('Schedule Mapped', $payload['items'][0]['sroScheduleNo']);
        $this->assertSame('02(A)', $payload['items'][0]['sroItemSerialNo']);
    }

    private function buildLine(string $environment, string $schedule, string $serial): array
    {
        $query = Mockery::mock(\Illuminate\Database\Query\Builder::class);
        $query->shouldReceive('whereIn', 'where', 'orderBy')->andReturnSelf();
        $query->shouldReceive('get')->once()->andReturn(collect([(object) [
            'hs_code' => '0101.2100', 'sale_type' => 'reduced',
            'sro_number' => 'Schedule Mapped', 'serial_number_value' => '02(A)',
        ]]));
        DB::shouldReceive('table')->once()->with('hs_code_mappings')->andReturn($query);

        $company = (new Company())->forceFill(['name' => 'Example seller', 'fbr_environment' => $environment, 'ntn' => '1234567']);
        $item = (new InvoiceItem())->forceFill([
            'hs_code' => '0101.2100', 'default_uom' => 'Numbers, pieces, units',
            'sale_type' => 'Goods at Reduced Rate', 'schedule_type' => 'reduced',
            'sro_schedule_no' => $schedule, 'serial_no' => $serial, 'quantity' => 1,
            'price' => 1, 'tax_rate' => 1,
        ]);
        $invoice = (new Invoice())->forceFill([
            'document_type' => 'Sale Invoice', 'invoice_date' => '2026-08-18',
            'internal_invoice_number' => 'D0001', 'buyer_registration_type' => 'Unregistered',
        ]);
        $invoice->setRelation('company', $company);
        $invoice->setRelation('items', collect([$item]));

        return (new FbrService())->buildPayload($invoice, true);
    }
}
