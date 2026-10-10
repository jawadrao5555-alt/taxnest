<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\IntegrityHashService;
use ReflectionMethod;
use Tests\TestCase;

class DiIntegrityCoverageTest extends TestCase
{
    public function test_each_payload_meaning_field_changes_the_new_fiscal_proof(): void
    {
        $invoice = new Invoice(['company_id' => 91, 'buyer_name' => 'Buyer', 'buyer_address' => 'Address']);
        $item = new InvoiceItem(['quantity' => 1, 'price' => 100, 'tax' => 18, 'default_uom' => 'KG']);
        $item->id = 1;
        $invoice->setRelation('items', collect([$item]));
        $original = IntegrityHashService::generate($invoice);
        foreach (['default_uom' => 'Liter', 'sale_type' => 'Exempt Goods', 'st_withheld_at_source' => true,
            'st_withheld_amount' => 12, 'petroleum_levy' => 10, 'further_tax' => 4, 'extra_tax' => 2,
            'fed_payable' => 5, 'discount' => 1] as $field => $value) {
            $copy = clone $item; $copy->$field = $value;
            $invoice->setRelation('items', collect([$copy]));
            $this->assertNotSame($original, IntegrityHashService::generate($invoice), $field);
        }
        $invoice->setRelation('items', collect([$item]));
        foreach (['buyer_name', 'buyer_address', 'supplier_province', 'destination_province', 'company_id'] as $field) {
            $copy = clone $invoice; $copy->$field = $field === 'company_id' ? 92 : 'Changed';
            $this->assertNotSame($original, IntegrityHashService::generate($copy), $field);
        }
        $this->assertSame($original, IntegrityHashService::generate($invoice));
    }

    public function test_v2_generation_preserves_historical_proof_algorithm(): void
    {
        $invoice = new Invoice(['company_id' => 91]);
        $item = new InvoiceItem(['quantity' => 1, 'price' => 100]); $item->id = 1;
        $invoice->setRelation('items', collect([$item]));
        $legacy = new ReflectionMethod(IntegrityHashService::class, 'generateVersion');
        $oldHash = $legacy->invoke(null, $invoice, 2);
        $item->default_uom = 'Liter';
        $this->assertSame($oldHash, $legacy->invoke(null, $invoice, 2));
        $this->assertNotSame($oldHash, IntegrityHashService::generate($invoice));
        $this->assertSame(64, strlen(IntegrityHashService::generate($invoice)));
    }
}
