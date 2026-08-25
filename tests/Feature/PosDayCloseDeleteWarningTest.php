<?php

namespace Tests\Feature;

use Tests\TestCase;

class PosDayCloseDeleteWarningTest extends TestCase
{
    public function test_delete_warning_is_added_only_when_resolved_actions_include_delete(): void
    {
        $blade = file_get_contents(resource_path('views/pos/day-close.blade.php'));

        $this->assertStringContainsString(
            "if (agg.delete && agg.delete.count > 0) {\n                lines.push('');\n                lines.push(btn.getAttribute('data-label-delete-warning'));",
            $blade,
            'The permanent-loss warning must appear when the resolved action is delete.'
        );
        $this->assertStringNotContainsString(
            "if (agg.save",
            $blade,
            'The save/archive action must not add the permanent-loss warning.'
        );
        $this->assertStringContainsString(
            "data-label-delete-warning=\"{{ __('pos.dc_confirm_delete_permanent_warning') }}\"",
            $blade
        );
        $this->assertStringContainsString('permanently erased and cannot be recovered', __('pos.dc_confirm_delete_permanent_warning'));
    }
}