<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Agent Portal' }} - TaxNest</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="h-full bg-gray-50 dark:bg-gray-950 text-gray-900 dark:text-gray-100">
<div class="min-h-full lg:flex">
    <aside class="lg:w-60 bg-white dark:bg-gray-900 border-b lg:border-b-0 lg:border-r border-gray-200 dark:border-gray-800 p-5">
        <h1 class="text-xl font-bold text-indigo-600">TaxNest Agent</h1>
        <p class="text-xs text-gray-500 mt-1 mb-6">{{ auth('agent')->user()->name }}</p>
        <nav class="flex lg:flex-col gap-2 text-sm overflow-x-auto">
            <a class="px-3 py-2 rounded hover:bg-indigo-50 dark:hover:bg-gray-800" href="{{ route('agent.dashboard') }}">Dashboard</a>
            <a class="px-3 py-2 rounded hover:bg-indigo-50 dark:hover:bg-gray-800" href="{{ route('agent.companies') }}">Companies</a>
            <a class="px-3 py-2 rounded hover:bg-indigo-50 dark:hover:bg-gray-800" href="{{ route('agent.commissions') }}">Commissions</a>
            <a class="px-3 py-2 rounded hover:bg-indigo-50 dark:hover:bg-gray-800" href="{{ route('agent.claims') }}">Sale Claims</a>
        </nav>
        <form method="POST" action="{{ route('agent.logout') }}" class="mt-6">@csrf
            <button class="text-sm text-red-500">Logout</button>
        </form>
    </aside>
    <main class="flex-1 p-4 sm:p-8 overflow-x-hidden">
        @if(session('success'))<div class="mb-4 rounded-lg bg-emerald-100 text-emerald-800 px-4 py-3 text-sm">{{ session('success') }}</div>@endif
        @if(session('error'))<div class="mb-4 rounded-lg bg-red-100 text-red-800 px-4 py-3 text-sm">{{ session('error') }}</div>@endif
        @if($errors->any())<div class="mb-4 rounded-lg bg-red-100 text-red-800 px-4 py-3 text-sm">{{ $errors->first() }}</div>@endif
        {{ $slot }}
    </main>
</div>
</body>
</html>