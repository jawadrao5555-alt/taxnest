<?php

namespace Tests\Feature;

use Tests\TestCase;

class CustomerFacingWhatsNewTitleTest extends TestCase
{
    public function test_customer_whats_new_views_use_customer_title_and_do_not_echo_raw_title(): void
    {
        $paths = [
            resource_path('views/layouts/pos-app.blade.php'),
            resource_path('views/layouts/fbr-pos-app.blade.php'),
            resource_path('views/partials/whats-new-detail-modals.blade.php'),
        ];

        foreach ($paths as $path) {
            $html = file_get_contents($path);
            $this->assertNotFalse($html);
            $this->assertStringContainsString('customerTitle()', $html, $path);
            $this->assertDoesNotMatchRegularExpression(
                '/\{\{\s*\$(?:wnu|wnp|whatsNewFeatured|whatsNewPopup|detailUpdate)->title\s*\}\}/',
                $html,
                $path.' still echoes a raw customer title'
            );
        }
    }

    public function test_admin_app_updates_keep_the_stored_title_for_operators(): void
    {
        $admin = file_get_contents(resource_path('views/admin/app-updates.blade.php'));
        $this->assertNotFalse($admin);
        $this->assertStringNotContainsString('customerTitle()', $admin);
    }
}
