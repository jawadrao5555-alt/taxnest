# Data safety form + permission justifications — TaxNest Caller ID

Task 1346. Play Console → **App content → Data safety** ka har sawal aur uska
tayyar jawab. Ghalat ya adhoora Data safety form Play ke sab se aam reject /
takedown ki wajah hai — is liye yahan **zyada bata dena** (over-declare) ka
usool apnaya gaya hai: jo cheez shak wali ho, usay "collected" likh dein.

Yeh sirf **play** flavor par lagu hai (`pk.taxnest.callerid`, Play build). Us
build mein na `READ_PHONE_STATE` hai, na `READ_CALL_LOG`, na
`REQUEST_INSTALL_PACKAGES` — is liye Call Log / SMS wala alag declaration form
lagu hi nahi hota.

---

## 1. Data collection & security — pehle 3 sawal

| Sawal | Jawab |
|---|---|
| Does your app collect or share any of the required user data types? | **Yes** |
| Is all of the user data collected by your app encrypted in transit? | **Yes** — sab kuch HTTPS/TLS par jata hai (`https://taxnest.com.pk/api/caller-app/v1`) |
| Do you provide a way for users to request that their data is deleted? | **Yes** — `https://taxnest.com.pk/data-deletion` |

---

## 2. Data types — kya declare karna hai

Har type ke liye Play chaar cheezen poochta hai: Collected? Shared? Processed
ephemerally? Required or optional? Aur purpose.

**Sab types ke liye mushtarak jawab:**
- **Shared:** No (kisi teesre fareeq ko kuch nahi jata; data sirf usi shop ke
  apne TaxNest account ko jata hai jo app istemal kar rahi hai — Play ke nazdeek
  yeh "transfer to your own service", sharing nahi)
- **Processed ephemerally:** No (ring record ~48 ghante rehta hai)
- **Required or optional:** Required (data collection is required — app ka
  poora maqsad yehi hai)
- **Purpose:** **App functionality** (aur kuch nahi — na analytics, na
  advertising, na personalisation)

### Personal info

| Data type | Collected | Kyun (form mein likhne ke liye) |
|---|---|---|
| **Name** | Yes | Incoming call ki notification par jo naam phone dikhata hai, wohi POS pop-up mein dikhaya jata hai |
| **Email address** | Yes | App mein sign-in ke liye (mojooda TaxNest POS account) |
| **Phone number** | Yes | Incoming call karne wale ka number — isi se POS apne saved customer ko pehchanta hai |
| User IDs | No | App koi alag user id nahi banati; account email se pehchana jata hai |
| Address, Race, Political, Sexual orientation, Other info | No | |

### Financial info
Sab **No**. App koi payment nahi leti, na card/bank data chhoti hai.

### Location
**No.** Na precise, na approximate. App mein koi location permission hi nahi.

### Messages, Photos and videos, Audio files, Files and docs, Calendar, Contacts, Health
Sab **No**.
- Messages: app sirf **incoming-call** ki notification parhti hai; kisi
  message/chat notification ko na parhti hai, na store karti hai, na bhejti hai.
- Audio: koi call recording nahi.
- Contacts: phone ki contact book kabhi nahi khuli — pehchan server par shop ke
  apne customer record se hoti hai.

### App activity
Sab **No** (no app interactions, no in-app search history, no installed apps).

### Web browsing
**No.**

### App info and performance
Sab **No** — koi crash-reporting ya analytics SDK app mein nahi.

### Device or other IDs

| Data type | Collected | Kyun |
|---|---|---|
| **Device or other IDs** | Yes | Sign-in par ek device token banta hai (us phone ki pehchan) aur phone ka model/naam bheja jata hai, taake shop ka malik POS → Customize mein dekh sake ke kaunse phone jude hain aur kisi ko revoke kar sake |

Purpose: **App functionality** aur **Account management**. Shared: No.

---

## 3. Notification access ka justification (jab Google poochhe)

Play Console mein notification-listener ka koi alag form nahi hota, lekin
review ke doran ya email par Google justification maang sakta hai. Ready jawab:

```
Core purpose: caller ID for the merchant's own point-of-sale screen.

TaxNest Caller ID is a companion app for merchants using our TaxNest NestPOS
platform. When the shop's phone rings, the merchant's POS sale screen shows who
is calling and what they last ordered.

In Pakistan a large share of shop orders arrive over WhatsApp calls. A WhatsApp
call is a VoIP call: it never reaches Android's telephony layer, so
READ_PHONE_STATE / the call-state APIs cannot see it. Notification access is
the only supported API that lets us know a WhatsApp call is ringing.

Consent gate: the listener service does nothing at all - it does not even read
the notification's contents - until the user has signed in with a TaxNest POS
account AND has accepted the in-app prominent disclosure. Enabling notification
access from Android's system Settings alone is not treated as consent, and
clearing app data clears the recorded consent.

Scope of use: the service acts on a notification only when BOTH conditions
hold: the notification's category is the system "call" category, AND the
posting package is either WhatsApp / WhatsApp Business or one of the phone's
own calling apps (the default dialer reported by TelecomManager, an app that
can handle ACTION_DIAL, or a known system telephony package). Everything else
returns immediately: a call notification from any other app - Messenger,
Telegram, Viber, Skype, Zoom - is not read and nothing about it leaves the
device. From the accepted notification the app extracts only the caller's
number and display name; notifications for outgoing, ongoing, missed or ended
calls are discarded as well.

Data flow: number, display name, timestamp, and call type are sent over HTTPS
to the merchant's own account on taxnest.com.pk, which draws the pop-up on the
merchant's POS screen. Nothing is sent anywhere else, nothing is sold, and the
record is deleted automatically after about 48 hours.

User consent: before the permission is requested the app shows a full-screen
prominent disclosure that states exactly what is read, what is ignored, where
it is sent and why, with an explicit agree / not now choice. Declining leaves
the app usable and the permission unrequested. The same information is in our
privacy policy at https://taxnest.com.pk/privacy.

Alternatives considered: telephony APIs (cannot see VoIP calls),
CallScreeningService and the CallRedirection APIs (SIM calls only),
AccessibilityService (heavier, and not an accessibility use case).
```

## 4. Agar Google phir bhi Call Log policy ka hawala de

Kabhi kabhi reviewer notification se call parhne ko "Call Log permission ka
chor darwaza" keh kar reject karta hai. Us soorat mein do raste hain, isi
tarteeb mein:

1. **Appeal** — upar wala justification bhejein, is izafe ke saath: app
   Call Log ya SMS permission maangti hi nahi, call history kabhi nahi parhti,
   aur "Caller ID" Google ki apni Call Log policy mein saaf tor par manzoor-shuda
   use case hai.
2. **Agar appeal na chale** — website wali **clean (sim) build** ka AAB banayein
   (usmein `READ_PHONE_STATE` + `READ_CALL_LOG` hain aur notification access
   bilkul nahi) aur Console ka **Call Log permissions declaration** bharein:
   core functionality = "Caller ID, spam detection and/or spam blocking".
   Us soorat mein Play wali app sirf SIM calls pakregi; WhatsApp calls ke liye
   shop ko website wali plus build hi deni hogi. (Is build ka AAB banane ka
   tareeqa `docs/play/signing-and-build.md` mein hai.)

## 5. Content rating questionnaire

Sab jawab **No** — koi violence, dar, sex, gaali, drugs, gambling,
user-generated content, user-to-user communication, location sharing, ya
personal info sharing nahi. Nateeja: Everyone / PEGI 3.

Target audience: **18 and over**. "Appeals to children": No.

## 6. Ads

Console → App content → Ads: **No, my app does not contain ads.**
App mein koi ad SDK nahi (dependencies sirf androidx core/appcompat/material).
