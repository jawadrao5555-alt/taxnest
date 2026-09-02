<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
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

    public function test_picker_searches_the_full_catalogue_but_renders_a_bounded_incremental_slice(): void
    {
        $html = view('pos.partials.deal-product-composer', [
            'accent' => 'emerald',
            'choiceTableOk' => true,
        ])->render();

        $this->assertStringContainsString('matchingProducts().slice(0, this.picker.limit)', $html);
        $this->assertStringContainsString('limit: 60, step: 60', $html);
        $this->assertStringContainsString('@click="loadMoreProducts()"', $html);
        $this->assertStringContainsString("picker.limit += this.picker.step", $html);
        $this->assertStringContainsString('@input="picker.limit = picker.step"', $html);
        $this->assertStringContainsString('lg:grid-cols-3', $html);
        $this->assertStringContainsString('overflow-y-auto', $html);
    }

    public function test_only_an_explicit_create_form_failure_can_restore_create_deal_input(): void
    {
        $deals = file_get_contents(resource_path('views/pos/deals.blade.php'));

        $this->assertStringContainsString('name="deal_form_context" value="create"', $deals);
        $this->assertStringContainsString("old('deal_form_context') === 'create' && \$errors->any()", $deals);
        $this->assertStringNotContainsString("old('editing_deal_id') === null && \$errors->any()", $deals);
        $this->assertStringContainsString('name="editing_deal_id"', $deals);
    }

    public function test_unrelated_validation_errors_do_not_restore_create_deal_values(): void
    {
        $this->withViewErrors(['profile_name' => 'Profile name is required.']);
        session()->flash('_old_input', ['name' => 'Must not appear in a deal form']);

        // Render the real Deals body without its POS layout: the layout reads
        // currentCompanyId and unrelated navigation data, neither of which is
        // part of the discriminator behavior under test.
        $template = str_replace(
            ['<x-pos-layout>', '</x-pos-layout>'],
            '',
            file_get_contents(resource_path('views/pos/deals.blade.php'))
        );
        $html = Blade::render($template, [
            'deals' => collect(),
            'products' => collect(),
            'productNames' => collect(),
            'choiceTableOk' => true,
        ]);

        $this->assertStringNotContainsString('value="Must not appear in a deal form"', $html);
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