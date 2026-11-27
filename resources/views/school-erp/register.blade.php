<x-school-erp-layout :title="__('school_erp.sign_up')">
    <div class="school-card school-narrow">
        <h1 class="school-title">{{ __('school_erp.sign_up') }}</h1>
        @if($plans->isEmpty())
            <p class="school-note">{{ __('school_erp.no_packages') }}</p>
        @else
            <p class="mb-4">{{ __('school_erp.registration_review') }}</p>
            <form class="school-form" method="POST" action="{{ route('school-erp.register.submit') }}">
                @csrf
                <label>
                    {{ __('school_erp.school_name') }}
                    <input name="company_name" value="{{ old('company_name') }}" required autocomplete="organization">
                </label>
                <label>
                    {{ __('school_erp.ntn') }}
                    <input name="company_ntn" value="{{ old('company_ntn') }}" required maxlength="50">
                </label>
                <label>
                    {{ __('school_erp.cnic') }}
                    <input name="company_cnic" value="{{ old('company_cnic') }}" inputmode="numeric">
                </label>
                <label>
                    {{ __('school_erp.name') }}
                    <input name="name" value="{{ old('name') }}" required autocomplete="name">
                </label>
                <label>
                    {{ __('school_erp.email') }}
                    <input name="email" type="email" value="{{ old('email') }}" required autocomplete="email">
                </label>
                <label>
                    {{ __('school_erp.password') }}
                    <input id="school-register-password" name="password" type="password" required autocomplete="new-password">
                </label>
                <label>
                    {{ __('school_erp.confirm_password') }}
                    <input id="school-register-password-confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
                </label>
                <label>
                    {{ __('school_erp.package') }}
                    <select name="package_choice" required>
                        <option value="">{{ __('school_erp.choose_package') }}</option>
                        @foreach($plans as $plan)
                            @foreach(\App\Services\ErpPublicPricing::prices($plan) as $cycle => $price)
                                <option value="{{ $plan->id }}:{{ $cycle }}" @selected(old('package_choice', $preferred ?? null) === $plan->id.':'.$cycle)>
                                    {{ $plan->name }} · {{ __('school_erp.'.$cycle) }} · PKR {{ number_format($price, 2) }}
                                </option>
                            @endforeach
                        @endforeach
                    </select>
                </label>
                <button class="school-button" type="submit">{{ __('school_erp.sign_up') }}</button>
            </form>
        @endif
        <p class="mt-4"><a href="{{ route('school-erp.login') }}">{{ __('school_erp.sign_in') }}</a></p>
    </div>
</x-school-erp-layout>
