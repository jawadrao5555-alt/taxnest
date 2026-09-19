<?php

namespace Tests\Feature;

use Tests\TestCase;

class FbrPosCustomersAlpineScopeTest extends TestCase
{
    public function test_customer_rows_share_one_alpine_scope_with_their_edit_rows(): void
    {
        $view = file_get_contents(resource_path('views/fbr-pos/customers.blade.php'));

        $this->assertSame(1, substr_count($view, 'x-data="custRow('));
        $this->assertStringContainsString(
            '<tbody class="divide-y divide-gray-100 dark:divide-gray-800" x-data="custRow(',
            $view
        );
        $this->assertSame(0, preg_match('/<tr\b[^>]*x-data="custRow\(/', $view));
        $this->assertStringContainsString('<tr x-show="editing"', $view);
        $this->assertStringContainsString('x-if="!addrLoading && addresses.length === 0"', $view);
        $this->assertStringContainsString('var next = row.nextElementSibling;', $view);

        $scopeStart = strpos($view, '<tbody class="divide-y divide-gray-100 dark:divide-gray-800" x-data="custRow(');
        $dataRow = strpos($view, '<tr class="cust-row', $scopeStart);
        $editRow = strpos($view, '<tr x-show="editing"', $dataRow);
        $scopeEnd = strpos($view, '</tbody>', $editRow);

        $this->assertNotFalse($scopeStart);
        $this->assertNotFalse($dataRow);
        $this->assertNotFalse($editRow);
        $this->assertNotFalse($scopeEnd);
        $this->assertLessThan($dataRow, $scopeStart);
        $this->assertLessThan($editRow, $dataRow);
        $this->assertLessThan($scopeEnd, $editRow);
    }
}