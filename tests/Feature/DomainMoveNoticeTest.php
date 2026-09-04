<?php

namespace Tests\Feature;

use Tests\TestCase;

class DomainMoveNoticeTest extends TestCase
{
    public function test_notice_is_shared_by_all_three_company_panel_layouts(): void
    {
        foreach ([
            resource_path('views/layouts/app.blade.php'),
            resource_path('views/layouts/pos-app.blade.php'),
            resource_path('views/layouts/fbr-pos-app.blade.php'),
        ] as $layout) {
            $this->assertStringContainsString('<x-domain-move-notice />', file_get_contents($layout));
        }
    }

    public function test_notice_is_daily_for_exactly_seven_calendar_days(): void
    {
        $markup = file_get_contents(resource_path('views/components/domain-move-notice.blade.php'));

        $this->assertStringContainsString('create(2026, 9, 4, 0, 0, 0, \'Asia/Karachi\')', $markup);
        $this->assertStringContainsString('->addDays(7)', $markup);
        $this->assertStringContainsString("timeZone: 'Asia/Karachi'", $markup);
        $this->assertStringContainsString("'taxnest-domain-move-seen-v1'", $markup);
        $this->assertStringContainsString('lastSeen !== today', $markup);
        $this->assertStringContainsString('https://taxnest.pk', $markup);
    }
}