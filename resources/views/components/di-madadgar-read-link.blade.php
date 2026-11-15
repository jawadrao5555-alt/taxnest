@php
    $sessionActor = auth('web')->user();
    $readActor = $sessionActor ? \App\Models\User::find($sessionActor->id) : null;
    $readCompany = $readActor ? \App\Models\Company::find($readActor->company_id) : null;
    $canReadDi = $readActor && $readActor->is_active && $readActor->role === 'company_admin'
        && in_array($readActor->pos_role, [null, '', 'pos_admin'], true)
        && (int) $sessionActor->company_id === (int) $readActor->company_id
        && $readCompany?->product_type === 'di';
@endphp
@if($canReadDi)
    <a href="{{ route('di.madadgar.read') }}" data-di-read-link
       class="sidebar-link flex items-center gap-3 py-3 px-4 rounded-lg text-sm text-gray-600 dark:text-gray-200 {{ request()->routeIs('di.madadgar.read') ? 'active' : '' }}">
        {{ __('madadgar_di.link') }}
    </a>
@endif
