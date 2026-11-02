<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PRA + FBR DI sandbox — TaxNest</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f2f6f5;color:#17312d;font:15px/1.6 system-ui,sans-serif}main{max-width:1150px;margin:32px auto;padding:0 20px}h1{font-size:28px;line-height:1.2}h2{font-size:19px}a{color:#08685c}header{border-bottom:1px solid #cddbd7;padding-bottom:16px;margin-bottom:20px}.card{padding:24px;background:white;border:1px solid #d3dfdb;border-radius:12px;margin:16px 0}.notice{padding:16px;background:#fff4d6;border:1px solid #e6ca85;border-radius:8px}.success{padding:12px;background:#e3f4ec}.error{padding:12px;background:#ffeae6;color:#812c20}.columns{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:24px}.muted{color:#536860;font-size:13px}label{display:block;margin:14px 0 6px}input[type=checkbox]{width:18px;height:18px;vertical-align:middle;margin-right:7px}select,textarea{width:100%;padding:12px;border:1px solid #92aaa1;border-radius:6px;background:white;color:#183c31}textarea{font:12px/1.6 monospace;min-height:410px}button,.button{display:inline-block;border:0;border-radius:7px;padding:11px 18px;background:#075e51;color:white;font:inherit;text-decoration:none;cursor:pointer;margin:10px 8px 0 0}button:disabled{opacity:.5;cursor:not-allowed}table{border-collapse:collapse;width:100%;font-size:14px}th,td{text-align:left;border-bottom:1px solid #dce5e1;padding:10px 6px;vertical-align:top;overflow-wrap:anywhere}.scroll{overflow:auto}.badge{display:inline-block;padding:3px 10px;border:1px solid #9eb7ac;border-radius:5px;font-size:13px}dl{display:grid;grid-template-columns:1fr 1fr;gap:10px}dd{margin:0;text-align:right;overflow-wrap:anywhere}pre{white-space:pre-wrap;overflow-wrap:anywhere}@media(max-width:750px){.columns{grid-template-columns:1fr}main{padding:0 12px;margin:16px auto}.card{padding:16px}h1{font-size:24px}}
    </style>
</head>
<body><main>
    <header><a href="{{ route('pos.di-sandbox.index') }}">TaxNest PRA POS · Sandbox workbench</a><h1>@yield('title')</h1></header>
    <div class="notice"><strong>TEST DATA ONLY — PRODUCTION REPORTING DISABLED</strong><br>This rehearsal uses a local disposable database. FBR acceptance does not approve PRA tax treatment. No automatic exemption, zero rate or provincial-tax mapping has been selected.</div>
    @if(session('success'))<p class="success">{{ session('success') }}</p>@endif
    @if(session('error'))<p class="error" role="alert">{{ session('error') }}</p>@endif
    @if($errors->any())<div class="error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @yield('content')
</main></body></html>
