<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\SystemSetting;
use App\Services\FbrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DiValidateOnlyResponseTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('regulatorResponses')]
    public function test_validate_only_distinguishes_regulator_outcomes(array|string $response, int $httpCode, string $expected): void
    {
        SystemSetting::set('demo_mode', 'false');
        $invoice = new Invoice();
        $invoice->forceFill([
            'status' => 'draft', 'total_value_excluding_st' => 200,
            'total_sales_tax' => 36, 'total_amount' => 236,
        ]);
        $invoice->setRelation('company', (object) [
            'fbr_environment' => 'sandbox',
            'fbr_sandbox_token' => Crypt::encryptString('synthetic-validate-only-token'),
        ]);
        $invoice->setRelation('items', collect([new InvoiceItem(['tax' => 18]), new InvoiceItem(['tax' => 18])]));

        $service = new class($response, $httpCode) extends FbrService {
            public array $urls = [];

            public function __construct(private array|string $response, private int $httpCode)
            {
            }

            public function buildPayload($invoice, bool $localOnly = false): array
            {
                return [
                    'invoiceType' => 'Sale Invoice', 'invoiceDate' => '2026-11-25',
                    'sellerNTNCNIC' => '1234567', 'sellerBusinessName' => 'Synthetic Seller',
                    'sellerProvince' => 'Punjab', 'buyerProvince' => 'Punjab',
                    'items' => array_fill(0, 2, [
                        'hsCode' => '1516.2020', 'productDescription' => 'Synthetic item',
                        'rate' => '18%', 'uoM' => 'Kilogram', 'quantity' => 1,
                        'valueSalesExcludingST' => 100, 'salesTaxApplicable' => 18,
                        'totalValues' => 118, 'saleType' => 'Goods at standard rate (default)',
                    ]),
                ];
            }

            protected function sendDirectToFbr(string $url, string $token, string $jsonBody, int $invoiceId): array
            {
                $this->urls[] = $url;

                return [
                    'body' => is_array($this->response) ? json_encode($this->response) : $this->response,
                    'http_code' => $this->httpCode,
                ];
            }
        };

        $result = $service->validateOnly($invoice);
        $this->assertSame($expected, $result['status']);
        $this->assertCount(1, $service->urls);
        $this->assertStringEndsWith('/validateinvoicedata_sb', $service->urls[0]);
        $this->assertSame('draft', $invoice->status);
        $this->assertNull($invoice->fbr_invoice_number);
        if ($expected === 'invalid') {
            $this->assertStringContainsString('Item 2: [0046]', implode(' ', $result['errors']));
        }
    }

    public static function regulatorResponses(): iterable
    {
        $validItem = ['itemSNo' => '1', 'statusCode' => '00', 'status' => 'Valid', 'errorCode' => null, 'error' => ''];
        $secondItem = array_replace($validItem, ['itemSNo' => '2']);
        $valid = ['statusCode' => '00', 'status' => 'Valid', 'invoiceStatuses' => [$validItem, $secondItem]];
        $invalidItem = array_replace($secondItem, ['statusCode' => '01', 'status' => 'Invalid', 'errorCode' => '0046']);
        yield 'valid and unnumbered' => [['validationResponse' => $valid], 200, 'valid'];
        yield 'successful header and rejected line' => [['validationResponse' => array_replace($valid, ['invoiceStatuses' => [$validItem, $invalidItem]])], 200, 'invalid'];
        yield 'invalid header and rejected line' => [['validationResponse' => array_replace($valid, ['status' => 'Invalid', 'invoiceStatuses' => [$validItem, $invalidItem]])], 400, 'invalid'];
        yield 'missing item' => [['validationResponse' => array_replace($valid, ['invoiceStatuses' => [$validItem]])], 200, 'error'];
        yield 'duplicate item' => [['validationResponse' => array_replace($valid, ['invoiceStatuses' => [$validItem, $validItem]])], 200, 'error'];
        yield 'malformed item' => [['validationResponse' => array_replace($valid, ['invoiceStatuses' => [$validItem, 'invalid shape']])], 200, 'error'];
        yield 'number without validation' => [['invoiceNumber' => 'SYNTHETIC-ACK'], 200, 'error'];
        yield 'non-JSON upstream reply' => ['<html>unavailable</html>', 200, 'error'];
        yield 'no connection' => ['', 0, 'error'];
        yield 'upstream unavailable' => ['', 503, 'error'];
    }
}
