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

    public function test_pos_layouts_do_not_stack_whats_new_over_the_shared_notice_window(): void
    {
        foreach ([
            resource_path('views/layouts/pos-app.blade.php'),
            resource_path('views/layouts/fbr-pos-app.blade.php'),
        ] as $layout) {
            $markup = file_get_contents($layout);

            $this->assertStringContainsString('$sharedDomainAgentNoticeLive', $markup);
            $this->assertStringContainsString('$sharedDomainAgentNoticeLive ? null : $whatsNewUnseen->first()', $markup);
        }

        $posLayout = file_get_contents(resource_path('views/layouts/pos-app.blade.php'));
        $this->assertStringContainsString('if ($sharedDomainAgentNoticeLive)', $posLayout);
        $this->assertStringContainsString('$surveyDismissedSession = true;', $posLayout);
        $this->assertStringContainsString('@if($praElaanShow && !$sharedDomainAgentNoticeLive', $posLayout);
    }

    public function test_notice_is_per_login_session_for_exactly_seven_days_and_closable(): void
    {
        $markup = file_get_contents(resource_path('views/components/domain-move-notice.blade.php'));

        // The window has ONE owner; the component must not re-derive dates.
        $this->assertStringContainsString('\App\Support\SharedServiceNotice::isLive()', $markup);
        $this->assertStringNotContainsString('Asia/Karachi', $markup);
        $this->assertStringContainsString("session()->get('taxnest_domain_agent_notice_key')", $markup);
        $this->assertStringContainsString("'taxnest-domain-agent-seen-{{ \$domainAgentNoticeKey }}'", $markup);
        $this->assertStringContainsString("localStorage.getItem(storageKey) === '1'", $markup);
        $this->assertStringContainsString("localStorage.setItem(storageKey, '1')", $markup);
        $this->assertStringContainsString('id="tn-domain-move-close"', $markup);
        $this->assertStringContainsString('id="tn-domain-move-dismiss"', $markup);
        $this->assertStringContainsString('max-h-[92vh]', $markup);
        $this->assertStringContainsString('style="display:none; z-index:10000;"', $markup);
        $this->assertStringContainsString('.tn-domain-move-card {', $markup);
        $this->assertStringContainsString('background: #fffdf7;', $markup);
        $this->assertStringContainsString('background: #075b5d;', $markup);
        $this->assertStringContainsString('background: #e8bf63;', $markup);
        $this->assertStringContainsString('aria-describedby="tn-domain-move-summary"', $markup);
        $this->assertStringContainsString('https://taxnest.pk', $markup);
        $this->assertStringContainsString('https://taxnest.pk/download', $markup);
        $this->assertStringContainsString("__('pos.domain_agent_update_title')", $markup);
        $this->assertStringContainsString("__('pos.domain_agent_step_one')", $markup);
        $this->assertStringContainsString("__('pos.domain_agent_sale_title')", $markup);
        $this->assertStringContainsString("__('pos.domain_agent_sale_payment')", $markup);
    }
}