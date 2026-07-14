{{--
PWA Install — navigation/dropdown menu item. Always visible (unlike the header pill,
which only appears when beforeinstallprompt fires).
Behavior on click:
  1) Already running as installed app → row shows "App Installed" (inert).
  2) Native install prompt available → triggers it (via <x-pwa-init /> tnPwaPromptInstall).
  3) Otherwise → opens a platform-aware instructions modal (iOS Safari / iOS other browser / Android / Desktop).
Safe to include MULTIPLE times per page (unique ids per include).
Requires <x-pwa-init /> once on the page (all three product layouts already have it).
Usage: <x-pwa-install-menu-item color="purple" app-name="Nest POS" item-class="menu-link flex items-center gap-2.5 px-4 py-2 ..." />
--}}
@props(['color' => 'emerald', 'label' => 'Install App', 'appName' => 'TaxNest', 'itemClass' => 'flex items-center gap-2 px-4 py-2 text-sm text-gray-700'])
@php
    $palette = [
        'emerald' => '#059669',
        'purple'  => '#7c3aed',
        'blue'    => '#1d4ed8',
        'teal'    => '#0d9488',
    ];
    $accent = $palette[$color] ?? $palette['emerald'];
    $uid = 'tnPwaMi' . str_replace('.', '', uniqid('', true));
@endphp
<a href="#" id="{{ $uid }}" class="{{ $itemClass }}">
    <svg class="w-4 h-4 opacity-70 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 4v12m0 0l-4-4m4 4l4-4m-9 8h10"/></svg>
    <span data-role="label">{{ $label }}</span>
    <span data-role="check" style="display:none; margin-left:auto; color:#059669; font-weight:700; font-size:10px;">&#10003;</span>
</a>

<div id="{{ $uid }}Modal" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,0.7); backdrop-filter:blur(6px); align-items:center; justify-content:center; padding:16px;">
    <div style="background:#fff; border-radius:18px; max-width:400px; width:100%; padding:24px; box-shadow:0 30px 70px rgba(0,0,0,0.45); position:relative;">
        <button type="button" data-role="close" style="position:absolute; top:12px; right:12px; width:32px; height:32px; border-radius:50%; background:#f3f4f6; border:none; font-size:18px; cursor:pointer; color:#6b7280;">&times;</button>
        <div style="text-align:center; margin-bottom:16px;">
            <div style="width:60px; height:60px; margin:0 auto 12px; border-radius:14px; background:{{ $accent }}; display:flex; align-items:center; justify-content:center;">
                <svg width="30" height="30" fill="none" stroke="white" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4m-9 8h10"/></svg>
            </div>
            <h3 style="font-size:17px; font-weight:800; color:#111827; margin:0;">Install {{ $appName }}</h3>
            <p style="font-size:12.5px; color:#6b7280; margin:5px 0 0;">Get the full app experience &mdash; opens like a real app, no browser bar.</p>
        </div>

        <div data-sec="ios" style="display:none;">
            <ol style="font-size:13.5px; color:#374151; padding:0; list-style:none; margin:0;">
                <li style="display:flex; gap:11px; margin-bottom:12px; align-items:flex-start;"><span style="flex-shrink:0; width:26px; height:26px; border-radius:50%; background:{{ $accent }}; color:#fff; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700;">1</span><span>Tap the <strong>Share</strong> button at the bottom of Safari.</span></li>
                <li style="display:flex; gap:11px; margin-bottom:12px; align-items:flex-start;"><span style="flex-shrink:0; width:26px; height:26px; border-radius:50%; background:{{ $accent }}; color:#fff; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700;">2</span><span>Scroll down and tap <strong>Add to Home Screen</strong>.</span></li>
                <li style="display:flex; gap:11px; align-items:flex-start;"><span style="flex-shrink:0; width:26px; height:26px; border-radius:50%; background:{{ $accent }}; color:#fff; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700;">3</span><span>Tap <strong>Add</strong> in the top-right corner.</span></li>
            </ol>
        </div>

        <div data-sec="ios-other" style="display:none;">
            <p style="font-size:13.5px; color:#374151; margin:0;">On iPhone/iPad, apps can only be installed from <strong>Safari</strong>. Open this page in Safari, tap <strong>Share</strong>, then <strong>Add to Home Screen</strong>.</p>
        </div>

        <div data-sec="android" style="display:none;">
            <ol style="font-size:13.5px; color:#374151; padding:0; list-style:none; margin:0;">
                <li style="display:flex; gap:11px; margin-bottom:12px; align-items:flex-start;"><span style="flex-shrink:0; width:26px; height:26px; border-radius:50%; background:{{ $accent }}; color:#fff; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700;">1</span><span>Tap the <strong>&#8942;</strong> menu in the top-right of Chrome.</span></li>
                <li style="display:flex; gap:11px; margin-bottom:12px; align-items:flex-start;"><span style="flex-shrink:0; width:26px; height:26px; border-radius:50%; background:{{ $accent }}; color:#fff; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700;">2</span><span>Tap <strong>Install app</strong> (or <strong>Add to Home screen</strong>).</span></li>
                <li style="display:flex; gap:11px; align-items:flex-start;"><span style="flex-shrink:0; width:26px; height:26px; border-radius:50%; background:{{ $accent }}; color:#fff; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700;">3</span><span>Confirm with <strong>Install</strong>.</span></li>
            </ol>
        </div>

        <div data-sec="desktop" style="display:none;">
            <ol style="font-size:13.5px; color:#374151; padding:0; list-style:none; margin:0;">
                <li style="display:flex; gap:11px; margin-bottom:12px; align-items:flex-start;"><span style="flex-shrink:0; width:26px; height:26px; border-radius:50%; background:{{ $accent }}; color:#fff; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700;">1</span><span>Look for the <strong>install icon</strong> at the right end of the address bar (a screen with a down arrow).</span></li>
                <li style="display:flex; gap:11px; margin-bottom:12px; align-items:flex-start;"><span style="flex-shrink:0; width:26px; height:26px; border-radius:50%; background:{{ $accent }}; color:#fff; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700;">2</span><span>Or open the browser <strong>&#8942;</strong> menu and choose <strong>Install {{ $appName }}&hellip;</strong> / <strong>Apps &rarr; Install</strong>.</span></li>
                <li style="display:flex; gap:11px; align-items:flex-start;"><span style="flex-shrink:0; width:26px; height:26px; border-radius:50%; background:{{ $accent }}; color:#fff; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700;">3</span><span>Confirm with <strong>Install</strong> &mdash; the app opens in its own window.</span></li>
            </ol>
            <p style="font-size:11.5px; color:#9ca3af; margin:12px 0 0;">Works in Chrome and Edge. Firefox/Safari on desktop don't support app installs.</p>
        </div>

        <button type="button" data-role="close" style="margin-top:18px; width:100%; padding:10px; border-radius:10px; border:none; background:#f3f4f6; color:#6b7280; font-size:13px; font-weight:600; cursor:pointer;">Close</button>
    </div>
</div>

<script>
(function(){
    const btn = document.getElementById('{{ $uid }}');
    const modal = document.getElementById('{{ $uid }}Modal');
    if (!btn || !modal) return;

    // Portal the modal to <body> — it's rendered inside a dropdown that closes
    // (display:none) when clicked, which would hide the modal with it.
    document.body.appendChild(modal);

    const labelEl = btn.querySelector('[data-role="label"]');
    const checkEl = btn.querySelector('[data-role="check"]');
    function markInstalled(){
        if (labelEl) labelEl.textContent = 'App Installed';
        if (checkEl) checkEl.style.display = 'inline';
        btn.setAttribute('data-installed', '1');
    }

    const standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;
    if (standalone) markInstalled();
    document.addEventListener('tn-pwa-installed', markInstalled);

    const ua = navigator.userAgent || '';
    const isIos = /iPad|iPhone|iPod/.test(ua) && !window.MSStream;
    const isIosSafari = isIos && /Safari/.test(ua) && !/CriOS|FxiOS|EdgiOS/.test(ua);
    const isAndroid = /Android/.test(ua);

    btn.addEventListener('click', async function(e){
        e.preventDefault();
        if (btn.getAttribute('data-installed') === '1') return;
        // Native one-tap install when the browser offers it (Chrome/Edge/Android).
        if (!isIos && window.tnPwaCanInstall && typeof window.tnPwaPromptInstall === 'function') {
            const outcome = await window.tnPwaPromptInstall();
            if (outcome === 'accepted') { markInstalled(); return; }
            if (outcome) return; // user saw and dismissed the native prompt — don't stack a modal on top
        }
        // Fallback: platform-specific how-to.
        modal.querySelectorAll('[data-sec]').forEach(function(el){ el.style.display = 'none'; });
        let sec = 'desktop';
        if (isIosSafari) sec = 'ios';
        else if (isIos) sec = 'ios-other';
        else if (isAndroid) sec = 'android';
        const active = modal.querySelector('[data-sec="' + sec + '"]');
        if (active) active.style.display = 'block';
        modal.style.display = 'flex';
    });

    modal.addEventListener('click', function(e){
        if (e.target === modal || (e.target.closest && e.target.closest('[data-role="close"]'))) modal.style.display = 'none';
    });
})();
</script>
