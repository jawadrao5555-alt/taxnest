<?php

namespace App\Services;

use App\Models\AgentCoreEvent;
use App\Models\Company;

/**
 * The projection protocol is deliberately closed. Adding a schema requires an
 * explicit entry here, so newly uploaded domain data can never be mistaken for
 * a successfully applied operation.
 */
class AgentCoreProjectorRegistry
{
    public const DOMAIN_SCHEMAS = [
        'sale.created' => 'local-core.sale.v1',
        'sale.voided' => 'local-core.sale.v1',
        'order.created' => 'local-core.order.v1',
        'order.held' => 'local-core.order.v1',
        'order.updated' => 'local-core.order.v1',
        'order.settled' => 'local-core.order.v1',
        'order.cancelled' => 'local-core.order.v1',
        'kot.created' => 'local-core.kot.v1',
        'kot.updated' => 'local-core.kot.v1',
        'kot.completed' => 'local-core.kot.v1',
        'stock.adjusted' => 'local-core.stock.v1',
        'stock.transferred' => 'local-core.stock.v1',
        'customer.ledger.posted' => 'local-core.customer-ledger.v1',
        'customer.khata.posted' => 'local-core.customer-ledger.v1',
        'customer.wasooli.posted' => 'local-core.customer-ledger.v1',
        'customer.refund.posted' => 'local-core.customer-ledger.v1',
        'cash.opened' => 'local-core.cash.v1',
        'cash.movement.posted' => 'local-core.cash.v1',
        'expense.created' => 'local-core.expense.v1',
        'day-close.created' => 'local-core.day-close.v1',
        'staff.attendance.recorded' => 'local-core.staff.v1',
        'staff.shift.recorded' => 'local-core.staff.v1',
        'print.requested' => 'local-core.print.v1',
        'print.completed' => 'local-core.print.v1',
    ];

    public function __construct(
        private AgentCoreSaleProjector $sales,
        private AgentCoreDomainProjector $domains,
        private AgentCoreRestaurantProjector $restaurant,
        private AgentCoreCashDayProjector $cashDay,
        private AgentCoreLedgerRefundProjector $ledgerRefund,
    ) {}

    public function project(Company $company, string $deviceUid, AgentCoreEvent $stored, array $event): AgentCoreProjectionOutcome
    {
        $type = (string) $event['event_type'];
        $schema = (string) ($event['payload']['schema'] ?? '');

        if ($type === 'sale.created' && $schema === 'pra.manual-immediate.v1') {
            $result = $this->sales->project($company, $deviceUid, $event);
            return new AgentCoreProjectionOutcome('projected', $result);
        }

        // Before domain projection existed these types were durable generic
        // inbox messages. Keep that wire contract unless the sender explicitly
        // opts into a local-core schema.
        if (in_array($type, [
            'sale.created', 'sale.voided', 'caller.ring', 'print.requested',
            'print.completed', 'sync.acked', 'sync.rejected',
        ], true) && !str_starts_with($schema, 'local-core.')) {
            return $this->legacyAccepted($stored);
        }

        if (isset(self::DOMAIN_SCHEMAS[$type])) {
            $expected = self::DOMAIN_SCHEMAS[$type];
            if ($schema !== $expected) {
                return new AgentCoreProjectionOutcome(
                    'rejected',
                    ['event_id' => $stored->event_id, 'status' => 'rejected'],
                    "Unsupported schema; expected {$expected}.",
                );
            }

            $command = (string) ($event['payload']['command_type'] ?? '');
            if (!in_array($command, $this->commandsFor($type), true)) {
                return new AgentCoreProjectionOutcome('rejected', [
                    'event_id' => $stored->event_id, 'status' => 'rejected',
                ], 'Event type does not match the canonical Local Core command.');
            }

            if (in_array($command, [
                'order.hold', 'order.open', 'order.line.add', 'order.line.consume', 'order.claim',
                'order.cancel', 'order.settle', 'table.claim', 'table.shift', 'table.release',
            ], true)) {
                return $this->restaurant->project($company, $stored, $event, (array) $event['scope']);
            }
            if (in_array($command, ['cash.open', 'cash.expense', 'cash.close', 'staff.start', 'staff.end'], true)) {
                return $this->cashDay->project($company, $stored, $event);
            }
            if (in_array($command, ['customer.upsert', 'khata.debit', 'wasooli.record', 'refund.record'], true)) {
                return $this->ledgerRefund->project($company, $stored, $event);
            }
            return $this->domains->project($company, $stored, $event);
        }

        if ($schema !== '' && str_starts_with($schema, 'local-core.')) {
            return new AgentCoreProjectionOutcome(
                'rejected',
                ['event_id' => $stored->event_id, 'status' => 'rejected'],
                'Unsupported event type/schema combination.',
            );
        }

        return $this->legacyAccepted($stored);
    }

    /** Exact command-to-event mapping exported by pra-agent LocalCoreDomain. */
    private function commandsFor(string $type): array
    {
        return match ($type) {
            'order.held' => ['order.hold'],
            // Sequential order.open/line events remain ingestible for old Core clients.
            'order.created' => ['order.open'],
            'order.updated' => ['order.line.add', 'order.line.consume', 'order.claim', 'table.claim', 'table.shift', 'table.release'],
            'order.settled' => ['order.settle'],
            'order.cancelled' => ['order.cancel'],
            'stock.adjusted' => ['stock.set', 'stock.adjust'],
            'customer.ledger.posted' => ['customer.upsert'],
            'customer.khata.posted' => ['khata.debit'],
            'customer.wasooli.posted' => ['wasooli.record'],
            'customer.refund.posted' => ['refund.record'],
            'cash.opened' => ['cash.open'],
            'expense.created' => ['cash.expense'],
            'day-close.created' => ['cash.close'],
            'staff.shift.recorded' => ['staff.start', 'staff.end'],
            'print.requested' => ['print.enqueue', 'print.claim', 'print.fail'],
            'print.completed' => ['print.complete'],
            default => [],
        };
    }

    private function legacyAccepted(AgentCoreEvent $event): AgentCoreProjectionOutcome
    {
        return new AgentCoreProjectionOutcome('accepted', [
            'event_id' => $event->event_id,
            'status' => 'accepted',
            'legacy_inbox' => true,
        ]);
    }
}