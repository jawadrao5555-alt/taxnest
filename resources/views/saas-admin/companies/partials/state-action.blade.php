<details class="tn-account-action w-full rounded-lg border border-gray-400 p-3">
    <summary class="font-semibold cursor-pointer">{{ $activate ? 'Activate company' : 'Suspend company' }}</summary>
    <p class="text-sm my-2">{{ $activate ? 'This clears company suspension/rejection only. It does not renew a paid term or remove subscription expiry.' : 'This applies the existing company-wide restriction. It does not pause, extend or cancel the subscription term.' }}</p>
    <form method="POST" data-company-state-action="{{ $activate ? 'activate' : 'suspend' }}" action="{{ route($activate ? 'saas.admin.companies.activate' : 'saas.admin.companies.suspend', $company->id) }}" class="space-y-3">
        @csrf
        <input type="hidden" name="state_token" value="{{ $accountOverview['state_token'] }}">
        <label class="block text-sm font-semibold">{{ $activate ? 'Activation reason' : 'Suspension reason' }}
            <textarea name="reason" required maxlength="1000" rows="3" class="mt-1 w-full rounded-lg border" placeholder="Explain why this account state is changing">{{ old('reason') }}</textarea>
        </label>
        @unless($activate)
        <label class="block text-sm font-semibold">Restoration condition (optional)
            <textarea name="restoration_condition" maxlength="1000" rows="2" class="mt-1 w-full rounded-lg border" placeholder="What must be reviewed before manual activation?">{{ old('restoration_condition') }}</textarea>
        </label>
        @endunless
        <button type="submit" class="px-4 py-2 rounded-lg bg-indigo-700 hover:bg-indigo-800 text-white font-semibold">{{ $activate ? 'Activate company' : 'Suspend company' }}</button>
    </form>
</details>
