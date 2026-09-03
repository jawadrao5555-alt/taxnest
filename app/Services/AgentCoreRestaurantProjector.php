<?php

namespace App\Services;

use App\Models\AgentCoreEvent;
use App\Models\Company;
use App\Models\PosProduct;
use App\Models\PosDeal;
use App\Models\PosService;
use App\Models\PosStation;
use App\Models\PosTransaction;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderItem;
use App\Models\RestaurantTable;
use App\Support\PosKitchenLines;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Projects the Local Core restaurant vocabulary without an HTTP/auth context.
 *
 * Lease and event-envelope verification deliberately live outside this class.
 * Every mutable domain row is nevertheless tenant constrained and locked here.
 */
final class AgentCoreRestaurantProjector
{
    private const OPEN = ['held', 'preparing', 'ready'];

    public function __construct(
        private AgentCoreAggregateMap $aggregates,
        private AgentCoreSaleProjector $sales,
    ) {}

    public function project(Company $company, AgentCoreEvent $event, array $data, array $scope = []): AgentCoreProjectionOutcome
    {
        $envelope = (array) ($event->payload ?? []);
        if (isset($data['payload']['data']) && is_array($data['payload']['data'])) {
            $scope = (array) ($data['scope'] ?? $scope);
            $data = $data['payload']['data'];
        } elseif (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }
        $scope = $scope ?: (array) ($event->event_scope ?? []);
        $command = (string) ($envelope['command_type'] ?? $data['command_type'] ?? '');
        $aggregate = (string) ($envelope['aggregate_id'] ?? $data['aggregate_id'] ?? '');
        $revision = (int) ($envelope['aggregate_revision'] ?? $data['aggregate_revision'] ?? 0);

        if ((string) ($scope['company_id'] ?? $company->id) !== (string) $company->id || empty($scope['branch_id'])) {
            return $this->reject($event, 'scope_mismatch', 'Canonical company and branch scope do not match.');
        }
        if (!Schema::hasTable('restaurant_orders') || !Schema::hasTable('restaurant_order_items')) {
            return $this->retry($event, 'restaurant-schema', 'Restaurant projection schema is unavailable.');
        }
        if ($aggregate === '' || $revision < 1) {
            return $this->reject($event, 'invalid_envelope', 'A canonical aggregate id and revision are required.');
        }
        $nextRevision = $this->nextRevision($company, $event, $aggregate, $scope);
        if ($revision < $nextRevision) {
            return $this->reject($event, 'revision_conflict', 'Aggregate revision is stale.');
        }
        if ($revision > $nextRevision) {
            return $this->retry($event, 'aggregate-revision:' . $event->device_uid . ':' . $aggregate . ':' . $nextRevision, 'An earlier aggregate revision is not projected yet.');
        }
        $domainAggregate = $aggregate;
        if (in_array($command, ['table.claim', 'table.release'], true)) {
            $domainAggregate = (string) ($data['order_id'] ?? '');
            if ($domainAggregate === '' && $command === 'table.release') {
                $domainAggregate = $this->mappedTableOrderAggregate($company, $event, $aggregate) ?? '';
            }
            $data['table_aggregate_id'] = $aggregate;
        }
        if ($command === 'table.shift') {
            $domainAggregate = (string) ($data['order_id'] ?? '');
            $data['source_table_aggregate_id'] = $aggregate;
            $data['target_table_aggregate_id'] = (string) ($data['target_table_id'] ?? '');
        }
        if ($revision === 1 && str_starts_with($command, 'order.')
            && !in_array($command, ['order.hold', 'order.open', 'order.opened'], true)) {
            return $this->missingOrder($event, $domainAggregate);
        }

        try {
            return match ($command) {
                'order.hold' => $this->hold($company, $event, $data, $scope, $domainAggregate),
                'order.open', 'order.opened' => $this->open($company, $event, $data, $scope, $domainAggregate),
                'order.amend', 'order.amended' => $this->amend($company, $event, $data, $scope, $domainAggregate),
                'order.line.add' => $this->addLine($company, $event, $data, $scope, $domainAggregate),
                'order.line.consume' => $this->consumeLine($company, $event, $data, $scope, $domainAggregate),
                'order.claim', 'order.claimed' => $this->claim($company, $event, $data, $scope, $domainAggregate),
                'order.settle', 'order.settled' => $this->settle($company, $event, $data, $scope, $domainAggregate),
                'order.cancel', 'order.cancelled' => $this->cancel($company, $event, $data, $scope, $domainAggregate),
                'table.claim', 'table.assign', 'table.assigned' => $this->assignTable($company, $event, $data, $scope, $domainAggregate, false),
                'table.shift', 'table.shifted' => $this->assignTable($company, $event, $data, $scope, $domainAggregate, true),
                'table.release', 'table.released' => $this->releaseTable($company, $event, $data, $scope, $domainAggregate),
                'kot.request', 'kot.requested' => $this->requestKot($company, $event, $data, $scope, $aggregate),
                'kot.print', 'kot.printed' => $this->markKotPrinted($company, $event, $data, $scope, $aggregate),
                default => $this->reject($event, 'unsupported_command', 'Unsupported restaurant command.'),
            };
        } catch (RestaurantProjectionConflict $conflict) {
            return $this->reject($event, 'table_conflict', $conflict->getMessage());
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $messages = reset($errors);
            return $this->reject($event, 'sale_rejected', (string) ($messages[0] ?? 'Sale projection was rejected.'));
        } catch (\Throwable $exception) {
            report($exception);
            return $this->retry($event, 'restaurant-projection', 'Restaurant projection failed transiently and may be retried.');
        }
    }

    private function hold(Company $company, AgentCoreEvent $event, array $data, array $scope, string $aggregate): AgentCoreProjectionOutcome
    {
        $snapshot = (array) ($data['order_snapshot'] ?? []);
        $userId = $this->scopeUser($company, $scope);
        $businessDate = (string) ($snapshot['business_date'] ?? '');
        $type = (string) ($snapshot['order_type'] ?? 'dine_in');
        if (!$snapshot || (string) ($snapshot['order_id'] ?? '') !== $aggregate
            || !$userId || !$this->date($businessDate)) {
            return $this->reject($event, 'invalid_order_snapshot', 'A complete order snapshot, business date, and tenant user are required.');
        }
        if (!in_array($type, ['dine_in', 'takeaway', 'delivery'], true)) {
            return $this->reject($event, 'invalid_order_type', 'Order type is invalid.');
        }
        $lineInput = $snapshot['lines'] ?? null;
        if (!is_array($lineInput) || $lineInput === []) {
            return $this->reject($event, 'lines_required', 'A held order snapshot must contain all immutable lines.');
        }
        foreach ($lineInput as $line) {
            if (!is_array($line) || trim((string) ($line['line_id'] ?? '')) === '') {
                return $this->reject($event, 'invalid_line', 'Every held line requires an opaque line_id.');
            }
        }
        $lineIds = array_map(fn (array $line) => (string) $line['line_id'], $lineInput);
        if (count($lineIds) !== count(array_unique($lineIds))) {
            return $this->reject($event, 'invalid_line', 'Held line identifiers must be unique.');
        }
        $lines = $this->resolveLines($company, $event, $lineInput, $type);
        if ($lines instanceof AgentCoreProjectionOutcome) return $lines;
        $totals = (array) ($snapshot['totals'] ?? []);
        $calculatedSubtotal = round(array_sum(array_column($lines, 'subtotal')), 2);
        $snapshotSubtotal = isset($totals['subtotal_cents'])
            ? ((int) $totals['subtotal_cents']) / 100 : ($totals['subtotal'] ?? null);
        $discount = isset($totals['discount_cents'])
            ? ((int) $totals['discount_cents']) / 100 : round((float) ($totals['discount_amount'] ?? 0), 2);
        $tax = isset($totals['tax_cents'])
            ? ((int) $totals['tax_cents']) / 100 : round((float) ($totals['tax_amount'] ?? 0), 2);
        $total = isset($totals['total_cents'])
            ? ((int) $totals['total_cents']) / 100 : round((float) ($totals['total_amount'] ?? ($calculatedSubtotal - $discount + $tax)), 2);
        if ($snapshotSubtotal === null
            || abs((float) $snapshotSubtotal - $calculatedSubtotal) > 0.009
            || abs($total - round($calculatedSubtotal - $discount + $tax, 2)) > 0.009) {
            return $this->reject($event, 'totals_mismatch', 'Frozen order totals do not match the immutable lines.');
        }

        $tableAggregate = (string) ($snapshot['table_id'] ?? '');
        $tableId = $this->numericId($snapshot['server_table_id'] ?? null)
            ?? $this->numericId($tableAggregate)
            ?? ($tableAggregate !== '' ? $this->mappedTableId($company, $event, $tableAggregate) : null);
        if ($tableAggregate !== '' && !$tableId) {
            return $this->retry($event, 'restaurant-table:' . $event->device_uid . ':' . $tableAggregate, 'The Local Core table mapping is not projected yet.');
        }
        $snapshotHash = hash('sha256', json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));

        return DB::transaction(function () use (
            $company, $event, $aggregate, $snapshot, $snapshotHash, $userId, $type,
            $lines, $lineInput, $discount, $tax, $totals, $tableId, $tableAggregate
        ): AgentCoreProjectionOutcome {
            Company::whereKey($company->id)->lockForUpdate()->first();
            $existingMap = $this->aggregates->resolve(
                (int) $company->id, $this->branchId($event),
                'restaurant_order', $aggregate, 'restaurant_order'
            );
            if ($existingMap) {
                $order = RestaurantOrder::where('company_id', $company->id)->find((int) $existingMap->cloud_id);
                if ($order && hash_equals((string) (($existingMap->metadata ?? [])['snapshot_hash'] ?? ''), $snapshotHash)) {
                    return $this->done($event, [
                        'order_id' => $order->id, 'order_aggregate_id' => $aggregate,
                        'order_status' => $order->status, 'replayed' => true,
                    ]);
                }
                return $this->reject($event, 'aggregate_conflict', 'Order aggregate was already held with a different snapshot.');
            }

            $order = new RestaurantOrder($this->orderFields($snapshot, $type, $userId));
            $order->company_id = $company->id;
            $order->order_number = (string) ($snapshot['order_number']
                ?? ('LC-' . $company->id . '-' . substr(hash('sha256', $event->device_uid . '|' . $aggregate), 0, 20)));
            $order->status = 'held';
            $order->source = 'agent_core';
            $order->save();
            foreach ($lines as $index => $line) {
                unset($line['id']);
                $item = new RestaurantOrderItem($line);
                $item->order_id = $order->id;
                $item->save();
                $localLineId = (string) $lineInput[$index]['line_id'];
                $this->aggregates->bind(
                    (int) $company->id, $this->branchId($event), 'restaurant_order_line',
                    $this->lineAggregate($aggregate, $localLineId),
                    'restaurant_order_item', (int) $item->id,
                    ['order_aggregate_id' => $aggregate, 'line_id' => $localLineId],
                );
            }
            $this->recalculate($order, array_merge($snapshot, [
                'discount_amount' => $discount,
                'tax_amount' => $tax,
            ]));
            if ($tableId) {
                $error = $this->lockedAssign($company, $order, $tableId, false);
                if ($error !== null) throw new RestaurantProjectionConflict($error);
                if ($tableAggregate !== '') {
                    $this->aggregates->bind(
                        (int) $company->id, $this->branchId($event),
                        'restaurant_table', $tableAggregate, 'restaurant_table', $tableId,
                    );
                }
            }
            $this->aggregates->bind(
                (int) $company->id, $this->branchId($event), 'restaurant_order', $aggregate,
                'restaurant_order', (int) $order->id, [
                    'business_date' => (string) $snapshot['business_date'],
                    'device_uid' => (string) $event->device_uid,
                    'aggregate_revision' => (int) (((array) $event->payload)['aggregate_revision'] ?? 1),
                    'snapshot_hash' => $snapshotHash,
                    'order_snapshot' => $snapshot,
                ],
            );
            return $this->done($event, [
                'order_id' => $order->id, 'order_aggregate_id' => $aggregate,
                'order_status' => 'held', 'replayed' => false,
            ]);
        });
    }

    private function open(Company $company, AgentCoreEvent $event, array $data, array $scope, string $aggregate): AgentCoreProjectionOutcome
    {
        $canonical = (string) (($event->payload ?? [])['command_type'] ?? '') === 'order.open';
        $orderId = $canonical ? null : $this->numericId($data['order_id'] ?? $aggregate);
        $userId = $this->scopeUser($company, $scope);
        $businessDate = (string) ($data['business_date'] ?? '');
        if (!$userId || !$this->date($businessDate)) {
            return $this->reject($event, 'invalid_order', 'business_date and a tenant user are required.');
        }
        if ($orderId && RestaurantOrder::where('company_id', $company->id)->whereKey($orderId)->exists()) {
            return $this->reject($event, 'already_exists', 'Order already exists.');
        }
        $type = (string) ($data['order_type'] ?? 'dine_in');
        if (!in_array($type, ['dine_in', 'takeaway', 'delivery'], true)) {
            return $this->reject($event, 'invalid_order_type', 'Order type is invalid.');
        }
        $lines = $this->resolveLines($company, $event, (array) ($data['lines'] ?? $data['items'] ?? []), $type);
        if ($lines instanceof AgentCoreProjectionOutcome) {
            return $lines;
        }
        $tableAggregate = (string) ($data['table_id'] ?? '');
        $openTableId = $this->numericId($data['server_table_id'] ?? null)
            ?? $this->numericId($tableAggregate)
            ?? ($tableAggregate !== '' ? $this->mappedTableId($company, $event, $tableAggregate) : null);
        if ($tableAggregate !== '' && !$openTableId) {
            return $this->retry($event, 'restaurant-table:' . $event->device_uid . ':' . $tableAggregate, 'The Local Core table mapping is not projected yet.');
        }

        return DB::transaction(function () use ($company, $event, $data, $scope, $aggregate, $orderId, $userId, $type, $lines, $openTableId): AgentCoreProjectionOutcome {
            // Serializes first-writer mapping creation, including two devices
            // concurrently opening the same opaque aggregate in this tenant.
            Company::whereKey($company->id)->lockForUpdate()->first();
            if ($this->mappedOrderId($company, $event, $aggregate)) {
                return $this->reject($event, 'already_exists', 'Order aggregate is already mapped.');
            }
            $order = new RestaurantOrder($this->orderFields($data, $type, $userId));
            if ($orderId) $order->id = $orderId;
            $order->company_id = $company->id;
            $order->order_number = (string) ($data['order_number'] ?? ('LC-' . $company->id . '-' . substr(hash('sha256', $event->device_uid . '|' . $aggregate), 0, 20)));
            $order->status = 'held';
            $order->source = (string) ($data['source'] ?? 'agent_core');
            $order->save();
            $this->replaceLines($order, $lines);
            $this->recalculate($order, $data);

            if ($openTableId) {
                $assigned = $this->lockedAssign($company, $order, $openTableId, false);
                if ($assigned !== null) {
                    throw new RestaurantProjectionConflict($assigned);
                }
            }
            $this->aggregates->bind(
                (int) $company->id, $this->branchId($event), 'restaurant_order', $aggregate,
                'restaurant_order', (int) $order->id, [
                    'business_date' => (string) $data['business_date'],
                    'device_uid' => (string) $event->device_uid,
                ],
            );
            return $this->done($event, [
                'order_id' => $order->id, 'order_aggregate_id' => $aggregate,
                'order_status' => $order->status,
            ]);
        });
    }

    private function amend(Company $company, AgentCoreEvent $event, array $data, array $scope, string $aggregate): AgentCoreProjectionOutcome
    {
        if (!array_key_exists('lines', $data) && !array_key_exists('items', $data)) {
            return $this->reject($event, 'lines_required', 'An amendment must contain the complete canonical line set.');
        }
        $order = $this->order($company, $event, $data, $aggregate);
        if (!$order) return $this->missingOrder($event, $aggregate);
        $lines = $this->resolveLines($company, $event, (array) ($data['lines'] ?? $data['items']), $order->order_type);
        if ($lines instanceof AgentCoreProjectionOutcome) return $lines;

        return DB::transaction(function () use ($company, $event, $data, $aggregate, $lines): AgentCoreProjectionOutcome {
            $order = $this->lockedOrder($company, $event, $data, $aggregate);
            if (!$order || !in_array($order->status, self::OPEN, true)) {
                return $this->reject($event, 'order_closed', 'Only an open order may be amended.');
            }
            $printed = $order->items()->whereNotNull('kot_printed_at')->exists();
            if ($printed) {
                return $this->reject($event, 'kot_lines_immutable', 'Printed kitchen lines cannot be replaced without an explicit void workflow.');
            }
            $this->replaceLines($order, $lines);
            $order->fill($this->orderFields($data, $order->order_type, (int) $order->created_by))->save();
            $this->recalculate($order, $data);
            return $this->done($event, ['order_id' => $order->id, 'order_status' => $order->status]);
        });
    }

    private function addLine(Company $company, AgentCoreEvent $event, array $data, array $scope, string $aggregate): AgentCoreProjectionOutcome
    {
        $order = $this->order($company, $event, $data, $aggregate);
        if (!$order) return $this->missingOrder($event, $aggregate);
        $lineId = (string) ($data['line_id'] ?? '');
        if ($lineId === '') return $this->reject($event, 'invalid_line', 'line_id is required.');
        $resolved = $this->resolveLines($company, $event, [$data], $order->order_type);
        if ($resolved instanceof AgentCoreProjectionOutcome) return $resolved;

        return DB::transaction(function () use ($company, $event, $data, $aggregate, $lineId, $resolved): AgentCoreProjectionOutcome {
            $order = $this->lockedOrder($company, $event, $data, $aggregate);
            if (!$order || !in_array($order->status, self::OPEN, true)) {
                return $this->reject($event, 'order_closed', 'Only an open order may receive a line.');
            }
            if ($this->mappedLineId($company, $event, $aggregate, $lineId)) {
                return $this->reject($event, 'already_exists', 'Order line already exists.');
            }
            $line = $resolved[0];
            unset($line['id']);
            $item = new RestaurantOrderItem($line);
            $item->order_id = $order->id;
            $item->save();
            $this->aggregates->bind(
                (int) $company->id, $this->branchId($event), 'restaurant_order_line',
                $this->lineAggregate($aggregate, $lineId), 'restaurant_order_item', (int) $item->id,
                ['order_aggregate_id' => $aggregate, 'line_id' => $lineId],
            );
            $oldSubtotal = (float) $order->subtotal;
            $newSubtotal = round((float) $order->items()->sum('subtotal'), 2);
            $order->update([
                'subtotal' => $newSubtotal,
                'total_amount' => round((float) $order->total_amount + ($newSubtotal - $oldSubtotal), 2),
            ]);
            return $this->done($event, [
                'order_id' => $order->id, 'order_item_id' => $item->id,
                'local_line_id' => $lineId, 'order_status' => $order->status,
            ]);
        });
    }

    private function consumeLine(Company $company, AgentCoreEvent $event, array $data, array $scope, string $aggregate): AgentCoreProjectionOutcome
    {
        $order = $this->order($company, $event, $data, $aggregate);
        if (!$order) return $this->missingOrder($event, $aggregate);
        $lineId = (string) ($data['line_id'] ?? '');
        $itemId = $this->mappedLineId($company, $event, $aggregate, $lineId);
        if (!$itemId) {
            return $this->retry($event, 'restaurant-line:' . $event->device_uid . ':' . $aggregate . ':' . $lineId, 'The Local Core line mapping is not projected yet.');
        }
        return DB::transaction(function () use ($company, $event, $data, $aggregate, $itemId, $lineId): AgentCoreProjectionOutcome {
            $order = $this->lockedOrder($company, $event, $data, $aggregate);
            if (!$order || !in_array($order->status, self::OPEN, true)) {
                return $this->reject($event, 'order_closed', 'Only an open order line may be consumed.');
            }
            $item = $order->items()->lockForUpdate()->find($itemId);
            if (!$item) return $this->retry($event, 'restaurant-line:' . $lineId, 'Mapped order line is not available yet.');
            // Stock consumption is already authoritative in Local Core. The
            // restaurant projection records the durable line resolution without
            // deleting the bill/KOT line or deducting inventory a second time.
            return $this->done($event, [
                'order_id' => $order->id, 'order_item_id' => $item->id,
                'local_line_id' => $lineId, 'consumed' => true,
            ]);
        });
    }

    private function claim(Company $company, AgentCoreEvent $event, array $data, array $scope, string $aggregate): AgentCoreProjectionOutcome
    {
        if (!$this->order($company, $event, $data, $aggregate)) return $this->missingOrder($event, $aggregate);
        $userId = $this->scopeUser($company, $scope);
        if (!$userId) return $this->reject($event, 'invalid_user', 'Claiming user is outside the company.');
        return DB::transaction(function () use ($company, $event, $data, $aggregate, $userId): AgentCoreProjectionOutcome {
            $order = $this->lockedOrder($company, $event, $data, $aggregate);
            if (!$order || !in_array($order->status, self::OPEN, true)) {
                return $this->reject($event, 'order_closed', 'Order is not claimable.');
            }
            if ($order->assigned_cashier_id && (int) $order->assigned_cashier_id !== $userId) {
                return $this->reject($event, 'already_claimed', 'Order was claimed by another cashier.');
            }
            $order->assigned_cashier_id = $userId;
            $order->save();
            return $this->done($event, ['order_id' => $order->id, 'claimed_by' => $userId]);
        });
    }

    private function settle(Company $company, AgentCoreEvent $event, array $data, array $scope, string $aggregate): AgentCoreProjectionOutcome
    {
        if (!$this->order($company, $event, $data, $aggregate)) return $this->missingOrder($event, $aggregate);
        $canonical = !$this->legacyCommand($event);
        $sale = $canonical
            ? (array) ($data['sale_snapshot'] ?? [])
            : (array) ($data['sale'] ?? $data['sale_snapshot'] ?? []);
        if ($canonical && !$sale) {
            return $this->reject($event, 'sale_snapshot_required', 'Settlement requires the complete immutable sale snapshot.');
        }
        $data['business_date'] ??= $this->mappedBusinessDate($company, $event, $aggregate);
        if (empty($data['business_date'])) return $this->missingOrder($event, $aggregate);
        return DB::transaction(function () use ($company, $event, $data, $scope, $aggregate, $canonical, $sale): AgentCoreProjectionOutcome {
            $order = $this->lockedOrder($company, $event, $data, $aggregate);
            if (!$order || !in_array($order->status, self::OPEN, true)) {
                return $this->reject($event, 'already_closed', 'Order was already settled or cancelled.');
            }
            if ($order->awaitingOnlinePayment() && empty($data['online_payment_confirmed'])) {
                return $this->reject($event, 'online_payment_awaited', 'Online payment must be confirmed before settlement.');
            }
            if ($canonical) {
                if (!$this->saleMatchesHeldSnapshot($company, $event, $aggregate, $sale)) {
                    return $this->reject($event, 'sale_snapshot_conflict', 'Sale snapshot does not match the frozen held order.');
                }
                $saleEvent = [
                    'event_id' => (string) $event->event_id . ':sale',
                    'event_type' => 'sale.created',
                    'occurred_at' => ($event->occurred_at ?: now())->toIso8601String(),
                    'idempotency_key' => 'restaurant-settlement:' . (string) $event->event_id,
                    'scope' => $scope,
                    'payload' => ['schema' => 'pra.manual-immediate.v1', 'sale' => $sale],
                ];
                $saleResult = $this->sales->project($company, (string) $event->device_uid, $saleEvent);
                $transactionId = $this->numericId($saleResult['transaction_id'] ?? null);
                if (!$transactionId) {
                    throw new \RuntimeException('Authoritative sale projection did not return a transaction.');
                }
                $this->aggregates->bind(
                    (int) $company->id, $this->branchId($event), 'restaurant_sale', $aggregate,
                    'pos_transaction', $transactionId, [
                        'offline_uuid' => (string) ($sale['offline_uuid'] ?? ''),
                        'event_id' => (string) $event->event_id,
                    ],
                );
            } else {
                $transactionId = $this->numericId($data['transaction_id'] ?? null);
                if (!$transactionId) {
                    return $this->retry($event, 'pos-transaction:' . $event->device_uid . ':' . $aggregate, 'The POS transaction required for settlement is not projected yet.');
                }
            }
            $txn = PosTransaction::withoutGlobalScope('hide_archived')
                ->where('company_id', $company->id)->lockForUpdate()->find($transactionId);
            if (!$txn || !$this->transactionInScope($txn, $scope)) {
                return $this->reject($event, 'transaction_scope', 'Transaction does not exist in this branch scope.');
            }
            if (RestaurantOrder::where('company_id', $company->id)->where('pos_transaction_id', $txn->id)
                ->where('id', '!=', $order->id)->exists()) {
                return $this->reject($event, 'transaction_used', 'Transaction already settles another order.');
            }
            if (!empty($data['business_date']) && (string) ($txn->business_date ?? '') !== (string) $data['business_date']) {
                return $this->reject($event, 'business_date_mismatch', 'Settlement transaction belongs to a different business date.');
            }
            if (abs((float) $txn->subtotal - (float) $order->items()->sum('subtotal')) > 0.009) {
                return $this->reject($event, 'settlement_drift', 'Transaction lines no longer match the locked order.');
            }
            $canonicalTotal = isset($data['total_cents'])
                ? ((int) $data['total_cents']) / 100
                : (isset($sale['totals']['total_amount']) ? (float) $sale['totals']['total_amount'] : null);
            if ($canonicalTotal !== null && abs((float) $txn->total_amount - $canonicalTotal) > 0.009) {
                return $this->reject($event, 'settlement_drift', 'Transaction total does not match the canonical settlement.');
            }
            $fields = [
                'status' => 'completed', 'pos_transaction_id' => $txn->id,
                'payment_method' => $txn->payment_method,
                'tax_amount' => $txn->tax_amount, 'total_amount' => $txn->total_amount,
            ];
            if (Schema::hasColumn('restaurant_orders', 'online_payment_awaited_at')) $fields['online_payment_awaited_at'] = null;
            $order->update($fields);
            $this->freeTableIfUnused($company, $order);
            return $this->done($event, ['order_id' => $order->id, 'order_status' => 'completed', 'transaction_id' => $txn->id]);
        });
    }

    private function cancel(Company $company, AgentCoreEvent $event, array $data, array $scope, string $aggregate): AgentCoreProjectionOutcome
    {
        if (!$this->order($company, $event, $data, $aggregate)) return $this->missingOrder($event, $aggregate);
        return DB::transaction(function () use ($company, $event, $data, $scope, $aggregate): AgentCoreProjectionOutcome {
            $order = $this->lockedOrder($company, $event, $data, $aggregate);
            if (!$order || !in_array($order->status, self::OPEN, true)) {
                return $this->reject($event, 'order_closed', 'Only an open order may be cancelled.');
            }
            $fields = ['status' => 'cancelled'];
            if (Schema::hasColumn('restaurant_orders', 'cancelled_at')) $fields['cancelled_at'] = now();
            if (Schema::hasColumn('restaurant_orders', 'cancelled_by')) $fields['cancelled_by'] = $this->scopeUser($company, $scope);
            $order->update($fields);
            $this->freeTableIfUnused($company, $order);
            return $this->done($event, ['order_id' => $order->id, 'order_status' => 'cancelled']);
        });
    }

    private function assignTable(Company $company, AgentCoreEvent $event, array $data, array $scope, string $aggregate, bool $shift): AgentCoreProjectionOutcome
    {
        if (!$this->order($company, $event, $data, $aggregate)) return $this->missingOrder($event, $aggregate);
        $tableAggregate = (string) ($data['target_table_aggregate_id'] ?? $data['table_aggregate_id'] ?? '');
        $tableId = $this->numericId($data['server_table_id'] ?? $data['table_id'] ?? $data['target_table_id'] ?? null)
            ?? ($tableAggregate !== '' ? $this->numericId($tableAggregate) : null)
            ?? ($tableAggregate !== '' ? $this->mappedTableId($company, $event, $tableAggregate) : null);
        if (!$tableId) {
            return $this->retry($event, 'restaurant-table:' . $event->device_uid . ':' . $tableAggregate, 'The Local Core table mapping is not projected yet.');
        }
        $sourceAggregate = (string) ($data['source_table_aggregate_id'] ?? '');
        $sourceTableId = $sourceAggregate !== '' ? (
            $this->numericId($sourceAggregate) ?? $this->mappedTableId($company, $event, $sourceAggregate)
        ) : null;
        if ($shift && $sourceAggregate !== '' && !$sourceTableId) {
            return $this->retry($event, 'restaurant-table:' . $event->device_uid . ':' . $sourceAggregate, 'The Local Core source table mapping is not projected yet.');
        }
        return DB::transaction(function () use ($company, $event, $data, $aggregate, $tableAggregate, $tableId, $shift, $sourceTableId): AgentCoreProjectionOutcome {
            $order = $this->lockedOrder($company, $event, $data, $aggregate);
            if (!$order || !in_array($order->status, self::OPEN, true)) {
                return $this->reject($event, 'order_closed', 'Only an open order may use a table.');
            }
            if ($shift && !$order->table_id) return $this->reject($event, 'table_missing', 'Order has no table to shift.');
            if ($shift && $sourceTableId && (int) $order->table_id !== $sourceTableId) {
                return $this->reject($event, 'table_conflict', 'Order is no longer associated with the source table.');
            }
            if ((int) $order->table_id === $tableId) return $this->reject($event, 'same_table', 'Order is already assigned to this table.');
            if ($error = $this->lockedAssign($company, $order, $tableId, $shift)) {
                return $this->reject($event, 'table_conflict', $error);
            }
            if ($tableAggregate !== '') {
                $this->aggregates->bind(
                    (int) $company->id, $this->branchId($event), 'restaurant_table',
                    $tableAggregate, 'restaurant_table', $tableId,
                );
            }
            return $this->done($event, ['order_id' => $order->id, 'table_id' => $tableId, 'table_aggregate_id' => $tableAggregate ?: null]);
        });
    }

    private function releaseTable(Company $company, AgentCoreEvent $event, array $data, array $scope, string $aggregate): AgentCoreProjectionOutcome
    {
        if (!$this->order($company, $event, $data, $aggregate)) return $this->missingOrder($event, $aggregate);
        return DB::transaction(function () use ($company, $event, $data, $aggregate): AgentCoreProjectionOutcome {
            $order = $this->lockedOrder($company, $event, $data, $aggregate);
            if (!$order || !$order->table_id) return $this->reject($event, 'table_missing', 'Order has no assigned table.');
            $old = (int) $order->table_id;
            $order->table_id = null;
            $order->save();
            $this->freeTable($company, $old);
            return $this->done($event, ['order_id' => $order->id, 'released_table_id' => $old]);
        });
    }

    private function requestKot(Company $company, AgentCoreEvent $event, array $data, array $scope, string $aggregate): AgentCoreProjectionOutcome
    {
        $order = $this->order($company, $event, $data, $aggregate);
        if (!$order) return $this->missingOrder($event, $aggregate);
        if (!in_array($order->status, self::OPEN, true)) {
            return $this->reject($event, 'order_closed', 'KOT requires an open order.');
        }
        $delta = (bool) ($data['delta'] ?? false);
        $queued = KotPrintService::enqueueForOrder($company, $order, $this->scopeUser($company, $scope), $delta);
        if (!($queued['printed'] ?? false)) {
            $reason = (string) ($queued['reason'] ?? 'error');
            return in_array($reason, ['agent_offline', 'error'], true)
                ? $this->retry($event, 'kot-printer', "KOT enqueue failed: {$reason}.")
                : $this->reject($event, 'kot_unavailable', "KOT was not queued: {$reason}.");
        }
        return $this->done($event, ['order_id' => $order->id, 'print_job_ids' => array_values($queued['job_ids'] ?? [])]);
    }

    private function markKotPrinted(Company $company, AgentCoreEvent $event, array $data, array $scope, string $aggregate): AgentCoreProjectionOutcome
    {
        if (!$this->order($company, $event, $data, $aggregate)) return $this->missingOrder($event, $aggregate);
        return DB::transaction(function () use ($company, $event, $data, $aggregate): AgentCoreProjectionOutcome {
            $order = $this->lockedOrder($company, $event, $data, $aggregate);
            if (!$order) return $this->reject($event, 'not_found', 'Order does not exist in this scope.');
            $items = PosKitchenLines::scope($order->items()->lockForUpdate());
            $ids = array_values(array_unique(array_map('intval', (array) ($data['line_ids'] ?? $data['item_ids'] ?? []))));
            if ($ids) $items->whereIn('id', $ids);
            if (array_key_exists('station_id', $data)) {
                $candidate = $items->get();
                $stations = PosStation::activeFor($company->id);
                $map = PosStation::mapItems($company->id, $stations, $candidate);
                $stationIds = $candidate->filter(fn ($item) => ($map[$item->id] ?? PosStation::DEFAULT_ID) === (int) $data['station_id'])->pluck('id');
                $items = PosKitchenLines::scope($order->items()->lockForUpdate())->whereIn('id', $stationIds);
            }
            $stampIds = $items->pluck('id');
            if ($stampIds->isEmpty()) return $this->reject($event, 'no_kitchen_lines', 'No matching kitchen lines were printed.');
            $batch = max(1, (int) ($data['batch_no'] ?? ((int) $order->kot_print_count + 1)));
            RestaurantOrderItem::where('order_id', $order->id)->whereIn('id', $stampIds)
                ->update(['kot_printed_at' => $event->occurred_at ?: now(), 'kot_batch_no' => $batch]);
            $order->update(['kot_sent_at' => $event->occurred_at ?: now(), 'kot_print_count' => max((int) $order->kot_print_count, $batch)]);
            return $this->done($event, ['order_id' => $order->id, 'printed_line_ids' => $stampIds->map(fn ($id) => (int) $id)->values()->all()]);
        });
    }

    private function lockedAssign(Company $company, RestaurantOrder $order, int $tableId, bool $shift): ?string
    {
        $oldId = (int) $order->table_id;
        $lockIds = array_values(array_unique(array_filter([$oldId, $tableId])));
        sort($lockIds, SORT_NUMERIC);
        $tables = RestaurantTable::where('company_id', $company->id)
            ->whereIn('id', $lockIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $target = $tables->get($tableId);
        if (!$target) return 'Table does not exist in this company.';
        $old = $oldId ? $tables->get($oldId) : null;
        if ($shift && (!$old || (int) $order->table_id !== (int) $old->id)) {
            return 'The source table association changed before the shift.';
        }
        $busy = RestaurantOrder::where('company_id', $company->id)->where('table_id', $tableId)
            ->where('id', '!=', $order->id)->whereIn('status', self::OPEN)->exists();
        if ($target->status !== 'available' || $busy) return 'Table is not available.';
        $since = $old?->occupied_since ?: $order->created_at ?: now();
        $target->update(['status' => 'occupied', 'locked_by_user_id' => null, 'locked_at' => null, 'occupied_since' => $since]);
        $order->table_id = $target->id;
        $order->save();
        if ($shift && $old) {
            $stillUsed = RestaurantOrder::where('company_id', $company->id)->where('table_id', $old->id)
                ->whereIn('status', self::OPEN)->exists();
            if (!$stillUsed) {
                $old->update(['status' => 'available', 'locked_by_user_id' => null, 'locked_at' => null, 'occupied_since' => null]);
            }
        }
        return null;
    }

    private function freeTableIfUnused(Company $company, RestaurantOrder $order): void
    {
        if ($order->table_id) $this->freeTable($company, (int) $order->table_id);
    }

    private function freeTable(Company $company, int $tableId): void
    {
        $active = RestaurantOrder::where('company_id', $company->id)->where('table_id', $tableId)
            ->whereIn('status', self::OPEN)->exists();
        if (!$active) RestaurantTable::where('company_id', $company->id)->whereKey($tableId)
            ->update(['status' => 'available', 'locked_by_user_id' => null, 'locked_at' => null, 'occupied_since' => null]);
    }

    private function resolveLines(Company $company, AgentCoreEvent $event, array $input, string $orderType): array|AgentCoreProjectionOutcome
    {
        if (!$input) return [];
        $out = [];
        $skipUsed = false;
        foreach ($input as $line) {
            if (!is_array($line)) return $this->reject($event, 'invalid_line', 'Every order line must be an object.');
            $type = (string) ($line['item_type'] ?? $line['type'] ?? 'product');
            $id = $this->numericId($line['item_id'] ?? $line['product_id'] ?? null);
            $quantity = (float) ($line['quantity'] ?? 0);
            if (!in_array($type, ['product', 'service', 'deal', 'manual'], true) || $quantity <= 0) {
                return $this->reject($event, 'invalid_line', 'Line type and quantity are invalid.');
            }
            if ($type === 'product') {
                $product = PosProduct::where('company_id', $company->id)->find($id);
                if (!$product) return $this->reject($event, 'product_scope', 'A line product is outside the company.');
                $name = trim((string) ($line['name'] ?? $product->name));
                $price = isset($line['unit_price_cents']) ? ((int) $line['unit_price_cents']) / 100 : (float) ($line['unit_price'] ?? $product->price);
            } elseif ($type === 'service') {
                $service = PosService::where('company_id', $company->id)->find($id);
                if (!$service) return $this->reject($event, 'service_scope', 'A line service is outside the company.');
                $name = $service->name;
                $price = isset($line['unit_price_cents']) ? ((int) $line['unit_price_cents']) / 100 : (float) ($line['unit_price'] ?? $service->price);
            } elseif ($type === 'deal') {
                $deal = Schema::hasTable('pos_deals')
                    ? PosDeal::where('company_id', $company->id)->find($id) : null;
                if (!$deal) return $this->reject($event, 'deal_scope', 'A line deal is outside the company.');
                $name = trim((string) ($line['name'] ?? $deal->name));
                $price = isset($line['unit_price_cents'])
                    ? ((int) $line['unit_price_cents']) / 100 : (float) ($line['unit_price'] ?? $deal->price);
            } else {
                $name = trim((string) ($line['name'] ?? $line['item_name'] ?? ''));
                $price = isset($line['unit_price_cents']) ? ((int) $line['unit_price_cents']) / 100 : (float) ($line['unit_price'] ?? 0);
                if ($name === '') return $this->reject($event, 'invalid_line', 'A non-product line requires a name.');
            }
            if ($price < 0) return $this->reject($event, 'invalid_line', 'Line price cannot be negative.');
            $skip = PosKitchenLines::allowSkip([
                'skip_kitchen' => $line['skip_kitchen'] ?? false,
                'item_type' => $type, 'item_name' => $name,
            ], $orderType, $skipUsed);
            if ($skip) $skipUsed = true;
            $out[] = [
                'id' => $this->numericId($line['line_id'] ?? null),
                'item_type' => $type, 'item_id' => $id, 'item_name' => $name,
                'quantity' => $quantity, 'unit_price' => round($price, 2),
                'subtotal' => round($quantity * $price, 2),
                'special_notes' => $line['notes'] ?? $line['special_notes'] ?? null,
                'is_tax_exempt' => (bool) ($line['is_tax_exempt'] ?? false),
                'skip_kitchen' => $skip,
            ];
        }
        return $out;
    }

    private function replaceLines(RestaurantOrder $order, array $lines): void
    {
        $order->items()->delete();
        foreach ($lines as $line) {
            $id = $line['id']; unset($line['id']);
            $item = new RestaurantOrderItem($line);
            if ($id) $item->id = $id;
            $item->order_id = $order->id;
            $item->save();
        }
    }

    private function recalculate(RestaurantOrder $order, array $data): void
    {
        $subtotal = round((float) $order->items()->sum('subtotal'), 2);
        $discount = isset($data['discount_cents']) ? ((int) $data['discount_cents']) / 100 : (float) ($data['discount_amount'] ?? 0);
        $tax = isset($data['tax_cents']) ? ((int) $data['tax_cents']) / 100 : (float) ($data['tax_amount'] ?? 0);
        $total = isset($data['total_cents']) ? ((int) $data['total_cents']) / 100 : round($subtotal - $discount + $tax, 2);
        if ($discount < 0 || $tax < 0 || $total < 0) throw new \InvalidArgumentException('Invalid order totals.');
        $order->update(['subtotal' => $subtotal, 'discount_amount' => $discount, 'tax_amount' => $tax, 'total_amount' => $total]);
    }

    private function orderFields(array $data, string $type, int $userId): array
    {
        return array_filter([
            'order_type' => $type, 'created_by' => $userId,
            'customer_id' => $data['customer_id'] ?? null, 'customer_name' => $data['customer_name'] ?? null,
            'customer_phone' => $data['customer_phone'] ?? null, 'delivery_address' => $data['delivery_address'] ?? null,
            'kitchen_notes' => $data['kitchen_notes'] ?? null, 'priority' => $data['priority'] ?? null,
        ], fn ($value) => $value !== null);
    }

    private function order(Company $company, AgentCoreEvent $event, array $data, string $aggregate): ?RestaurantOrder
    {
        $id = $this->mappedOrderId($company, $event, $aggregate)
            ?? ($this->legacyCommand($event) ? $this->numericId($data['server_order_id'] ?? $aggregate) : null);
        return $id ? RestaurantOrder::where('company_id', $company->id)->find($id) : null;
    }

    private function lockedOrder(Company $company, AgentCoreEvent $event, array $data, string $aggregate): ?RestaurantOrder
    {
        $id = $this->mappedOrderId($company, $event, $aggregate)
            ?? ($this->legacyCommand($event) ? $this->numericId($data['server_order_id'] ?? $aggregate) : null);
        return $id ? RestaurantOrder::where('company_id', $company->id)->lockForUpdate()->find($id) : null;
    }

    private function mappedOrderId(Company $company, AgentCoreEvent $event, string $aggregate): ?int
    {
        $branchId = $this->branchId($event);
        $mapping = $this->aggregates->resolve(
            (int) $company->id, $branchId, 'restaurant_order', $aggregate, 'restaurant_order'
        );
        if ($mapping) return (int) $mapping->cloud_id;
        $legacy = $this->aggregates->resolveProjectedResult(
            (int) $company->id, $branchId, $aggregate, 'order_id'
        );
        if ($legacy && RestaurantOrder::where('company_id', $company->id)->whereKey($legacy)->exists()) {
            $this->aggregates->bind(
                (int) $company->id, $branchId, 'restaurant_order', $aggregate,
                'restaurant_order', $legacy, ['device_uid' => (string) $event->device_uid],
            );
            return $legacy;
        }
        return null;
    }

    private function mappedBusinessDate(Company $company, AgentCoreEvent $event, string $aggregate): ?string
    {
        $mapping = $this->aggregates->resolve(
            (int) $company->id, $this->branchId($event),
            'restaurant_order', $aggregate, 'restaurant_order'
        );
        $mappedDate = (string) (($mapping?->metadata ?? [])['business_date'] ?? '');
        if ($this->date($mappedDate)) return $mappedDate;

        $row = AgentCoreEvent::where('company_id', $company->id)
            ->where('device_uid', $event->device_uid)
            ->whereIn('projection_status', ['accepted', 'projected'])
            ->orderBy('id')->get(['payload', 'event_scope'])->first(function ($row) use ($event, $aggregate) {
                $payload = (array) $row->payload;
                $scope = (array) ($row->event_scope ?? []);
                $current = (array) ($event->event_scope ?? []);
                return (string) ($payload['aggregate_id'] ?? '') === $aggregate
                    && in_array((string) ($payload['command_type'] ?? ''), ['order.open', 'order.opened'], true)
                    && (string) ($scope['branch_id'] ?? '') === (string) ($current['branch_id'] ?? '');
            });
        $date = (string) (((array) ($row?->payload ?? []))['data']['business_date'] ?? '');
        return $this->date($date) ? $date : null;
    }

    private function saleMatchesHeldSnapshot(Company $company, AgentCoreEvent $event, string $aggregate, array $sale): bool
    {
        $mapping = $this->aggregates->resolve(
            (int) $company->id, $this->branchId($event),
            'restaurant_order', $aggregate, 'restaurant_order'
        );
        $held = (array) (($mapping?->metadata ?? [])['order_snapshot'] ?? []);
        // Sequential legacy orders have no single frozen hold snapshot.
        if (!$held) return true;
        if ((string) ($sale['order_id'] ?? '') !== $aggregate
            || (string) ($sale['business_date'] ?? '') !== (string) ($held['business_date'] ?? '')) {
            return false;
        }
        $heldLines = (array) ($held['lines'] ?? []);
        $saleLines = (array) ($sale['items'] ?? []);
        if (count($heldLines) !== count($saleLines)) return false;
        foreach ($heldLines as $index => $line) {
            $candidate = (array) ($saleLines[$index] ?? []);
            foreach (['line_id', 'product_id', 'quantity', 'unit_price_cents'] as $key) {
                if ((string) ($candidate[$key] ?? '') !== (string) ($line[$key] ?? '')) return false;
            }
            foreach (['tax_snapshot', 'recipe_snapshot', 'deal_snapshot', 'direct_consumption_snapshot'] as $key) {
                if (($candidate[$key] ?? []) != ($line[$key] ?? [])) return false;
            }
        }
        return true;
    }

    private function mappedLineId(Company $company, AgentCoreEvent $event, string $aggregate, string $lineId): ?int
    {
        if ($lineId === '') return null;
        $mapping = $this->aggregates->resolve(
            (int) $company->id, $this->branchId($event), 'restaurant_order_line',
            $this->lineAggregate($aggregate, $lineId), 'restaurant_order_item'
        );
        return $mapping ? (int) $mapping->cloud_id : null;
    }

    private function mappedTableId(Company $company, AgentCoreEvent $event, string $aggregate): ?int
    {
        $mapping = $this->aggregates->resolve(
            (int) $company->id, $this->branchId($event),
            'restaurant_table', $aggregate, 'restaurant_table'
        );
        if ($mapping) return (int) $mapping->cloud_id;

        $rows = AgentCoreEvent::where('company_id', $company->id)
            ->where('device_uid', $event->device_uid)
            ->whereIn('projection_status', ['accepted', 'projected'])
            ->orderByDesc('id')->get(['payload', 'projection_result', 'event_scope']);
        foreach ($rows as $row) {
            $payload = (array) $row->payload;
            if ((string) ($payload['aggregate_id'] ?? '') !== $aggregate
                || !in_array((string) ($payload['command_type'] ?? ''), ['table.claim', 'table.assign', 'table.assigned'], true)) continue;
            $scope = (array) ($row->event_scope ?? []);
            $currentScope = (array) ($event->event_scope ?? []);
            if ((string) ($scope['branch_id'] ?? '') !== (string) ($currentScope['branch_id'] ?? '')) continue;
            $id = $this->numericId(((array) $row->projection_result)['table_id'] ?? null);
            if ($id) {
                $this->aggregates->bind(
                    (int) $company->id, $this->branchId($event),
                    'restaurant_table', $aggregate, 'restaurant_table', $id,
                );
                return $id;
            }
        }
        return null;
    }

    private function mappedTableOrderAggregate(Company $company, AgentCoreEvent $event, string $aggregate): ?string
    {
        $rows = AgentCoreEvent::where('company_id', $company->id)
            ->where('device_uid', $event->device_uid)
            ->whereIn('projection_status', ['accepted', 'projected'])
            ->orderByDesc('id')->get(['payload', 'event_scope']);
        foreach ($rows as $row) {
            $payload = (array) $row->payload;
            if ((string) ($payload['aggregate_id'] ?? '') !== $aggregate
                || (string) ($payload['command_type'] ?? '') !== 'table.claim') continue;
            $scope = (array) ($row->event_scope ?? []);
            $currentScope = (array) ($event->event_scope ?? []);
            if ((string) ($scope['branch_id'] ?? '') !== (string) ($currentScope['branch_id'] ?? '')) continue;
            $orderAggregate = (string) ($payload['data']['order_id'] ?? '');
            if ($orderAggregate !== '') return $orderAggregate;
        }
        return null;
    }

    private function missingOrder(AgentCoreEvent $event, string $aggregate): AgentCoreProjectionOutcome
    {
        return $this->retry($event, 'restaurant-order:' . $event->device_uid . ':' . $aggregate, 'The Local Core order mapping is not projected yet.');
    }

    private function scopeUser(Company $company, array $scope): ?int
    {
        $id = $this->numericId($scope['user_id'] ?? null);
        return $id && Schema::hasTable('users') && DB::table('users')->where('company_id', $company->id)->where('id', $id)->exists() ? $id : null;
    }

    private function transactionInScope(PosTransaction $transaction, array $scope): bool
    {
        if (!Schema::hasColumn('pos_transactions', 'branch_id')) return empty($scope['branch_id']);
        return (int) ($transaction->branch_id ?? 0) === (int) ($scope['branch_id'] ?? 0);
    }

    private function nextRevision(Company $company, AgentCoreEvent $event, string $aggregate, array $scope): int
    {
        $prior = AgentCoreEvent::where('company_id', $company->id)->where('device_uid', $event->device_uid)->where('id', '!=', $event->id)
            ->get(['payload', 'event_scope']);
        $seen = [];
        foreach ($prior as $row) {
            $payload = (array) $row->payload;
            if ((string) ($payload['aggregate_id'] ?? '') !== $aggregate) continue;
            $rowScope = (array) ($row->event_scope ?? []);
            if ((string) ($rowScope['branch_id'] ?? '') !== (string) ($scope['branch_id'] ?? '')) continue;
            $seen[(int) ($payload['aggregate_revision'] ?? 0)] = true;
        }
        $contiguous = 0;
        while (isset($seen[$contiguous + 1])) $contiguous++;
        return $contiguous + 1;
    }

    private function numericId(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function branchId(AgentCoreEvent $event): int
    {
        return (int) (((array) ($event->event_scope ?? []))['branch_id'] ?? 0);
    }

    private function lineAggregate(string $orderAggregate, string $lineId): string
    {
        return hash('sha256', $orderAggregate . "\0" . $lineId);
    }

    private function legacyCommand(AgentCoreEvent $event): bool
    {
        return in_array((string) (((array) ($event->payload ?? []))['command_type'] ?? ''), [
            'order.opened', 'order.amended', 'order.claimed', 'order.settled',
            'order.cancelled', 'table.assigned', 'table.shifted', 'table.released',
            'kot.requested', 'kot.printed',
        ], true);
    }

    private function date(string $value): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }

    private function done(AgentCoreEvent $event, array $data): AgentCoreProjectionOutcome
    {
        return new AgentCoreProjectionOutcome('projected', ['event_id' => $event->event_id, 'status' => 'projected'] + $data);
    }

    private function reject(AgentCoreEvent $event, string $code, string $message): AgentCoreProjectionOutcome
    {
        return new AgentCoreProjectionOutcome('rejected', ['event_id' => $event->event_id, 'status' => 'rejected', 'code' => $code], $message);
    }

    private function retry(AgentCoreEvent $event, string $dependency, string $message): AgentCoreProjectionOutcome
    {
        return new AgentCoreProjectionOutcome('retryable', ['event_id' => $event->event_id, 'status' => 'retryable', 'dependency' => $dependency], $message, $dependency);
    }
}

final class RestaurantProjectionConflict extends \RuntimeException
{
}