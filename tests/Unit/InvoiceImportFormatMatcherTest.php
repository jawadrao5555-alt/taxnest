<?php

namespace Tests\Unit;

use App\Services\InvoiceImportFormatMatcher;
use PHPUnit\Framework\TestCase;

class InvoiceImportFormatMatcherTest extends TestCase
{
    public function test_matches_saved_mapping_when_columns_are_reordered(): void
    {
        $result = (new InvoiceImportFormatMatcher())->match(
            ['Quantity', ' Outlet Name ', 'Product-Name', 'Extra Column'],
            [['id' => 11, 'mapping' => [
                'buyer_name' => 'Outlet Name',
                'description' => 'Product Name',
                'quantity' => 'Quantity',
            ]]]
        );

        $this->assertSame(['preset_id' => 11, 'reason' => 'unique_exact_header_match'], $result);
    }

    public function test_rejects_duplicate_source_headers(): void
    {
        $result = (new InvoiceImportFormatMatcher())->match(
            ['Outlet Name', 'outlet-name', 'Quantity'],
            [['id' => 2, 'mapping' => ['buyer_name' => 'Outlet Name', 'quantity' => 'Quantity']]]
        );

        $this->assertNull($result['preset_id']);
    }

    public function test_rejects_multiple_matching_presets(): void
    {
        $presets = [
            ['id' => 1, 'mapping' => ['buyer_name' => 'Outlet Name']],
            ['id' => 2, 'mapping' => ['buyer_name' => 'Outlet Name']],
        ];

        $this->assertSame(
            ['preset_id' => null, 'reason' => 'ambiguous_presets'],
            (new InvoiceImportFormatMatcher())->match(['Outlet Name'], $presets)
        );
    }

    public function test_rejects_renamed_required_source_column(): void
    {
        $this->assertSame(
            ['preset_id' => null, 'reason' => 'no_exact_match'],
            (new InvoiceImportFormatMatcher())->match(
                ['Customer Name', 'Quantity'],
                [['id' => 3, 'mapping' => ['buyer_name' => 'Outlet Name', 'quantity' => 'Quantity']]]
            )
        );
    }
}
