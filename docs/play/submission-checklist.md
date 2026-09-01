# Play Store submission — owner ki qadam-ba-qadam checklist

Task 1346. App ka Play wala package, dono legal safhay, listing ka matn,
graphics aur Data safety ke jawabat sab tayyar hain. Ab jo kaam **sirf owner**
kar sakta hai (account, adaigi, verification, upload) woh yeh hai. Tarteeb se
chalen — har qadam ke aage waqt ka andaza bhi likha hai.

Related files:
- `docs/play/store-listing.md` — listing ka har khana (copy/paste)
- `docs/play/data-safety.md` — Data safety form + notification access ka justification
- `docs/play/signing-and-build.md` — AAB banane aur key ka tareeqa
- `docs/qa/task-1346-caller-id-play-qa.md` — phone par khud test karne ki list

---

## Qadam 0 — pehle app khud phone par chala kar dekh lein  (30 min)

Upload se pehle QA doc wali list chala lein. Reviewer jo pehli cheez karta hai
wohi hai: install → login → permission → feature. Agar in mein se koi tootay to
reject "broken functionality" par hota hai, jiska jawab dena mushkil hota hai.

---

## Qadam 1 — account ki qism ka faisla  (5 min sochne ka, asar mahinon ka)

Play Console account $25 (ek dafa, life-time) — dono soorton mein.

| | **Personal account** | **Organization account** (sifarish) |
|---|---|---|
| Developer ka naam listing par | aap ka apna naam | **TaxNest / company ka naam** |
| D-U-N-S number chahiye? | Nahi | **Haan** — muft, Dun & Bradstreet se, 5–30 din |
| 12 testers × 14 din wali shart | **Haan** — production se pehle 12 testers ko 14 din tak musalsal closed test par rakhna parta hai | **Nahi** |
| Kitni jaldi live ho sakti hai | D-U-N-S ka intezar nahi, magar 14 din ka test lazmi | D-U-N-S mil jaye to seedha production |
| Kis ke liye theek | shauqiya / ek bandey ki app | **karobari app — yehi TaxNest ke liye theek hai** |

**Sifarish: Organization.** Wajah do hain: listing par "TaxNest" likha aayega
(shop maalik ko bharosa aata hai), aur 12 testers/14 din wali shart nahi lagti.
Nuqsan sirf D-U-N-S ka intezar hai.

**D-U-N-S abhi lein (muft):** dnb.com par "Get a D-U-N-S Number" → business ka
naam, address, phone. Wohi naam/address dein jo FBR registration par hai, warna
Google verification par mismatch nikalta hai. Aksar 1–2 hafte, kabhi 30 din.

Agar owner intezar nahi karna chahta: personal account se shuru karein, 12
testers (staff, dost, mojooda shop maalik — koi bhi 12 Gmail accounts) ko closed
test par rakhein, 14 din baad production ki darkhwast dein.

---

## Qadam 2 — Play Console account banayein aur verify karayein  (1 ghanta + intezar)

1. `play.google.com/console` → Gmail se sign in → account type chunain
   (Qadam 1 wala faisla).
2. $25 adaigi — international card ya kisi bhi Pakistani bank ka dollar card.
3. **Verification:** Google identity documents maangta hai —
   - Personal: CNIC/passport ki tasveer, address, phone.
   - Organization: D-U-N-S, business ka legal naam/address, website
     (`taxnest.pk`), aur us website ki milkiyat ki tasdeeq, plus ek
     "authorised representative" ki identity.
4. Contact email par jo verification link aaye usay foran kholein.

> Verification mukammal na ho to app publish nahi hoti — is liye yeh sab se
> pehle nipta lein, app banane se pehle bhi kiya ja sakta hai.

---

## Qadam 3 — app banayein aur listing bharein  (45 min)

Console → **Create app**:
- App name: `TaxNest Caller ID`
- Default language: English (US) · App · Free
- Declarations: developer program policies + US export laws — dono tick.

Phir **Main store listing** (`docs/play/store-listing.md` se paste):
- Short description, Full description
- App icon → `docs/play/assets/play-icon-512.png`
- Feature graphic → `docs/play/assets/play-feature-graphic-1024x500.png`
- Phone screenshots → phone se liye hue 4 shots (store-listing.md §5)
- Category: Business · Tags · Contact email/phone/website

**Store settings → App category & contact details** bhi wahin bhar dein.

---

## Qadam 4 — App content ke saare form  (45 min)

Console → **Policy → App content**. Har item green tick hona chahiye:

- [ ] **Privacy policy** → `https://taxnest.pk/privacy`
- [ ] **App access** → "All or some functionality is restricted" + demo login
      aur steps (store-listing.md §6 ka matn). Pehle QA shop ka password reset
      kar ke Unlimited plan + Caller ID ON karein.
- [ ] **Ads** → No
- [ ] **Content rating** → questionnaire, sab "No" (data-safety.md §5)
- [ ] **Target audience** → 18 and over; appeals to children: No
- [ ] **News apps** → No · **COVID-19** → No · **Government apps** → No
- [ ] **Financial features** → No (app koi payment nahi karti)
- [ ] **Health apps** → No
- [ ] **Data safety** → `docs/play/data-safety.md` ka har jawab
- [ ] **Account deletion** → `https://taxnest.pk/data-deletion` (Data safety
      ke andar "users can request data deletion" ke saath)

---

## Qadam 5 — AAB upload  (20 min)

1. AAB banayein: `docs/play/signing-and-build.md` §2. Phir **upload se pehle
   lazmi** `bash scripts/play-build-check.sh` chalayein (§3) — exit 1 aaye to
   upload bilkul na karein, warna reject ka pata hafton baad chalega.
2. **App signing:** pehli upload par Google poochega. `signing-and-build.md` §4
   parh kar faisla karein — sifarish "existing key upload" (PEPK) hai, taake
   website wali APK aur Play wali app ek doosre par bina uninstall update hoti
   rahen.
3. Track chunain:
   - **Internal testing** pehle — foran available, 100 testers tak, apna phone
     add kar ke asal Play install test karein. (Yeh qadam skip na karein.)
   - Personal account: uske baad **Closed testing** 12 testers × 14 din.
   - Phir **Production**.
4. Release notes (pehli release ke liye):
   ```
   First release. Caller ID for shops using TaxNest NestPOS: incoming SIM and
   WhatsApp calls appear as a customer pop-up on your POS sale screen.
   ```
5. Countries: Pakistan (chahen to sab).

---

## Qadam 6 — review ka intezar  (2 din – 2 hafte)

- Pehli app ka review aksar **3–7 din** leta hai, kabhi 2 hafte tak.
- Status Console ke **Publishing overview** par dikhta hai.
- Review ke doran QA shop ka account chalu rakhein — reviewer usi se login
  karega. Us ka plan expire ho gaya ya Caller ID off ho gaya to reject pakka.
- Email ka jawab 7 din ke andar dena hota hai warna review band ho jata hai.

---

## Qadam 7 — reject ho jaye to  (tayyar jawabat)

| Reject ki wajah | Kya karna hai |
|---|---|
| **Privacy policy missing / doesn't cover the app** | `https://taxnest.pk/privacy` ka link dobara dein aur §3 (Caller ID section) ki taraf ishara karein — usmein data, maqsad, retention aur deletion sab likha hai |
| **Data safety mismatch** | `docs/play/data-safety.md` ke mutabiq form dobara check karein; aksar wajah yeh hoti hai ke koi type "collected" nahi kiya gaya. Zyada declare karna mehfooz hai |
| **Prominent disclosure missing** | App mein pehle se hai — disclosure screen ka screenshot bhejein aur likhein ke woh notification-access maangne se pehle aati hai, agree/not-now dono ke saath |
| **Notification access / Call Log policy** | `docs/play/data-safety.md` §3 ka poora justification bhejein. Zor is baat par: app Call Log ya SMS permission maangti hi nahi, aur "Caller ID" Google ki apni policy mein manzoor-shuda use case hai. Na chale to §4 ka Plan B (sirf-SIM build) |
| **Broken functionality / can't sign in** | QA shop ka login khud test karein, plan Unlimited aur Caller ID ON rakhein, aur App access mein qadam-ba-qadam steps (POS sale screen par pop-up dekhne tak) dobara likhein |
| **Minimum functionality** | Jawab: yeh companion app hai jo mojooda NestPOS subscription ke saath kaam karti hai; iska apna UI (login, status, disclosure, test call) mojood hai aur yeh WebView shell nahi |
| **Metadata / keyword spam** | Title "TaxNest Caller ID" saaf hai; description mein koi "best/#1" jaisa dawa na daalein |
| **Target API level** | Play build pehle se Android 16 (API 36) par hai — yeh nahi aana chahiye |
| **Account deletion URL** | `https://taxnest.pk/data-deletion` |

Har appeal Console ke "Appeal" button se hoti hai. Ek hi baar mein poora
justification bhejein, tukron mein nahi.

---

## Qadam 8 — publish hone ke baad  (30 min)

- [ ] Apne phone par Play se install kar ke poora QA dobara chalayein.
- [ ] QA shop ka password badal dein (reviewer ke paas jo tha usay retire karein).
- [ ] Website ke downloads page par Play ka link/badge lagwane ka faisla karein
      (yeh alag chhota kaam hai — website builds jyun ki tyun rahengi).
- [ ] Shops ko elaan: "ab Caller ID Play Store se bhi install ho sakti hai,
      koi Play Protect warning nahi".
- [ ] Yaad rahe: Play wali app khud ko update nahi karti — har nai release
      Console par `versionCode` barha kar upload karni hogi.
