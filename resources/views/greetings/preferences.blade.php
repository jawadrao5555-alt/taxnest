<x-app-layout>
<main class="max-w-4xl mx-auto p-4 space-y-5">
<h1 class="text-xl font-bold">{{ __('greetings.title') }}</h1>
<p>Off by default. Email only. Enable only after recipients explicitly consent. No patient dates or contacts are imported. February 29 birthdays and anniversaries use February 28 in non-leap years. Delivery also requires the platform release gate and an available mail provider.</p>
@if($errors->any())<div role="alert" tabindex="-1">{{ $errors->first() }}</div>@endif
<form method="POST" action="{{ route('greetings.save') }}" class="space-y-3">@csrf
<input type="hidden" name="enabled" value="0"><label class="block"><input type="checkbox" name="enabled" value="1" @checked($preferences?->enabled)> Enable opted-in email greetings</label>
<label class="block">{{ __('greetings.timezone') }}<input name="timezone" value="{{ $preferences?->timezone ?? 'Asia/Karachi' }}" required class="block"></label>
@php($templates=$preferences ? json_decode($preferences->templates,true):[])
@foreach(\App\Services\CompanyGreetingService::EVENTS as $event)
<label class="block">{{ ucfirst($event) }} template<textarea name="templates[{{ $event }}]" maxlength="500" required class="block w-full">{{ $templates[$event] ?? 'Hello {name}, best wishes from {company}.' }}</textarea></label>
@endforeach
<p>Allowed placeholders: {name}, {company}. Template changes never resend a completed greeting.</p>
<button type="submit">{{ __('greetings.save') }}</button>
</form>
<form method="POST" action="{{ route('greetings.preview') }}">@csrf<label class="block">Preview template<input name="template" value="Hello {name}, best wishes from {company}." maxlength="500" required></label><button>{{ __('greetings.preview') }}</button></form>
<h2 class="font-bold">{{ __('greetings.recipients') }}</h2>
@foreach($recipients->concat([null]) as $recipient)
<form method="POST" action="{{ route('greetings.recipient') }}" class="border p-3 space-y-2">@csrf
@if($recipient)<input type="hidden" name="id" value="{{ $recipient->id }}">@endif
<label class="block">{{ __('greetings.name') }}<input name="name" value="{{ $recipient?->name }}" required maxlength="120"></label>
<label class="block">{{ __('greetings.email') }}<input type="email" name="email" value="{{ $recipient?->email }}" required></label>
<label class="block">{{ __('greetings.event') }}<select name="event">@foreach(\App\Services\CompanyGreetingService::EVENTS as $event)<option value="{{ $event }}" @selected($recipient?->event===$event)>{{ ucfirst($event) }}</option>@endforeach</select></label>
<label class="block">{{ __('greetings.date') }}<input type="date" name="event_date" value="{{ $recipient?->event_date }}" required></label>
<input type="hidden" name="email_consent" value="0"><label class="block"><input type="checkbox" name="email_consent" value="1" @checked($recipient?->email_consent)> Recipient explicitly consented to this event by email</label>
<button>{{ $recipient ? __('greetings.revoke') : __('greetings.add') }}</button>
</form>
@endforeach
<h2 class="font-bold">{{ __('greetings.audit') }}</h2>
<p>Unknown outcomes and interrupted sending remain on hold for reconciliation. They are never blindly retried. Provider-unavailable checks stop after three attempts for the occurrence.</p>
<ul>@foreach($deliveries as $delivery)<li>{{ $delivery->occurrence }} · {{ $delivery->event }} · {{ $delivery->status }} · {{ $delivery->reason }} · attempts {{ $delivery->attempts }}</li>@endforeach</ul>
</main>
</x-app-layout>
