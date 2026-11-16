<div class="p-3 text-xs space-y-2">
@if($v->release_version)<p>{{ __('tutorial_access.version') }}: {{ $v->release_version }} · {{ $v->reviewed_on }}</p>@endif
@if($v->transcript)<details><summary>{{ __('tutorial_access.transcript') }}</summary><p class="whitespace-pre-wrap">{{ $v->transcript }}</p></details>@endif
@if(!$v->caption_path)<p>{{ __('tutorial_access.captions_pending') }}</p>@endif
</div>
