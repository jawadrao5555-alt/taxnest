<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Agent Login - TaxNest</title><script src="https://cdn.tailwindcss.com"></script></head>
<body class="min-h-screen bg-gray-950 flex items-center justify-center p-6">
<div class="w-full max-w-md bg-gray-900 border border-gray-800 rounded-2xl p-8">
    <h1 class="text-3xl font-bold text-indigo-500 text-center">TaxNest</h1>
    <p class="text-gray-400 text-center mb-8">Distributor Agent Portal</p>
    @if($errors->any())<div class="mb-4 bg-red-900/30 text-red-300 rounded-lg p-3 text-sm">{{ $errors->first() }}</div>@endif
    @if(session('error'))<div class="mb-4 bg-red-900/30 text-red-300 rounded-lg p-3 text-sm">{{ session('error') }}</div>@endif
    <form method="POST" action="{{ route('agent.login.submit') }}" class="space-y-5">@csrf
        <div><label class="text-sm text-gray-300">Email</label><input type="email" name="email" value="{{ old('email') }}" required autofocus class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg text-white px-4 py-2.5"></div>
        <div><label class="text-sm text-gray-300">Password</label><input type="password" name="password" required class="mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg text-white px-4 py-2.5"></div>
        <label class="flex gap-2 text-sm text-gray-400"><input type="checkbox" name="remember"> Remember me</label>
        <button class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-lg py-2.5">Sign In</button>
    </form>
</div>
</body></html>