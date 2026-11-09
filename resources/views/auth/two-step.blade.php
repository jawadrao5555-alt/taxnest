<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Two-step login — TaxNest</title>
<style>body{font:16px system-ui,sans-serif;background:#f2f7f6;color:#173c35;margin:0;padding:20px}main{max-width:560px;margin:30px auto;background:white;padding:24px;border-radius:12px}label{display:block;margin-top:16px}input{box-sizing:border-box;width:100%;padding:12px;margin:6px 0;border:1px solid #77988f;border-radius:6px;font:inherit}button,a.action{display:inline-block;padding:13px 18px;margin-top:12px;background:#006b55;color:white;border:0;border-radius:6px;font:inherit;text-decoration:none;cursor:pointer}button:focus-visible,input:focus-visible,a:focus-visible{outline:3px solid #bd7300;outline-offset:3px}code{overflow-wrap:anywhere}li{margin-bottom:10px}.error{padding:12px;background:#fff0ee;color:#8e1b12}h1{font-size:24px}p{line-height:1.5}</style></head>
<body><main><h1>Two-step login</h1>
@if($errors->any())<div class="error" role="alert">{{ $errors->first() }}</div>@endif
@if($challenge)
<p>Enter a fresh code from your authenticator app, or one unused recovery code.</p>
<form method="POST" action="{{ route('two-step.verify',['panel'=>$panel]) }}">@csrf<label for="code">Authenticator or recovery code</label><input id="code" name="code" autocomplete="one-time-code" maxlength="64" required autofocus><button type="submit">Verify login</button></form>
@else
<p>Status: <strong>{{ $enabled ? 'Enabled' : 'Off' }}</strong>. This setting protects this account's login.</p>
@if($recoveryCodes)
<section aria-labelledby="recovery-title"><h2 id="recovery-title">Save your recovery codes</h2><p>Keep these somewhere safe, outside this account. Each code works once. They will not be displayed again.</p><ul>@foreach($recoveryCodes as $code)<li><code>{{ $code }}</code></li>@endforeach</ul></section>
@endif
@if(!$enabled && !$secret)
<p>To enable, add TaxNest to an authenticator app, confirm its code and save the recovery codes. Your password is required to start.</p>
<form method="POST" action="{{ route('two-step.begin',['panel'=>$panel]) }}">@csrf<label for="password">Current password</label><input id="password" type="password" name="password" autocomplete="current-password" required><button type="submit">Set up two-step login</button></form>
@elseif(!$enabled)
<p>In your authenticator app, add a time-based account named TaxNest. Enter this setup key manually:</p><p><code data-two-step-setup-key>{{ $secret }}</code></p><p>Confirm the current six-digit code within ten minutes to finish enabling.</p>
<form method="POST" action="{{ route('two-step.confirm',['panel'=>$panel]) }}">@csrf<label for="code">Authenticator code</label><input id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" required><button type="submit">Confirm and enable</button></form>
@else
<p>To turn this off, enter your password and a fresh authenticator code or unused recovery code.</p>
<form method="POST" action="{{ route('two-step.disable',['panel'=>$panel]) }}">@csrf<label for="password">Current password</label><input id="password" type="password" name="password" autocomplete="current-password" required><label for="code">Authenticator or recovery code</label><input id="code" name="code" maxlength="64" autocomplete="one-time-code" required><button type="submit">Turn off two-step login</button></form>
@endif
@endif
@if(session()->has('impersonation'))
<form method="POST" action="{{ route('saas.admin.impersonation.stop') }}">@csrf<button type="submit">Exit company access</button></form>
@elseif(session()->has('consultant_console'))
<form method="POST" action="{{ route('consultant.exit') }}">@csrf<button type="submit">Exit client access</button></form>
@else
<form method="POST" action="{{ route('two-step.logout',['panel'=>$panel]) }}">@csrf<button type="submit">Sign out of this account</button></form>
@endif
</main></body></html>
