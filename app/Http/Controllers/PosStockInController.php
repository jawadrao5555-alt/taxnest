<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Ingredient;
use App\Models\PosProduct;
use App\Models\PosStockInBatch;
use App\Services\BranchStockService;
use App\Services\PosStockInExcelService;
use App\Services\PosStockInService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PosStockInController extends Controller
{
    public function __construct(
        protected PosStockInService $stockIn,
        protected PosStockInExcelService $excel,
    ) {
    }

    public function index()
    {
        [$companyId, $company] = $this->boot();
        $multiBranch = BranchStockService::isMultiBranch($companyId);
        $openBatch = $multiBranch ? null : $this->stockIn->latestOpenBatch($companyId);
        $batches = PosStockInBatch::query()
            ->where('company_id', $companyId)
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return view('pos.inventory.stock-in.index', [
            'company' => $company,
            'multiBranch' => $multiBranch,
            'openBatch' => $openBatch,
            'batches' => $batches,
            'canTransfer' => BranchStockService::canTransfer($companyId),
        ]);
    }

    public function template()
    {
        $this->boot();

        return $this->excel->streamTemplate();
    }

    public function store(Request $request)
    {
        [$companyId, $company] = $this->boot();
        $this->assertNotCashier();

        $request->validate([
            'reference' => 'required|string|max:100',
            'excel_file' => 'required|file|mimes:xlsx,xls|max:5120',
        ], [
            'reference.required' => __('pos.stock_in_reference_required'),
            'excel_file.required' => __('pos.stock_in_file_required'),
            'excel_file.mimes' => __('pos.stock_in_file_mimes'),
            'excel_file.max' => __('pos.stock_in_file_max'),
        ]);

        try {
            $batch = $this->stockIn->stageUpload(
                $company,
                $request->file('excel_file'),
                (int) auth('pos')->id(),
                (string) $request->input('reference'),
                $request->boolean('update_cost'),
                $request->boolean('save_supplier_code'),
            );
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }

        return redirect()
            ->route('pos.inventory.stock-in.show', $batch->id)
            ->with('success', __('pos.stock_in_staged', ['count' => $batch->line_count]));
    }

    public function show(int $id)
    {
        [$companyId, $company] = $this->boot();
        $batch = $this->findBatch($companyId, $id);

        $ingredients = Ingredient::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'unit']);
        $products = PosProduct::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'barcode']);

        $readyCount = $batch->lines
            ->filter(fn ($line) => $line->isPostable() && $line->selected)
            ->count();

        return view('pos.inventory.stock-in.show', [
            'company' => $company,
            'batch' => $batch->load('lines'),
            'ingredients' => $ingredients,
            'products' => $products,
            'readyCount' => $readyCount,
            'canTransfer' => BranchStockService::canTransfer($companyId),
            'multiBranch' => false,
        ]);
    }

    public function rematch(int $id)
    {
        [$companyId, $company] = $this->boot();
        $this->assertNotCashier();
        $batch = $this->findBatch($companyId, $id);

        try {
            $this->stockIn->rematch($batch, $company);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', __('pos.stock_in_rematched'));
    }

    public function mapLine(Request $request, int $id, int $line)
    {
        [$companyId, $company] = $this->boot();
        $this->assertNotCashier();
        $batch = $this->findBatch($companyId, $id);

        try {
            $this->stockIn->mapClearance(
                $batch,
                $company,
                $line,
                $request->filled('ingredient_id') ? (int) $request->input('ingredient_id') : null,
                $request->filled('product_id') ? (int) $request->input('product_id') : null,
            );
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', __('pos.stock_in_mapped'));
    }

    public function post(Request $request, int $id)
    {
        [$companyId, $company] = $this->boot();
        $this->assertNotCashier();
        $batch = $this->findBatch($companyId, $id);

        $selected = array_map('intval', (array) $request->input('selected', []));
        $this->stockIn->setSelected($batch, $company, $selected);

        $batch->update_cost = $request->boolean('update_cost');
        $batch->save_supplier_code = $request->boolean('save_supplier_code');
        $batch->save();

        try {
            $result = $this->stockIn->post($batch->fresh(['lines']), $company, (int) auth('pos')->id());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        } catch (\Throwable $e) {
            return back()->with('error', __('pos.stock_in_post_failed', ['reason' => $e->getMessage()]));
        }

        $msg = __('pos.stock_in_posted', ['count' => $result['posted']]);
        if ($result['already'] > 0) {
            $msg .= ' '.$this->alreadyMessage($result['already']);
        }

        return redirect()
            ->route('pos.inventory.stock-in.show', $batch->id)
            ->with('success', $msg);
    }

    public function cancel(int $id)
    {
        [$companyId, $company] = $this->boot();
        $this->assertNotCashier();
        $batch = $this->findBatch($companyId, $id);

        try {
            $this->stockIn->cancelBatch($batch, $company);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()
            ->route('pos.inventory.stock-in.index')
            ->with('success', __('pos.stock_in_cancelled'));
    }

    private function boot(): array
    {
        $companyId = (int) app('currentCompanyId');
        $company = Company::find($companyId);
        if (! $company || ! $company->inventory_enabled) {
            abort(redirect()->route('pos.features')->with('error', __('pos.stock_in_needs_inventory')));
        }
        BranchStockService::healLegacyRows($companyId);

        return [$companyId, $company];
    }

    private function findBatch(int $companyId, int $id): PosStockInBatch
    {
        return PosStockInBatch::query()
            ->where('company_id', $companyId)
            ->with('lines')
            ->findOrFail($id);
    }

    /**
     * Cashiers cannot POST Stock-In, even with Custom Access inventory grant.
     */
    private function assertNotCashier(): void
    {
        $user = auth('pos')->user();
        if ($user && $user->isPosCashier()) {
            abort(403, __('pos.access_denied'));
        }
    }

    private function alreadyMessage(int $count): string
    {
        return __('pos.stock_in_already_received_count', ['count' => $count]);
    }
}
