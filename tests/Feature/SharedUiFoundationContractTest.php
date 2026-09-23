<?php

namespace Tests\Feature;

use Tests\TestCase;

class SharedUiFoundationContractTest extends TestCase
{
    public function test_every_supported_shell_loads_the_shared_ui_foundation_after_utility_css(): void
    {
        $layouts = [
            'app.blade.php',
            'admin-app.blade.php',
            'agent-app.blade.php',
            'franchise-app.blade.php',
            'guest.blade.php',
            'health-app.blade.php',
            'pos-app.blade.php',
            'fbr-pos-app.blade.php',
        ];

        foreach ($layouts as $layout) {
            $html = file_get_contents(resource_path('views/layouts/'.$layout));
            $this->assertNotFalse($html);
            $sharedPosition = strpos($html, 'css/taxnest-ui.css?v=1.1');
            $utilityPosition = str_contains($html, '@vite(')
                ? strpos($html, '@vite(')
                : strpos($html, 'cdn.tailwindcss.com');
            $mobilePosition = strpos($html, 'css/mobile.css');

            $this->assertNotFalse($sharedPosition, $layout.' must load the shared layer');
            $this->assertNotFalse($utilityPosition, $layout.' must declare a utility CSS source');
            $this->assertLessThan(
                $sharedPosition,
                $utilityPosition,
                $layout.' must load the shared layer after its utility CSS source'
            );
            if ($mobilePosition !== false) {
                $this->assertLessThan(
                    $sharedPosition,
                    $mobilePosition,
                    $layout.' must load the shared layer after the mobile layer'
                );
            }
        }
    }

    public function test_shared_layer_contains_operational_contrast_and_keyboard_contracts(): void
    {
        $css = file_get_contents(public_path('css/taxnest-ui.css'));
        $this->assertNotFalse($css);
        foreach ([
            '--tn-muted',
            'focus-visible',
            'button:disabled',
            'prefers-reduced-motion',
            'main .text-gray-400',
            'main table tbody tr:hover',
        ] as $contract) {
            $this->assertStringContainsString($contract, $css, $contract.' is missing');
        }
    }

    public function test_company_detail_uses_the_shared_wide_content_rail(): void
    {
        $view = file_get_contents(resource_path('views/saas-admin/companies/show.blade.php'));
        $this->assertNotFalse($view);
        $this->assertStringContainsString('tn-content-rail', $view);
        $this->assertStringNotContainsString('max-w-5xl mx-auto', $view);
    }
}
