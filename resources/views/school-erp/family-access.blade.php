<x-school-erp-layout :title="__('school_family.manage')">
    <h1 class="school-title">{{ __('school_family.manage') }}</h1>
    @if($canGrant)
        <section class="school-card">
            <h2>{{ __('school_family.create') }}</h2>
            <p>{{ __('school_family.access_description') }}</p>
            <form class="school-form" method="POST" action="{{ route('school-erp.family-access.create') }}">
                @csrf
                <label>{{ __('school_family.account_name') }}<input name="name" required maxlength="120" value="{{ old('name') }}"></label>
                <label>{{ __('school_erp.email') }}<input type="email" name="email" required maxlength="255" value="{{ old('email') }}"></label>
                <label>{{ __('school_erp.student_reference') }}<input name="student_reference" maxlength="64" required></label>
                <label>{{ __('school_erp.password') }}<input type="password" name="password" autocomplete="new-password" required></label>
                <label>{{ __('school_erp.confirm_password') }}<input type="password" name="password_confirmation" autocomplete="new-password" required></label>
                <button class="school-button" type="submit">{{ __('school_family.create') }}</button>
            </form>
        </section>
    @endif

    <section class="school-card">
        <h2>{{ __('school_family.accounts') }}</h2>
        @forelse($accounts as $account)
            <section class="school-card">
                <h3>{{ $account->name }} · {{ $account->email }}</h3>
                @forelse($grants->get($account->id, []) as $grant)
                    <form method="POST" action="{{ route('school-erp.family-access.revoke', $grant->id) }}">
                        @csrf
                        @method('DELETE')
                        <span>{{ $grant->display_name }} · {{ $grant->student_reference }}</span>
                        <button type="submit">{{ __('school_family.revoke') }}</button>
                    </form>
                @empty
                    <p>{{ __('school_family.no_access') }}</p>
                @endforelse
                @if($canGrant)
                    <form class="school-form" method="POST" action="{{ route('school-erp.family-access.link', $account->id) }}">
                        @csrf
                        <label>{{ __('school_erp.student_reference') }}<input name="student_reference" maxlength="64" required></label>
                        <button class="school-button" type="submit">{{ __('school_family.link') }}</button>
                    </form>
                @endif
            </section>
        @empty
            <p>{{ __('school_family.no_accounts') }}</p>
        @endforelse
        {{ $accounts->links() }}
    </section>
</x-school-erp-layout>
