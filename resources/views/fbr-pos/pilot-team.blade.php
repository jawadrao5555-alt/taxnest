<x-fbr-pos-layout>
<h1>{{ __('pos.team_management') }}</h1>
<p class="notice">{{ __('pilot_team.notice') }}</p>
@if(session('success'))<p role="status">{{ session('success') }}</p>@endif
@if($errors->any())<p role="alert" class="error">{{ $errors->first() }}</p>@endif
@if(!$readOnly)
<section class="card"><h2>{{ __('pos.add_team_member') }}</h2><form method="post" action="{{ route('fbrpos.team.store') }}">@csrf
<div class="grid"><label>{{ __('pos.full_name') }}<input name="name" required maxlength="100"></label><label>{{ __('pos.email_label') }}<input type="email" name="email" required maxlength="150"></label>
<label>{{ __('pos.username_label') }}<input name="username" autocomplete="off"></label><label>{{ __('pilot_team.new_password') }}<input type="password" name="password" required autocomplete="new-password" maxlength="100"></label></div>
<label>{{ __('pos.role_label') }}<select name="pos_role"><option value="pos_cashier">{{ __('pos.role_cashier') }}</option><option value="pos_manager">{{ __('pos.role_manager') }}</option></select></label>
<label>{{ __('pilot_team.campus') }}<select name="default_branch_id"><option value="">{{ __('pos.main_branch') }}</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></label>
<button type="submit" @disabled(!($quota['allowed']??true))>{{ __('pos.create_account') }}</button>@if(!($quota['allowed']??true))<p>{{ $quota['reason']??'' }}</p>@endif</form></section>
@endif
@foreach($team as $member)
<section class="card" data-pilot-team-member="{{ $member->id }}"><h2>{{ $member->name }}</h2><p>{{ $member->email }} · {{ $member->username }}</p>
<p>{{ $member->pos_role==='pos_admin' ? __('pos.owner_word') : ($member->pos_role==='pos_manager' ? __('pos.role_manager') : __('pos.role_cashier')) }} · {{ $member->is_active ? __('pos.active_word') : __('pos.inactive_word') }} · {{ $branches->firstWhere('id',$member->default_branch_id)?->name ?? __('pos.main_branch') }}</p>
@if(!$readOnly && $member->pos_role!=='pos_admin')
<details><summary>{{ __('pos.edit') }}</summary><form method="post" action="{{ route('fbrpos.team.update',$member->id) }}">@csrf @method('PUT')
<label>{{ __('pos.full_name') }}<input name="name" value="{{ $member->name }}" required maxlength="100"></label><label>{{ __('pos.email_label') }}<input type="email" name="email" value="{{ $member->email }}" required maxlength="150"></label><label>{{ __('pos.username_label') }}<input name="username" value="{{ $member->username }}" autocomplete="off"></label>
<label>{{ __('pilot_team.reset_password') }}<input type="password" name="password" autocomplete="new-password" maxlength="100"></label>
<label>{{ __('pos.role_label') }}<select name="pos_role"><option value="pos_cashier" @selected($member->pos_role==='pos_cashier')>{{ __('pos.role_cashier') }}</option><option value="pos_manager" @selected($member->pos_role==='pos_manager')>{{ __('pos.role_manager') }}</option></select></label>
<label>{{ __('pilot_team.campus') }}<select name="default_branch_id"><option value="">{{ __('pos.main_branch') }}</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((int)$member->default_branch_id===(int)$branch->id)>{{ $branch->name }}</option>@endforeach</select></label><button type="submit">{{ __('school.save') }}</button><button type="button" onclick="this.form.reset(); this.closest('details').open = false">{{ __('pos.cancel') }}</button></form>
<form method="post" action="{{ route('fbrpos.team.toggle',$member->id) }}">@csrf<button type="submit">{{ $member->is_active ? __('pos.deactivate') : __('pos.activate') }}</button></form></details>
@endif
</section>
@endforeach
</x-fbr-pos-layout>
