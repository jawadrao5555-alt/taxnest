<?php

namespace App\Services;

use App\Models\{Company, User, PosTaxRule};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Approval is a pre-sale record. Only storeInvoice's existing transaction may consume it. */
final class PosStaffDiscount
{
    public static function reviewer(?User $user, int $companyId): bool
    {
        return $user && (int) $user->company_id === $companyId && $user->is_active
            && ($user->pos_role === 'pos_admin' || $user->role === 'company_admin');
    }

    public static function requested(Request $request): bool
    {
        return $request->has('staff_discount') || $request->filled('staff_discount_request_id');
    }

    public static function assertBoundUuid(Request $request, int $companyId): void
    {
        if ($request->filled('staff_discount_request_id')) {
            $bound=DB::table('pos_staff_discount_requests')->where('company_id',$companyId)->where('id',$request->integer('staff_discount_request_id'))->first();
            abort_unless($bound && (int)$bound->requester_id === (int)auth('pos')->id(),404);
            if ($request->input('offline_uuid') !== $bound->request_uuid) self::fail('Use the original approval request key for payment.');
        }
        if ($request->has('staff_discount') && $request->input('offline_uuid') !== $request->input('staff_discount.request_uuid')) self::fail('Proposal and payment request keys must match.');
        if (!$request->filled('offline_uuid') || !\Schema::hasTable('pos_staff_discount_requests')) return;
        $row=DB::table('pos_staff_discount_requests')->where('company_id',$companyId)->where('request_uuid',$request->input('offline_uuid'))->first();
        if (!$row) return;
        abort_unless((int)$row->requester_id === (int)auth('pos')->id(),403);
        $same=$request->integer('staff_discount_request_id') === (int)$row->id
            || $request->input('staff_discount.request_uuid') === $row->request_uuid;
        if (!$same) self::fail('This sale key belongs to a staff discount request. Resume or cancel the request.');
    }

    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['staff_discount' => $message]);
    }

    public static function value(string $type, mixed $value, float $eligible): float
    {
        if (!in_array($type, ['percentage', 'amount'], true) || !is_numeric($value) || !is_finite((float) $value)) self::fail('Enter a valid discount.');
        $v = round((float) $value, 2);
        if (($type === 'percentage' && ($v < 1 || $v > 99)) || ($type === 'amount' && ($v <= 0 || $v > $eligible))) self::fail('Discount is outside the eligible amount or 1–99 percent range.');
        return $v;
    }

    public static function fingerprint(Request $request, Company $company, array $items): string
    {
        // Inputs affecting money, routing, identity and held-order ownership are
        // bound to approval. Cash tender and print choice may change afterwards.
        $input = $request->only(['items', 'payment_method', 'order_type', 'table_id', 'customer_name', 'customer_phone',
            'delivery_address', 'delivery_collection_mode', 'kitchen_notes', 'incoming_order_id', 'recalled_order_id',
            'expected_incoming_revision', 'terminal_id', 'fbr_service_charge']);
        $orderId=$request->integer('incoming_order_id') ?: $request->integer('recalled_order_id');
        $orderState=null;
        if ($orderId) {
            $orderQuery=DB::table('restaurant_orders')->where('company_id',$company->id)->where('id',$orderId);
            if (DB::transactionLevel()>0) $orderQuery->lockForUpdate();
            $order=$orderQuery->first();
            abort_unless($order,404);
            $orderState=[$order->updated_at, $order->status,
                DB::table('restaurant_order_items')->where('order_id',$orderId)->orderBy('id')->get()->toArray()];
        }
        return hash('sha256', json_encode([$input, $items, $company->posTaxPricingMode(),
            PosTaxRule::getRateForMethod($request->payment_method, $company),
            (bool) auth('pos')->user()?->praReportingEnabled($company),
            app(BranchContextService::class)->stampBranchId(), $orderState], JSON_THROW_ON_ERROR));
    }

    public function prepare(Request $request, Company $company, array $items, float $eligible): ?array
    {
        if (!self::requested($request)) return null;
        if (!$company->pos_staff_discount_enabled) self::fail('Staff/family discount is not enabled by the owner.');
        if ($request->boolean('save_as_provisional') || $request->filled('draft_id') || $request->filled('offline_queued_at') || $request->attributes->get('agent_core_inventory_sale')) self::fail('Staff bills need online approval before final payment.');
        $hash = self::fingerprint($request, $company, $items);
        $user = auth('pos')->user();
        abort_unless($user && (int) $user->company_id === (int) $company->id, 403);
        if ($request->filled('staff_discount_request_id')) {
            $row = DB::table('pos_staff_discount_requests')->where('company_id', $company->id)->where('id', $request->integer('staff_discount_request_id'))->first();
            abort_unless($row && (int) $row->requester_id === (int) $user->id, 404);
            if ($request->input('offline_uuid') !== $row->request_uuid) self::fail('Use the original approval request key for payment.');
            if ($row->status !== 'approved' || !hash_equals($row->cart_hash, $hash)
                || (int) $row->revision !== $request->integer('staff_discount_revision')) self::fail('Approval is pending, changed, or belongs to a different cart. Request approval again.');
            if (!User::where('company_id', $company->id)->whereKey($row->employee_id)->where('is_active', true)->exists()) self::fail('The selected employee is no longer active.');
            self::value($row->approved_type, $row->approved_value, $eligible);
            $request->merge(['discount_type' => $row->approved_type, 'discount_value' => $row->approved_value, 'offline_uuid' => $row->request_uuid]);
            return ['row' => $row, 'hash' => $hash, 'request'=>$request, 'company'=>$company, 'items'=>$items];
        }
        $data = $request->validate([
            'staff_discount' => 'required|array:employee_id,kind,reason,request_uuid',
            'staff_discount.employee_id' => 'required|integer', 'staff_discount.kind' => 'required|in:staff,family',
            'staff_discount.reason' => 'required|string|min:3|max:500', 'staff_discount.request_uuid' => 'required|uuid',
        ])['staff_discount'];
        $data['reason']=trim($data['reason']);
        if (mb_strlen($data['reason'])<3) self::fail('Add a reason for the staff/family discount.');
        abort_unless(User::where('company_id', $company->id)->whereKey($data['employee_id'])->where('is_active', true)->exists(), 422, 'Choose an active employee of this company.');
        $value = self::value((string) $request->discount_type, $request->discount_value, $eligible);
        $row = DB::transaction(function () use ($request, $company, $items, $eligible, $hash, $user, $data, $value) {
            Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $old = DB::table('pos_staff_discount_requests')->where('company_id', $company->id)->where('request_uuid', $data['request_uuid'])->first();
            if ($old) {
                abort_unless((int) $old->requester_id === (int) $user->id && hash_equals($old->cart_hash, $hash)
                    && (int) $old->employee_id === (int) $data['employee_id'] && $old->kind === $data['kind']
                    && $old->reason === $data['reason'] && $old->proposed_type === $request->discount_type
                    && (float) $old->proposed_value === $value, 409, 'This request key already belongs to another proposal.');
                return $old;
            }
            $orderId = $request->integer('incoming_order_id') ?: $request->integer('recalled_order_id');
            if ($orderId) {
                $order = DB::table('restaurant_orders')->where('company_id', $company->id)->where('id', $orderId)->lockForUpdate()->first();
                abort_unless($order && in_array($order->status, ['held', 'preparing', 'ready'], true), 409, 'Order is no longer available.');
                self::assertOrderAvailable((int) $company->id, $orderId);
            }
            $id = DB::table('pos_staff_discount_requests')->insertGetId([
                'company_id' => $company->id, 'branch_id' => app(BranchContextService::class)->stampBranchId(),
                'request_uuid' => $data['request_uuid'], 'requester_id' => $user->id, 'employee_id' => $data['employee_id'],
                'kind' => $data['kind'], 'reason' => $data['reason'], 'cart_hash' => $hash,
                'order_snapshot' => json_encode(['items' => $items, 'checkout' => $request->only(['items', 'payment_method', 'order_type',
                    'table_id', 'customer_name', 'customer_phone', 'delivery_address', 'delivery_collection_mode', 'kitchen_notes',
                    'incoming_order_id', 'recalled_order_id', 'expected_incoming_revision', 'terminal_id', 'fbr_service_charge', 'discount_type', 'discount_value'])], JSON_THROW_ON_ERROR),
                'eligible_subtotal' => $eligible, 'proposed_type' => $request->discount_type, 'proposed_value' => $value,
                'order_id' => $orderId ?: null, 'status' => 'pending', 'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = DB::table('pos_staff_discount_requests')->find($id);
            self::event($row, 'proposed', $user->id);
            return $row;
        });
        return ['proposal' => $row];
    }

    public static function assertOrderAvailable(int $companyId, int $orderId, ?int $requestId = null): void
    {
        if (!$orderId || !\Schema::hasTable('pos_staff_discount_requests')) return;
        $q = DB::table('pos_staff_discount_requests')->where('company_id', $companyId)->where('order_id', $orderId)->whereIn('status', ['pending', 'approved']);
        if ($requestId) $q->where('id', '!=', $requestId);
        if ($q->exists()) self::fail('This order has a staff discount request. Complete or cancel that request first.');
    }

    public static function claim(?array $approval, int $companyId): void
    {
        if (!$approval) return;
        if (!Company::whereKey($companyId)->value('pos_staff_discount_enabled')) self::fail('The owner disabled staff discount approval.');
        if (!User::where('company_id',$companyId)->whereKey($approval['row']->employee_id)->where('is_active',true)->lockForUpdate()->first()) self::fail('The selected employee is no longer active.');
        if (!hash_equals($approval['hash'],self::fingerprint($approval['request'],$approval['company'],$approval['items']))) self::fail('The cart or held order changed during payment. Request approval again.');
        $row = DB::table('pos_staff_discount_requests')->where('company_id', $companyId)->where('id', $approval['row']->id)->lockForUpdate()->first();
        if (!$row || $row->status !== 'approved' || $row->revision !== $approval['row']->revision || !hash_equals($row->cart_hash, $approval['hash'])) self::fail('This approval was already used or changed. Refresh the request.');
    }

    public static function consumed(?array $approval, int $transactionId): void
    {
        if (!$approval) return;
        $row = $approval['row'];
        DB::table('pos_staff_discount_requests')->where('id', $row->id)->where('company_id', $row->company_id)->update(['status' => 'finalized', 'transaction_id' => $transactionId, 'updated_at' => now()]);
        $row->transaction_id = $transactionId; self::event($row, 'finalized', (int) auth('pos')->id());
    }

    public static function event(object $row, string $action, int $actor): void
    {
        DB::table('pos_staff_discount_events')->insert(['company_id' => $row->company_id, 'request_id' => $row->id,
            'actor_id' => $actor, 'action' => $action, 'revision' => $row->revision,
            'snapshot' => json_encode((array) $row, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}
