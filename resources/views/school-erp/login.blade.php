<x-school-erp-layout :title="__('school_erp.sign_in')">
    <div class="school-card school-narrow">
        <h1 class="school-title">{{ __('school_erp.sign_in') }}</h1>
        <p class="mb-4">{{ __('school_erp.tagline') }}</p>
        <form class="school-form" method="POST" action="{{ route('school-erp.login.submit') }}">
            @csrf
            <label>
                {{ __('school_erp.email') }}
                <input name="email" type="email" value="{{ old('email') }}" required autocomplete="username">
            </label>
            <label>
                {{ __('school_erp.password') }}
                <input id="school-login-password" name="password" type="password" required autocomplete="current-password">
            </label>
            <button class="school-button" type="submit">{{ __('school_erp.sign_in') }}</button>
        </form>
        @if(\App\Support\SchoolErpPanel::registrationOpen())
            <p class="mt-4"><a href="{{ route('school-erp.register') }}">{{ __('school_erp.sign_up') }}</a></p>
        @endif
    </div>
</x-school-erp-layout>
