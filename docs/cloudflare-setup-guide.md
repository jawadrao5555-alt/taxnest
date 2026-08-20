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

## Step 2 — Nameservers change (HostCry — registrar yehi hai)
> Note: domain HostCry se registered hai (Namecheap nahi). HostCry ke client area ka
> nameserver form is .pk domain ke liye kaam NahI karta — support ticket se hota hai.
1. Cloudflare 2 nameservers dega (hamaray liye: `rihana.ns.cloudflare.com` / `ryan.ns.cloudflare.com`).
2. HostCry Client Area → Support → Open Ticket → nameserver change request likhen
   (dono Cloudflare nameservers de kar purane ns1/ns2.hostcry.com hatane ka kahen).
3. Support kuch ghanton mein kar deta hai (12 Aug 2026 ko ~isi din ho gaya tha).
   Cloudflare dashboard par "Active" status ka intezar karen.

## Step 3 — Cloudflare settings (Active hone ke baad)
SSL/TLS tab:
- Mode: **Full (strict)** — cPanel par AutoSSL cert pehle se hai, yeh kaam karega.
- Edge Certificates → "Always Use HTTPS": **ON**

Speed tab — **YEH BOHOT ZAROORI HAI**:
- **Rocket Loader: OFF rakhen** (POS sale screen ke scripts kharab kar dega)
  - Automated guard: nightly `cloudflare:check-rocket-loader` command (05:15) live homepage check karta hai — agar Rocket Loader ka script mila to pehle Cloudflare API se Rocket Loader khud-ba-khud OFF kiya jata hai (PATCH `settings/rocket_loader = off`), phir sab admins ko email jati hai ("detected + auto-fixed"). Agar API fail ho (ya token set na ho) to purani urgent manual-fix email jati hai.
  - Auto-fix ke liye 2 env values chahiye (live `.env` + Replit secrets): `CLOUDFLARE_API_TOKEN` (Cloudflare dashboard → My Profile → API Tokens → Create Token → "Edit zone settings" template ya custom **Zone → Zone Settings → Edit** permission, sirf taxnest.com.pk zone) aur `CLOUDFLARE_ZONE_ID` (dashboard → taxnest.com.pk Overview page, right side "Zone ID"). Token set karne ke baad live par `php artisan config:cache` zaroor chalayen.
- **Auto Minify: sab OFF rakhen** (HTML/CSS/JS teeno)
  - Automated guard: nightly `cloudflare:check-settings` command (05:20) Cloudflare API se yeh 3 settings parh kar check karta hai — **Auto Minify** (sab OFF), **SSL mode** (Full (strict)), **Browser Cache TTL** ("Respect Existing Headers"). Ghalat value milne par khud-ba-khud sahi value PATCH ho jati hai aur sab admins ko "detected + auto-fixed" email jati hai. Agar API read/fix fail ho to urgent manual-fix email jati hai. (Wohi `CLOUDFLARE_API_TOKEN` / `CLOUDFLARE_ZONE_ID` use hota hai jo Rocket Loader guard ke liye set hai.)

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

---

# Error 525 — "SSL handshake failed"

Yeh Cloudflare ka error page hai, hamari app ka nahi. Iska matlab: browser Cloudflare
tak pohanch gaya, lekin **Cloudflare hamare hosting server se secure connection nahi
bana saka**. Error page par teen nishaan hote hain — Browser (sabz), Cloudflare (sabz),
`taxnest.com.pk` Host (**laal**). Laal wala hamesha hosting side hota hai.

**Kabhi bhi is par app ka code debug na karein, aur na hi redeploy karein.**

## Pehla qadam: kya masla ab bhi hai?
`curl -s -o /dev/null -w "%{http_code}" https://taxnest.com.pk/` — agar 200 aaya to
waqia khatam ho chuka hai. Error page par likha **UTC waqt** parhein aur +5 kar ke PKT
banayein: aksar report ghanton baad aati hai (purani browser tab ki photo).

## Tashkhees (diagnosis)
1. **Origin certificate theek hai?** Server se:
   `echo | openssl s_client -connect <public-ip>:443 -servername taxnest.com.pk | openssl x509 -noout -dates`
   Yaad rahe: `127.0.0.1:443` test karne par hosting ka **default** cert (provider ka
   hostname) aata hai — yeh normal hai, ghabrane ki baat nahi. Hamesha public IP + SNI se test karein.
2. **Us minute server sach mein down tha?** Access log hi asal gawah hai:
   `~/access-logs/taxnest.com.pk-ssl_log` (live) aur `~/logs/*-ssl_log-<Mon>-<Year>.gz` (archive).
   Us minute ke hits ginein — busy waqt mein normal 20–50 hits/minute hote hain. Agar us
   minute normal traffic tha, to server chal raha tha aur sirf **aik Cloudflare edge**
   (Pakistan wala) fail hua — yani waqti aur juzvi masla.

## Automatic detection — `site:uptime-watch`
Har 2 minute scheduler se chalta hai (`routes/console.php`). Har run mein **do** probe:
- **edge** — public URL Cloudflare ke zariye
- **origin** — wohi URL, magar DNS origin IP par pin kar ke (Cloudflare bypass)

Nateeja isi jori se classify hota hai:

| edge | origin | Kya matlab |
|---|---|---|
| OK | (probe nahi hota) | Sab theek |
| FAIL | OK | `CLOUDFLARE-ORIGIN-LINK` — 525 family, **hosting** ka masla |
| FAIL | FAIL | `ORIGIN-DOWN` — hamara server / app |

- Alert **2 lagataar** nakaam checks ke baad (aik blip kabhi email nahi bhejta).
- Har waqia par **aik** alert email + **aik** recovery email (duration ke saath).
- Poori history: `storage/logs/uptime-watch.log` (email se azaad record).
- Origin IP khud `cpanel.<domain>` se resolve hoti hai (yeh record kabhi Cloudflare-proxied
  nahi hota), is liye hosting IP badle to bhi redeploy ki zaroorat nahi.
- Manual check: `php artisan site:uptime-watch --force --no-mail`

## Hosting ko ticket (copy-paste)
525 hone par hosting provider ko yeh bhejein (waqt UTC mein likhein):

> Our domain taxnest.com.pk is behind Cloudflare (SSL mode: Full (strict)).
> On <DATE> at <TIME> UTC visitors received Cloudflare Error 525 "SSL handshake failed".
> At that same minute our Apache access log shows the server was serving normal traffic,
> and our origin certificate (Let's Encrypt, CN=taxnest.com.pk) was valid — so the origin
> was up and only the Cloudflare-to-origin TLS leg failed.
>
> Please:
> 1. Check the firewall (CSF/lfd) logs for that timestamp for any temporary block or
>    connection-rate limit against Cloudflare edge IPs.
> 2. Permanently whitelist ALL Cloudflare IPv4 + IPv6 ranges (https://www.cloudflare.com/ips/)
>    in CSF, and raise CT_LIMIT / connection-tracking limits for them — a single Cloudflare
>    edge IP legitimately opens many concurrent connections for a busy site.
> 3. Confirm whether Apache/LiteSpeed was restarted at that time (AutoSSL renewal, server
>    maintenance), since in-flight TLS handshakes die during a restart.
> 4. Tell us if there was any upstream network incident in the datacentre at that time.
>
> This is a live POS/invoicing platform used by retail shops, so even a few minutes of
> handshake failures is disruptive. Thank you.

## Cloudflare Origin Certificate (optional, mazboot hal)
AutoSSL har ~60 din baad cert renew karta hai aur us lamhe web server restart hota hai —
yehi 525 ki aik wajah ban sakti hai. Cloudflare ka apna **Origin Certificate** 15 saal
chalta hai, yani renewal wali wajah hamesha ke liye khatam.

1. Cloudflare dashboard → `taxnest.com.pk` → **SSL/TLS → Origin Server** → *Create Certificate*.
2. Private key type **RSA (2048)**, hostnames `taxnest.com.pk` aur `*.taxnest.com.pk`,
   validity **15 years** → Create.
3. Do box milenge: **Origin Certificate** aur **Private Key**. Private Key sirf **abhi**
   dikhti hai — dono ko mehfooz jagah copy karein (kabhi git/repo mein na rakhein — repo public hai).
4. cPanel → **SSL/TLS → Manage SSL sites** → domain `taxnest.com.pk` chunein →
   Certificate wale box mein Origin Certificate paste karein, Private Key wale box mein key →
   **Install Certificate**.
5. SSL/TLS mode **Full (strict)** hi rehne dein (`cloudflare:check-settings` isay roz check karta hai).
6. Verify: `curl -sI https://taxnest.com.pk/ | head -1` → `HTTP/2 200`, aur
   `php artisan site:uptime-watch --force --no-mail` → `OK`.

**Zaroori khayal:** Origin Certificate par sirf Cloudflare bharosa karta hai. Iske baad
domain ka traffic **hamesha** Cloudflare ke zariye (orange cloud ON) hi aana chahiye —
grey cloud karte hi browser "certificate not trusted" warning dega. Isi liye yeh optional
hai; sab se ziyada faida wala qadam **firewall whitelist** hai (ticket wala point 2).
