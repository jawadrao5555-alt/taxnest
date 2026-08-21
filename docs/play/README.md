# Google Play — TaxNest Caller ID

Task 1346 ka saara Play-related material yahan hai. App ka code
`caller-app/` mein hai; yeh folder sirf Play Console ke liye hai.

| File | Kis kaam ka |
|---|---|
| `store-listing.md` | Listing ka har khana: naam, short/full description, graphics, screenshots ka tareeqa, reviewer ka demo login |
| `data-safety.md` | Data safety form ka har jawab + notification access ka justification + reject hone par Plan B |
| `signing-and-build.md` | AAB banane ka command, verification, Play App Signing ka faisla, version rules |
| `submission-checklist.md` | Owner ke liye qadam-ba-qadam: account, verification, upload, review, rejection ke jawab |
| `assets/play-icon-512.png` | Store icon (512×512) |
| `assets/play-feature-graphic-1024x500.png` | Feature graphic (1024×500) |

Phone par test karne ki list: `docs/qa/task-1346-caller-id-play-qa.md`.

---

## Ek nazar mein: Play build kya cheez hai

Caller ID ki ab **teen** builds hain, teenon ka package id ek — `pk.taxnest.callerid`:

| Build | Kahan se | Call detection | Self-update | targetSdk |
|---|---|---|---|---|
| `sim` (clean) | website | sirf SIM calls (telephony) | haan | 34 |
| `plus` | website | SIM + WhatsApp (notification access) | haan | 34 |
| **`play`** | **Google Play** | **SIM + WhatsApp (notification access)** | **nahi — Play Store karta hai** | **36** |

Play build alag isliye hai ke Play do cheezon ki ijazat nahi deta:

1. **Self-update** — `REQUEST_INSTALL_PACKAGES` ka jaiz istemal Play ki policy
   mein giney-chuney kaam hain, aur "app khud ko update kare" un mein nahi.
   Is liye play flavor mein download/install ka code compile hi nahi hota
   (`src/web/java` uske source set mein nahi hai) aur manifest se permission
   `tools:node="remove"` se nikal di gayi hai.
2. **Purana targetSdk** — 31 August 2026 se nai app ke liye Android 16 (API 36)
   lazmi hai.

Website ki dono builds bilkul pehle jaisi hain: wohi permissions, wohi
`targetSdk 34`, wohi self-update, wohi keystore.

Yeh poora farq sirf source-sets par khara hai — koi compiler ise nahi pakarta.
Is liye har build ke baad `bash scripts/play-build-check.sh` lazmi hai: AAB mein
in mein se koi cheez ghus aaye (ya website APKs se nikal jaye) to woh wahin fail
ho jati hai, Play ke reject ka intezar nahi karna parta. Tafseel:
`signing-and-build.md` §3.

Aur ek cheez dono notification builds (`plus` + `play`) mein nai hai:
**prominent disclosure** — notification access maangne se pehle poori screen par
saaf likha jata hai ke kya parha jayega, kya nahi, kahan jayega aur kyun. Play
ki User Data policy iske baghair app reject kar deti hai.

## Kyun sirf Caller ID Play par ja rahi hai

Baqi paanch Android apps (POS, FBR POS, DI, Waiter, Rider) website se theek
install hoti hain, un ka self-update Play par mana hai, aur WebView shells par
Play ki minimum-functionality policy ka khatra hai. Caller ID ka masla alag hai:
uski WhatsApp detection ke liye notification access chahiye, jise Play Protect
website-install par block karta hai — aur Play Store se aane wali app par woh
block hota hi nahi. Is liye poori app (SIM + WhatsApp) sirf Play se bila-rukawat
chal sakti hai.
