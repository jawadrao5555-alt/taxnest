<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') | Nest HR</title>
    <style>
        :root{font:16px system-ui,sans-serif;color:#16222b;background:#f3f6f7}*{box-sizing:border-box}body{margin:0}a{color:#165e56}header{background:#123733;color:#fff}header a{color:#fff}header nav,main{max-width:76rem;margin:auto;padding:1rem}nav, nav form,.actions,.filters{display:flex;gap:1rem;align-items:center;flex-wrap:wrap}nav strong{margin-right:auto}h1,h2{line-height:1.15}h1{font-size:clamp(1.8rem,5vw,2.6rem)}h2{font-size:1.35rem;margin-top:0}section,.notice{margin:1rem 0;background:#fff;border:1px solid #d4e1df;border-radius:.55rem;padding:1.15rem}.login{max-width:28rem;margin:2rem auto}label{display:flex;flex-direction:column;gap:.3rem;font-weight:600}input,select,button{font:inherit;padding:.55rem .7rem;border:1px solid #738681;border-radius:.35rem;max-width:100%}button{background:#185b53;color:#fff;border-color:#185b53;cursor:pointer}button:focus-visible,a:focus-visible,input:focus-visible,select:focus-visible{outline:3px solid #bb6200;outline-offset:2px}.fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(13rem,1fr));gap:.75rem}.fields+.actions,.fields+button{margin-top:1rem}.login .fields{grid-template-columns:1fr}.table{overflow:auto}table{border-collapse:collapse;width:100%;text-align:left}caption{text-align:left;font-weight:bold}th,td{padding:.65rem;border-bottom:1px solid #d4e1df;vertical-align:top}td p{margin:0}td form{margin-top:.65rem;min-width:11.75rem}.notice p{margin:.25rem 0}.error{border-color:#b03d37;color:#892d29}.success{border-color:#188267;color:#13624f}.filters{align-items:end;margin-bottom:1rem}small{color:#395650}details{margin:1rem 0}summary{cursor:pointer}.table button{white-space:nowrap}.pagination{margin-top:1rem}.pagination svg{width:1rem;height:1rem}.pagination nav>div{display:flex;flex-wrap:wrap;gap:.5rem}
    </style>
</head>
<body>
    <header>
        <nav aria-label="Nest HR navigation">
            <strong>Nest HR</strong>
            @auth('hr')
                <a href="{{ route('hr.dashboard') }}">Workspaces</a>
                <a href="{{ route('two-step.settings', ['panel' => 'hr']) }}">Two-step verification</a>
                <form method="POST" action="{{ route('hr.logout') }}">@csrf <button type="submit">Sign out</button></form>
            @endauth
        </nav>
    </header>
    <main>
        @if (session('status'))
            <p class="notice success" role="status">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <div class="notice error" role="alert">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif
        @yield('content')
    </main>
</body>
</html>
