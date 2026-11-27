<?php

namespace App\Http\Controllers\Health;

use App\Models\HealthLabTest;
use App\Services\HealthAccessService;
use App\Services\HealthAudit\HealthAuditRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class HealthLabCatalogueController extends HealthPanelController
{
    public function index()
    {
        return response()->view('health.lab.catalogue', [
            'tests' => $this->catalogue()->orderBy('code')->paginate(25),
            'editTest' => null,
            'canEditCost' => HealthAccessService::isOwner($this->user()),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function edit(int $test)
    {
        return response()->view('health.lab.catalogue', [
            'tests' => $this->catalogue()->orderBy('code')->paginate(25),
            'editTest' => $this->catalogue()->findOrFail($test),
            'canEditCost' => HealthAccessService::isOwner($this->user()),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request)
    {
        $this->save($request, new HealthLabTest(['company_id' => $this->user()->company_id]));

        return redirect()->route('health.lab.catalogue')->with('success', __('lab_workflow.test_saved'));
    }

    public function update(Request $request, int $test)
    {
        $this->save($request, $this->catalogue()->findOrFail($test));

        return redirect()->route('health.lab.catalogue')->with('success', __('lab_workflow.test_saved'));
    }

    private function catalogue()
    {
        return HealthLabTest::query()->where('company_id', $this->user()->company_id);
    }

    private function save(Request $request, HealthLabTest $test): void
    {
        if ($request->has('unit_cost')) {
            abort_unless(HealthAccessService::isOwner($this->user()), 403);
        }
        $code = $request->input('code');
        if (is_string($code)) {
            $request->merge(['code' => strtoupper(trim($code))]);
        }
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/',
                Rule::unique('health_lab_tests', 'code')->where('company_id', $this->user()->company_id)
                    ->ignore($test->id)],
            'name' => ['required', 'string', 'max:120'],
            'price' => ['required', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'unit_cost' => ['sometimes', 'nullable', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'is_active' => ['required', 'boolean'],
        ]);
        try {
            $test->fill([
                'code' => $data['code'],
                'name' => $data['name'],
                'price_paisa' => $this->paisa($data['price']),
                'is_active' => $data['is_active'],
            ]);
            $costChanged = false;
            if (array_key_exists('unit_cost', $data)) {
                $cost = $data['unit_cost'] === null ? null : $this->paisa($data['unit_cost']);
                $costChanged = $test->unit_cost_paisa !== $cost;
                $test->unit_cost_paisa = $cost;
            }
            $test->save();
            if ($costChanged) {
                HealthAuditRecorder::record('lab.catalogue_cost_changed', [
                    'actor' => $this->user(),
                    'company_id' => $test->company_id,
                    'category' => 'accounts',
                    'action' => 'updated',
                    'entity_type' => 'health_lab_tests',
                    'entity_id' => $test->id,
                ]);
            }
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => __('lab_workflow.test_code_taken')]);
        }
    }

    private function paisa(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }
}
