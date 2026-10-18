<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PosTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PosWhatsAppOutbox
{
    public static function allowed(?Company $c): bool
    {
        return $c && ($c->product_type ?? 'pos') === 'pos'
            && !in_array($c->status, ['suspended', 'rejected', 'pending'], true)
            && !in_array($c->company_status, ['suspended', 'inactive'], true)
            && (bool) $c->pos_whatsapp_auto_send && (bool) $c->pos_whatsapp_bill_enabled
            && PosFeatureService::planAllows($c, 'whatsapp_enabled')
            && WhatsAppBusinessApi::configuredFor($c)
            && !empty($c->getRawOriginal('wa_app_secret'));
    }

    public static function finalBill(PosTransaction $bill): bool
    {
        return $bill->status === 'completed' && !$bill->isDeliberateProvisional() && !$bill->is_archived;
    }

    /** Runs inside the bill transaction: rollback removes the outbox row too. */
    public function enqueue(PosTransaction $bill): ?int
    {
        if (!Schema::hasTable('pos_whatsapp_outbox') || !self::finalBill($bill)) return null;
        $company = Company::find($bill->company_id);
        $phone = PkPhone::normalize($bill->customer_phone);
        if (!self::allowed($company) || !$phone) return null;
        DB::table('pos_whatsapp_outbox')->insertOrIgnore([
            'company_id' => $bill->company_id, 'transaction_id' => $bill->id, 'recipient' => $phone,
            'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = DB::table('pos_whatsapp_outbox')->where('company_id', $bill->company_id)
            ->where('transaction_id', $bill->id)->value('id');
        // The durable row is the source of truth, not this best-effort wake-up.
        // Final request state and committed bill/items are re-read after response.
        app()->terminating(function () use ($id) {
            try { $this->process((int) $id); } catch (\Throwable $e) {
                report($e); // Scheduler recovers pending rows; sending is never blindly replayed.
            }
        });
        return $id ? (int) $id : null;
    }

    public function process(int $id): void
    {
        // Atomic claim; duplicate callbacks/workers cannot send the same row.
        $claimed = DB::table('pos_whatsapp_outbox')->where('id', $id)->where('status', 'pending')
            ->where('next_attempt_at', '<=', now())->update([
                'status' => 'sending', 'claimed_at' => now(), 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now(),
            ]);
        if (!$claimed) return;
        $row = DB::table('pos_whatsapp_outbox')->find($id);
        $company = Company::find($row->company_id);
        $bill = PosTransaction::withoutGlobalScope('hide_archived')->where('company_id', $row->company_id)->find($row->transaction_id);
        // Downgrade, opt-out, cancellation or changed recipient always wins.
        PosFeatureService::flushGateCaches();
        if (!self::allowed($company) || !$bill || !self::finalBill($bill)
            || PkPhone::normalize($bill->customer_phone) !== $row->recipient) {
            $this->finish($id, ['status' => 'cancelled', 'error' => 'Auto-send disabled, package unavailable or bill changed.']);
            return;
        }
        $token = $bill->publicBillToken();
        $bill->refresh();
        if (!$token || ($bill->share_token_created_at && $bill->share_token_created_at->lt(now()->subDays(30)))) {
            $this->finish($id, ['status' => 'failed', 'error' => 'Receipt link unavailable or expired.']);
            return;
        }
        $result = app(PosWhatsAppSender::class)->send($company, $bill, $row->recipient, url('/pos/invoice/share/' . $token));
        if ($result['status'] === 'retry') {
            $result = $row->attempts < 5
                ? ['status' => 'pending', 'next_attempt_at' => now()->addSeconds(min(3600, 60 * 2 ** $row->attempts)), 'error' => $result['error']]
                : ['status' => 'failed', 'error' => 'Provider rate limit retry limit reached.'];
        }
        $this->finish($id, $result);
        if (!empty($result['provider_message_id'])) {
            // A webhook may arrive before the send response was persisted.
            $events = DB::table('pos_whatsapp_status_events')->where('company_id', $row->company_id)
                ->where('provider_message_id', $result['provider_message_id'])->orderBy('id')->get();
            foreach ($events as $event) $this->advanceStatus($row->company_id, ['id' => $event->provider_message_id, 'status' => $event->status]);
        }
    }

    private function finish(int $id, array $values): void
    {
        DB::table('pos_whatsapp_outbox')->where('id', $id)->where('status', 'sending')
            ->update($values + ['updated_at' => now()]);
    }

    public function drain(int $limit = 5): int
    {
        if (!Schema::hasTable('pos_whatsapp_outbox')) return 0;
        // A process killed after provider acceptance is ambiguous, not retryable.
        DB::table('pos_whatsapp_outbox')->where('status', 'sending')->where('claimed_at', '<', now()->subMinutes(5))
            ->update(['status' => 'unknown', 'error' => 'Send interrupted; verify provider delivery before resending.', 'updated_at' => now()]);
        DB::table('pos_whatsapp_status_events')->where('created_at', '<', now()->subDays(30))->limit(500)->delete();
        $ids = DB::table('pos_whatsapp_outbox')->where('status', 'pending')->where('next_attempt_at', '<=', now())
            ->orderBy('id')->limit(max(1, min(20, $limit)))->pluck('id');
        foreach ($ids as $id) {
            try { $this->process((int) $id); } catch (\Throwable $e) { report($e); }
        }
        return $ids->count();
    }

    public function applyStatus(int $company, array $status): void
    {
        if (!Schema::hasTable('pos_whatsapp_outbox')) return;
        $new = strtolower((string) ($status['status'] ?? ''));
        if (!in_array($new, ['sent', 'delivered', 'read', 'failed'], true) || empty($status['id'])) return;
        // Persist before matching so early status callbacks are not lost.
        DB::table('pos_whatsapp_status_events')->insertOrIgnore([
            'company_id' => $company, 'provider_message_id' => (string) $status['id'],
            'status' => $new, 'created_at' => now(),
        ]);
        $this->advanceStatus($company, $status);
    }

    private function advanceStatus(int $company, array $status): void
    {
        $new = strtolower((string) $status['status']);
        // Monotonic conditional UPDATE remains safe under concurrent webhooks.
        $previous = match ($new) {
            'sent' => ['sending', 'unknown'], 'delivered' => ['sending', 'unknown', 'sent'],
            'read' => ['sending', 'unknown', 'sent', 'delivered'], 'failed' => ['sending', 'unknown', 'sent'],
        };
        DB::table('pos_whatsapp_outbox')->where('company_id', $company)
            ->where('provider_message_id', (string) $status['id'])->whereIn('status', $previous)
            ->update(['status' => $new, 'error' => $new === 'failed' ? 'Provider reported delivery failure.' : null, 'updated_at' => now()]);
    }
}
