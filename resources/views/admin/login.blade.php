<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Login - TaxNest</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="h-full bg-gray-950 flex items-center justify-center">
    <div class="w-full max-w-md px-6">
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-indigo-400">TaxNest</h1>
            <p class="text-gray-500 mt-1">Super Admin Panel</p>
        </div>

        <div class="bg-gray-900 border border-gray-800 rounded-2xl p-8">
            <h2 class="text-xl font-bold text-white mb-6">Admin Login</h2>

            @if($errors->any())
            <div class="mb-4 bg-red-900/30 border border-red-700 text-red-300 rounded-lg px-4 py-3 text-sm">
                {{ $errors->first() }}
            </div>
            @endif

            <form method="POST" action="/admin/login" class="space-y-5">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1.5">Email</label>
                    <input type="email" name="email" value="{{ old('email') }}" required autofocus class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white px-4 py-2.5 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition placeholder-gray-500" placeholder="admin@taxnest.com">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1.5">Password</label>
                    <input type="password" name="password" required class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white px-4 py-2.5 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition placeholder-gray-500" placeholder="Enter password">
                </div>
                <div class="flex items-center">
                    <input type="checkbox" name="remember" id="remember" class="rounded bg-gray-800 border-gray-600 text-indigo-500 focus:ring-indigo-500">
                    <label for="remember" class="ml-2 text-sm text-gray-400">Remember me</label>
                </div>
                <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2.5 rounded-lg transition text-sm">Sign In</button>
            </form>
        </div>
    </div>
</body>
</html>
