<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\UpcomingProduct;
use App\Models\UpcomingProductInterest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class UpcomingProductController extends Controller
{
    public function index(): View
    {
        AdminUser::requireSuperAdministrator();
        $products = UpcomingProduct::withCount(['interests as confirmed_interests_count' => fn ($query) => $query
            ->whereNotNull('confirmed_at')->whereNull('withdrawn_at')])
            ->orderBy('sort_order')->orderBy('id')->paginate(25);

        return view('saas-admin.upcoming-products.index', compact('products'));
    }

    public function create(): View
    {
        AdminUser::requireSuperAdministrator();

        return view('saas-admin.upcoming-products.edit', ['product' => new UpcomingProduct([
            'status' => 'upcoming',
            'concept' => 'general',
            'sort_order' => 100,
        ])]);
    }

    public function edit(UpcomingProduct $product): View
    {
        AdminUser::requireSuperAdministrator();

        return view('saas-admin.upcoming-products.edit', compact('product'));
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request, new UpcomingProduct);
    }

    public function update(Request $request, UpcomingProduct $product): RedirectResponse
    {
        return $this->save($request, $product);
    }

    public function interests(Request $request, UpcomingProduct $product): View
    {
        AdminUser::requireSuperAdministrator();
        $purpose = $request->validate([
            'purpose' => ['nullable', 'string', Rule::in(array_keys(UpcomingProductInterest::PURPOSES))],
        ])['purpose'] ?? null;
        $interests = $product->interests()->whereNotNull('confirmed_at')->whereNull('withdrawn_at')
            ->when($purpose, fn ($query) => $query->where('purpose', $purpose))
            ->latest('confirmed_at')->paginate(30)->withQueryString();

        return view('saas-admin.upcoming-products.interests', compact('product', 'interests', 'purpose'));
    }

    private function save(Request $request, UpcomingProduct $product): RedirectResponse
    {
        $actor = AdminUser::requireSuperAdministrator();
        $attributes = $request->validate([
            'slug' => $product->exists ? ['prohibited'] : ['required', 'string', 'max:80', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', 'unique:upcoming_products,slug'],
            'name' => ['required', 'string', 'max:120'],
            'introduction' => ['required', 'string', 'max:500'],
            'benefits' => ['required', 'string', 'max:1000'],
            'features' => ['required', 'string', 'max:1000'],
            'industries' => ['required', 'string', 'max:1000'],
            'status' => ['required', 'string', Rule::in(array_keys(UpcomingProduct::STATUSES))],
            'concept' => ['required', 'string', Rule::in(array_keys(UpcomingProduct::CONCEPTS))],
            'existing_product_path' => ['nullable', 'string', 'max:160', 'regex:~\A/[a-zA-Z0-9][a-zA-Z0-9/_-]*(?:#[A-Za-z0-9_-]+)?\z~'],
            'sort_order' => ['required', 'integer', 'between:0,5000'],
            'published' => ['required', 'boolean'],
        ]);
        foreach (['benefits', 'features', 'industries'] as $field) {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $attributes[$field])), fn ($value) => $value !== ''));
            if (!$lines || count($lines) > 8 || array_filter($lines, fn ($line) => mb_strlen($line) > 140)) {
                throw ValidationException::withMessages([$field => 'Enter one to eight items of up to 140 characters, one per line.']);
            }
            $attributes[$field] = $lines;
        }

        DB::transaction(function () use ($product, $actor, $attributes): void {
            $record = $product->exists ? UpcomingProduct::query()->lockForUpdate()->findOrFail($product->id) : $product;
            $record->fill($attributes);
            $record->published_at = $attributes['published'] ? ($record->published_at ?? now()) : null;
            $record->save();
            AdminAuditLog::log($actor->id, 'upcoming_product_saved', 'upcoming_product', $record->id, [
                'status' => $record->status,
                'published' => (bool) $record->published_at,
            ]);
        });

        return redirect()->route('saas.admin.upcoming-products.index')->with('status', 'Upcoming product saved.');
    }
}
