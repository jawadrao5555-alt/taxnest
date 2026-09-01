# FBR Cloud VPS Setup Guide (TaxNest "Cloud Fiscal Bridge")

Ye guide us waqt ke liye hai jab TaxNest ka apna **Windows VPS** ho aur clients ke FBR IMS
components shop-PC ke bajaye is VPS par chalane hon. Cashier ke liye kuch nahi badalta —
billing 100% web par hoti hai; VPS sirf bills ko FBR tak pohnchata hai.

---

## Qadam 0 — VPS khareedte waqt kya lena hai (checklist)

| Cheez | Kya chahiye |
|---|---|
| Operating System | **Windows Server 2019 ya 2022** (Linux NAHI — FBR ka software Linux par nahi chalta) |
| RAM | Pehle 1–2 clients ke liye **4 GB** kaafi; 3+ clients par **8 GB** |
| CPU / Disk | 2 cores, 80 GB+ disk |
| Access | **RDP (Remote Desktop)** login — provider IP address + Administrator password dega |
| Location | Koi bhi (Europe/Singapore sasta; Pakistan se internet fast hona chahiye) |

> Khareedne ke baad sirf 3 cheezein sambhal kar rakhein: **IP address, username (Administrator), password.**

---

## Qadam 1 — Pehli dafa VPS kholna

1. Apne PC par **Remote Desktop Connection** kholein (Windows mein pehle se hota hai).
2. IP address dalein → Connect → username `Administrator` + password.
3. Windows Desktop khul jayega — bas, ab ye aapka "cloud PC" hai.

---

## Qadam 2 — Har client ke liye TaxNest side ON karna (ye web par hota hai)

1. taxnest.pk par us client ke **FBR POS → Settings** kholein.
2. **POS ID** dalein, Environment **Production**, Submission Mode **Fiscal Device** → Save.
3. Screen par 3 values aayengi — copy kar lein:
   - Server URL: `https://taxnest.pk/api/agent`
   - Company ID: (number)
   - API Key: `tnk_...`

---

## Qadam 3 — VPS par FBR ka software install (har client ke liye alag)

1. Browser mein download karein: `https://download.fbr.gov.pk/IMS_Setup/FBRIMS.zip`
2. Unzip karein → `Setup.exe` ko **Run as Administrator**.
3. Install ke doran:
   - **POS Registration No** = client ki POS ID (masalan X-WAY = `196354`)
   - **Access Code** = IRIS ke *Point of Sale Registration* grid wala code
   - Mode = **Production**
   - **Target Folder** = har client ka ALAG folder, masalan `C:\FBR\Client-XWAY\`
4. Health check — VPS ke browser mein kholein:
   `http://localhost:8524/api/IMSFiscal/get` → "Service is responding" aana chahiye.

> **Multi-client note:** Doosre client ka install alag folder (`C:\FBR\Client-2\`) mein hoga
> aur usay alag port chahiye hoga. Pehla client 8524 par hi rahega. Doosra client lagane
> se PEHLE agent ko batayen (TaxNest side par port setting main set karunga).

---

## Qadam 4 — TaxNest Agent install + connect

1. TaxNest POS panel se **TaxNest-Agent-Setup.exe** download karein (POS → Sync Agent).
2. VPS par install karein aur kholein.
3. Qadam 2 wali 3 values paste karein (Server URL / Company ID / API Key).
4. **Test Connection** → ✅ → **Save & Connect**. Agent tray mein chala jayega aur
   VPS restart par khud start hoga.

---

## Qadam 5 — Test

1. Client ke FBR POS mein ek chota test bill banayein (reporting ON).
2. ~30 second mein bill **Pending → Submitted** ho kar **FBR Invoice Number** dikhayega.
3. Masla ho to sale screen par **F11 (Failed)** list mein exact error milta hai.

---

## Aam masail

| Masla | Hal |
|---|---|
| Settings page par Agent "Offline" | VPS par agent band hai — RDP se khol kar dobara Save & Connect |
| `localhost:8524` jawab nahi deta | FBRIMS service band — VPS par `services.msc` → "Fiscalization Service" → Start |
| Bill Failed, Code 104 | POS ID / Access Code ghalat — IRIS grid se dobara check kar ke FBRIMS re-install |
| VPS restart hua, bills ruk gaye | Agent auto-start hai; na chale to ek dafa manually kholein |

---

## Aage ka plan (2+ clients par)

- Pehle **alag-folder + alag-port** wala test hoga (main TaxNest side par per-company port
  ka intezam karunga).
- Agar FBR ka installer doosri install par purani wali kharab kare, to fallback = VPS ke
  andar har client ka chota **virtual PC** (Hyper-V) — 100% chalta hai, bas RAM zyada chahiye.
- Clients barhne par ek **multi-company sync service** banaunga jo ek hi jagah se sab
  clients ke bills sambhale (alag-alag agent kholne ki zaroorat khatam).
