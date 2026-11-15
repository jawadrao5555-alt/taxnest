<form method="GET" class="mb-5 flex flex-wrap gap-2 items-end" role="search">
<label for="tutorial-search">{{ __('tutorial_access.search') }}<input id="tutorial-search" name="q" maxlength="120" value="{{ request('q') }}" class="block rounded-lg border-gray-300 dark:bg-gray-800"></label>
<button type="submit" class="rounded-lg border p-2">{{ __('tutorial_access.search') }}</button>
@if(request('q'))<a href="{{ request()->url() }}">{{ __('tutorial_access.clear') }}</a>@endif
</form>
