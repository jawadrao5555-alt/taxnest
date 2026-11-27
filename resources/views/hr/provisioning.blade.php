<x-admin-layout>
    <x-slot name="header"><h1 class="text-xl font-bold">Nest HR workspaces</h1></x-slot>
    <div class="mx-auto max-w-6xl space-y-6 px-4 py-6">
        @if (session('status'))
            <p role="status" class="text-emerald-700">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <ul role="alert" class="text-red-700">
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        @endif
        <section class="space-y-3 rounded-lg border bg-white p-4 dark:bg-gray-800" aria-labelledby="new-hr-workspace">
            <h2 id="new-hr-workspace" class="font-bold">Create a workspace</h2>
            <p>Use the owner’s existing TaxNest user ID. Share the workspace’s sign-in link with its members.</p>
            <form method="POST" action="{{ route('admin.hr.store') }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <label>Workspace name <input name="name" required maxlength="180" class="block rounded border p-2 dark:bg-gray-900"></label>
                <label>Timezone <input name="timezone" required value="Asia/Karachi" class="block rounded border p-2 dark:bg-gray-900"></label>
                <label>Owner user ID <input name="owner_id" required type="number" min="1" class="block rounded border p-2 dark:bg-gray-900"></label>
                <button class="rounded bg-emerald-800 px-4 py-2 text-white">Create workspace</button>
            </form>
        </section>
        @forelse ($workspaces as $workspace)
            <article class="space-y-3 rounded-lg border bg-white p-4 dark:bg-gray-800">
                <h2 class="font-bold">{{ $workspace->name }}</h2>
                <p>Workspace {{ $workspace->id }} · {{ $workspace->timezone }} · {{ ucfirst($workspace->status) }} · {{ $workspace->employees_count }} employees · {{ $workspace->memberships_count }} memberships</p>
                <p><a class="underline" href="{{ route('admin.hr.members.index', ['organization' => $workspace->id]) }}">Manage memberships</a></p>
                @if ($workspace->status === 'active')
                    <a class="underline" href="{{ route('hr.login', ['workspace' => $workspace->id]) }}">Workspace sign-in</a>
                    <form method="POST" action="{{ route('admin.hr.members.store', ['organization' => $workspace->id]) }}" class="flex flex-wrap items-end gap-3">
                        @csrf
                        <label>Existing user ID <input name="user_id" required type="number" min="1" class="block rounded border p-2 dark:bg-gray-900"></label>
                        <label>Role
                            <select name="role" class="block rounded border p-2 dark:bg-gray-900">
                                <option value="employee">Employee</option>
                                <option value="manager">Manager</option>
                                <option value="hr_admin">HR administrator</option>
                            </select>
                        </label>
                        <button class="rounded bg-emerald-800 px-4 py-2 text-white">Grant access</button>
                    </form>
                @endif
            </article>
        @empty
            <p>There are no HR workspaces yet.</p>
        @endforelse
        {{ $workspaces->links() }}
    </div>
</x-admin-layout>
