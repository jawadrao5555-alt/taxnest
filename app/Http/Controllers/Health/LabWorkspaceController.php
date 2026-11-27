<?php

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use App\Support\HealthPanel;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LabWorkspaceController extends Controller
{
    private const LAYOUTS = ['modern', 'classic', 'fast_reception'];

    public function index(Request $request)
    {
        $layout = $request->session()->get($this->preferenceKey());

        return view('health.lab.index', [
            'layout' => in_array($layout, self::LAYOUTS, true) ? $layout : 'modern',
            'layouts' => self::LAYOUTS,
        ]);
    }

    public function saveLayout(Request $request)
    {
        $validated = $request->validate(['layout' => ['required', Rule::in(self::LAYOUTS)]]);
        $request->session()->put($this->preferenceKey(), $validated['layout']);

        return redirect()->route('health.lab');
    }

    private function preferenceKey(): string
    {
        return 'health.lab_layout.'.app('currentCompanyId').'.'.auth(HealthPanel::GUARD)->id();
    }
}
