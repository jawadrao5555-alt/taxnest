<x-admin-layout>
    <x-slot name="header"><h1 class="text-xl font-bold">{{ $workspace->name }} memberships</h1></x-slot>
    <div class="mx-auto max-w-6xl space-y-4 px-4 py-6">
        <p><a class="underline" href="{{ route('admin.hr.index') }}">Nest HR workspaces</a></p>
        @if (session('status'))
            <p role="status" class="text-emerald-700">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <ul role="alert" class="text-red-700">
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        @endif
        @forelse ($members as $member)
            <article class="space-y-2 rounded-lg border bg-white p-4 dark:bg-gray-800">
                <h2 class="font-bold">{{ $member->user->name }}</h2>
                <p>{{ $member->user->email }} · {{ ucfirst(str_replace('_', ' ', $member->role)) }} · {{ $member->active ? 'Access granted' : 'Access revoked' }}</p>
                @if ($member->role !== 'owner' && $workspace->status === 'active' && ($member->active || $member->user->is_active))
                    <form method="POST" action="{{ route('admin.hr.members.update', ['organization' => $workspace->id, 'member' => $member->id]) }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="active" value="{{ $member->active ? 0 : 1 }}">
                        <button class="rounded bg-emerald-800 px-4 py-2 text-white">{{ $member->active ? 'Revoke access' : 'Reactivate access' }}</button>
                    </form>
                @endif
            </article>
        @empty
            <p>There are no members in this workspace.</p>
        @endforelse
        {{ $members->links() }}
    </div>
</x-admin-layout>
