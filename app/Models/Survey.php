<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Lightweight POS survey (Task 1022 — Caller ID elaan / advice collection).
 * questions = [{key, text, options: [{key, label}]}] (fixed-choice only).
 */
class Survey extends Model
{
    protected $fillable = [
        'starts_at', 'expires_at', 'targeting', 'response_policy', 'title', 'intro', 'questions', 'allow_comment', 'audience', 'is_published', 'closed_at',
    ];

    protected $casts = [
        'allow_comment' => 'boolean',
        'is_published' => 'boolean',
        'closed_at' => 'datetime', 'starts_at' => 'datetime', 'expires_at' => 'datetime', 'targeting' => 'array',
    ];

    /** Always return an array — one bad row must never 500 the POS layout. */
    public function getQuestionsAttribute($value)
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? array_values($decoded) : [];
    }

    public function setQuestionsAttribute($value)
    {
        $this->attributes['questions'] = is_string($value)
            ? $value
            : json_encode(array_values((array) $value), JSON_UNESCAPED_UNICODE);
    }

    public function scopeActive($query)
    {
        $query->where('is_published', true)->whereNull('closed_at');
        if (\Illuminate\Support\Facades\Schema::hasColumn('surveys', 'starts_at')) {
            $query->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
        }

        return $query;
    }

    public function eligibleFor(User $user): bool
    {
        if (! $this->isActive() || ! $user->company_id) {
            return false;
        }
        $product = \App\Support\ProductCatalog::normalize($user->company?->product_type) ?? 'pos';
        $audience = match ($this->audience) {
            'di' => 'di', 'fbrpos' => 'fbrpos', 'erps' => 'erps', default => 'pos'
        };
        if ($audience !== $product) {
            return false;
        }
        if ($this->audience === 'pos_restaurant' && ! $user->company?->restaurant_mode) {
            return false;
        }
        $targets = $this->targeting ?? [];
        foreach (['companies' => $user->company_id, 'users' => $user->id] as $key => $id) {
            if (! empty($targets[$key]) && ! in_array((int) $id, array_map('intval', $targets[$key]), true)) {
                return false;
            }
        }

        return $this->respondentRoleAllowed($user);
    }

    public function respondentRoleAllowed(User $user): bool
    {
        $product = \App\Support\ProductCatalog::normalize($user->company?->product_type) ?? 'pos';
        $targets = $this->targeting ?? [];
        $role = $product === 'erps' ? ($user->health_role ?: $user->role) : ($product === 'pos' || $product === 'fbrpos' ? ($user->pos_role ?: $user->role) : $user->role);
        if (! empty($targets['roles'])) {
            return in_array($role, $targets['roles'], true);
        }

        return $product === 'pos' || $product === 'fbrpos' ? $user->isPosAdmin() : $user->isCompanyAdmin();
    }

    public function audienceUsers()
    {
        $product = match ($this->audience) {
            'di' => 'di','fbrpos' => 'fbrpos','erps' => 'erps',default => 'pos'
        };
        $targets = $this->targeting ?? [];
        $q = User::query()->where('is_active', true)->whereHas('company', function ($q) use ($product, $targets) {
            $q->whereIn('product_type', $product === 'erps' ? ['erps', 'health'] : [$product]);
            if ($this->audience === 'pos_restaurant') {
                $q->where('restaurant_mode', true);
            }
            if (! empty($targets['companies'])) {
                $q->whereIn('id', $targets['companies']);
            }
        });
        if (! empty($targets['users'])) {
            $q->whereIn('id', $targets['users']);
        }
        $roleField = match ($product) {
            'pos','fbrpos' => 'pos_role','erps' => 'health_role',default => 'role'
        };
        if (! empty($targets['roles'])) {
            $q->where(function ($w) use ($roleField, $targets) {
                $w->whereIn($roleField, $targets['roles']);
                if ($roleField !== 'role') {
                    $w->orWhere(fn ($w) => $w->whereNull($roleField)->whereIn('role', $targets['roles']));
                }
            });
        } else {
            $q->where(function ($w) use ($product) {
                $w->where('role', 'company_admin');
                if (in_array($product, ['pos', 'fbrpos'], true)) {
                    $w->orWhereIn('pos_role', ['pos_admin', 'pos_manager']);
                }
            });
        }

        return $q;
    }

    public function answeredBy(User $user): bool
    {
        return $this->responses()->whereNotNull('answered_at')
            ->when(($this->response_policy ?? 'user') === 'company', fn ($q) => $q->where('company_id', $user->company_id), fn ($q) => $q->where('user_id', $user->id))->exists();
    }

    public function responses()
    {
        return $this->hasMany(SurveyResponse::class);
    }

    public function isActive(): bool
    {
        return $this->is_published && $this->closed_at === null && (! $this->starts_at || $this->starts_at->lte(now())) && (! $this->expires_at || $this->expires_at->gt(now()));
    }
}
