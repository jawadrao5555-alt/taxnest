# Cloudflare Setup Guide — taxnest.com.pk (Free Plan)

Maqsad: Pakistan se website tez kholna. Server (US) tez hai, lekin PK↔US network raat ko slow hota hai. Cloudflare ke Karachi/Lahore/Islamabad edge servers TLS aur static files (CSS/JS/images) Pakistan ke andar serve karenge.

Code side taiyar hai (visitor IP restore middleware deploy ho chuka hai). Ab sirf yeh steps aap ne karne hain:

## Step 1 — Cloudflare account + site add
1. https://dash.cloudflare.com par account banayen (free).
2. "Add a site" → `taxnest.com.pk` → **Free plan** chunain.
3. Cloudflare khud DNS records import karega. Check karen ke yeh sab mojood hain (cPanel → Zone Editor se compare karen):
   - `A` record: `taxnest.com.pk` → `66.29.138.229` — **Proxied (orange cloud ON)**
   - `CNAME/A` record: `www` → **Proxied (orange cloud ON)**
   - `MX` + `mail` records — **DNS only (grey cloud)** — warna email band ho jayegi!
   - `cpanel`, `webmail`, `ftp` jese records — **DNS only (grey cloud)**

## Step 2 — Nameservers change (Namecheap)
1. Cloudflare 2 nameservers dega (misal: `xxx.ns.cloudflare.com`).
2. Namecheap → Domain List → taxnest.com.pk → Manage → Nameservers → **Custom DNS** → dono Cloudflare nameservers dalen.
3. 5 min – 24 ghante lagta hai. Cloudflare dashboard par "Active" status ka intezar karen.

## Step 3 — Cloudflare settings (Active hone ke baad)
SSL/TLS tab:
- Mode: **Full (strict)** — cPanel par AutoSSL cert pehle se hai, yeh kaam karega.
- Edge Certificates → "Always Use HTTPS": **ON**

Speed tab — **YEH BOHOT ZAROORI HAI**:
- **Rocket Loader: OFF rakhen** (POS sale screen ke scripts kharab kar dega)
- **Auto Minify: sab OFF rakhen** (HTML/CSS/JS teeno)

Caching tab:
- Caching Level: Standard (default theek hai)
- Browser Cache TTL: **"Respect Existing Headers"**
- Koi "Cache Everything" page rule NA banayen.

Network tab:
- WebSockets: ON (default) — theek hai.

## Step 4 — Verify (switch ke baad)
1. `nslookup taxnest.com.pk` — ab Cloudflare IP (104.x / 172.x) dikhna chahiye, 66.29.138.229 nahi.
2. Website kholen, login karen (DI + POS + FBR POS panels) — sab normal chalna chahiye.
3. Ek shop ke Desktop Agent ka status check karen (heartbeat chalta rahe).
4. Ek test bill FBR/PRA par bhejen — submission server-side hai, farq nahi parega.
5. Pakistan se ping/page-load time note karen (pehle wala time: page ~1.5-3s evening; target: kaafi kam).

## Agar masla ho
- Email band: `mail`/MX records ko grey cloud (DNS only) karen.
- "Too many redirects": SSL mode Full (strict) confirm karen (Flexible NAHI).
- Site down: Namecheap par purane nameservers wapas laga den — 5 min mein sab pehle jesa.

## Cloudflare limits (free plan) — humari app ke liye theek
- Upload limit 100 MB per request — payment proofs / attachments is se bohot chhote hain.
- Koi WebSocket dependency nahi (agent HTTP polling use karta hai).
