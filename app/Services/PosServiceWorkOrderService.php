<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PosService;
use App\Models\PosServiceWorkOrder;
use App\Models\PosServiceWorkOrderEvent;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PosServiceWorkOrderService
{
    public function create(Company $company, array $data, ?int $branchId, ?int $actorId): PosServiceWorkOrder
    {
        $profile = PosServiceWorkflowProfiles::forCompany($company);
        if (! $profile) {
            throw new InvalidArgumentException('This category does not have a complete work-order workflow.');
        }

        $service = null;
        if (! empty($data['service_id'])) {
            $service = PosService::where('company_id', $company->id)->findOrFail($data['service_id']);
        }
        $quantity = max(0.001, (float) ($data['quantity'] ?? 1));
        $unitPrice = max(0, (float) ($data['unit_price'] ?? ($service?->price ?? 0)));
        $details = $this->filterDetails($profile, $data['details'] ?? []);

        return $this->transactionWithRetry(function () use ($company, $profile, $data, $branchId, $actorId, $service, $quantity, $unitPrice, $details) {
            $now = now();
            DB::table('pos_service_work_order_series')->updateOrInsert(
                ['company_id' => $company->id],
                ['updated_at' => $now, 'created_at' => $now]
            );
            $series = DB::table('pos_service_work_order_series')
                ->where('company_id', $company->id)->lockForUpdate()->first();
            $next = max(1, (int) ($series->next_number ?? 1));
            DB::table('pos_service_work_order_series')->where('company_id', $company->id)
                ->update(['next_number' => $next + 1, 'updated_at' => $now]);

            $order = PosServiceWorkOrder::create([
                'company_id' => $company->id,
                'branch_id' => $branchId,
                'category' => $profile['category'],
                'job_number' => $profile['prefix'].'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT),
                'service_id' => $service?->id,
                'customer_name' => trim((string) $data['customer_name']),
                'customer_phone' => trim((string) ($data['customer_phone'] ?? '')) ?: null,
                'title' => trim((string) ($data['title'] ?? ($service?->name ?? $profile['noun']))),
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'due_at' => $data['due_at'] ?? null,
                'status' => $profile['stages'][0],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_amount' => round($quantity * $unitPrice, 2),
                'details' => $details,
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                'created_by' => $actorId,
            ]);
            PosServiceWorkOrderEvent::create([
                'company_id' => $company->id, 'branch_id' => $branchId,
                'work_order_id' => $order->id, 'from_status' => null,
                'to_status' => $order->status, 'note' => null,
                'actor_id' => $actorId, 'occurred_at' => $now,
            ]);

            return $order;
        });
    }

    public function transition(
        Company $company,
        int $orderId,
        string $to,
        ?string $note,
        ?int $actorId,
        ?int $branchId = null
    ): PosServiceWorkOrder {
        $profile = PosServiceWorkflowProfiles::forCompany($company);
        if (! $profile) {
            throw new InvalidArgumentException('Unsupported service workflow.');
        }

        return $this->transactionWithRetry(function () use ($company, $orderId, $to, $note, $actorId, $branchId, $profile) {
            $query = PosServiceWorkOrder::where('company_id', $company->id)
                ->where('category', $profile['category']);
            if ($branchId !== null) {
                $query->where('branch_id', $branchId);
            }
            $order = $query->lockForUpdate()->findOrFail($orderId);
            PosServiceWorkflowProfiles::assertTransition($profile, $order->status, $to);
            $from = $order->status;
            $terminal = in_array($to, $profile['terminal'] ?? [], true);
            $order->update(['status' => $to, 'completed_at' => $terminal ? now() : null]);
            PosServiceWorkOrderEvent::create([
                'company_id' => $company->id, 'branch_id' => $order->branch_id,
                'work_order_id' => $order->id, 'from_status' => $from, 'to_status' => $to,
                'note' => trim((string) $note) ?: null, 'actor_id' => $actorId, 'occurred_at' => now(),
            ]);

            return $order->fresh('events');
        });
    }

    private function filterDetails(array $profile, $details): array
    {
        if (! is_array($details)) {
            return [];
        }
        $out = [];
        foreach (array_keys($profile['fields']) as $key) {
            $value = trim((string) ($details[$key] ?? ''));
            if ($value !== '') {
                $out[$key] = mb_substr($value, 0, 500);
            }
        }

        return $out;
    }

    private function transactionWithRetry(callable $callback)
    {
        $attempts = 0;
        beginning:
        try {
            return DB::transaction($callback, 1);
        } catch (\Illuminate\Database\QueryException $e) {
            $attempts++;
            $text = strtolower($e->getMessage());
            if ($attempts < 4 && (str_contains($text, 'deadlock') || str_contains($text, 'database is locked') || str_contains($text, '1213'))) {
                usleep(20000 * $attempts);
                goto beginning;
            }
            throw $e;
        }
    }
}
