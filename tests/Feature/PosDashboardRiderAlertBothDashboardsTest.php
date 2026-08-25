<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The rider-settlement reminder must exist on EVERY dashboard a shop lands on.
 *
 * A POS shop does not have one dashboard, it has two: retail companies get
 * /pos/dashboard, restaurant companies get /pos/restaurant/dashboard, and the
 * top nav sends each one to its own. The reminder was built on the retail
 * wrapper only — so the shops that actually run deliveries (restaurants) never
 * saw it, and the owner reported the alert as missing the day after it shipped.
 *
 * Both wrappers must include the partial, and both controllers must feed it,
 * or the reminder silently disappears for half the shops again. The pending
 * figures themselves are pinned by PosDashboardRiderPendingTest.
 */
class PosDashboardRiderAlertBothDashboardsTest extends TestCase
{
    private const PARTIAL = 'pos.partials.rider-settlement-pending';

    /** @return array<string, array{0:string,1:string}> wrapper blade => controller */
    public static function dashboards(): array
    {
        return [
            'retail' => [
                'resources/views/pos/dashboard.blade.php',
                'app/Http/Controllers/PosController.php',
            ],
            'restaurant' => [
                'resources/views/pos/restaurant/dashboard.blade.php',
                'app/Http/Controllers/RestaurantPosController.php',
            ],
        ];
    }

    /**
     * @dataProvider dashboards
     */
    public function test_the_dashboard_renders_the_rider_settlement_alert(string $wrapper, string $controller): void
    {
        $blade = file_get_contents(base_path($wrapper));

        $this->assertStringContainsString(
            self::PARTIAL,
            $blade,
            $wrapper . ' must include the rider settlement reminder — a delivery shop reads its dashboard, not the day-close report.'
        );

        $php = file_get_contents(base_path($controller));

        $this->assertStringContainsString(
            'PosRiderKhataAlert::pending',
            $php,
            $controller . ' must supply $riderPending, otherwise the partial renders nothing at all.'
        );

        $this->assertStringContainsString(
            "'riderPending'",
            $php,
            $controller . ' must pass riderPending to its view.'
        );

        // Owner (25 Aug 2026): banner sirf pending par aata hai, chip ki jagah
        // pakki honi chahiye — dono dashboard ko summary bhi bhejni hai warna
        // chip aadhi dukanon par gayab.
        $this->assertStringContainsString(
            'PosRiderKhataAlert::summary',
            $php,
            $controller . ' must supply $riderChip for the permanent chip.'
        );

        $this->assertStringContainsString(
            "'riderChip'",
            $php,
            $controller . ' must pass riderChip to its view.'
        );
    }

    /** Chip pending-bills row ka mustaqil hissa hai — zero par bhi apni jagah. */
    public function test_the_pending_bills_row_carries_a_permanent_rider_chip(): void
    {
        $tile = file_get_contents(base_path('resources/views/pos/partials/pending-bills-tile.blade.php'));

        $this->assertStringContainsString('pos.pending_rider_settlement', $tile);
        $this->assertStringContainsString("route('pos.deliveries')", $tile);
        $this->assertStringContainsString(
            'riderChip',
            $tile,
            'Chip apna figure riderChip se hi leta hai — FBR dashboard yeh tile bina riderChip ke reuse karta hai.'
        );
    }

    /** Both dashboards must read the SAME khata, never a second copy of the query. */
    public function test_there_is_only_one_source_for_the_pending_khata(): void
    {
        $service = base_path('app/Services/PosRiderKhataAlert.php');

        $this->assertFileExists($service);

        foreach (['PosController.php', 'RestaurantPosController.php'] as $file) {
            $php = file_get_contents(base_path('app/Http/Controllers/' . $file));

            $this->assertStringNotContainsString(
                "whereNull('rider_settlement_id')\n            ->where(function (\$s) {",
                $php,
                $file . ' must not carry its own copy of the open-cash predicate.'
            );
        }
    }
}
