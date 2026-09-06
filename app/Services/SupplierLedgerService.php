<?php

namespace App\Services;

use App\Models\PharmacyClaim;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\SupplierPayment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Distributor (supplier) ledger for the FBR POS stock module — Task 1580.
 *
 * ONE source of truth for "what does this shop owe each distributor":
 *
 *   balance = billed (non-void purchases, NET of discounts)
 *           − paid   (active payments)
 *           − returned (purchase-return credit notes)
 *           − claim credits (pharmacy claims the distributor credited)
 *
 * The supplier list, the stock-page header total, the statement page, the
 * PDF, the WhatsApp text and every test read THIS class — never their own
 * SUM(). A voided purchase drops out of `billed` while its payments stay in
 * `paid`, which is exactly the "payment becomes an unallocated credit"
 * behaviour the shop expects: nothing silently drifts.
 *
 * Also owns the purchase COSTING rule (computeLines): net line cost after the
 * line discount and its share of the invoice discount, spread over paid +
 * bonus units. Bonus units therefore enter stock at their real (diluted)
 * cost, not at zero and not at full rate.
 */
class SupplierLedgerService
{
    /** Quantity precision (matches the inventory ledger). */
    private const Q = 3;

    private static ?bool $ready = null;

    /**
     * True once the ledger tables/columns exist. The stock page keeps working
     * (without the money features) on a host whose migration has not run yet.
     */
    public static function schemaReady(): bool
    {
        if (self::$ready !== null) {
            return self::$ready;
        }
        try {
            self::$ready = Schema::hasTable('supplier_payments')
                && Schema::hasTable('purchase_returns')
                && Schema::hasTable('purchase_return_items')
                && Schema::hasColumn('purchase_order_items', 'net_unit_cost')
                && Schema::hasColumn('purchase_orders', 'supplier_invoice_no');
        } catch (\Throwable $e) {
            self::$ready = false;
        }

        return self::$ready;
    }

    /** Tests swap schemas between cases. */
    public static function flushSchemaCache(): void
    {
        self::$ready = null;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Costing
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Work out every line's net cost for a purchase entry.
     *
     * @param array $items  rows of ['product_id','quantity','unit_price',
     *                      'bonus_qty'?, 'discount_pct'?] (+ anything else,
     *                      passed through untouched under 'raw')
     * @param float $invoiceDiscount flat rupee discount on the whole bill
     *
     * Returns ['lines' => [...], 'gross','line_discount','invoice_discount','total'].
     * Each line: product_id, quantity (paid units), bonus_qty, received
     * (paid+bonus), unit_price, discount_pct, gross, discount_amount (line),
     * invoice_share, net_total, net_unit_cost, raw.
     *
     * The invoice discount is apportioned pro-rata by line net so a
     * 5% flat discount really is 5% off every unit's cost. Rounding
     * remainders land on the last line so Σ net_total == total exactly.
     */
    public static function computeLines(array $items, float $invoiceDiscount = 0): array
    {
        $lines = [];
        $gross = 0.0;
        $lineDiscount = 0.0;
        $netBeforeInvoice = 0.0;

        foreach ($items as $row) {
            $qty = round((float) ($row['quantity'] ?? 0), self::Q);
            $bonus = round(max(0, (float) ($row['bonus_qty'] ?? 0)), self::Q);
            $price = (float) ($row['unit_price'] ?? 0);
            $pct = min(100, max(0, (float) ($row['discount_pct'] ?? 0)));

            $lineGross = round($qty * $price, 2);
            $lineDisc = round($lineGross * $pct / 100, 2);
            $lineNet = round($lineGross - $lineDisc, 2);

            $lines[] = [
                'product_id' => (int) ($row['product_id'] ?? 0),
                'quantity' => $qty,
                'bonus_qty' => $bonus,
                'received' => round($qty + $bonus, self::Q),
                'unit_price' => $price,
                'discount_pct' => $pct,
                'gross' => $lineGross,
                'discount_amount' => $lineDisc,
                'invoice_share' => 0.0,
                'net_total' => $lineNet,
                'net_unit_cost' => 0.0,
                'raw' => $row,
            ];
            $gross += $lineGross;
            $lineDiscount += $lineDisc;
            $netBeforeInvoice += $lineNet;
        }

        // Invoice discount can never exceed what is left to discount.
        $invoiceDiscount = round(min(max(0, $invoiceDiscount), $netBeforeInvoice), 2);

        $allocated = 0.0;
        $last = count($lines) - 1;
        foreach ($lines as $i => &$line) {
            if ($invoiceDiscount > 0 && $netBeforeInvoice > 0) {
                $share = $i === $last
                    ? round($invoiceDiscount - $allocated, 2)
                    : round($invoiceDiscount * $line['net_total'] / $netBeforeInvoice, 2);
                $allocated += $share;
                $line['invoice_share'] = $share;
                $line['net_total'] = round($line['net_total'] - $share, 2);
            }
            $line['net_unit_cost'] = $line['received'] > 0
                ? round($line['net_total'] / $line['received'], 4)
                : 0.0;
        }
        unset($line);

        return [
            'lines' => $lines,
            'gross' => round($gross, 2),
            'line_discount' => round($lineDiscount, 2),
            'invoice_discount' => $invoiceDiscount,
            'total' => round($netBeforeInvoice - $invoiceDiscount, 2),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Balances
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Per-supplier money picture for a company (optionally one branch).
     *
     * @return Collection<int, object{supplier_id:int, billed:float, paid:float,
     *         returned:float, credited:float, balance:float}> keyed by supplier_id
     */
    public static function balances(int $companyId, ?int $branchId = null): Collection
    {
        if (!self::schemaReady()) {
            return collect();
        }

        $branch = function ($q, string $table) use ($branchId) {
            if ($branchId !== null) {
                $q->where($table . '.branch_id', $branchId);
            }
        };

        $billedQ = DB::table('purchase_orders')
            ->where('company_id', $companyId)
            ->where('status', '!=', PurchaseOrder::STATUS_CANCELLED)
            ->whereNotNull('supplier_id');
        $branch($billedQ, 'purchase_orders');
        $billed = $billedQ->groupBy('supplier_id')
            ->selectRaw('supplier_id, COALESCE(SUM(total_amount), 0) as v')
            ->pluck('v', 'supplier_id');

        $paidQ = DB::table('supplier_payments')
            ->where('company_id', $companyId)
            ->where('status', SupplierPayment::STATUS_ACTIVE);
        $branch($paidQ, 'supplier_payments');
        $paid = $paidQ->groupBy('supplier_id')
            ->selectRaw('supplier_id, COALESCE(SUM(amount), 0) as v')
            ->pluck('v', 'supplier_id');

        $retQ = DB::table('purchase_returns')
            ->where('company_id', $companyId)
            ->where('status', PurchaseReturn::STATUS_POSTED)
            ->whereNotNull('supplier_id');
        $branch($retQ, 'purchase_returns');
        $returned = $retQ->groupBy('supplier_id')
            ->selectRaw('supplier_id, COALESCE(SUM(credit_amount), 0) as v')
            ->pluck('v', 'supplier_id');

        $credited = collect();
        if (Schema::hasTable('pharmacy_claims') && Schema::hasColumn('pharmacy_claims', 'ledger_credited_at')) {
            $clQ = DB::table('pharmacy_claims')
                ->where('company_id', $companyId)
                ->whereNotNull('ledger_credited_at')
                ->whereNotNull('supplier_id');
            $branch($clQ, 'pharmacy_claims');
            $credited = $clQ->groupBy('supplier_id')
                ->selectRaw('supplier_id, COALESCE(SUM(COALESCE(settled_amount, total_amount)), 0) as v')
                ->pluck('v', 'supplier_id');
        }

        $ids = collect($billed->keys())
            ->merge($paid->keys())->merge($returned->keys())->merge($credited->keys())
            ->map(fn ($v) => (int) $v)->unique()->values();

        return $ids->mapWithKeys(function (int $id) use ($billed, $paid, $returned, $credited) {
            $b = round((float) ($billed[$id] ?? 0), 2);
            $p = round((float) ($paid[$id] ?? 0), 2);
            $r = round((float) ($returned[$id] ?? 0), 2);
            $c = round((float) ($credited[$id] ?? 0), 2);

            return [$id => (object) [
                'supplier_id' => $id,
                'billed' => $b,
                'paid' => $p,
                'returned' => $r,
                'credited' => $c,
                'balance' => round($b - $p - $r - $c, 2),
            ]];
        });
    }

    /** One supplier's picture (zeros when nothing was ever recorded). */
    public static function balanceFor(int $companyId, int $supplierId, ?int $branchId = null): object
    {
        return self::balances($companyId, $branchId)->get($supplierId) ?? (object) [
            'supplier_id' => $supplierId,
            'billed' => 0.0, 'paid' => 0.0, 'returned' => 0.0, 'credited' => 0.0, 'balance' => 0.0,
        ];
    }

    /**
     * Header figures: total still owed (sum of POSITIVE balances), advances
     * sitting with distributors (sum of negative ones, as a positive number)
     * and how many suppliers the shop currently owes.
     */
    public static function totals(int $companyId, ?int $branchId = null): array
    {
        $payable = 0.0;
        $advance = 0.0;
        $due = 0;
        foreach (self::balances($companyId, $branchId) as $b) {
            if ($b->balance > 0.005) {
                $payable += $b->balance;
                $due++;
            } elseif ($b->balance < -0.005) {
                $advance += -$b->balance;
            }
        }

        return [
            'payable' => round($payable, 2),
            'advance' => round($advance, 2),
            'suppliers_due' => $due,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Statement
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Running-balance statement for one supplier.
     *
     * Every row: ['date' => 'Y-m-d', 'kind' => purchase|payment|return|claim,
     * 'ref', 'detail', 'debit', 'credit', 'balance', 'void' => bool, 'id'].
     * Voided purchases/payments are listed with ZERO money and void=true so
     * the shop can still see them, but they never move the balance.
     *
     * Returns ['opening', 'rows', 'closing', 'period' => [billed, paid,
     * returned, credited], 'from', 'to'].
     */
    public static function statement(int $companyId, int $supplierId, ?int $branchId = null, ?string $from = null, ?string $to = null): array
    {
        $empty = [
            'opening' => 0.0, 'rows' => [], 'closing' => 0.0,
            'period' => ['billed' => 0.0, 'paid' => 0.0, 'returned' => 0.0, 'credited' => 0.0],
            'from' => $from, 'to' => $to,
        ];
        if (!self::schemaReady()) {
            return $empty;
        }

        $branch = function ($q) use ($branchId) {
            if ($branchId !== null) {
                $q->where('branch_id', $branchId);
            }
        };

        $events = [];

        $poQ = PurchaseOrder::where('company_id', $companyId)->where('supplier_id', $supplierId);
        $branch($poQ);
        foreach ($poQ->orderBy('id')->get() as $po) {
            $void = $po->status === PurchaseOrder::STATUS_CANCELLED;
            $events[] = [
                'id' => (int) $po->id,
                'date' => ($po->received_date ?? $po->created_at)?->toDateString() ?? now()->toDateString(),
                'ts' => $po->created_at?->timestamp ?? 0,
                'kind' => 'purchase',
                'ref' => (string) $po->po_number,
                'detail' => $po->supplier_invoice_no ? ('#' . $po->supplier_invoice_no) : '',
                'debit' => $void ? 0.0 : round((float) $po->total_amount, 2),
                'credit' => 0.0,
                'void' => $void,
            ];
        }

        $payQ = SupplierPayment::where('company_id', $companyId)->where('supplier_id', $supplierId);
        $branch($payQ);
        foreach ($payQ->orderBy('id')->get() as $p) {
            $void = $p->isVoid();
            $events[] = [
                'id' => (int) $p->id,
                'date' => ($p->paid_on ?? $p->created_at)?->toDateString() ?? now()->toDateString(),
                'ts' => $p->created_at?->timestamp ?? 0,
                'kind' => 'payment',
                'ref' => (string) $p->method,
                'detail' => trim(($p->reference ? $p->reference . ' ' : '') . ($p->purchase_order_id ? '→ PO#' . $p->purchase_order_id : '')),
                'debit' => 0.0,
                'credit' => $void ? 0.0 : round((float) $p->amount, 2),
                'void' => $void,
                'purchase_order_id' => $p->purchase_order_id ? (int) $p->purchase_order_id : null,
            ];
        }

        $retQ = PurchaseReturn::where('company_id', $companyId)->where('supplier_id', $supplierId)
            ->where('status', PurchaseReturn::STATUS_POSTED);
        $branch($retQ);
        foreach ($retQ->orderBy('id')->get() as $r) {
            $events[] = [
                'id' => (int) $r->id,
                'date' => ($r->returned_on ?? $r->created_at)?->toDateString() ?? now()->toDateString(),
                'ts' => $r->created_at?->timestamp ?? 0,
                'kind' => 'return',
                'ref' => (string) $r->return_number,
                'detail' => (string) ($r->reason ?? ''),
                'debit' => 0.0,
                'credit' => round((float) $r->credit_amount, 2),
                'void' => false,
            ];
        }

        if (Schema::hasTable('pharmacy_claims') && Schema::hasColumn('pharmacy_claims', 'ledger_credited_at')) {
            $clQ = PharmacyClaim::where('company_id', $companyId)->where('supplier_id', $supplierId)
                ->whereNotNull('ledger_credited_at');
            $branch($clQ);
            foreach ($clQ->orderBy('id')->get() as $c) {
                $events[] = [
                    'id' => (int) $c->id,
                    'date' => $c->ledger_credited_at?->toDateString() ?? now()->toDateString(),
                    'ts' => $c->ledger_credited_at?->timestamp ?? 0,
                    'kind' => 'claim',
                    'ref' => (string) $c->claim_number,
                    'detail' => (string) ($c->settlement_reference ?? ''),
                    'debit' => 0.0,
                    'credit' => self::claimCreditAmount($c),
                    'void' => false,
                ];
            }
        }

        // Same day, same second (paid-now rides the purchase's own request):
        // the bill must be listed before the money against it.
        $rank = ['purchase' => 0, 'return' => 1, 'claim' => 1, 'payment' => 2];
        usort($events, fn ($a, $b) => [$a['date'], $a['ts'], $rank[$a['kind']] ?? 9, $a['id']]
            <=> [$b['date'], $b['ts'], $rank[$b['kind']] ?? 9, $b['id']]);

        $opening = 0.0;
        $rows = [];
        $period = ['billed' => 0.0, 'paid' => 0.0, 'returned' => 0.0, 'credited' => 0.0];
        foreach ($events as $e) {
            $delta = $e['debit'] - $e['credit'];
            if ($from && $e['date'] < $from) {
                $opening += $delta;
                continue;
            }
            if ($to && $e['date'] > $to) {
                continue;
            }
            $rows[] = $e;
            if (!$e['void']) {
                match ($e['kind']) {
                    'purchase' => $period['billed'] += $e['debit'],
                    'payment' => $period['paid'] += $e['credit'],
                    'return' => $period['returned'] += $e['credit'],
                    'claim' => $period['credited'] += $e['credit'],
                    default => null,
                };
            }
        }
        $opening = round($opening, 2);
        // Running balance starts at the opening figure; a statement with no
        // rows in the window closes at its opening.
        $running = $opening;
        foreach ($rows as &$r) {
            $running = round($running + $r['debit'] - $r['credit'], 2);
            $r['balance'] = $running;
        }
        unset($r);
        $closing = $running;

        foreach ($period as $k => $v) {
            $period[$k] = round($v, 2);
        }

        return [
            'opening' => $opening,
            'rows' => $rows,
            'closing' => round($closing, 2),
            'period' => $period,
            'from' => $from,
            'to' => $to,
        ];
    }

    /** Amount a credited claim posts to the ledger: the agreed figure, else the claim total. */
    public static function claimCreditAmount(PharmacyClaim $claim): float
    {
        $v = $claim->settled_amount;

        return round((float) ($v === null ? $claim->total_amount : $v), 2);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Payments
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Record money handed to a distributor. Caller has already validated
     * amount/method/supplier ownership; this only writes + audit-logs.
     */
    public static function recordPayment(array $data, int $userId): SupplierPayment
    {
        $payment = SupplierPayment::create([
            'company_id' => (int) $data['company_id'],
            'branch_id' => $data['branch_id'] ?? null,
            'supplier_id' => (int) $data['supplier_id'],
            'purchase_order_id' => $data['purchase_order_id'] ?? null,
            'amount' => round((float) $data['amount'], 2),
            'method' => in_array($data['method'] ?? '', SupplierPayment::METHODS, true) ? $data['method'] : 'cash',
            'paid_on' => $data['paid_on'] ?? now()->toDateString(),
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => SupplierPayment::STATUS_ACTIVE,
            'created_by' => $userId,
        ]);

        self::audit('supplier_payment_created', 'supplier_payment', $payment->id, null, [
            'supplier_id' => $payment->supplier_id,
            'purchase_order_id' => $payment->purchase_order_id,
            'amount' => $payment->amount,
            'method' => $payment->method,
            'paid_on' => $payment->paid_on?->toDateString(),
            'reference' => $payment->reference,
        ], $payment->company_id, $userId);

        return $payment;
    }

    /**
     * Void a payment (the ONLY way to "edit" one). Idempotent: voiding a
     * voided payment does nothing and returns false.
     */
    public static function voidPayment(SupplierPayment $payment, int $userId, ?string $reason = null): bool
    {
        return DB::transaction(function () use ($payment, $userId, $reason) {
            $locked = SupplierPayment::lockForUpdate()->find($payment->id);
            if (!$locked || $locked->isVoid()) {
                return false;
            }
            $before = ['status' => $locked->status, 'amount' => $locked->amount];
            $locked->update([
                'status' => SupplierPayment::STATUS_VOID,
                'voided_at' => now(),
                'voided_by' => $userId,
                'void_reason' => $reason ? mb_substr($reason, 0, 200) : null,
            ]);
            self::audit('supplier_payment_voided', 'supplier_payment', $locked->id, $before, [
                'status' => SupplierPayment::STATUS_VOID,
                'reason' => $reason,
                'supplier_id' => $locked->supplier_id,
                'amount' => $locked->amount,
            ], $locked->company_id, $userId);

            return true;
        });
    }

    /** Audit trail — never lets a logging failure break the money write. */
    public static function audit(string $action, string $entity, $entityId, $old, $new, int $companyId, int $userId): void
    {
        try {
            if (!Schema::hasTable('audit_logs')) {
                return;
            }
            AuditLogService::log($action, $entity, $entityId, $old, $new, $companyId, $userId);
        } catch (\Throwable $e) {
            \Log::warning("Supplier ledger audit failed [{$action} #{$entityId}]: " . $e->getMessage());
        }
    }
}
