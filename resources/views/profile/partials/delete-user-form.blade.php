@php($isCompanyOwner = isset($company) && $company && in_array($user->role, ['company_admin', 'super_admin'], true))
<section class="space-y-6">
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ $isCompanyOwner ? __('Request Company Deletion') : __('Delete Account') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            {{ $isCompanyOwner
                ? __('A verified request starts administrator review. It does not immediately delete your company, invoices, fiscal records, subscription, or users. If approved, the company first enters a recoverable seven-day bin hold; legally required evidence may be retained.')
                : __('Deleting this staff login removes the user account. Company, fiscal, subscription and accounting records are not deleted by this action.') }}
        </p>
        @if($isCompanyOwner && $companyDeletionRequest)
            <p class="mt-3 text-sm font-medium text-amber-700 dark:text-amber-300">
                Request #{{ $companyDeletionRequest->id }} is already recorded ({{ str_replace('_', ' ', $companyDeletionRequest->status) }}).
            </p>
        @endif
    </header>

    <x-danger-button
        x-data=""
        x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion')"
    >{{ $isCompanyOwner ? __('Request Company Deletion') : __('Delete Account') }}</x-danger-button>

    <x-modal name="confirm-user-deletion" :show="$errors->userDeletion->isNotEmpty()" focusable>
        <form method="post" action="{{ route('profile.destroy') }}" class="p-6">
            @csrf
            @method('delete')

            <h2 class="text-lg font-medium text-gray-900">
                {{ $isCompanyOwner ? __('Submit a verified company deletion request?') : __('Are you sure you want to delete your account?') }}
            </h2>

            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                {{ $isCompanyOwner
                    ? __('Enter your password to verify the request. No company data is deleted by this submission. An administrator must review it, and the recoverable bin hold applies before any permanent purge.')
                    : __('Please enter your password to confirm deletion of this login. This does not delete company or fiscal records.') }}
            </p>

            @if($isCompanyOwner)
            <div class="mt-6">
                <x-input-label for="deletion_reason" value="Reason (optional)" />
                <textarea id="deletion_reason" name="reason" maxlength="1000" rows="3"
                    class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm">{{ old('reason') }}</textarea>
            </div>
            @endif

            <div class="mt-6">
                <x-input-label for="password" value="{{ __('Password') }}" class="sr-only" />

                <x-text-input
                    id="password"
                    name="password"
                    type="password"
                    class="mt-1 block w-3/4"
                    placeholder="{{ __('Password') }}"
                />

                <x-input-error :messages="$errors->userDeletion->get('password')" class="mt-2" />
            </div>

            <div class="mt-6 flex justify-end">
                <x-secondary-button x-on:click="$dispatch('close')">
                    {{ __('Cancel') }}
                </x-secondary-button>

                <x-danger-button class="ms-3">
                    {{ $isCompanyOwner ? __('Submit Request') : __('Delete Account') }}
                </x-danger-button>
            </div>
        </form>
    </x-modal>
</section>
