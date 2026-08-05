{{-- DB-DOWN friendly page (Aug 2026). Rendered when MySQL is unreachable
     (shared-host evening blips) instead of a raw 500/stack. MUST stay fully
     self-contained: NO auth()/session/DB/__() calls — the database is DOWN
     while this renders. Auto-retries via meta refresh. --}}
<!DOCTYPE html>
<html lang="ur">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="12">
    <title>Server thori der mein wapas — TaxNest</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; background: #f9fafb; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; color: #111827; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 40px 32px; max-width: 440px; width: 100%; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
        .icon { width: 64px; height: 64px; border-radius: 50%; background: #fef3c7; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 30px; }
        h1 { font-size: 19px; font-weight: 800; margin-bottom: 10px; }
        p { font-size: 14px; color: #6b7280; line-height: 1.7; }
        .hint { margin-top: 6px; font-size: 12.5px; color: #9ca3af; }
        .spin { display: inline-block; width: 14px; height: 14px; border: 2px solid #e5e7eb; border-top-color: #d97706; border-radius: 50%; animation: s .8s linear infinite; vertical-align: -2px; margin-right: 6px; }
        @keyframes s { to { transform: rotate(360deg); } }
        button { margin-top: 22px; background: #7c3aed; color: #fff; border: 0; border-radius: 10px; padding: 11px 26px; font-size: 14px; font-weight: 700; cursor: pointer; }
        button:hover { background: #6d28d9; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">&#9888;&#65039;</div>
        <h1>Server thori der ke liye masroof hai</h1>
        <p>Maazrat &mdash; system waqti tor par jawab nahi de raha. Aap ka koi data zaya nahi hua. Barah-e-karam thori der mein dobara koshish karein.</p>
        <p class="hint"><span class="spin"></span>Yeh page 12 second mein khud dobara koshish karega&hellip;</p>
        <button onclick="location.reload()">Abhi dobara koshish karein</button>
    </div>
</body>
</html>
