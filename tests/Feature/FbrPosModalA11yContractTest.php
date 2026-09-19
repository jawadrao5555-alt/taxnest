<?php

namespace Tests\Feature;

use Tests\TestCase;

class FbrPosModalA11yContractTest extends TestCase
{
    public function test_shared_helper_preserves_stack_focus_dynamic_controls_and_restoration(): void
    {
        $helper = file_get_contents(resource_path('views/partials/modal-a11y-support.blade.php'));

        $this->assertStringContainsString('window.TnModalA11y = window.TnModalA11y ||', $helper);
        $this->assertStringContainsString('stack: []', $helper);
        $this->assertStringContainsString('data-tn-modal-owner', $helper);
        $this->assertStringContainsString('this.controls(root)', $helper);
        $this->assertStringContainsString('document.addEventListener(\'focusin\'', $helper);
        $this->assertStringContainsString('previous.isConnected', $helper);
        $this->assertStringContainsString('active.contains(previous)', $helper);
        $this->assertStringContainsString('root.getAttribute(\'data-tn-dismissible\')', $helper);
    }

    public function test_fbr_decision_supports_safe_escape_and_explicit_mandatory_state(): void
    {
        $view = file_get_contents(resource_path('views/fbr-pos/partials/integration-decision-card.blade.php'));

        $this->assertStringContainsString('$fdcDismissible = (bool) ($fbrDecisionDismissible ?? true);', $view);
        $this->assertStringContainsString('data-tn-dismissible="{{ $fdcDismissible ? \'true\' : \'false\' }}"', $view);
        $this->assertStringContainsString('TnModalA11y.escape($event, $refs.fdDialog, () => fdChoose(\'later\'))', $view);
        $this->assertStringContainsString('if (choice === \'later\')', $view);
        $this->assertMatchesRegularExpression(
            '/if \\(choice === \'later\'\\) \\{\\s*this\\.fdOpen = true;\\s*this\\.\\$nextTick\\(\\(\\) => window\\.TnModalA11y\\.open\\(dialog\\)\\);/s',
            $view
        );
        $this->assertStringContainsString('@unless($fdcDismissible)', $view);
        $this->assertStringContainsString('fbr_decision_required_hint', $view);
        $this->assertStringNotContainsString("Escape') fdChoose('connect", $view);
        $this->assertStringNotContainsString("Escape') fdChoose('without_fbr", $view);
    }

    public function test_server_renders_only_one_initial_fbr_announcement_overlay(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/fbr-pos-app.blade.php'));

        $this->assertMatchesRegularExpression(
            '/if\s*\(\$fbrDecisionCard\)\s*\{[^}]*\$whatsNewPopup\s*=\s*null;[^}]*\$whatsNewPopupList\s*=\s*collect\(\);/s',
            $layout
        );
        $this->assertStringContainsString('@if($fbrDecisionCard)', $layout);
        $this->assertStringContainsString('@if($whatsNewPopup)', $layout);
    }
}