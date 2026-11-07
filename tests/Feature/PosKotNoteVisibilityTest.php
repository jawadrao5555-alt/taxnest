<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderItem;
use App\Models\User;
use App\Support\PosPrintDesigns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosKotNoteVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function company(array $attributes = []): Company
    {
        return Company::create(array_merge([
            'name' => 'Synthetic Kitchen', 'ntn' => '7654321', 'product_type' => 'pos',
            'status' => 'approved', 'company_status' => 'approved', 'is_internal_account' => true,
            'restaurant_mode' => true, 'kot_show_kitchen_notes' => false,
            'feature_flags' => ['kot' => true, 'kitchen' => true],
        ], $attributes))->fresh();
    }

    private function user(Company $company, string $role = 'pos_admin'): User
    {
        return User::factory()->create([
            'company_id' => $company->id, 'role' => 'employee', 'pos_role' => $role, 'is_active' => true,
        ]);
    }

    private function ticket(Company $company, bool $shim = false, string $design = 'inherit'): string
    {
        $order = new RestaurantOrder(['company_id' => $company->id,
            'order_number' => 'SYNTHETIC-28', 'order_type' => 'takeaway',
            'kitchen_notes' => "Less chilli <please>\nPack separately"]);
        $order->exists = !$shim;
        $order->created_at = now();
        $order->setRelation('table', null);
        $order->setRelation('creator', null);
        $item = new RestaurantOrderItem(['item_name' => 'Synthetic Pizza', 'item_type' => 'manual',
            'quantity' => 2, 'special_notes' => 'No onions']);
        $item->id = 1;
        $items = collect([$item]);
        $company->invoice_display_prefs = ['receipt_options' => ['local' => ['kot_design' => $design]]];

        return view('pos.restaurant.kitchen-ticket', [
            'order' => $order, 'company' => $company, 'ticketItems' => $items,
            'grouped' => collect(['ALL' => $items]), 'stationLabel' => null,
            'delta' => false, 'kotBatchNo' => null, 'newItemIds' => collect(),
        ])->render();
    }

    public function test_explicit_enable_recovers_notes_without_resetting_layout_or_other_tenant(): void
    {
        $company = $this->company(['kot_align_center' => true, 'kot_compact' => true,
            'kot_show_customer' => true, 'kot_left_margin_mm' => 7, 'print_on_hold' => true]);
        $other = $this->company(['ntn' => '7654322']);
        $this->actingAs($this->user($company), 'pos');
        $before = $company->getAttributes();
        $otherBefore = $other->getAttributes();
        $rev = $company->posConfigRev();

        // Reproduce the report: receipt notes exist but the saved OFF choice hides
        // order notes on the KOT. Per-item instructions already worked.
        $this->assertStringNotContainsString('Less chilli', $this->ticket($company));
        $this->assertStringContainsString('No onions', $this->ticket($company));

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->postJson(route('pos.restaurant.kitchen-notes.enable'), [
                'company_id' => $other->id, 'kot_align_center' => false,
            ])->assertOk()->assertJson(['success' => true, 'enabled' => true]);
        }
        $company->refresh();
        $this->assertTrue($company->kot_show_kitchen_notes);
        $this->assertNotSame($rev, $company->posConfigRev());
        foreach ($before as $key => $value) {
            if (!in_array($key, ['kot_show_kitchen_notes', 'updated_at'], true)) {
                $this->assertSame($value, $company->getAttributes()[$key], $key);
            }
        }
        $this->assertSame($otherBefore, $other->fresh()->getAttributes());

        foreach (array_keys(PosPrintDesigns::KOTS) as $design) {
            foreach ([false, true] as $shim) {
                $html = $this->ticket($company, $shim, $design);
                $this->assertStringContainsString('Less chilli &lt;please&gt;', $html, $design);
                $this->assertStringContainsString('Pack separately', $html, $design);
                $this->assertStringContainsString('No onions', $html, $design);
                $this->assertStringNotContainsString('<please>', $html);
            }
        }
    }

    public function test_unprivileged_cashier_cannot_enable_notes(): void
    {
        $company = $this->company();
        $this->actingAs($this->user($company, 'pos_cashier'), 'pos')
            ->postJson(route('pos.restaurant.kitchen-notes.enable'))->assertStatus(302);
        $this->assertFalse($company->fresh()->kot_show_kitchen_notes);
    }

    public function test_custom_access_denial_blocks_a_manager(): void
    {
        $company = $this->company();
        $user = $this->user($company, 'pos_manager');
        $user->forceFill(['pos_custom_access' => json_encode(['sale'])])->save();
        $this->actingAs($user, 'pos')->postJson(route('pos.restaurant.kitchen-notes.enable'))->assertForbidden();
        $this->assertFalse($company->fresh()->kot_show_kitchen_notes);
    }

    public function test_note_editor_offers_explicit_recovery_to_admin_but_not_cashier(): void
    {
        $company = $this->company();
        foreach (['pos_admin' => true, 'pos_cashier' => false] as $role => $canEnable) {
            $this->actingAs($this->user($company, $role), 'pos');
            $html = view('pos.partials.kot-note-status', compact('company'))->render();
            $this->assertStringContainsString(__('pos.kot_note_off_warning'), $html);
            $this->assertSame($canEnable, str_contains($html, __('pos.kot_note_enable_shop')));
            $this->assertStringContainsString(__('pos.kot_note_already_sent'), $html);
        }
        $this->assertFalse($company->fresh()->kot_show_kitchen_notes);
    }
}
