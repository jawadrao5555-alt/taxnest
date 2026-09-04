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
        $this->assertStringContainsString('findProduct(id)', $html);
        $this->assertStringNotContainsString('this.product(id)', $html);
        $this->assertStringContainsString('@click.self="closeProductPicker()"', $html);
        $this->assertStringNotContainsString('@click.outside="closeProductPicker()"', $html);
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

    public function test_fixed_product_picker_is_an_explicit_add_control_in_create_and_edit_forms(): void
    {
        $composer = view('pos.partials.deal-product-composer', [
            'accent' => 'emerald',
            'choiceTableOk' => true,
        ])->render();
        $pra = file_get_contents(resource_path('views/pos/deals.blade.php'));
        $fbr = file_get_contents(resource_path('views/fbr-pos/deals.blade.php'));

        $this->assertStringContainsString("openProductPicker('fixed')", $composer);
        $this->assertStringContainsString('x-text="labels.addProducts"', $composer);
        $this->assertSame(2, substr_count($pra, "@include('pos.partials.deal-product-composer'"));
        $this->assertSame(2, substr_count($fbr, "@include('pos.partials.deal-product-composer'"));
    }

    public function test_sale_screens_require_every_configured_choice_group_to_have_usable_options(): void
    {
        $praSale = file_get_contents(resource_path('views/pos/universal.blade.php'));
        $fbrSale = file_get_contents(resource_path('views/fbr-pos/universal.blade.php'));
        $praPayload = file_get_contents(app_path('Http/Controllers/PosController.php'));
        $fbrPayload = file_get_contents(app_path('Http/Controllers/FbrPosController.php'));

        foreach ([$praSale, $fbrSale] as $sale) {
            $this->assertStringContainsString('Array.isArray(group.options) && group.options.length > 0', $sale);
        }
        $this->assertStringContainsString('where(\'is_active\', true)', $praPayload);
        $this->assertStringContainsString('choiceGroups->contains', $praPayload);
        $this->assertStringContainsString('$usableChoiceGroups', $fbrPayload);
        $this->assertStringContainsString('$usableChoiceGroups->count() !== $dealRow->choiceGroups->count()', $fbrPayload);
        $this->assertStringContainsString('where(\'is_active\', true)', $fbrPayload);
    }

    public function test_pra_store_and_provisional_update_payloads_keep_deal_choices_and_rich_snapshots(): void
    {
        $praSale = file_get_contents(resource_path('views/pos/universal.blade.php'));

        $this->assertGreaterThanOrEqual(
            2,
            substr_count($praSale, "deal_choices: c.item_type === 'deal'"),
            'Both new-sale and provisional-update payloads must carry the selected Deal choices.'
        );
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($praSale, "deal_snapshot: c.item_type === 'deal'"),
            'Both new-sale and provisional-update payloads must carry the immutable Deal snapshot.'
        );
        $this->assertStringContainsString(
            'component.recipe_snapshot.map(part => ({ ...part }))',
            $praSale
        );
        $this->assertStringContainsString(
            'tax_facts: component.tax_facts ? { ...component.tax_facts } : null',
            $praSale
        );
    }

    public function test_fbr_edit_failure_restores_every_scalar_control_only_for_its_edit_card(): void
    {
        $fbr = file_get_contents(resource_path('views/fbr-pos/deals.blade.php'));

        foreach ([
            "old('name') : \$deal->name",
            "old('price') : \$deal->price",
            "old('description') : \$deal->description",
            "old('starts_on') : \$deal->starts_on?->format('Y-m-d')",
            "old('ends_on') : \$deal->ends_on?->format('Y-m-d')",
            "old('special_start_time') : (\$deal->special_start_time",
            "old('special_end_time') : (\$deal->special_end_time",
            "old('total_deal_units_limit') : \$deal->total_deal_units_limit",
            "old('daily_deal_units_limit') : \$deal->daily_deal_units_limit",
            "old('is_active') : \$deal->is_active",
            "old('active_days', [])",
        ] as $needle) {
            $this->assertStringContainsString($needle, $fbr);
        }
        $this->assertStringContainsString("\$restoringEditDeal ? old('items', []) : \$dealItemsJson", $fbr);
        $this->assertStringContainsString("\$restoringEditDeal ? old('choice_groups', []) : \$dealChoiceGroupsJson", $fbr);
    }

    public function test_deals_navigation_uses_the_correct_panel_routes_and_permission_plan_gates(): void
    {
        $pra = file_get_contents(resource_path('views/layouts/pos-app.blade.php'));
        $fbr = file_get_contents(resource_path('views/layouts/fbr-pos-app.blade.php'));

        $this->assertStringContainsString('$dealsNavVisible', $pra);
        $this->assertStringContainsString('$posUserLayout->isPosAdmin()', $pra);
        $this->assertStringContainsString('$posNavCan(\'customize\', !$isCashierLayout)', $pra);
        $this->assertStringContainsString("planAllows(\$companyLayout, 'deals_enabled')", $pra);
        $this->assertStringContainsString("route('pos.deals')", $pra);
        $this->assertStringNotContainsString("route('fbrpos.deals')", $pra);

        $this->assertStringContainsString('$fbrDealsNavVisible', $fbr);
        $this->assertStringContainsString('$fbrPlanDeals && $fbrUser && $fbrUser->isPosAdmin()', $fbr);
        $this->assertStringContainsString("route('fbrpos.deals')", $fbr);
        $this->assertStringNotContainsString("route('pos.deals')", $fbr);
    }

    public function test_pra_navigation_hides_the_entire_inventory_section_when_inventory_is_off(): void
    {
        $pra = file_get_contents(resource_path('views/layouts/pos-app.blade.php'));

        $this->assertStringContainsString(
            '@if($inventoryEnabledLayout && $posNavCan(\'inventory\', !$isCashierLayout))',
            $pra
        );
        $this->assertStringNotContainsString(
            '@if(!$inventoryEnabledLayout)<span',
            $pra
        );
    }
}