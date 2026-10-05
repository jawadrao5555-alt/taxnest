<?php
namespace App\Services;
use App\Models\HotelStay;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
class HotelGuestDirectory
{
    public static function key(HotelStay $stay): string
    {
        return hash('sha256', (int) $stay->branch_id.'|'.mb_strtolower(trim((string) $stay->guest_name)).'|'.trim((string) $stay->guest_phone));
    }
    public static function profile(HotelStay $stay): ?object
    {
        return Schema::hasTable('hotel_guest_profiles') ? DB::table('hotel_guest_profiles')->where('company_id', $stay->company_id)->where('source_key', self::key($stay))->first() : null;
    }
    public static function rows(int $company, ?int $branch = null): \Illuminate\Support\Collection
    {
        $profiles = Schema::hasTable('hotel_guest_profiles') ? DB::table('hotel_guest_profiles')->where('company_id', $company)->get()->keyBy('source_key') : collect();
        return HotelStay::where('company_id', $company)->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->orderByDesc('id')->get()->unique(fn ($stay) => self::key($stay))->filter(function ($stay) use ($profiles) {
                $profile = $profiles->get(self::key($stay));
                if ($profile?->hidden) return false;
                if ($profile) foreach (['guest_name', 'guest_phone', 'guest_cnic'] as $field) $stay->{$field} = $profile->{$field};
                return true;
            })->values();
    }
    public static function save(HotelStay $source, array $data, int $actor, bool $hidden = false): void
    {
        $key = self::key($source);
        DB::transaction(function () use ($source, $data, $actor, $hidden, $key) {
            $source = HotelStay::where('company_id', $source->company_id)->lockForUpdate()->findOrFail($source->id);
            DB::table('hotel_guest_profiles')->updateOrInsert(['company_id' => $source->company_id, 'source_key' => $key], [
                'branch_id' => $source->branch_id, 'source_stay_id' => $source->id, 'guest_name' => $data['guest_name'],
                'guest_phone' => $data['guest_phone'] ?? null, 'guest_cnic' => $data['guest_cnic'] ?? null,
                'hidden' => $hidden, 'updated_at' => now(),
            ]);
            AuditLogService::log($hidden ? 'hotel_guest_removed' : 'hotel_guest_updated', 'hotel_stay', $source->id, null,
                ['directory_only' => true], (int) $source->company_id, $actor);
        });
    }
}
