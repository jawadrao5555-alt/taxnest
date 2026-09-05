<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Shell for every authenticated Nest ERPS Healthcare page: <x-health-layout>.
 *
 * Same one-line shape as the other panels' layout components (FbrPosLayout,
 * PosLayout) — the panel context itself is shared by HealthAuth, so nothing has
 * to be passed in per page.
 */
class HealthLayout extends Component
{
    public function render(): View
    {
        return view('layouts.health-app');
    }
}
