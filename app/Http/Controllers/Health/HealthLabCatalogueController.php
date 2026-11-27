<?php

namespace App\Http\Controllers\Health;

use App\Models\HealthLabTest;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class HealthLabCatalogueController extends HealthPanelController
{
    public function index()
    {
        return view('health.lab.catalogue', [
            'tests' => $this->catalogue()->orderBy('code')->paginate(25),
            'editTest' => null,
        ]);
    }

    public function edit(int $test)
    {
        return view('health.lab.catalogue', [
            'tests' => $this->catalogue()->orderBy('code')->paginate(25),
            'editTest' => $this->catalogue()->findOrFail($test),
        ]);
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
            'is_active' => ['required', 'boolean'],
        ]);
        [$whole, $fraction] = array_pad(explode('.', (string) $data['price'], 2), 2, '');
        try {
            $test->fill([
                'code' => $data['code'],
                'name' => $data['name'],
                'price_paisa' => (int) $whole * 100 + (int) str_pad($fraction, 2, '0'),
                'is_active' => $data['is_active'],
            ])->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => __('lab_workflow.test_code_taken')]);
        }
    }
}
