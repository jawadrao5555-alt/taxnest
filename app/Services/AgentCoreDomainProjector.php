<?php

namespace App\Services;

use App\Models\AgentCoreEvent;
use App\Models\Company;
use App\Models\CustomerLedger;
use App\Models\InventoryMovement;
use App\Models\PosPrintJob;
use App\Models\PosUserSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Small, controller-free projections. They only use append-only/domain service
 * APIs and require tenant-owned references; unsupported rich workflows remain
 * durably retryable rather than approximated.
 */
class AgentCoreDomainProjector
{
    public function project(Company $company, AgentCoreEvent $event, array $wire): AgentCoreProjectionOutcome
    {
        $wire['payload'] = (array) ($wire['payload']['data'] ?? []);
        return match ($event->event_type) {
            'stock.adjusted' => $this->stock($company, $event, $wire),
            'customer.ledger.posted', 'customer.khata.posted', 'customer.wasooli.posted', 'customer.refund.posted'
                => $this->ledger($company, $event, $wire),
            'print.requested', 'print.completed' => $this->print($company, $event, $wire),
            'staff.attendance.recorded', 'staff.shift.recorded' => $this->staff($company, $event, $wire),
            default => new AgentCoreProjectionOutcome('rejected',
                ['event_id' => $event->event_id, 'status' => 'rejected'],
                'No safe projector exists for this command.'),
        };
    }

    private function stock(Company $company, AgentCoreEvent $event, array $wire): AgentCoreProjectionOutcome
    {
        if (!Schema::hasTable('inventory_stocks') || !Schema::hasTable('inventory_movements')) {
            return $this->waiting($event, 'inventory-schema');
        }
        $p = $wire['payload'];
        $envelope = (array) ($event->payload ?? []);
        $command = (string) ($envelope['command_type'] ?? '');
        if (!in_array($command, ['stock.set', 'stock.adjust'], true)) $this->invalid('command_type');
        $productId = (int) ($p['product_id'] ?? $envelope['aggregate_id'] ?? 0);
        if (!$productId) $this->invalid('product_id');
        $ownedProduct = (Schema::hasTable('products')
                && DB::table('products')->where('company_id', $company->id)->where('id', $productId)->exists())
            || (Schema::hasTable('pos_products')
                && DB::table('pos_products')->where('company_id', $company->id)->where('id', $productId)->exists());
        if (!$ownedProduct) return $this->waiting($event, 'product:' . $productId);
        $branch = (int) ($wire['scope']['branch_id'] ?? 0) ?: null;
        $current = \App\Services\InventoryService::getStockLevel($company->id, $productId, $branch);
        $delta = $command === 'stock.set' ? (float) ($p['quantity'] ?? NAN) - $current : (float) ($p['delta'] ?? NAN);
        if (!is_finite($delta) || $current + $delta < 0) $this->invalid('quantity');
        $method = $delta >= 0 ? 'addStock' : 'deductStock';
        $stock = \App\Services\InventoryService::$method(
            $company->id, $productId, abs($delta), (float) ($p['unit_price'] ?? 0),
            (string) ($p['movement_type'] ?? InventoryMovement::TYPE_OPENING), $branch,
            ['type' => 'agent_core_event', 'id' => $event->id, 'number' => $event->event_id],
            $p['notes'] ?? null, (int) ($wire['scope']['user_id'] ?? 0) ?: null,
        );
        return $this->done($event, ['stock_id' => $stock->id, 'quantity' => (float) $stock->quantity]);
    }

    private function ledger(Company $company, AgentCoreEvent $event, array $wire): AgentCoreProjectionOutcome
    {
        if (!Schema::hasTable('customer_ledgers')) return $this->waiting($event, 'customer-ledger-schema');
        $p = $wire['payload'];
        if (empty($p['customer_name'])) $this->invalid('customer_name');
        $debit = (float) ($p['debit'] ?? 0);
        $credit = (float) ($p['credit'] ?? 0);
        if ($debit < 0 || $credit < 0 || ($debit == 0.0 && $credit == 0.0)) $this->invalid('debit/credit');
        $balance = (float) CustomerLedger::query()->where('company_id', $company->id)
            ->where('customer_name', $p['customer_name'])->latest('id')->value('balance_after');
        $row = CustomerLedger::create([
            'company_id' => $company->id, 'customer_name' => $p['customer_name'],
            'customer_ntn' => $p['customer_ntn'] ?? null, 'debit' => $debit, 'credit' => $credit,
            'balance_after' => $balance + $debit - $credit, 'type' => $event->event_type,
            'notes' => $p['notes'] ?? "Local Core {$event->event_id}",
        ]);
        return $this->done($event, ['ledger_id' => $row->id, 'balance_after' => (float) $row->balance_after]);
    }

    private function print(Company $company, AgentCoreEvent $event, array $wire): AgentCoreProjectionOutcome
    {
        if (!Schema::hasTable('pos_print_jobs')) return $this->waiting($event, 'print-job-schema');
        $p = $wire['payload'];
        $envelope = (array) $event->payload;
        $command = (string) ($envelope['command_type'] ?? '');
        $aggregate = (string) ($envelope['aggregate_id'] ?? '');
        $job = PosPrintJob::query()->where('company_id', $company->id)->where('claim_token', 'ac:' . $aggregate)->lockForUpdate()->first();
        if ($command === 'print.enqueue') {
            if ($job) return $this->done($event, ['print_job_id' => $job->id, 'print_status' => $job->status, 'replayed' => true]);
            $job = PosPrintJob::create([
                'company_id' => $company->id, 'type' => $p['type'] ?? 'receipt',
                'target_printer' => $p['target_printer'] ?? 'default',
                'status' => 'queued', 'device_uid' => $wire['scope']['device_id'],
                'claim_token' => 'ac:' . $aggregate,
                'render_query' => $p['render_query'] ?? json_encode($p['document'] ?? []),
                'created_by' => (int) $wire['scope']['user_id'],
            ]);
        } else {
            if (!$job) return $this->waiting($event, 'print-job:' . $aggregate);
            $status = match ($command) {
                'print.claim' => 'claimed', 'print.complete' => 'printed', 'print.fail' => 'queued',
                default => throw ValidationException::withMessages(['payload.command_type' => ['Unsupported print command.']]),
            };
            $job->update(['status' => $status, 'error' => $command === 'print.fail' ? ($p['error'] ?? 'Print failed.') : null,
                'attempts' => $command === 'print.claim' ? ((int) $job->attempts + 1) : $job->attempts]);
        }
        return $this->done($event, ['print_job_id' => $job->id, 'print_status' => $job->status]);
    }

    private function staff(Company $company, AgentCoreEvent $event, array $wire): AgentCoreProjectionOutcome
    {
        if (!Schema::hasTable('pos_user_sessions')) return $this->waiting($event, 'staff-session-schema');
        $p = $wire['payload']; $userId = (int) ($wire['scope']['user_id'] ?? 0);
        if (!$userId || !Schema::hasTable('users') || !DB::table('users')->where('company_id', $company->id)->where('id', $userId)->exists()) return $this->waiting($event, 'user:' . $userId);
        $session = PosUserSession::create([
            'company_id' => $company->id, 'user_id' => $userId,
            'login_at' => $p['login_at'] ?? $event->occurred_at ?? now(),
            'logout_at' => $p['logout_at'] ?? null, 'last_activity_at' => $p['last_activity_at'] ?? $event->occurred_at ?? now(),
            'ip' => $p['ip'] ?? 'agent-core',
        ]);
        return $this->done($event, ['session_id' => $session->id]);
    }

    private function done(AgentCoreEvent $event, array $data): AgentCoreProjectionOutcome { return new AgentCoreProjectionOutcome('projected', ['event_id' => $event->event_id, 'status' => 'projected'] + $data); }
    private function waiting(AgentCoreEvent $event, string $dependency): AgentCoreProjectionOutcome { return new AgentCoreProjectionOutcome('pending-domain', ['event_id' => $event->event_id, 'status' => 'pending-domain', 'dependency' => $dependency], 'A required domain prerequisite is unavailable.', $dependency); }
    private function invalid(string $field): never { throw ValidationException::withMessages(["payload.{$field}" => ['A valid value is required.']]); }
}