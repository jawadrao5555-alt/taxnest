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

    public function test_full_cci_pilot_routes_explicit_empty_and_damage_labels_to_review(): void
    {
        $grid = json_decode(file_get_contents(__DIR__.'/../Fixtures/smart-di/cci-voyage-sanitized.json'), true);
        $rows = (new \App\Services\CciVoyageInvoiceParser())->parseGrids($grid)['rows'];
        $counts = [];
        foreach ($rows as $row) {
            $class = (new InvoiceImportLineClassifier())->classify($row['data']['description']);
            $counts[$class] = ($counts[$class] ?? 0) + 1;
        }
        $this->assertSame(90, $counts['packaging_review'] ?? 0);
        $this->assertSame(12, $counts['adjustment_review'] ?? 0);
        $this->assertSame(311, $counts['product_review'] ?? 0);
        $this->assertSame('product_review', (new InvoiceImportLineClassifier())->classify('COCA-COLA RB 250 ML'));
    }

    public function test_unknown_blank_label_requires_review(): void
    {
        $this->assertSame('review', (new InvoiceImportLineClassifier())->classify(''));
    }
}
