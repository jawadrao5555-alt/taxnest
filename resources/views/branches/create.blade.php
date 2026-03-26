<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">Add Branch</h2>
            <a href="/branches" class="text-sm text-gray-600 hover:text-gray-800 dark:text-gray-100">Back to Branches</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">

            @if($errors->any())
            <div class="mb-4 bg-red-50 border border-red-200 rounded-2xl p-4">
                <ul class="list-disc list-inside text-sm text-red-700">
                    @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
            @endif

            <form method="POST" action="/branches" class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-100 dark:border-gray-800 p-6 space-y-6">
                @csrf

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Branch Name <span class="text-red-500">*</span></label>
                    <input type="text" name="name" value="{{ old('name') }}" required class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Address</label>
                    <textarea name="address" rows="3" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-emerald-500 focus:border-emerald-500">{{ old('address') }}</textarea>
                </div>

                <div class="flex items-center gap-3">
                    <input type="hidden" name="is_head_office" value="0">
                    <input type="checkbox" name="is_head_office" value="1" id="is_head_office" {{ old('is_head_office') ? 'checked' : '' }} class="rounded border-gray-300 text-emerald-600 shadow-sm focus:ring-emerald-500">
                    <label for="is_head_office" class="text-sm font-medium text-gray-700 dark:text-gray-300">This is the Head Office</label>
                </div>

                <div class="flex justify-end space-x-3">
                    <a href="/branches" class="px-6 py-2.5 border border-gray-300 rounded-lg text-gray-700 dark:text-gray-300 font-medium hover:bg-gray-50 dark:hover:bg-gray-800 dark:bg-gray-800 transition">Cancel</a>
                    <button type="submit" class="px-6 py-2.5 bg-emerald-600 text-white rounded-lg font-medium hover:bg-emerald-700 transition">Create Branch</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
