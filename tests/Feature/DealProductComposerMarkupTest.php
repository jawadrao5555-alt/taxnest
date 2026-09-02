<?php

namespace Tests\Feature;

use Tests\TestCase;

class DealProductComposerMarkupTest extends TestCase
{
    public function test_shared_composer_keeps_the_existing_deal_request_contract(): void
    {
        $html = view('pos.partials.deal-product-composer', [
            'accent' => 'emerald',
            'choiceTableOk' => true,
        ])->render();

        $this->assertStringContainsString("items[' + idx + '][product_id]", $html);
        $this->assertStringContainsString("items[' + idx + '][quantity]", $html);
        $this->assertStringContainsString("choice_groups[' + groupIdx + '][label]", $html);
        $this->assertStringContainsString("choice_groups[' + groupIdx + '][quantity]", $html);
        $this->assertStringContainsString("choice_groups[' + groupIdx + '][product_ids][]", $html);
    }

    public function test_shared_composer_uses_one_searchable_picker_instead_of_native_multi_selects(): void
    {
        $html = view('pos.partials.deal-product-composer', [
            'accent' => 'blue',
            'choiceTableOk' => true,
        ])->render();

        $this->assertStringContainsString("openProductPicker('fixed')", $html);
        $this->assertStringContainsString("openProductPicker('choice', groupIdx)", $html);
        $this->assertStringContainsString('pickerResults()', $html);
        $this->assertStringContainsString('product.sku', $html);
        $this->assertStringContainsString('product.barcode', $html);
        $this->assertStringNotContainsString('<select multiple', $html);
    }

    public function test_both_pos_panels_use_the_shared_composer(): void
    {
        $pra = file_get_contents(resource_path('views/pos/deals.blade.php'));
        $fbr = file_get_contents(resource_path('views/fbr-pos/deals.blade.php'));

        $needle = "@include('pos.partials.deal-product-composer'";
        $this->assertSame(2, substr_count($pra, $needle));
        $this->assertSame(2, substr_count($fbr, $needle));
        $this->assertStringNotContainsString('<select multiple', $pra);
        $this->assertStringNotContainsString('<select multiple', $fbr);
    }
}