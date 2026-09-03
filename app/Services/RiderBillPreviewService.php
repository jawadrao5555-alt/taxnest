<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PosRider;
use App\Models\PosTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Security boundary for a rider's bill view.  Keep policy parsing, assignment
 * checks and DTO construction here; no controller/view is allowed to serialize
 * a transaction model for this feature.
 */
class RiderBillPreviewService
{
    private const VERSION = 1;
    private const FLAGS = [
        'enabled', 'quantity', 'prices', 'tax', 'ntn', 'qr',
        'customer_name', 'customer_phone', 'customer_address', 'customer_code',
        'business',
    ];
    private const CUSTOMER_FLAGS = ['customer_name', 'customer_phone', 'customer_address', 'customer_code'];

    public function prefs(Company $company): array
    {
        $default = array_fill_keys(self::FLAGS, false);
        $default['enabled'] = true;
        $raw = $company->rider_bill_preview_prefs;
        if (!is_array($raw) || ($raw['v'] ?? null) !== self::VERSION) {
            // Missing policy is the intentional minimal default. Corrupt/unknown
            // policy fails closed, rather than accidentally revealing a new field.
            return $raw === null ? $default : array_merge($default, ['enabled' => false]);
        }
        $out = ['v' => self::VERSION];
        foreach (self::FLAGS as $flag) {
            if (array_key_exists($flag, $raw)) {
                $out[$flag] = filter_var($raw[$flag], FILTER_VALIDATE_BOOLEAN);
            } elseif (in_array($flag, self::CUSTOMER_FLAGS, true) && array_key_exists('customer', $raw)) {
                // Version-one policies originally had one broad customer switch.
                // Preserve that owner's choice while all newly-saved policies use
                // the four independent, least-privilege switches.
                $out[$flag] = filter_var($raw['customer'], FILTER_VALIDATE_BOOLEAN);
            } else {
                $out[$flag] = false;
            }
        }
        return $out;
    }

    public function save(Company $company, array $input): void
    {
        $policy = ['v' => self::VERSION];
        foreach (self::FLAGS as $flag) {
            $policy[$flag] = !empty($input[$flag]);
        }
        $company->update(['rider_bill_preview_prefs' => $policy]);
    }

    /** Finds only a current, open assignment for this exact authenticated rider. */
    public function assigned(PosRider $rider, int $id, ?string $revision = null): ?Model
    {
        $class = PosTransaction::class;
        $table = 'pos_transactions';
        if (!Schema::hasTable($table)) return null;

        $query = $class::query()->where('company_id', $rider->company_id)
            ->where('rider_id', $rider->id)
            ->whereIn('delivery_status', ['assigned', 'dispatched']);
        if (Schema::hasColumn($table, 'rider_settlement_id')) $query->whereNull('rider_settlement_id');
        // Do not eager load line items before the caller has established that
        // this is a current assignment.  In particular, a disabled policy never
        // needs to fetch them at all.
        $bill = $query->find($id);
        if (!$bill) return null;
        // A revision is mandatory even for pre-column assignments. The keyed
        // legacy value is opaque and binds bill+rider+assignment instant.
        if (!$revision || !hash_equals($this->assignmentRevision($bill, $rider), $revision)) return null;
        return $bill;
    }

    public function assignmentRevision(Model $bill, PosRider $rider): string
    {
        if (filled($bill->rider_assignment_revision ?? null)) {
            return (string) $bill->rider_assignment_revision;
        }
        $stamp = $bill->rider_assigned_at ?? $bill->created_at;
        try {
            $at = $stamp ? \Illuminate\Support\Carbon::parse($stamp)->getTimestamp() : 0;
        } catch (\Throwable $e) {
            $at = 0;
        }
        return hash_hmac('sha256', implode('|', [(int) $bill->id, (int) $rider->id, $at]), (string) config('app.key'));
    }

    /** Strict allowlisted response; this must never use ->toArray() on a model. */
    public function dto(Company $company, Model $bill): array
    {
        $p = $this->prefs($company);
        if (!$p['enabled']) return ['available' => false];

        $items = [];
        foreach ($bill->items as $item) {
            $line = ['name' => (string) $item->item_name];
            if ($p['quantity']) $line['quantity'] = (float) $item->quantity;
            if ($p['prices']) {
                $line['unit_rate'] = (float) $item->unit_price;
                $line['line_total'] = (float) $item->subtotal;
            }
            $items[] = $line;
        }
        $out = [
            'available' => true,
            'items' => $items,
            'grand_total' => (float) $bill->total_amount,
        ];
        if ($p['tax']) $out['tax'] = ['rate' => (float) $bill->tax_rate, 'amount' => (float) $bill->tax_amount];
        if ($p['ntn'] && filled($company->ntn)) $out['ntn'] = (string) $company->ntn;
        $customer = array_filter([
            'name' => $p['customer_name'] && filled($bill->customer_name) ? (string) $bill->customer_name : null,
            'phone' => $p['customer_phone'] && filled($bill->customer_phone) ? (string) $bill->customer_phone : null,
            'address' => $p['customer_address'] && filled($bill->delivery_address) ? (string) $bill->delivery_address : null,
            'code' => $p['customer_code'] ? $this->customerCode($bill) : null,
        ], fn ($v) => $v !== null);
        if ($customer !== []) {
            $out['customer'] = $customer;
        }
        if ($p['business']) $out['business'] = ['name' => (string) $company->name];
        if ($p['qr']) {
            // Project invariant: the customer QR payload is the bare filed
            // regulator invoice number. Never QR a local/unfiled bill and never
            // place a raw transaction JSON payload in a rider response.
            $fiscal = trim((string) $bill->pra_invoice_number);
            // A number is not filing proof: failed/pending/local rows can carry
            // stale data. Only the submitted state is a customer-verifiable PRA
            // invoice under the application's fiscal invariant.
            $filed = ($bill->pra_status ?? null) === 'submitted' && $fiscal !== '';
            $out['qr'] = ['available' => $filed];
            if ($filed) $out['qr']['payload'] = $fiscal;
        }
        return $out;
    }

    /**
     * Account codes are optional and schema-dependent. Only explicitly named
     * code columns are considered; the internal customer id is never disclosed.
     * The linked customer is constrained to the bill's company.
     */
    private function customerCode(Model $bill): ?string
    {
        foreach (['customer_code', 'account_code'] as $column) {
            if (Schema::hasColumn($bill->getTable(), $column) && filled($bill->getAttribute($column))) {
                return (string) $bill->getAttribute($column);
            }
        }

        if (!filled($bill->customer_id) || !Schema::hasTable('pos_customers')) return null;
        foreach (['customer_code', 'account_code', 'code'] as $column) {
            if (!Schema::hasColumn('pos_customers', $column)) continue;
            $value = \App\Models\PosCustomer::query()
                ->whereKey((int) $bill->customer_id)
                ->where('company_id', (int) $bill->company_id)
                ->value($column);
            if (filled($value)) return (string) $value;
        }
        return null;
    }
}