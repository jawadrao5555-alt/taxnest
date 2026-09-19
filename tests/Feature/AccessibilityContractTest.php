<?php

namespace Tests\Feature;

use Tests\TestCase;

class AccessibilityContractTest extends TestCase
{
    public function test_admin_muted_metadata_and_logout_contracts_are_explicit(): void
    {
        $view = file_get_contents(resource_path('views/layouts/admin-app.blade.php'));

        $this->assertNotFalse($view);
        $this->assertStringContainsString('--admin-text-muted-functional: #9ca3af', $view);
        $this->assertStringContainsString('class="text-xs admin-text-muted-functional"', $view);
        $this->assertStringNotContainsString('.dark .text-gray-500', $view);
        $this->assertStringContainsString('aria-label="Log out"', $view);

        $this->assertGreaterThanOrEqual(
            4.5,
            $this->contrastRatio('#9ca3af', '#030712'),
            'Functional SaaS Admin metadata must meet WCAG AA on the dark shell.'
        );
    }

    public function test_company_admin_logout_and_product_header_responsive_contracts(): void
    {
        $navigation = file_get_contents(resource_path('views/layouts/navigation.blade.php'));
        $productCreate = file_get_contents(resource_path('views/products/create.blade.php'));

        $this->assertNotFalse($navigation);
        $this->assertNotFalse($productCreate);
        $this->assertStringContainsString('text-red-700 dark:text-red-400', $navigation);
        $this->assertGreaterThanOrEqual(
            4.5,
            $this->contrastRatio('#b91c1c', '#ffffff'),
            'Company Admin logout must meet WCAG AA on light backgrounds.'
        );
        $this->assertStringContainsString('flex flex-wrap items-center justify-between gap-x-4 gap-y-2', $productCreate);
        $this->assertStringContainsString('class="shrink-0 text-sm', $productCreate);
    }

    private function contrastRatio(string $foreground, string $background): float
    {
        $foregroundLuminance = $this->relativeLuminance($foreground);
        $backgroundLuminance = $this->relativeLuminance($background);

        return (max($foregroundLuminance, $backgroundLuminance) + 0.05)
            / (min($foregroundLuminance, $backgroundLuminance) + 0.05);
    }

    private function relativeLuminance(string $hex): float
    {
        $channels = array_map(
            static fn (int $channel): float => $channel / 255,
            sscanf(ltrim($hex, '#'), '%2x%2x%2x')
        );
        $linear = array_map(
            static fn (float $channel): float => $channel <= 0.03928
                ? $channel / 12.92
                : (($channel + 0.055) / 1.055) ** 2.4,
            $channels
        );

        return (0.2126 * $linear[0]) + (0.7152 * $linear[1]) + (0.0722 * $linear[2]);
    }
}