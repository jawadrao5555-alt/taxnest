<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Optional support copies only; never a live ledger, invoice or fiscal queue. */
class OptionalLocalBillArchive
{
    public const POLICY = 'local-support-copy-v1';
    public const WORDING = 'Keep an encrypted TaxNest support/dispute copy of eligible local bills deleted after this consent. No customer contact details or fiscal payloads. Stop keeps copies until their original expiry; purge removes optional copies and destroys their keys. Mandatory accounting/fiscal records and independent backups are separate.';

    public function assertOwner($user, int $companyId): void
    {
        abort_unless($user && $user->role === 'company_admin'
            && (int) $user->company_id === $companyId && !Auth::guard('admin')->check()
            && !(app()->bound('request') && request()->hasSession() && request()->session()->has('impersonation')), 403);
    }

    public function grant(int $companyId, $user, int $retentionDays, CarbonInterface $validUntil): int
    {
        $this->assertOwner($user, $companyId);
        if ($retentionDays < 1 || $retentionDays > 365 || $validUntil->lte(now())
            || $validUntil->gt(now()->addYear()->endOfDay())) {
            throw ValidationException::withMessages(['consent' => 'Choose 1–365 retention days and a future consent expiry within one year.']);
        }
        $keyId = bin2hex(random_bytes(32));
        $this->writeKey($keyId, random_bytes(32));
        try {
            return DB::transaction(function () use ($companyId, $user, $retentionDays, $validUntil, $keyId) {
                $this->lockedPrefs($companyId);
                $id = $this->consent($companyId, $user->id, 'grant', [
                    'key_id' => $keyId, 'retention_days' => $retentionDays,
                    'valid_until' => $validUntil->toDateTimeString(),
                ]);
                DB::table('support_bill_archive_prefs')->where('company_id', $companyId)
                    ->update(['enabled' => true, 'grant_id' => $id, 'updated_at' => now()]);
                return $id;
            });
        } catch (\Throwable $e) {
            $this->destroyKey($keyId);
            throw $e;
        }
    }

    public function stop(int $companyId, $user): void
    {
        $this->assertOwner($user, $companyId);
        DB::transaction(function () use ($companyId, $user) {
            $this->lockedPrefs($companyId);
            DB::table('support_bill_archive_prefs')->where('company_id', $companyId)
                ->update(['enabled' => false, 'grant_id' => null, 'updated_at' => now()]);
            $this->consent($companyId, $user->id, 'stop');
        });
    }

    public function purge(int $companyId, $user): void
    {
        $this->assertOwner($user, $companyId);
        DB::transaction(function () use ($companyId, $user) {
            $this->lockedPrefs($companyId);
            // Destroy first. A SQL rollback/old SQL backup must never restore the
            // ability to decrypt a purged copy. Failure is closed and retryable.
            $this->destroyCompanyKeys($companyId);
            DB::table('support_bill_archives')->where('company_id', $companyId)->delete();
            DB::table('support_bill_archive_prefs')->where('company_id', $companyId)
                ->update(['enabled' => false, 'grant_id' => null, 'updated_at' => now()]);
            $this->consent($companyId, $user->id, 'purge');
        });
    }

    /** Caller holds the bill lock; optional capture and deletion share its transaction. */
    public function capture(Model $bill, string $product, ?iterable $items = null): ?int
    {
        $expected = ['pos' => 'pos_transactions', 'fbrpos' => 'fbr_pos_transactions'][$product] ?? null;
        if (!$expected || $bill->getTable() !== $expected) {
            throw new RuntimeException('Unsupported optional archive source.');
        }
        if (!Schema::hasTable('support_bill_archive_prefs')) {
            return null; // Safe rolling deployment: no preference means OFF.
        }
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('Optional archive capture requires the deletion transaction.');
        }
        // The service must not trust a stale controller/model instance. Re-read
        // the exact company/source under the financial lock before eligibility.
        $current = DB::table($expected)->where('company_id', $bill->company_id)
            ->where('id', $bill->getKey())->lockForUpdate()->first();
        if (!$current) throw new RuntimeException('Optional support copy source disappeared.');
        $bill = $bill->newFromBuilder((array) $current);
        $prefix = $product === 'pos' ? 'pra' : 'fbr';
        $status = $bill->getAttribute($prefix.'_status');
        $mode = $bill->getAttribute('invoice_mode');
        if ($bill->getAttribute($prefix.'_invoice_number')
            || ($bill->getAttribute('transaction_type') ?? 'sale') !== 'sale'
            || $bill->getAttribute('status') !== 'completed'
            || !(($mode === 'local' && in_array($status, ['local', null], true))
                || ($status === null && in_array($mode, [$prefix, null], true)))) {
            return null;
        }
        $companyId = (int) $bill->getAttribute('company_id');
        $prefs = DB::table('support_bill_archive_prefs')->where('company_id', $companyId)->lockForUpdate()->first();
        if (!$prefs || !$prefs->enabled || !$prefs->grant_id) {
            return null;
        }
        $grant = DB::table('support_bill_archive_consents')->where('company_id', $companyId)
            ->where('id', $prefs->grant_id)->where('action', 'grant')->first();
        if (!$grant || !$grant->valid_until || Carbon::parse($grant->valid_until)->lte(now())) {
            return null;
        }
        $key = $this->readKey($grant->key_id); // A promised copy may not silently disappear.
        $payload = $this->snapshot($bill, $product, $items ?? $bill->items()->orderBy('id')->get());
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > (int) config('local_bill_archive.max_payload_bytes', 1048576)) {
            throw new RuntimeException('Optional support copy exceeds its safe size limit; deletion cancelled.');
        }
        $hash = hash('sha256', $json);
        $existing = DB::table('support_bill_archives')->where('company_id', $companyId)
            ->where('product', $product)->where('source_id', $bill->getKey())->first();
        if ($existing) {
            if ((int) $existing->grant_id !== (int) $grant->id || !hash_equals($existing->payload_hash, $hash)) {
                throw new RuntimeException('Optional support copy source collision; deletion cancelled.');
            }
            return (int) $existing->id;
        }
        $compressed = gzencode($json);
        if ($compressed === false) {
            throw new RuntimeException('Optional support copy compression failed.');
        }
        return DB::table('support_bill_archives')->insertGetId([
            'company_id' => $companyId, 'product' => $product,
            'branch_id' => $bill->getAttribute('branch_id'), 'source_id' => $bill->getKey(),
            'grant_id' => $grant->id, 'reference' => (string) $bill->getAttribute('invoice_number'),
            'deleted_at' => now(), 'expires_at' => now()->addDays((int) $grant->retention_days),
            'payload_hash' => $hash,
            'encrypted_payload' => (new Encrypter($key, 'AES-256-GCM'))->encryptString(base64_encode($compressed)),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function available(int $companyId): Builder
    {
        return DB::table('support_bill_archives')->where('company_id', $companyId)->where('expires_at', '>', now());
    }

    public function readPayload(object $archive): array
    {
        return DB::transaction(function () use ($archive) {
            // Serialize with purge/quarantine; a cached row may no longer be
            // available by the time an export request reaches this service.
            DB::table('support_bill_archive_prefs')->where('company_id', $archive->company_id)->lockForUpdate()->first();
            $current = $this->available((int) $archive->company_id)->where('id', $archive->id)
                ->where('product', $archive->product)->where('source_id', $archive->source_id)->first();
            if (!$current) throw new RuntimeException('Optional copy unavailable.');
            return $this->decodePayload($current);
        });
    }

    private function decodePayload(object $archive): array
    {
        if (Carbon::parse($archive->expires_at)->lte(now())) {
            throw new RuntimeException('Optional copy expired.');
        }
        $grant = DB::table('support_bill_archive_consents')->where('company_id', $archive->company_id)
            ->where('id', $archive->grant_id)->where('action', 'grant')->first();
        if (!$grant) {
            throw new RuntimeException('Optional copy consent unavailable.');
        }
        $packed = (new Encrypter($this->readKey($grant->key_id), 'AES-256-GCM'))->decryptString($archive->encrypted_payload);
        $compressed = base64_decode($packed, true);
        $json = $compressed === false ? false : @gzdecode($compressed, (int) config('local_bill_archive.max_payload_bytes', 1048576) + 1);
        if ($json === false || strlen($json) > (int) config('local_bill_archive.max_payload_bytes', 1048576)
            || !hash_equals($archive->payload_hash, hash('sha256', $json))) {
            throw new RuntimeException('Optional copy integrity check failed.');
        }
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (($payload['company_id'] ?? null) !== (int) $archive->company_id
            || ($payload['product'] ?? null) !== $archive->product
            || ($payload['source_id'] ?? null) !== (int) $archive->source_id
            || ($payload['header']['branch_id'] ?? null) != $archive->branch_id
            || ($payload['header']['invoice_number'] ?? null) !== $archive->reference) {
            throw new RuntimeException('Optional copy scope mismatch.');
        }
        return $payload;
    }

    public function audit(int $companyId, ?int $actorId, string $guard, string $action, ?int $archiveId = null): void
    {
        DB::table('support_bill_archive_access')->insert([
            'company_id' => $companyId, 'actor_id' => $actorId, 'actor_guard' => $guard,
            'action' => $action, 'archive_id' => $archiveId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function cleanup(int $limit = 100): int
    {
        $limit = max(1, min(1000, $limit));
        $count = DB::transaction(function () use ($limit) {
            $ids = DB::table('support_bill_archives')->where('expires_at', '<=', now())
                ->orderBy('id')->limit($limit)->lockForUpdate()->pluck('id');
            return DB::table('support_bill_archives')->whereIn('id', $ids)->where('expires_at', '<=', now())->delete();
        });
        // Keep keys while any unexpired copy refers to them. Expired consent is
        // never reused; its key can be destroyed once all its copies have gone.
        $grants = DB::table('support_bill_archive_consents')->where('action', 'grant')
            ->where('valid_until', '<=', now())->whereNotNull('key_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('support_bill_archive_consents as removed')
                ->where('removed.action', 'key_destroyed')->whereColumn('removed.subject_grant_id', 'support_bill_archive_consents.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('support_bill_archives')
                ->whereColumn('grant_id', 'support_bill_archive_consents.id'))
            ->orderBy('id')->limit($limit)->get();
        foreach ($grants as $grant) {
            DB::transaction(function () use ($grant) {
                // A capture may have started before expiry but not committed.
                // Serialize on the same company preference lock, then recheck.
                DB::table('support_bill_archive_prefs')->where('company_id', $grant->company_id)->lockForUpdate()->first();
                if (Carbon::parse($grant->valid_until)->gt(now())
                    || DB::table('support_bill_archives')->where('grant_id', $grant->id)->exists()
                    || DB::table('support_bill_archive_consents')->where('action', 'key_destroyed')
                        ->where('subject_grant_id', $grant->id)->exists()) return;
                $this->destroyKey($grant->key_id);
                $this->consent((int) $grant->company_id, null, 'key_destroyed', ['subject_grant_id' => $grant->id]);
            });
        }
        return $count;
    }

    /** Operator MUST run before exposing a restored database/keyring to traffic. */
    public function quarantineAfterRestore(): void
    {
        DB::transaction(function () {
            $companies = DB::table('support_bill_archive_prefs')->orderBy('company_id')->lockForUpdate()->pluck('company_id');
            foreach ($companies as $companyId) {
                $this->destroyCompanyKeys((int) $companyId);
                $this->consent((int) $companyId, null, 'restore_quarantine');
            }
            DB::table('support_bill_archive_prefs')->update(['enabled' => false, 'grant_id' => null, 'updated_at' => now()]);
            DB::table('support_bill_archives')->delete();
        });
    }

    private function lockedPrefs(int $companyId): object
    {
        DB::table('support_bill_archive_prefs')->insertOrIgnore([
            'company_id' => $companyId, 'enabled' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return DB::table('support_bill_archive_prefs')->where('company_id', $companyId)->lockForUpdate()->first();
    }

    private function consent(int $companyId, ?int $actorId, string $action, array $extra = []): int
    {
        return DB::table('support_bill_archive_consents')->insertGetId(array_merge([
            'company_id' => $companyId, 'actor_id' => $actorId, 'action' => $action,
            'policy_version' => self::POLICY, 'wording' => self::WORDING,
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    private function snapshot(Model $bill, string $product, iterable $items): array
    {
        // Decimal values remain exact stored strings. No customer/contact,
        // free-text notes, regulator payloads, bank/card references or tokens.
        $header = $this->pick($bill, ['invoice_number', 'business_date', 'created_at', 'created_by',
            'branch_id', 'terminal_id', 'invoice_mode', 'subtotal', 'discount_type', 'discount_value',
            'discount_amount', 'tax_rate', 'tax_amount', 'exempt_amount', 'total_amount',
            'tax_inclusive', 'tax_menu_rate', 'fbr_service_charge', 'payment_method', 'cash_received',
            'change_due', 'loyalty_redemption_amount']);
        $lines = [];
        foreach ($items as $item) {
            $row = $this->pick($item, ['id', 'item_type', 'item_id', 'product_id', 'item_name', 'hs_code',
                'uom', 'quantity', 'unit_price', 'cost_price', 'subtotal', 'total', 'tax_rate', 'tax_amount',
                'is_tax_exempt', 'is_third_schedule', 'item_discount_type', 'item_discount_value',
                'item_discount_amount', 'discount', 'item_discount', 'promotion_discount', 'deal_group',
                'deal_id', 'deal_name', 'deal_quantity', 'deal_unit_price']);
            // Store a minimized bill-time deal component snapshot, never today's
            // product/recipe prices or arbitrary nested client data.
            $deal = $item instanceof Model ? $item->getAttribute('deal_snapshot') : ($item->deal_snapshot ?? null);
            if (is_string($deal)) $deal = json_decode($deal, true);
            if (is_array($deal)) $row['deal_snapshot'] = $this->dealFacts($deal);
            $lines[] = $row;
        }
        $payments = [];
        if ($product === 'pos' && Schema::hasTable('pos_payments')) {
            foreach (DB::table('pos_payments')->where('transaction_id', $bill->getKey())->orderBy('id')->get() as $payment) {
                $payments[] = $this->pick($payment, ['payment_method', 'amount']);
            }
        } elseif ($product === 'fbrpos') {
            $breakdown = $bill->getAttribute('payment_breakdown');
            if (is_string($breakdown)) $breakdown = json_decode($breakdown, true);
            foreach (is_array($breakdown) ? $breakdown : [] as $method => $amount) {
                if (is_array($amount)) {
                    $method = $amount['method'] ?? null;
                    $amount = $amount['amount'] ?? null;
                }
                if (is_string($method) && in_array($method, ['cash', 'card', 'debit_card', 'credit_card', 'qr_payment', 'credit', 'bank_transfer'], true)
                    && is_numeric($amount)) $payments[] = ['payment_method' => $method, 'amount' => (string) $amount];
            }
        }
        return ['version' => 1, 'company_id' => (int) $bill->company_id, 'product' => $product,
            'source_id' => (int) $bill->getKey(), 'header' => $header, 'items' => $lines, 'payments' => $payments];
    }

    private function pick($row, array $keys): array
    {
        $all = $row instanceof Model ? $row->getAttributes() : (array) $row;
        return array_intersect_key($all, array_flip($keys));
    }

    private function dealFacts(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                // Keep structure for known component containers only.
                if (is_int($key) || in_array($key, ['items', 'components', 'products', 'choices'], true)) $out[$key] = $this->dealFacts($item);
            } elseif (in_array($key, ['id', 'item_id', 'product_id', 'type', 'item_type', 'name', 'item_name',
                'quantity', 'unit_price', 'cost_price', 'subtotal', 'total', 'tax_rate', 'tax_amount', 'is_tax_exempt',
                'discount_amount', 'deal_id'], true) && (is_scalar($item) || $item === null)) $out[$key] = $item;
        }
        return $out;
    }

    private function keyRoot(bool $create = false): string
    {
        $root = (string) config('local_bill_archive.key_root');
        if ($root === '' || $root[0] !== '/' || str_contains($root, "\0") || preg_match('~(^|/)\.\.?(/|$)~', $root)) {
            throw new RuntimeException('Optional copy keyring must be an absolute private path.');
        }
        // Reject symlinks at every ancestor, not just the final directory.
        $parts = explode('/', trim($root, '/'));
        $path = '';
        foreach ($parts as $part) {
            $path .= '/'.$part;
            if (is_link($path)) throw new RuntimeException('Optional copy keyring may not use symlinks.');
        }
        if (!is_dir($root) && $create && !@mkdir($root, 0700, true) && !is_dir($root)) {
            throw new RuntimeException('Optional copy keyring cannot be created.');
        }
        $real = realpath($root);
        $publicRoots = [public_path(), storage_path('app/public')];
        foreach ((array) config('filesystems.disks', []) as $disk) {
            if (($disk['visibility'] ?? null) === 'public' && isset($disk['root'])) $publicRoots[] = $disk['root'];
        }
        if ($real === false) {
            throw new RuntimeException('Optional copy keyring is unavailable or public.');
        }
        foreach ($publicRoots as $publicRoot) {
            $public = realpath($publicRoot);
            if ($public !== false && ($real === $public || str_starts_with($real, $public.'/'))) {
                throw new RuntimeException('Optional copy keyring is unavailable or public.');
            }
        }
        if ($create && !@chmod($root, 0700)) throw new RuntimeException('Optional copy keyring permissions failed.');
        clearstatcache(true, $root);
        if ((fileperms($root) & 0077) !== 0) throw new RuntimeException('Optional copy keyring is not private.');
        return $real;
    }

    private function keyPath(string $id, bool $create = false): string
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $id)) throw new RuntimeException('Invalid optional copy key identifier.');
        $path = $this->keyRoot($create).'/'.$id.'.key';
        if (is_link($path)) throw new RuntimeException('Optional copy key may not be a symlink.');
        return $path;
    }

    private function writeKey(string $id, string $key): void
    {
        $path = $this->keyPath($id, true);
        $handle = @fopen($path, 'x');
        if (!$handle) throw new RuntimeException('Optional copy key cannot be stored.');
        try {
            if (!@chmod($path, 0600)) throw new RuntimeException('Optional copy key permissions failed.');
            $content = Crypt::encryptString(base64_encode($key));
            if (fwrite($handle, $content) !== strlen($content) || !fflush($handle)) throw new RuntimeException('Optional copy key write failed.');
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($path);
            throw $e;
        }
        fclose($handle);
    }

    private function readKey(string $id): string
    {
        $path = $this->keyPath($id);
        clearstatcache(true, $path);
        if (!is_file($path) || (fileperms($path) & 0077) !== 0) throw new RuntimeException('Optional copy key unavailable.');
        $content = @file_get_contents($path);
        if ($content === false) throw new RuntimeException('Optional copy key unavailable.');
        $key = base64_decode(Crypt::decryptString($content), true);
        if ($key === false || strlen($key) !== 32) throw new RuntimeException('Optional copy key invalid.');
        return $key;
    }

    private function destroyKey(string $id): void
    {
        $path = $this->keyPath($id, true);
        if (file_exists($path) && !@unlink($path) && file_exists($path)) throw new RuntimeException('Optional copy key removal failed; retry purge.');
    }

    private function destroyCompanyKeys(int $companyId): void
    {
        foreach (DB::table('support_bill_archive_consents')->where('company_id', $companyId)->whereNotNull('key_id')->orderBy('id')->get() as $grant) {
            $this->destroyKey($grant->key_id);
        }
    }
}
