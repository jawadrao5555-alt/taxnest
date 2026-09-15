<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PosService;
use App\Models\PosServiceWorkOrder;
use App\Services\BranchContextService;
use App\Services\PosServiceWorkflowProfiles;
use App\Services\PosServiceWorkOrderService;
use Illuminate\Http\Request;

class PosServiceWorkOrderController extends Controller
{
    public function index(Request $request, BranchContextService $branches)
    {
        [$company, $profile] = $this->context();
        $query = PosServiceWorkOrder::where('company_id', $company->id)->where('category', $profile['category']);
        $branches->applyToQuery($query);
        if ($request->filled('status') && in_array($request->status, $profile['stages'], true)) {
            $query->where('status', $request->status);
        }
        $orders = $query->latest()->paginate(30)->withQueryString();
        $countsQuery = PosServiceWorkOrder::where('company_id', $company->id)->where('category', $profile['category']);
        $branches->applyToQuery($countsQuery);
        $counts = $countsQuery->selectRaw('status, COUNT(*) total')->groupBy('status')->pluck('total', 'status');

        return view('pos.service-work-orders.index', compact('company', 'profile', 'orders', 'counts'));
    }

    public function create()
    {
        [$company, $profile] = $this->context();
        $services = PosService::where('company_id', $company->id)->where('is_active', true)->orderBy('name')->get();

        return view('pos.service-work-orders.create', compact('company', 'profile', 'services'));
    }

    public function store(Request $request, PosServiceWorkOrderService $jobs, BranchContextService $branches)
    {
        [$company, $profile] = $this->context();
        $data = $request->validate([
            'customer_name' => 'required|string|max:255', 'customer_phone' => 'nullable|string|max:40',
            'service_id' => 'nullable|integer', 'title' => 'nullable|string|max:255',
            'scheduled_at' => ($profile['schedule'] ? 'required' : 'nullable').'|date',
            'due_at' => 'nullable|date|after_or_equal:scheduled_at',
            'quantity' => 'required|numeric|min:0.001|max:999999', 'unit_price' => 'required|numeric|min:0|max:999999999',
            'details' => 'nullable|array', 'details.*' => 'nullable|string|max:500', 'notes' => 'nullable|string|max:2000',
        ]);
        $order = $jobs->create($company, $data, $branches->stampBranchId(), auth('pos')->id());

        return redirect()->route('pos.service-work-orders.show', $order)->with('success', $profile['noun'].' created.');
    }

    public function show(int $id, BranchContextService $branches)
    {
        [$company, $profile] = $this->context();
        $query = PosServiceWorkOrder::where('company_id', $company->id)->where('category', $profile['category']);
        $branches->applyToQuery($query);
        $order = $query->with(['events', 'service'])->findOrFail($id);
        $nextStatuses = PosServiceWorkflowProfiles::nextStatuses($profile, $order->status);

        return view('pos.service-work-orders.show', compact('company', 'profile', 'order', 'nextStatuses'));
    }

    public function transition(Request $request, int $id, PosServiceWorkOrderService $jobs, BranchContextService $branches)
    {
        [$company, $profile] = $this->context();
        $data = $request->validate(['to_status' => 'required|string|in:'.implode(',', $profile['stages']), 'note' => 'nullable|string|max:1000']);
        $scoped = PosServiceWorkOrder::where('company_id', $company->id)->where('category', $profile['category']);
        $branches->applyToQuery($scoped);
        abort_unless($scoped->whereKey($id)->exists(), 404);
        try {
            $jobs->transition(
                $company,
                $id,
                $data['to_status'],
                $data['note'] ?? null,
                auth('pos')->id(),
                $branches->stampBranchId()
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $profile['noun'].' updated.');
    }

    public function report(BranchContextService $branches)
    {
        [$company, $profile] = $this->context();
        $query = PosServiceWorkOrder::where('company_id', $company->id)->where('category', $profile['category']);
        $branches->applyToQuery($query);
        $rows = $query->orderBy('id')->get();

        return response()->streamDownload(function () use ($rows, $profile) {
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBF");
            fputcsv($f, [$profile['noun'].' Number', 'Customer', 'Status', 'Scheduled', 'Due', 'Amount']);
            foreach ($rows as $row) {
                fputcsv($f, [$row->job_number, $row->customer_name, $row->status, $row->scheduled_at, $row->due_at, $row->total_amount]);
            }
            fclose($f);
        }, $profile['category'].'-work-orders-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function context(): array
    {
        $company = Company::findOrFail(app('currentCompanyId'));
        $profile = PosServiceWorkflowProfiles::forCompany($company);
        abort_unless($profile, 404, 'No complete category-native workflow is available for this business category.');

        return [$company, $profile];
    }
}
