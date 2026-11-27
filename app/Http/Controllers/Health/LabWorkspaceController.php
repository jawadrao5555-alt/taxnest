<?php

namespace App\Http\Controllers\Health;

use App\Models\HealthLabOrder;
use App\Models\HealthLabSpecimen;
use App\Support\HealthPanel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class LabWorkspaceController extends HealthPanelController
{
    private const LAYOUTS = ['modern', 'classic', 'fast_reception'];

    public function index(Request $request)
    {
        $layout = $request->session()->get($this->preferenceKey());
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:60']]);
        $search = strtoupper((string) ($validated['q'] ?? ''));
        $orders = null;
        if (Schema::hasTable('health_lab_orders')) {
            $orders = HealthLabOrder::visibleTo($this->user())
                ->with('patient:id,company_id,name,mrn')
                ->withCount(['items', 'items as verified_items_count' => fn ($items) => $items->whereHas(
                    'results', fn ($results) => $results->whereNotNull('verified_at')
                )])
                ->when($search !== '', fn ($query) => $query->where(fn ($matches) => $matches
                    ->where('order_no', $search)
                    ->orWhereHas('patient', fn ($patient) => $patient->where('mrn', $search))
                    ->orWhereHas('items.specimens', fn ($specimen) => $specimen->where('barcode', $search)
                        ->where('status', '!=', HealthLabSpecimen::REJECTED))))
                ->latest('id')->paginate(20)->withQueryString();
        }

        return view('health.lab.index', [
            'layout' => in_array($layout, self::LAYOUTS, true) ? $layout : 'modern',
            'layouts' => self::LAYOUTS,
            'orders' => $orders,
            'search' => $search,
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
