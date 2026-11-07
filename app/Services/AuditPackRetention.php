<?php

namespace App\Services;

use App\Models\AuditPack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/** Bounded export-file expiry only. Original invoices and audit history survive. */
final class AuditPackRetention
{
    public static function ownsPath(AuditPack $pack): bool
    {
        return $pack->file_path === 'audit-packs/company_'.(int) $pack->company_id.'/fbr-audit-pack-'.(int) $pack->id.'.zip';
    }

    public static function prune(?int $companyId = null, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException('Use a batch size from 1 to 500.');
        }
        $eligible = AuditPack::where('created_at', '<', now()->subDays(AuditPackBuilderService::RETENTION_DAYS))
            ->whereIn('status', ['ready', 'failed'])->whereNull('locked_at');
        if ($companyId !== null) {
            $eligible->where('company_id', $companyId);
        }
        $ids = (clone $eligible)->orderBy('id')->limit($limit)->pluck('id');
        $result = ['examined' => $ids->count(), 'expired' => 0, 'failed' => 0];
        foreach ($ids as $id) {
            try {
                $expired = DB::transaction(function () use ($eligible, $id) {
                    $pack = (clone $eligible)->whereKey($id)->lockForUpdate()->first();
                    if (! $pack) {
                        return false;
                    }
                    if ($pack->file_path) {
                        if (! self::ownsPath($pack)) {
                            throw new \RuntimeException('Export path requires review.');
                        }
                        $disk = Storage::disk('local');
                        if ($disk->exists($pack->file_path) && ! $disk->delete($pack->file_path)) {
                            throw new \RuntimeException('Export file removal failed.');
                        }
                    }
                    $pack->forceFill(['status' => 'expired', 'file_path' => null, 'file_size' => null])->save();

                    return true;
                });
                if ($expired) {
                    $result['expired']++;
                }
            } catch (\Throwable $exception) {
                $result['failed']++;
                Log::warning('Audit export retention requires review', ['pack_id' => (int) $id]);
            }
        }

        return $result;
    }
}
