<h3>{{ $notice->title }}</h3>
<p>{{ $notice->campus_name ?? __('school_announcement.all_campuses') }}</p>
<p class="school-announcement-text">{{ $notice->body }}</p>
@if($notice->expires_on)
    <p>{{ __('school_announcement.visible_through', ['date' => $notice->expires_on]) }}</p>
@endif
