'use strict';
/*!
 * NestPOS LAN — the human-facing door of the shop PC's little server.
 *
 * WHY THIS EXISTS (owner, Aug 2026):
 * The agent window tells the shop "Tablet par yeh address kholein:
 * http://192.168.1.17:8531 — Pairing code: 144038". Until now that address
 * answered raw JSON ({"ok":false,"error":"pair_required"}), so no tablet could
 * ever pair and the owner saw a broken-looking error. This module is the page
 * that address serves instead.
 *
 * DESIGN RULES (same spirit as lan-server.js):
 *  1. PURE NODE, no Electron, no build step, no npm deps — the page is one
 *     self-contained HTML string so it stays testable with plain `node`.
 *  2. SAME-ORIGIN ONLY. No CDN font, no external script, no remote image.
 *     Everything (CSS + JS + icon) is inline, so the CSP below can stay tight
 *     and a tablet with no internet still renders the page perfectly.
 *  3. NOTHING ABOUT THE SHOP BEFORE PAIRING. No shop name, no device count and
 *     never the pairing code itself — an unpaired device must learn nothing.
 *  4. ROMAN URDU, same voice as the agent window.
 */

const BRAND_ICON =
    'data:image/svg+xml;utf8,' +
    encodeURIComponent(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">' +
        '<rect width="64" height="64" rx="14" fill="#4c1d95"/>' +
        '<path d="M18 44V20l14 15V20h5v24h-5L23 29v15z" fill="#c4b5fd"/>' +
        '</svg>'
    );

const BASE_CSS = `
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{
  background:#1e1b4b;color:#ede9fe;
  font-family:'Segoe UI',system-ui,-apple-system,Roboto,Arial,sans-serif;
  font-size:15px;line-height:1.6;-webkit-text-size-adjust:100%;
  min-height:100vh;padding:18px 14px 34px;
}
.wrap{max-width:430px;margin:0 auto}
.brand{display:flex;align-items:center;gap:10px;margin-bottom:16px}
.brand img{width:34px;height:34px;border-radius:9px}
.brand b{font-size:17px;letter-spacing:.3px}
.brand span{display:block;font-size:11px;color:#a5b4fc;font-weight:400}
.card{background:#2e1065;border:1px solid #4c1d95;border-radius:14px;padding:16px;margin-bottom:14px}
h1{font-size:17px;margin:0 0 6px}
p{margin:0 0 12px;font-size:13px;color:#c4b5fd}
label{display:block;font-size:12px;color:#c4b5fd;margin:12px 0 5px}
input,select{
  width:100%;padding:11px 12px;border-radius:10px;border:1px solid #6d28d9;
  background:#1e1b4b;color:#fff;font-size:15px;font-family:inherit;outline:none;
}
input:focus,select:focus{border-color:#a78bfa}
#code{font-size:28px;letter-spacing:10px;text-align:center;padding:12px 0;font-weight:700}
button{
  width:100%;margin-top:16px;padding:13px;border:0;border-radius:10px;
  background:#7c3aed;color:#fff;font-size:15px;font-weight:600;font-family:inherit;cursor:pointer;
}
button:disabled{opacity:.55;cursor:default}
button.ghost{background:transparent;border:1px solid #6d28d9;color:#c4b5fd;font-weight:500;font-size:13px;padding:10px}
.msg{margin-top:14px;padding:11px 12px;border-radius:10px;font-size:13px;display:none}
.msg.bad{display:block;background:#450a0a;border:1px solid #7f1d1d;color:#fecaca}
.msg.good{display:block;background:#052e16;border:1px solid #14532d;color:#bbf7d0}
.rows{margin:0;padding:0;list-style:none}
.rows li{display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid #4c1d95;font-size:13px}
.rows li:last-child{border-bottom:0}
.rows .k{color:#a5b4fc}
.rows .v{color:#fff;text-align:right;word-break:break-word}
.ok{color:#34d399}
.warn{color:#fca5a5}
.calls{margin:0;padding:0;list-style:none}
.calls li{padding:9px 0;border-bottom:1px solid #4c1d95}
.calls li:last-child{border-bottom:0}
.calls .num{font-size:15px;color:#fff;font-weight:600;direction:ltr}
.calls .meta{font-size:11px;color:#a5b4fc}
.empty{font-size:13px;color:#a5b4fc;padding:6px 0}
.foot{font-size:11px;color:#8b83c9;text-align:center;margin-top:16px}
.hide{display:none}
`;

function shell(bodyHtml, extraHead) {
    return '<!doctype html>\n' +
        '<html lang="en"><head>' +
        '<meta charset="utf-8">' +
        '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">' +
        '<meta name="robots" content="noindex,nofollow">' +
        '<meta name="theme-color" content="#1e1b4b">' +
        '<title>NestPOS</title>' +
        '<link rel="icon" href="' + BRAND_ICON + '">' +
        '<style>' + BASE_CSS + '</style>' +
        (extraHead || '') +
        '</head><body><div class="wrap">' +
        '<div class="brand"><img alt="" src="' + BRAND_ICON + '">' +
        '<b>NestPOS<span>Shop ka local server</span></b></div>' +
        bodyHtml +
        '<div class="foot">NestPOS — TaxNest</div>' +
        '</div></body></html>';
}

/**
 * A dead-end page: one short human sentence, no shop data, no JavaScript.
 * Used for "LAN Mode band hai" and "yeh address sirf shop ke WiFi se khulta hai".
 */
function messagePage(heading, sentence, hint) {
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
    return shell(
        '<div class="card">' +
        '<h1>' + esc(heading) + '</h1>' +
        '<p style="margin-bottom:0">' + esc(sentence) + '</p>' +
        (hint ? '<p style="margin:10px 0 0;color:#8b83c9">' + esc(hint) + '</p>' : '') +
        '</div>'
    );
}

/* The pairing + status app. One page, three views, driven by a token the
 * device keeps in its own localStorage. Written without template literals on
 * purpose so this file can itself live inside one. */
const APP_JS = `
(function () {
  var KEY = 'nestpos_lan_token';
  var token = '';
  try { token = localStorage.getItem(KEY) || ''; } catch (e) { token = ''; }

  function $(id) { return document.getElementById(id); }
  function show(id) {
    ['viewPair', 'viewStatus', 'viewLoading'].forEach(function (v) {
      $(v).className = (v === id) ? '' : 'hide';
    });
  }
  function say(el, text, good) {
    el.textContent = text;
    el.className = text ? ('msg ' + (good ? 'good' : 'bad')) : 'msg';
  }
  function saveToken(t) {
    token = t || '';
    try { if (token) { localStorage.setItem(KEY, token); } else { localStorage.removeItem(KEY); } } catch (e) {}
  }
  function auth() { return { 'X-Lan-Token': token }; }

  // Short, human sentences — a tablet owner must never see a JSON error code.
  function reason(status, code) {
    if (code === 'bad_code') { return 'Code ghalat hai. Agent window par jo naya code likha hai wohi daalein.'; }
    if (code === 'too_many_attempts' || status === 429) {
      return 'Bohat zyada ghalat koshishein ho gayi hain. Taqreeban 10 minute baad dobara koshish karein.';
    }
    if (code === 'lan_only' || status === 403) { return 'Yeh address sirf shop ke apne WiFi se khulta hai.'; }
    if (status === 401) { return 'Yeh device pair nahi hai. Naya code daal kar dobara jorein.'; }
    return 'Kuch masla ho gaya. Thori dair baad dobara koshish karein.';
  }
  var OFFLINE = 'Shop ke PC se rabta nahi ho raha. Dekhein ke wohi WiFi juda hai aur PC par NestPOS agent chal raha hai.';

  function api(path, opts) {
    return fetch(path, opts || {}).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (body) {
        return { status: res.status, body: body || {} };
      });
    });
  }

  /* ---- pairing ---- */

  function pair(ev) {
    if (ev) { ev.preventDefault(); }
    var code = ($('code').value || '').replace(/[^0-9]/g, '');
    var name = ($('device').value || '').trim();
    var kind = $('kind').value;
    var box = $('pairMsg');
    if (code.length !== 6) { say(box, 'Poora 6 hindson ka code daalein.'); return; }
    if (!name) { say(box, 'Is device ka naam likhein (masalan: Waiter Tablet 1).'); return; }
    $('pairBtn').disabled = true;
    say(box, '');
    api('/lan/pair', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ code: code, device: name, kind: kind })
    }).then(function (r) {
      $('pairBtn').disabled = false;
      if (r.status === 200 && r.body.token) {
        saveToken(r.body.token);
        say(box, 'Ho gaya! Yeh device ab shop ke PC se juda hua hai.', true);
        $('code').value = '';
        setTimeout(boot, 700);
        return;
      }
      say(box, reason(r.status, r.body.error));
      $('code').value = '';
      $('code').focus();
    }).catch(function () {
      $('pairBtn').disabled = false;
      say(box, OFFLINE);
    });
  }

  function askToPair(note) {
    show('viewPair');
    var box = $('pairMsg');
    if (note) { say(box, note); } else { say(box, ''); }
    try {
      var last = localStorage.getItem('nestpos_lan_name');
      if (last && !$('device').value) { $('device').value = last; }
    } catch (e) {}
  }

  /* ---- status ---- */

  var timer = null;
  function stopTimer() { if (timer) { clearInterval(timer); timer = null; } }

  function refresh() {
    if (!token) { return; }
    api('/lan/whoami', { headers: auth() }).then(function (r) {
      if (r.status === 401) {
        stopTimer();
        saveToken('');
        askToPair('Yeh device shop ke PC se hata diya gaya hai. Naya code daal kar dobara jorein.');
        return;
      }
      if (r.status !== 200) { $('serverLine').innerHTML = '<span class="warn">Rabta nahi ho raha</span>'; return; }
      $('devName').textContent = r.body.name || '—';
      $('devKind').textContent = kindLabel(r.body.kind);
      try { localStorage.setItem('nestpos_lan_name', r.body.name || ''); } catch (e) {}
      $('serverLine').innerHTML = '<span class="ok">Theek chal raha hai</span>';
      return api('/lan/health').then(function (h) {
        $('agentVer').textContent = (h.body && h.body.version) ? ('v' + h.body.version) : '—';
      });
    }).catch(function () {
      $('serverLine').innerHTML = '<span class="warn">Rabta nahi ho raha</span>';
      $('callsBox').innerHTML = '<div class="empty">' + OFFLINE + '</div>';
    });

    api('/lan/caller/events?after=0', { headers: auth() }).then(function (r) {
      if (r.status !== 200 || !r.body.events) { return; }
      renderCalls(r.body.events);
    }).catch(function () {});
  }

  function kindLabel(k) {
    if (k === 'waiter') { return 'Waiter tablet'; }
    if (k === 'caller') { return 'Caller ID phone'; }
    if (k === 'counter') { return 'Counter / PC'; }
    return k || 'Device';
  }

  function clock(iso) {
    try {
      var d = new Date(iso);
      if (isNaN(d.getTime())) { return ''; }
      var h = d.getHours(), m = d.getMinutes();
      var ap = h >= 12 ? 'PM' : 'AM';
      h = h % 12; if (h === 0) { h = 12; }
      return h + ':' + (m < 10 ? '0' : '') + m + ' ' + ap;
    } catch (e) { return ''; }
  }

  function renderCalls(list) {
    var box = $('callsBox');
    if (!list.length) {
      box.innerHTML = '<div class="empty">Abhi tak is PC par koi call nahi aayi.</div>';
      return;
    }
    var html = '<ul class="calls">';
    list.slice().reverse().forEach(function (e) {
      var who = e.name ? String(e.name) : '';
      html += '<li><div class="num">' + esc(e.number || '') + '</div>' +
        '<div class="meta">' + (who ? esc(who) + ' &middot; ' : '') +
        esc(clock(e.at)) + ' &middot; ' + (e.source === 'whatsapp' ? 'WhatsApp' : 'SIM') +
        '</div></li>';
    });
    box.innerHTML = html + '</ul>';
  }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function unpair() {
    if (!confirm('Yeh device shop ke PC se hata dein? Dobara jorne ke liye naya code chahiye hoga.')) { return; }
    $('unpairBtn').disabled = true;
    api('/lan/unpair', { method: 'POST', headers: auth() }).catch(function () {}).then(function () {
      stopTimer();
      $('unpairBtn').disabled = false;
      saveToken('');
      askToPair('Yeh device hata diya gaya hai. Naya code daal kar dobara jorein.');
    });
  }

  /* ---- boot ---- */

  function boot() {
    stopTimer();
    if (!token) { askToPair(''); return; }
    show('viewLoading');
    api('/lan/whoami', { headers: auth() }).then(function (r) {
      if (r.status === 200) {
        show('viewStatus');
        refresh();
        timer = setInterval(function () {
          if (!document.hidden) { refresh(); }
        }, 5000);
        return;
      }
      if (r.status === 401) {
        saveToken('');
        askToPair('Yeh device ab pair nahi hai. Naya code daal kar dobara jorein.');
        return;
      }
      askToPair(reason(r.status, r.body.error));
    }).catch(function () {
      show('viewStatus');
      $('serverLine').innerHTML = '<span class="warn">Rabta nahi ho raha</span>';
      $('callsBox').innerHTML = '<div class="empty">' + OFFLINE + '</div>';
      timer = setInterval(function () { if (!document.hidden) { refresh(); } }, 5000);
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    $('pairForm').addEventListener('submit', pair);
    $('unpairBtn').addEventListener('click', unpair);
    $('code').addEventListener('input', function () {
      this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);
    });
    boot();
  });
})();
`;

const APP_BODY =
    '<div id="viewLoading"><div class="card"><p style="margin:0">Dekh rahe hain…</p></div></div>' +

    '<div id="viewPair" class="hide"><div class="card">' +
    '<h1>Is device ko shop ke PC se jorein</h1>' +
    '<p>Shop ke PC par NestPOS agent window kholein — wahan 6 hindson ka pairing code likha hota hai. ' +
    'Wohi code yahan daalein.</p>' +
    '<form id="pairForm" autocomplete="off">' +
    '<label for="code">Pairing code</label>' +
    '<input id="code" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" ' +
    'placeholder="------" autocomplete="one-time-code">' +
    '<label for="device">Is device ka naam</label>' +
    '<input id="device" name="device" maxlength="60" placeholder="Waiter Tablet 1">' +
    '<label for="kind">Yeh device kis kaam ke liye hai?</label>' +
    '<select id="kind" name="kind">' +
    '<option value="waiter">Waiter tablet</option>' +
    '<option value="caller">Caller ID phone</option>' +
    '<option value="counter">Counter / PC</option>' +
    '<option value="other">Koi aur device</option>' +
    '</select>' +
    '<button id="pairBtn" type="submit">Pair karein</button>' +
    '<div id="pairMsg" class="msg"></div>' +
    '</form>' +
    '</div>' +
    '<p style="font-size:11px;color:#8b83c9;text-align:center">Har device ke liye alag code banta hai. ' +
    'Ek baar pair hone ke baad yeh device dobara nahi poochhega.</p>' +
    '</div>' +

    '<div id="viewStatus" class="hide">' +
    '<div class="card">' +
    '<h1>Yeh device juda hua hai</h1>' +
    '<p style="margin-bottom:6px">Shop ke PC se seedha rabta chal raha hai — internet band ho tab bhi.</p>' +
    '<ul class="rows">' +
    '<li><span class="k">Device ka naam</span><span class="v" id="devName">—</span></li>' +
    '<li><span class="k">Kaam</span><span class="v" id="devKind">—</span></li>' +
    '<li><span class="k">Agent version</span><span class="v" id="agentVer">—</span></li>' +
    '<li><span class="k">Server</span><span class="v" id="serverLine">—</span></li>' +
    '</ul>' +
    '</div>' +
    '<div class="card">' +
    '<h1 style="font-size:15px">Is PC par aayi hui calls</h1>' +
    '<div id="callsBox"><div class="empty">Dekh rahe hain…</div></div>' +
    '</div>' +
    '<button id="unpairBtn" class="ghost" type="button">Is device ko hata dein (dobara pair karein)</button>' +
    '</div>';

function appPage() {
    return shell(APP_BODY, '') .replace('</body>', '<script>' + APP_JS + '</script></body>');
}

module.exports = {
    appPage: appPage,
    messagePage: messagePage,
};
