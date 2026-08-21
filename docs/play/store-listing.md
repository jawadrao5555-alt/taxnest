# Play Store listing — TaxNest Caller ID

Task 1346. Yeh file Play Console ke "Main store listing" page ke har khane ka
tayyar-shuda matn hai. Copy → paste. Jo cheez owner ko khud karni hai (asal
screenshots) uska tareeqa neeche likha hai.

App: **TaxNest Caller ID** · package `pk.taxnest.callerid` · build = `play`
flavor ka AAB (`caller-app/app/build/outputs/bundle/playRelease/app-play-release.aab`).

---

## 1. App details

| Field | Value |
|---|---|
| App name (30 chars max) | `TaxNest Caller ID` (17) |
| Default language | English (United States) |
| App or game | App |
| Free or paid | Free |
| Category | Business |
| Tags (up to 5) | Business, Productivity tools, Point of sale, Customer management, Retail |
| Contact email | wohi jo website ke Contact page par hai (admin → settings mein set) |
| Contact phone | shop support number (optional but bharna behtar) |
| Website | `https://taxnest.com.pk/pos` |
| Privacy policy URL | `https://taxnest.com.pk/privacy` |
| Data deletion URL | `https://taxnest.com.pk/data-deletion` |

> Dono URLs public hain (login ke baghair khulte hain) — reviewer ye khud kholta hai.

---

## 2. Short description (80 characters max)

```
Know which customer is calling — right on your NestPOS sale screen.
```
(67 characters)

Backup option (agar owner ko doosra andaz chahiye):

```
Caller ID for your shop: SIM and WhatsApp calls on your POS sale screen.
```
(72 characters)

---

## 3. Full description (4000 characters max)

> Neeche ka matn jyun ka tyun paste karein. Isme koi aisa dawa nahi jo app
> karti na ho — yehi Play ke "Misrepresentation" reject ki sab se badi wajah
> hoti hai.

```
TaxNest Caller ID tells your shop who is calling — before you pick up.

It is a companion app for shops that already use TaxNest NestPOS. Put it on the
shop's phone, sign in once with your NestPOS account, and every incoming call
appears as a pop-up on your POS sale screen: the customer's name, their phone
number, and what they ordered last time. Your cashier greets the customer by
name and repeats the last order in one line.

WHAT IT DOES

• Shows an instant pop-up on the NestPOS sale screen when the shop phone rings
• Works for normal SIM calls and for WhatsApp calls
• Matches the number to your saved customers and shows their recent orders
• Unknown number? The pop-up lets you save it as a new customer in one tap
• Runs quietly in the background — no need to keep the app open
• One phone or several: link as many shop phones as you need, and unlink any of
  them from POS at any time

WHO IT IS FOR

Restaurants, bakeries, karyana and retail shops in Pakistan that take orders on
the phone and already run TaxNest NestPOS. You need an existing NestPOS account
to sign in — this app does not create accounts and is not useful on its own.

HOW IT WORKS

To see a WhatsApp call the app uses Android's notification access, because
WhatsApp calls are internet calls and no other Android API can see them. Before
that permission is requested the app shows a full screen that explains exactly
what is read and where it is sent, and you must agree.

The app reads an incoming-call notification only when it comes from your phone's
own calling app or from WhatsApp / WhatsApp Business. Every other notification —
messages, chats, email, and calls in other apps — is ignored: not read, not
stored, not sent.

WHAT IS SENT, AND WHERE

Only four things, and only to your own shop account on taxnest.com.pk over a
secure HTTPS connection: the caller's number, the caller's name if the phone
shows one, the time, and whether it was a SIM call or a WhatsApp call. That is
what draws the pop-up on your sale screen. Nothing goes to any third party.

The call record deletes itself automatically after about 48 hours. You can
withdraw the permission from Android settings at any time, and the shop owner
can switch the feature off or unlink a phone from POS → Customize. There are no
ads, no analytics SDKs, and nothing is ever sold.

WHAT IT DOES NOT DO

• Does not record calls or audio
• Does not read your messages, contacts, call history, photos or files
• Does not track your location
• Does not block calls or identify unknown spam numbers from any public database

REQUIREMENTS

• An active TaxNest NestPOS account with the Caller ID feature enabled by the
  shop owner (POS → Customize → Caller ID)
• Android 8.0 or newer
• Internet on the shop phone

Privacy Policy: https://taxnest.com.pk/privacy
Delete your data: https://taxnest.com.pk/data-deletion
Support: https://taxnest.com.pk/contact
```

Approx 2,700 characters — Play ki 4,000 ki hadd ke andar.

---

## 4. Graphics

| Asset | Play requirement | File |
|---|---|---|
| App icon | 512×512 PNG, 32-bit, ≤1 MB | `docs/play/assets/play-icon-512.png` (100 KB) |
| Feature graphic | 1024×500 PNG/JPG, ≤15 MB | `docs/play/assets/play-feature-graphic-1024x500.png` (208 KB) |
| Phone screenshots | 2–8, min 320 px side, 16:9 ya 9:16 | owner ke phone se — neeche §5 |

Dono banaye hue graphics app ke apne launcher icon aur brand rang (teal #0A4D5C,
gold #E7BF3B) par hain, taake listing website aur app se milti-julti lage.
Feature graphic mein koi choti/na-parhne wali tehreer nahi hai (Play us par
warning deta hai).

---

## 5. Screenshots — owner ka qadam (10 minute)

Play asli screenshots maangta hai: aise mock-up jo app se mail na khate hon,
"Misrepresentation" ke tehet reject hote hain. Is liye yeh phone par khud lene
hain. Ek hi phone se, ek hi baar mein.

**Kis build se?** Website wali **WhatsApp (plus) build** se le lein — uski har
screen bilkul Play build jaisi hi hai (sirf badge ki Roman line ka jumla alag
hai). Agar Play build ka internal-testing link mil jaye to us se aur behtar.

Tarteeb (yehi 4, isi tarteeb mein upload karein):

1. **Login screen** — app kholte hi. Email khali chhor dein ya QA wala email
   likhein; asal shop ka email na dikhe.
2. **Prominent disclosure screen** — main screen par "نوٹیفکیشن کی اجازت دیں"
   dabayen; poori screen ka shot lein (yeh reviewer ko bhi tasalli deta hai).
3. **Main status screen, sab sabz** — permission on, battery theek, "سب ٹھیک
   ہے" wali halat.
4. **POS sale screen ka caller pop-up** — POS phone/tablet par sale screen
   khol kar test ring bhejein (app ka "ٹیسٹ کال بھیجیں" button) aur pop-up ka
   shot lein. Yeh sab se aham shot hai: reviewer ko dikhta hai ke data kis kaam
   aata hai.

Har shot ke liye:
- Phone ka screenshot (Power + Volume-Down), koi crop nahi, koi frame nahi.
- Status bar mein asal shop ka naam/number na ho.
- Play khud portrait 9:16 accept karta hai — resize ki zarurat nahi.
- Upload se pehle gallery mein dekh lein ke tehreer parhi ja rahi ho.

Caption ki zarurat nahi (Play caption nahi maangta), lekin agar owner chahay to
Console mein har shot ke saath koi tehreer nahi jodni — sirf tarteeb maayne
rakhti hai.

---

## 6. Reviewer ke liye demo login (App content → App access)

Play reviewer ko sign-in karne ka rasta dena LAZMI hai warna "Login required,
no credentials" par reject ho jata hai.

Console → **App content → App access** → "All or some functionality is
restricted" → add instructions:

```
This app is a companion for merchants who already use our TaxNest NestPOS
web platform. Sign-in is required; there is no public sign-up.

Demo account (test shop, safe to use):
  App sign-in email: <QA shop ka email>
  Password:          <QA shop ka password>

Steps to see the main feature:
1. Open the app and sign in with the account above.
2. The app asks for notification access and first shows a full-screen
   disclosure explaining what is read and where it is sent. Tap agree, then
   enable "TaxNest Caller ID" in the Android list.
3. Back in the app, tap the "Send test call" button (the third button).
4. Open https://taxnest.com.pk/login in a browser, sign in with the same
   account, open POS -> Sale screen: the caller pop-up appears there.
   (The pop-up is the whole point of the app; it is shown on the merchant's
   POS screen, not inside the app.)
```

**Credentials kahan se?** Standing live QA shop hi istemal karni hai (id 35),
kisi asal shop ka login kabhi nahi. Password aur email `.local/qa-creds.env`
mein hain — yeh file jaan-boojh kar git se bahar hai (repo public hai), is liye
yahan naqal nahi ki gayi. Submission se pehle owner:

1. QA shop ka password reset kar ke ek naya, sirf-Play-ke-liye password rakhe.
2. Us shop par plan = Unlimited aur POS → Customize → Caller ID = ON kar de,
   warna reviewer ko app "feature band hai" dikhayegi aur woh usay "broken
   functionality" likh dega.
3. Review mukammal hone ke baad chahe to password dobara badal le.

---

## 7. Store settings ke baqi khane

- **App category:** Business
- **Store listing contact details:** email lazmi; phone aur website behtar
- **External marketing:** "Yes" tab hi chunain jab owner sach mein Play listing
  ka ishtihar chalaye — warna "No"
- **Ads:** app mein koi ad nahi → Console ke "Ads" declaration mein **No**
- **Content rating questionnaire:** sab sawalon ka jawab "No" (koi violence,
  sex, drugs, gambling, user-generated content, sharing of location, ya
  user-to-user communication nahi). Nateeja: Everyone / PEGI 3.
- **Target audience:** 18 and over. "Appeals to children" = No.
- **News app:** No. **COVID-19 apps:** No. **Data safety:** alag file dekhein
  (`docs/play/data-safety.md`).
- **Government apps:** No — app FBR/PRA ko kuch nahi bhejti, sirf shop ke apne
  POS se baat karti hai.
