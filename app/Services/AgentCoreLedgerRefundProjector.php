<?php

namespace App\Services;

use App\Models\AgentCoreEvent;
use App\Models\Company;
use App\Models\FbrCustomerLedger;
use App\Models\FbrPosTransaction;
use App\Models\FbrPosTransactionItem;
use App\Models\InventoryMovement;
use App\Models\PosCustomer;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Controller-free projector for the Retail Core money/return commands.
 *
 * The canonical payload is payload.data with nested customer, khata, wasooli,
 * sale and stock objects.  This class intentionally is not registered here:
 * callers opt in through AgentCoreProjectorRegistry when the wire contract is
 * released.
 */
final class AgentCoreLedgerRefundProjector
{
    public function __construct(private FbrKhataService $khata, private AgentCoreAggregateMap $aggregates)
    {
    }

    public function project(Company $company, AgentCoreEvent $event, array $wire): AgentCoreProjectionOutcome
    {
        try {
            [$actor, $branchId] = $this->scope($company, $wire);
            $envelope = (array) ($wire['payload'] ?? []);
            $data = (array) ($envelope['data'] ?? []);
            $command = (string) ($envelope['command_type'] ?? '');
            $aggregate = (string) ($envelope['aggregate_id'] ?? '');
            $data = $this->normalizeCanonical($command, $aggregate, $data);
            $data['_aggregate_id'] = $aggregate;

            $result = match ($command) {
                'customer.upsert' => $this->upsertCustomer($company, $event, $data, $branchId, $aggregate),
                'khata.debit', 'khata.debited', 'customer.khata.debited' => $this->debit($company, $event, $data, $actor, $branchId),
                'wasooli.record', 'wasooli.recorded', 'customer.wasooli.recorded' => $this->wasooli($company, $event, $data, $actor, $branchId),
                'refund.record', 'sale.refunded' => $this->refund($company, $event, $data, $actor, $branchId),
                'stock.restored' => $this->restore($company, $event, $data, $actor, $branchId),
                default => throw ValidationException::withMessages(['payload.command_type' => ['Unsupported ledger/refund command.']]),
            };

            return new AgentCoreProjectionOutcome('projected', [
                'event_id' => $event->event_id,
                'status' => 'projected',
            ] + $result);
        } catch (AgentCoreProjectionDependency $e) {
            return new AgentCoreProjectionOutcome('retryable', [
                'event_id' => $event->event_id,
                'status' => 'retryable',
                'dependency' => $e->dependency,
            ], $e->getMessage(), $e->dependency);
        } catch (ValidationException $e) {
            return new AgentCoreProjectionOutcome('rejected', [
                'event_id' => $event->event_id,
                'status' => 'rejected',
            ], $this->validationMessage($e));
        } catch (QueryException $e) {
            return new AgentCoreProjectionOutcome('retryable', [
                'event_id' => $event->event_id,
                'status' => 'retryable',
            ], 'A database prerequisite is temporarily unavailable.');
        } catch (\Throwable $e) {
            return new AgentCoreProjectionOutcome('retryable', [
                'event_id' => $event->event_id,
                'status' => 'retryable',
            ], 'The domain operation could not be completed safely.');
        }
    }

    private function normalizeCanonical(string $command, string $aggregate, array $data): array
    {
        if (in_array($command, ['khata.debit', 'wasooli.record'], true)) {
            $data['customer'] ??= ['aggregate_id' => $aggregate];
            $bucket = $command === 'khata.debit' ? 'khata' : 'wasooli';
            $data[$bucket] ??= [];
            if (!array_key_exists('amount', $data[$bucket]) && is_int($data['amount_cents'] ?? null)) {
                $data[$bucket]['amount'] = $data['amount_cents'] / 100;
            }
            if ($command === 'khata.debit' && !isset($data['sale'])) {
                $reference = $data['reference'] ?? null;
                $data['sale'] = ['aggregate_id' => is_string($reference) ? $reference : ''];
            }
            $data[$bucket]['note'] ??= $data['note'] ?? null;
        }
        if ($command === 'refund.record') {
            $data['sale'] ??= [
                'aggregate_id' => $data['order_id'] ?? null,
                'refund_method' => $data['method'] ?? null,
                'line_ids' => $data['line_ids'] ?? [],
            ];
            $data['customer'] ??= ['aggregate_id' => $data['customer_id'] ?? null];
            $data['sale']['amount'] = is_int($data['amount_cents'] ?? null)
                ? $data['amount_cents'] / 100 : ($data['amount'] ?? null);
        }
        return $data;
    }

    private function upsertCustomer(Company $company, AgentCoreEvent $event, array $data, int $branchId, string $aggregate): array
    {
        if ($aggregate === '') $this->invalid('payload.aggregate_id', 'Customer aggregate is required.');
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') $this->invalid('payload.data.name', 'Customer name is required.');
        $mapping = $this->aggregates->resolve((int) $company->id, $branchId, 'customer', $aggregate, 'pos_customer');
        $customer = $mapping
            ? PosCustomer::where('company_id', $company->id)->find((int) $mapping->cloud_id)
            : null;
        $values = array_intersect_key($data, array_flip(['name', 'phone', 'email', 'address', 'city', 'ntn', 'cnic']));
        $values['name'] = $name;
        if ($customer) {
            $customer->fill($values)->save();
        } else {
            $customer = PosCustomer::create($values + ['company_id' => $company->id, 'is_active' => true]);
            $this->aggregates->bind(
                (int) $company->id, $branchId, 'customer', $aggregate, 'pos_customer', (int) $customer->id
            );
        }
        return ['customer_id' => $customer->id, 'customer_aggregate_id' => $aggregate, 'replayed' => (bool) $mapping];
    }

    private function debit(Company $company, AgentCoreEvent $event, array $data, User $actor, int $branchId): array
    {
        $customerAggregate = (string) ($data['customer']['aggregate_id'] ?? $data['customer']['id'] ?? '');
        $customerId = $this->mappedId($company, $branchId, 'customer', $customerAggregate, 'pos_customer');
        $sale = (array) ($data['sale'] ?? []);
        $saleAggregate = (string) ($sale['aggregate_id'] ?? $sale['id'] ?? '');
        $transactionId = $this->transactionId($company, $branchId, $saleAggregate);
        $amount = $this->money($data['khata']['amount'] ?? null, 'payload.data.khata.amount');

        $transaction = FbrPosTransaction::where('company_id', $company->id)
            ->where('branch_id', $branchId)->whereKey($transactionId)->first();
        if (!$transaction) {
            $this->missing("fbr-pos-transaction:{$transactionId}");
        }
        if ((int) $transaction->customer_id !== $customerId
            || $transaction->payment_method !== 'credit'
            || ($transaction->transaction_type ?? 'sale') !== 'sale') {
            $this->invalid('payload.data.sale', 'Credit sale does not belong to this scope/customer.');
        }
        if (abs((float) $transaction->total_amount - $amount) > 0.001) {
            $this->invalid('payload.data.khata.amount', 'Khata debit must equal the credit bill total.');
        }

        $existing = FbrCustomerLedger::where('company_id', $company->id)
            ->where('transaction_id', $transactionId)->where('entry_type', 'udhaar')->first();
        if ($existing) {
            if ((int) $existing->customer_id !== $customerId || abs((float) $existing->amount - $amount) > 0.001) {
                $this->invalid('payload.data.sale.id', 'Credit sale was already posted with different values.');
            }
            return ['ledger_id' => $existing->id, 'balance_after' => (float) $existing->balance_after, 'replayed' => true];
        }

        $entry = $this->khata->recordCreditSale(
            (int) $company->id, $customerId, $amount, $transactionId,
            (string) $transaction->invoice_number, $actor
        );
        return ['ledger_id' => $entry->id, 'balance_after' => (float) $entry->balance_after, 'replayed' => false];
    }

    private function wasooli(Company $company, AgentCoreEvent $event, array $data, User $actor, int $branchId): array
    {
        $customerAggregate = (string) ($data['customer']['aggregate_id'] ?? $data['customer']['id'] ?? '');
        $customerId = $this->mappedId($company, $branchId, 'customer', $customerAggregate, 'pos_customer');
        $wasooli = (array) ($data['wasooli'] ?? []);
        $amount = $this->money($wasooli['amount'] ?? null, 'payload.data.wasooli.amount');
        $result = $this->khata->recordWasooli(
            (int) $company->id, $customerId, $amount, $wasooli['note'] ?? null,
            $actor, (string) $event->event_id
        );
        return [
            'ledger_id' => $result['entry']->id,
            'balance_after' => (float) $result['entry']->balance_after,
            'replayed' => (bool) $result['replayed'],
        ];
    }

    private function refund(Company $company, AgentCoreEvent $event, array $data, User $actor, int $branchId): array
    {
        $sale = (array) ($data['sale'] ?? []);
        $saleAggregate = (string) ($sale['aggregate_id'] ?? $sale['id'] ?? '');
        $parentId = $this->transactionId($company, $branchId, $saleAggregate);
        $method = (string) ($sale['refund_method'] ?? '');
        if (!in_array($method, ['cash', 'card', 'store_credit', 'khata'], true)) {
            $this->invalid('payload.data.sale.refund_method', 'Unsupported refund method.');
        }
        $rows = $sale['items'] ?? null;
        if (!is_array($rows) && is_array($sale['line_ids'] ?? null)) {
            $rows = [];
            foreach ($sale['line_ids'] as $lineAggregate) {
                $line = $this->aggregates->resolve(
                    (int) $company->id, $branchId, 'sale_line',
                    (string) $lineAggregate, 'fbr_pos_transaction_item'
                );
                if (!$line) {
                    $this->missing('sale-line:' . (string) $lineAggregate);
                }
                $rows[] = ['item_id' => (int) $line->cloud_id, 'return_qty' => null];
            }
        }
        if (!is_array($rows) || $rows === []) {
            $this->invalid('payload.data.sale.items', 'At least one return item is required.');
        }

        return DB::transaction(function () use ($company, $event, $actor, $branchId, $parentId, $method, $rows, $sale, $data) {
            $invoice = $this->returnInvoice($event);
            $existing = FbrPosTransaction::where('company_id', $company->id)
                ->where('invoice_number', $invoice)->where('transaction_type', 'return')->first();
            if ($existing) {
                if ((int) $existing->parent_transaction_id !== $parentId || $existing->payment_method !== $method) {
                    $this->invalid('payload.data.sale', 'Refund event was already used with different values.');
                }
                return ['return_transaction_id' => $existing->id, 'refund_total' => (float) $existing->total_amount, 'replayed' => true];
            }

            $parent = FbrPosTransaction::where('company_id', $company->id)
                ->where('branch_id', $branchId)->lockForUpdate()->find($parentId);
            if (!$parent) {
                $this->missing("fbr-pos-transaction:{$parentId}");
            }
            if (($parent->transaction_type ?? 'sale') !== 'sale' || $parent->status !== 'completed') {
                $this->invalid('payload.data.sale.id', 'Completed parent sale was not found in this branch.');
            }
            if ($method === 'khata' && (!$parent->customer_id || $parent->payment_method !== 'credit')) {
                $this->invalid('payload.data.sale.refund_method', 'Khata refunds are allowed only for saved-customer credit bills.');
            }

            $parentItems = FbrPosTransactionItem::where('transaction_id', $parent->id)
                ->lockForUpdate()->get()->keyBy('id');
            $returnItems = [];
            $subtotal = $tax = 0.0;
            foreach ($rows as $row) {
                $itemId = $this->positiveId($row['item_id'] ?? null, 'payload.data.sale.items.item_id');
                $item = $parentItems->get($itemId);
                if (!$item) {
                    $this->missing("fbr-pos-transaction-item:{$itemId}");
                }
                $requestedQty = $row['quantity'] ?? $row['return_qty'] ?? null;
                $qty = $requestedQty === null && $item
                    ? round((float) $item->quantity - (float) $item->returned_quantity, 3)
                    : round((float) $requestedQty, 3);
                if ($qty <= 0) {
                    $this->invalid('payload.data.sale.items', 'Return item or quantity is invalid.');
                }
                $remaining = round((float) $item->quantity - (float) $item->returned_quantity, 3);
                if ($qty > $remaining + 0.0005) {
                    $this->invalid('payload.data.sale.items', 'Return quantity exceeds the locked remaining quantity.');
                }
                $ratio = $qty / max((float) $item->quantity, 0.001);
                $sub = round((float) $item->subtotal * $ratio, 2);
                $itemTax = round((float) $item->tax_amount * $ratio, 2);
                $subtotal += $sub;
                $tax += $itemTax;
                $returnItems[] = [
                    'parent_item_id' => $item->id, 'product_id' => $item->product_id,
                    'item_name' => $item->item_name, 'hs_code' => $item->hs_code, 'uom' => $item->uom,
                    'quantity' => $qty, 'unit_price' => $item->unit_price, 'cost_price' => $item->cost_price,
                    'item_discount' => round((float) $item->item_discount * $ratio, 2),
                    'tax_rate' => $item->tax_rate, 'tax_amount' => $itemTax, 'subtotal' => $sub,
                    'total' => round($sub + $itemTax, 2), 'is_tax_exempt' => $item->is_tax_exempt,
                ];
                $item->update(['returned_quantity' => round((float) $item->returned_quantity + $qty, 3)]);
            }

            $goods = round($subtotal + $tax, 2);
            $parentGoods = round((float) $parent->subtotal + (float) $parent->tax_amount, 2);
            $parentDiscount = round((float) $parent->discount_amount, 2);
            $already = (float) FbrPosTransaction::where('company_id', $company->id)
                ->where('parent_transaction_id', $parent->id)->where('transaction_type', 'return')->sum('discount_amount');
            $discount = $parentGoods > 0
                ? round($parentDiscount * min($goods / $parentGoods, 1), 2) : 0.0;
            $discount = max(0.0, min($discount, round($parentDiscount - $already, 2), $goods));
            $total = round($goods - $discount, 2);
            if (isset($sale['amount']) && abs($total - round((float) $sale['amount'], 2)) > 0.001) {
                $this->invalid('payload.data.amount_cents', 'Refund amount does not match the locked return lines.');
            }

            $return = FbrPosTransaction::create([
                'company_id' => $company->id, 'branch_id' => $parent->branch_id,
                'terminal_id' => $parent->terminal_id, 'invoice_number' => $invoice,
                'invoice_mode' => $parent->invoice_mode, 'transaction_type' => 'return',
                'parent_transaction_id' => $parent->id, 'customer_id' => $parent->customer_id,
                'customer_name' => $parent->customer_name, 'customer_phone' => $parent->customer_phone,
                'customer_ntn' => $parent->customer_ntn, 'subtotal' => round($subtotal, 2),
                'discount_amount' => $discount, 'tax_amount' => round($tax, 2), 'total_amount' => $total,
                'payment_method' => $method, 'payment_breakdown' => [['method' => $method, 'amount' => $total]],
                'status' => 'completed', 'fbr_status' => 'local', 'created_by' => $actor->id,
            ]);
            foreach ($returnItems as $attributes) {
                $return->items()->create($attributes);
            }
            if ($method === 'khata') {
                $customerAggregate = (string) ($data['customer']['aggregate_id'] ?? $data['customer']['id'] ?? '');
                $mappedCustomer = $this->mappedId($company, $branchId, 'customer', $customerAggregate, 'pos_customer');
                if ((int) $parent->customer_id !== $mappedCustomer) {
                    $this->invalid('payload.data.customer_id', 'Refund customer does not match the credit bill.');
                }
                $this->khata->recordReturnAdjustment(
                    (int) $company->id, (int) $parent->customer_id, $total,
                    (int) $return->id, $invoice, $actor
                );
            }
            if ($company->fbr_reporting_enabled && $company->agent_enabled
                && !empty($parent->fbr_invoice_number) && $company->agentServesFbr()) {
                $return->update(['fbr_status' => 'pending']);
            }
            $this->aggregates->bind(
                (int) $company->id, $branchId, 'refund',
                (string) ($data['_aggregate_id'] ?: $event->event_id),
                'fbr_pos_transaction', (int) $return->id
            );
            return ['return_transaction_id' => $return->id, 'refund_total' => $total, 'replayed' => false];
        });
    }

    private function restore(Company $company, AgentCoreEvent $event, array $data, User $actor, int $branchId): array
    {
        $stockData = (array) ($data['stock'] ?? []);
        $returnId = $this->positiveId($stockData['return_transaction_id'] ?? null, 'payload.data.stock.return_transaction_id');

        return DB::transaction(function () use ($company, $event, $actor, $branchId, $returnId) {
            $return = FbrPosTransaction::where('company_id', $company->id)
                ->where('branch_id', $branchId)->where('transaction_type', 'return')
                ->lockForUpdate()->find($returnId);
            if (!$return) {
                $this->missing("fbr-pos-return:{$returnId}");
            }
            $existing = InventoryMovement::where('company_id', $company->id)
                ->where('reference_type', 'agent_core_return')->where('reference_id', $return->id)->get();
            if ($existing->isNotEmpty()) {
                return ['return_transaction_id' => $return->id, 'restored_quantity' => (float) $existing->sum('quantity'), 'replayed' => true];
            }

            $parent = FbrPosTransaction::where('company_id', $company->id)
                ->where('branch_id', $branchId)->lockForUpdate()->find($return->parent_transaction_id);
            if (!$parent) {
                $this->missing('fbr-pos-parent-sale:' . (string) $return->parent_transaction_id);
            }
            $deducted = InventoryMovement::where('company_id', $company->id)
                ->where('reference_type', 'fbr_pos_transaction')->where('reference_id', $parent->id)
                ->where('type', InventoryMovement::TYPE_SALE)->pluck('product_id')->map(fn ($id) => (int) $id)->all();
            $restored = 0.0;
            if ($company->inventory_enabled) {
                $writeBranch = BranchStockService::writeBranchId((int) $company->id, (int) $parent->branch_id);
                foreach ($return->items()->lockForUpdate()->get() as $item) {
                    if (!$item->product_id || !in_array((int) $item->product_id, $deducted, true)) {
                        continue;
                    }
                    InventoryService::addStock(
                        (int) $company->id, (int) $item->product_id, (float) $item->quantity,
                        (float) $item->unit_price, InventoryMovement::TYPE_RETURN_IN, $writeBranch,
                        ['type' => 'agent_core_return', 'id' => $return->id, 'number' => $event->event_id],
                        'Agent Core return restore', (int) $actor->id
                    );
                    $restored += (float) $item->quantity;
                }
            }
            return ['return_transaction_id' => $return->id, 'restored_quantity' => round($restored, 3), 'replayed' => false];
        });
    }

    private function scope(Company $company, array $wire): array
    {
        $scope = (array) ($wire['scope'] ?? []);
        if ((string) ($scope['company_id'] ?? '') !== (string) $company->id) {
            $this->invalid('scope.company_id', 'Company scope mismatch.');
        }
        $branchId = $this->positiveId($scope['branch_id'] ?? null, 'scope.branch_id');
        $userId = $this->positiveId($scope['user_id'] ?? null, 'scope.user_id');
        if (!Schema::hasTable('branches') || !DB::table('branches')->where('company_id', $company->id)->where('id', $branchId)->exists()) {
            $this->invalid('scope.branch_id', 'Branch scope mismatch.');
        }
        $actor = User::where('company_id', $company->id)->where('is_active', true)->find($userId);
        if (!$actor) {
            $this->invalid('scope.user_id', 'Active scoped user was not found.');
        }
        $role = app(BranchContextService::class)->effectiveRole($actor);
        if (in_array($role, ['cashier', 'employee'], true) && (int) $actor->default_branch_id !== $branchId) {
            $this->invalid('scope.branch_id', 'User is not assigned to this branch.');
        }
        if ($role === 'manager' && (int) $actor->default_branch_id !== $branchId
            && !DB::table('branch_user')->where('user_id', $actor->id)->where('branch_id', $branchId)->exists()) {
            $this->invalid('scope.branch_id', 'Manager is not assigned to this branch.');
        }
        return [$actor, $branchId];
    }

    private function returnInvoice(AgentCoreEvent $event): string
    {
        return 'ACR-' . strtoupper(substr(hash('sha256', (string) $event->event_id), 0, 20));
    }

    private function mappedId(
        Company $company,
        int $branchId,
        string $localType,
        string $aggregate,
        string $cloudType,
    ): int {
        $mapping = $this->aggregates->resolve(
            (int) $company->id, $branchId, $localType, $aggregate, $cloudType
        );
        if (!$mapping) {
            $this->missing("{$localType}:{$aggregate}");
        }
        return (int) $mapping->cloud_id;
    }

    private function transactionId(Company $company, int $branchId, string $aggregate): int
    {
        if ($aggregate === '') {
            $this->missing('sale:missing');
        }
        foreach (['sale', 'order'] as $type) {
            $mapping = $this->aggregates->resolve(
                (int) $company->id, $branchId, $type, $aggregate, 'fbr_pos_transaction'
            );
            if ($mapping) return (int) $mapping->cloud_id;
        }
        $id = $this->aggregates->resolveProjectedResult(
            (int) $company->id, $branchId, $aggregate, 'transaction_id'
        );
        if ($id && FbrPosTransaction::where('company_id', $company->id)
            ->where('branch_id', $branchId)->whereKey($id)->exists()) {
            $this->aggregates->bind(
                (int) $company->id, $branchId, 'order', $aggregate, 'fbr_pos_transaction', $id
            );
            return $id;
        }
        $this->missing("sale:{$aggregate}");
    }

    private function positiveId(mixed $value, string $field): int
    {
        if (!is_numeric($value) || (int) $value < 1) {
            $this->invalid($field, 'A positive identifier is required.');
        }
        return (int) $value;
    }

    private function money(mixed $value, string $field): float
    {
        if (!is_numeric($value) || !is_finite((float) $value) || round((float) $value, 2) <= 0) {
            $this->invalid($field, 'A positive amount is required.');
        }
        return round((float) $value, 2);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }

    private function missing(string $dependency): never
    {
        throw new AgentCoreProjectionDependency(
            $dependency,
            "Local aggregate {$dependency} has not been projected to the cloud yet."
        );
    }

    private function validationMessage(ValidationException $e): string
    {
        $errors = $e->errors();
        ksort($errors);
        return (string) (reset($errors)[0] ?? 'The command is invalid.');
    }
}

final class AgentCoreProjectionDependency extends \RuntimeException
{
    public function __construct(public readonly string $dependency, string $message)
    {
        parent::__construct($message);
    }
}