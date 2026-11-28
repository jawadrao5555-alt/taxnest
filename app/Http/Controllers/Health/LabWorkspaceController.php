<?php

namespace App\Http\Controllers\Health;

use App\Models\HealthLabOrder;
use App\Models\HealthLabSpecimen;
use App\Support\HealthPanel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class LabWorkspaceController extends HealthPanelController
{
    private const LAYOUTS = ['modern', 'classic', 'fast_reception'];

    private const WORKLIST_STAGES = ['collection', 'result', 'verification'];

    public function index(Request $request)
    {
        $layout = $request->session()->get($this->preferenceKey());
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:60'],
            'stage' => ['nullable', Rule::in(self::WORKLIST_STAGES)],
        ]);
        $search = strtoupper((string) ($validated['q'] ?? ''));
        $stage = $validated['stage'] ?? null;
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
                ->when($stage !== null, fn (Builder $orders) => $orders->whereHas('items',
                    fn (Builder $items) => $this->itemsAtStage($items, $stage)))
                ->latest('id')->paginate(20)->withQueryString();
        }

        return response()->view('health.lab.index', [
            'layout' => in_array($layout, self::LAYOUTS, true) ? $layout : 'modern',
            'layouts' => self::LAYOUTS,
            'orders' => $orders,
            'search' => $search,
            'stage' => $stage,
            'stages' => self::WORKLIST_STAGES,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function saveLayout(Request $request)
    {
        $validated = $request->validate(['layout' => ['required', Rule::in(self::LAYOUTS)]]);
        $request->session()->put($this->preferenceKey(), $validated['layout']);

        return redirect()->route('health.lab');
    }

    private function itemsAtStage(Builder $items, string $stage): Builder
    {
        $specimenStatus = $stage === 'collection' ? HealthLabSpecimen::PENDING : HealthLabSpecimen::COLLECTED;
        $items->whereHas('latestSpecimen', fn (Builder $specimen) => $specimen->where('status', $specimenStatus));

        return match ($stage) {
            'collection' => $items,
            'result' => $items->doesntHave('results'),
            'verification' => $items->whereHas('latestResult', fn (Builder $result) => $result->whereNull('verified_at')),
        };
    }

    private function preferenceKey(): string
    {
        return 'health.lab_layout.'.app('currentCompanyId').'.'.auth(HealthPanel::GUARD)->id();
    }
}
