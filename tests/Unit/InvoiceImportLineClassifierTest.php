<?php

namespace Tests\Unit;

use App\Services\InvoiceImportLineClassifier;
use PHPUnit\Framework\TestCase;

final class InvoiceImportLineClassifierTest extends TestCase
{
    public function test_returnable_bottle_beverage_remains_product(): void
    {
        $this->assertSame('product_review', (new InvoiceImportLineClassifier())->classify('Coca-Cola RB 250ML'));
    }

    public function test_explicit_empty_bottles_are_separately_reviewed(): void
    {
        $this->assertSame('packaging_review', (new InvoiceImportLineClassifier())->classify('Empty Bottle Deposit'));
    }

    public function test_damages_are_not_silently_dropped(): void
    {
        $this->assertSame('adjustment_review', (new InvoiceImportLineClassifier())->classify('Damaged Goods'));
    }

    public function test_unknown_blank_label_requires_review(): void
    {
        $this->assertSame('review', (new InvoiceImportLineClassifier())->classify(''));
    }
}
