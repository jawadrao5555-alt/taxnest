<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\PosAccessService;
use Tests\TestCase;

/**
 * Authorization invariants for POS Team Custom Access.
 * Pure attribute/path logic — no database needed.
 */
class PosAccessServiceTest extends TestCase
{
    private function member(string $posRole, ?string $access, string $role = 'employee'): User
    {
        $u = new User();
        $u->pos_role = $posRole;
        $u->role = $role;
        $u->pos_custom_access = $access;

        return $u;
    }

    // ── customSet scope rules ──────────────────────────────────────────

    public function test_no_custom_set_returns_null_for_every_role(): void
    {
        foreach (['pos_cashier', 'pos_manager', 'pos_admin', 'pos_waiter'] as $role) {
            $this->assertNull(PosAccessService::customSet($this->member($role, null)));
        }
    }

    public function test_custom_set_applies_only_to_cashier_and_manager(): void
    {
        $json = '["reports","day_close"]';
        $this->assertSame(['reports', 'day_close'], PosAccessService::customSet($this->member('pos_cashier', $json)));
        $this->assertSame(['reports', 'day_close'], PosAccessService::customSet($this->member('pos_manager', $json)));
        // Confined roles: stored grants are IGNORED — confinement supersedes.
        foreach (['pos_waiter', 'pos_kitchen', 'pos_rider', 'pos_delivery', 'archive_viewer', 'local_viewer', 'pos_admin'] as $role) {
            $this->assertNull(PosAccessService::customSet($this->member($role, $json)), "role $role must ignore custom sets");
        }
    }

    public function test_company_admin_can_never_be_restricted(): void
    {
        $owner = $this->member('pos_cashier', '["reports"]', 'company_admin');
        $this->assertNull(PosAccessService::customSet($owner));
    }

    public function test_corrupt_json_and_unknown_keys_are_safe(): void
    {
        $this->assertNull(PosAccessService::customSet($this->member('pos_cashier', 'not-json{')));
        $this->assertNull(PosAccessService::customSet($this->member('pos_cashier', '"just-a-string"')));
        // Unknown keys are dropped; empty array = deny-everything-mapped (valid).
        $this->assertSame(['reports'], PosAccessService::customSet($this->member('pos_cashier', '["reports","bogus_key"]')));
        $this->assertSame([], PosAccessService::customSet($this->member('pos_cashier', '["bogus_key"]')));
    }

    public function test_custom_allows_tri_state(): void
    {
        $u = $this->member('pos_cashier', '["reports"]');
        $this->assertTrue(PosAccessService::customAllows($u, 'reports'));
        $this->assertFalse(PosAccessService::customAllows($u, 'customize'));
        $this->assertNull(PosAccessService::customAllows($this->member('pos_cashier', null), 'reports'));
    }

    // ── featureForPath mapping ─────────────────────────────────────────

    public function test_billing_paths_are_always_unmapped(): void
    {
        // Sale screen + invoice APIs must NEVER be gated — billing cannot break.
        foreach ([
            'pos/invoice/create',
            'pos/v2/invoice/create',
            'pos/api/provisional-bills',
            'pos/api/draft/save',
            'pos/settings/theme',   // per-device pref, every role
            'pos/set-language',
            'pos/my-profile',
            'pos/logout',
        ] as $path) {
            $this->assertNull(PosAccessService::featureForPath($path), "$path must stay open");
        }
    }

    public function test_feature_paths_map_correctly(): void
    {
        $expect = [
            'pos/dashboard' => 'dashboard',
            'pos/restaurant/dashboard' => 'dashboard',
            'pos/transactions' => 'orders',
            'pos/transaction/55/edit' => 'orders',
            'pos/transaction/55/retry-pra' => 'orders',
            'pos/archive' => 'orders',
            'pos/local-bills' => 'orders',
            'pos/products/labels' => 'products',
            'pos/customers/9/history' => 'customers',
            'pos/restaurant/table-management' => 'tables',
            'pos/restaurant/floors/3' => 'tables',
            'pos/restaurant/kds' => 'kitchen',
            'pos/deliveries/7/assign' => 'deliveries',
            'pos/riders' => 'riders',
            'pos/riders/4/settle' => 'deliveries', // cashiers receive rider cash
            'pos/reports/csv' => 'reports',
            'pos/restaurant/cancelled-orders' => 'reports',
            'pos/tax-reports/pdf' => 'tax_reports',
            'pos/day-close' => 'day_close',
            'pos/inventory/stock' => 'inventory',
            'pos/restaurant/ingredients' => 'inventory',
            'pos/customize' => 'customize',
            'pos/features' => 'customize',
            'pos/settings/guided-flow' => 'customize',
            'pos/settings/local-billing' => 'customize',
            'pos/restaurant/kitchen-settings' => 'customize',
            'pos/pra-settings' => 'customize',
            'pos/billing' => 'customize',
            'pos/receipt-settings' => 'customize',
            'pos/printer-settings' => 'customize',
            'pos/team/cashier/3/access' => 'team',
        ];
        foreach ($expect as $path => $feature) {
            $this->assertSame($feature, PosAccessService::featureForPath($path), "path $path");
        }
    }
}
