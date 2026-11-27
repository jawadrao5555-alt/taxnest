<x-school-erp-layout :title="__('school_announcement.title')">
    <h1 class="school-title">{{ __('school_announcement.title') }}</h1>
    @if($canPublish)
        <section class="school-card">
            <h2>{{ __('school_announcement.publish') }}</h2>
            <p>{{ __('school_announcement.audience_hint') }}</p>
            <form class="school-form" method="POST" action="{{ route('school-erp.announcements.publish') }}">
                @csrf
                <input type="hidden" name="publication_key" value="{{ old('publication_key', (string) \Illuminate\Support\Str::uuid()) }}">
                <label>{{ __('school_announcement.subject') }}<input name="title" maxlength="120" value="{{ old('title') }}" required></label>
                <label>{{ __('school_announcement.message') }}<textarea name="body" rows="5" maxlength="2000" required>{{ old('body') }}</textarea></label>
                <label>{{ __('school_announcement.audience') }}
                    <select name="campus_id">
                        <option value="">{{ __('school_announcement.all_campuses') }}</option>
                        @foreach($campuses as $campus)
                            <option value="{{ $campus->id }}" @selected((string) old('campus_id') === (string) $campus->id)>{{ $campus->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label>{{ __('school_announcement.expires_on') }}
                    <input type="date" name="expires_on" min="{{ now()->toDateString() }}" value="{{ old('expires_on') }}">
                </label>
                <button class="school-button" type="submit">{{ __('school_announcement.publish') }}</button>
            </form>
        </section>
    @endif

    @forelse($notices as $notice)
        <article class="school-card">
            @include('school-erp.announcement-content')
            @if($notice->withdrawn_at)
                <p>{{ __('school_announcement.withdrawn') }}</p>
            @else
                <form method="POST" action="{{ route('school-erp.announcements.withdraw', $notice->id) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="school-button">{{ __('school_announcement.withdraw') }}</button>
                </form>
            @endif
        </article>
    @empty
        <p>{{ __('school_announcement.none') }}</p>
    @endforelse
    {{ $notices->links() }}
</x-school-erp-layout>
