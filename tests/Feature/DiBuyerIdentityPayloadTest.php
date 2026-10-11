<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Services\FbrService;
use Tests\TestCase;

class DiBuyerIdentityPayloadTest extends TestCase
{
    private function payload(?string $ntn, ?string $cnic, int $companyId = 91): array
    {
        $company = new Company(['name' => 'Fictional seller', 'ntn' => '1234567', 'fbr_environment' => 'production']);
        $company->id = $companyId;
        $invoice = new Invoice(['buyer_ntn' => $ntn, 'buyer_cnic' => $cnic, 'buyer_registration_type' => 'Unregistered']);
        $invoice->setRelation('company', $company);
        $invoice->setRelation('items', collect());
        return (new FbrService())->buildPayload($invoice);
    }

    public function test_cnic_only_buyer_is_forwarded_without_separators(): void
    {
        $this->assertSame('3620312345671', $this->payload(null, '36203-1234567-1')['buyerNTNCNIC']);
        $this->assertSame('3620312345672', $this->payload('  ', '36203-1234567-2', 92)['buyerNTNCNIC']);
    }

    public function test_existing_ntn_takes_precedence_and_walk_in_remains_optional(): void
    {
        $this->assertSame('1234567', $this->payload('1234567-8', '3620312345671')['buyerNTNCNIC']);
        $this->assertSame('', $this->payload(null, null)['buyerNTNCNIC']);
    }
}
