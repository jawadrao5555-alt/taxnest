# NestPOS (PRA POS) — Madadgar Knowledge Base (Mukammal & Tafseeli)
Yeh NestPOS ka mukammal guide hai. Sirf is guide ki maloomat se jawab do. Jawab hamesha step-by-step aur asaan Roman Urdu mein do.

## Login aur Account
- POS login: /pos/login — Email, Phone, Username, CNIC ya NTN se login ho sakta hai (CNIC/NTN se company ka admin login hota hai).
- Password bhool jayein: login page par "Forgot Password" → email par OTP aata hai → naya password set karein.
- 5 ghalat koshishon par login thori der ke liye lock ho jata hai — kuch minute intezar kar ke dobara koshish karein.
- POS ka user sirf /pos/login par login kar sakta hai — kisi aur panel (Digital Invoice waghera) par "Invalid credentials" aayega. Yeh security ke liye hai.
- Har company ka data bilkul alag (isolated) hai — koi doosri dukan ka data nahi dekh sakta.
- Nayi company register hone ke baad admin approval tak sab pages DEKH sakte hain lekin kaam (bill banana waghera) approval ke baad hi hota hai.
- Apna profile (naam, password) badalna ho: /pos/my-profile.

## Dashboard (/pos/dashboard)
- Dashboard par KPI cards (aaj ki sales, bills, tax, net sales), hourly chart, 30 din ka trend, payment method ka breakdown aur aaj ke recent bills hote hain.
- Cashier ko sirf apne stats nazar aate hain; admin/manager ko poori company ke.
- Din ke shuru mein "Opening Cash" card par drawer ka cash + note enter kar ke Save karein (tafseel Day Close section mein).
- Dashboard ka style badalna ho: /pos/customize ke "POS Ka Style" section se — "Full" (mukammal) ya "Saaf" (seedha saada, Roman Urdu) — aur kuch mazeed fancy styles bhi hain. Sirf admin/manager badal sakta hai.

## Sale Screen — Nayi Bill Banana (Dashboard → "Nayi Sale" ya /pos/invoice/create)
Bill banane ka aam tareeqa, shuru se aakhir tak:
1. (Optional) Customer: customer box mein phone/naam type karein → list se select karein. Naya customer ho to naam+phone likh kar Add kar dein. Walk-in customer ke liye yeh step CHHOR dein.
2. Items dalein — 3 tareeqay: (a) search box mein naam/barcode/SKU type karein aur Enter, (b) product grid par item click karein, (c) barcode scanner se scan karein — exact match foran cart mein chala jata hai.
3. Quantity: cart row ke qty box par click kar ke number type karein, ya same item dobara add karein, ya cart row select kar ke + / - keys.
4. (Optional) Item par discount (D), tax on/off (T), ya note (N) — neeche shortcuts section dekhein.
5. Order type chunein (restaurant mode mein): Dine In / Takeaway / Delivery buttons. Dine In chunte hi TABLE PICKER khul jata hai — arrow keys se table select, Enter.
6. Bill mukammal karne ke 2 raste:
   - "PAY" button (ya F8) → payment modal → Cash (key 1) ya Card (key 2) → bill FINAL ho jata hai (PRA reporting ON ho to PRA ko report hota hai).
   - "Save Provisional" (ya F9) → bill L-series LOCAL/provisional ban jata hai — PRA ko report NAHI hota, quota nahi katta; baad mein promote kar sakte hain.
7. Receipt popup khulta hai — Print (P), KOT (K), ya Enter se nayi sale. Popup default 10 second mein khud band hota hai (mouse le jao to timer rukta hai); yeh waqt /pos/customize se badal sakte hain.

Sale screen ki mazeed cheezein:
- Search hamesha GLOBAL hai: category dropdown/pills sirf GRID filter karti hain — search box har category ka item dhoondta hai, deals aur services samet.
- Search mein pehle harf ko priority milti hai (jo item aapke likhe harf se shuru ho wo upar).
- Product grid ON/OFF toggle hai — grid OFF ho aur products ghayab lagein to "Show All Products" ya Products toggle dobara ON karein (yeh setting har PC/browser par alag save hoti hai).
- Manual entry: item inventory mein na ho to "Manual" button se naam + price khud likh kar cart mein dalein; "Save Permanent" tick karein to product master mein bhi save ho jata hai.
- Quick Type Mode (F7): "chai 2, samosa 1" jaisi line likhein — pura order khud cart mein aa jata hai. DEFAULT BAND hai; admin /pos/customize se ON kare.
- Rush button: order ko priority mark karta hai — KOT/kitchen screen par RUSH ka nishan aata hai.
- Hold (F5): order ko hold karein (Dine-In ke liye) — baad mein F3 se wapas kholein. Manual items aur deals hold NAHI ho sakte (sirf seedha bill).
- Screen Fit: header ke "Fit" menu se poori screen 80–125% adjust karein (chhoti screens par khud 90% ho jata hai). Har PC par alag save hota hai.
- Discount limit DONO types par lagti hai: percentage bhi aur Rs. amount bhi — amount discount subtotal ke limit% se zyada nahi ho sakta (default 50%). Manager PIN override se zyada ho sakta hai.
- Offline mode: internet chala jaye to bill queue mein save hota hai aur net aane par khud PRA ko chala jata hai.
- Saaf style ho to kam-istemaal buttons (Rush, Fit, Keys, Quick), guided steps ki patti aur category pills "Mazeed" button ke peechay hoti hain — Mazeed dabane se dikh jati hain. Saare features waise hi kaam karte hain.

## Keyboard Shortcuts (Sale Screen) — MUKAMMAL LIST
- F1 = shortcuts ki madad wali screen kholo/band karo.
- F2 = search box par focus (search mode).
- F3 = Held Orders ki list kholo (hold kiye hue orders wapas lene ke liye).
- F4 = poora cart khali karo (confirm poochta hai).
- F5 = current order HOLD karo.
- F6 = cart mode — cart ke aakhri item par focus (qty/edit ke liye). Ctrl+E bhi yehi karta hai.
- F7 = Quick Type modal (agar company mein ON hai).
- F8 = PAY — payment modal kholo. Phir 1 = Cash, 2 = Card.
- F9 = Save Provisional (local bill seedha save).
- F10 = Local/Provisional bills ka modal.
- F11 = Failed/offline PRA bills ka modal (Edit & Retry yahin se).
- Alt+R = Reprint modal (aaj ke bills ki list, click = print).
- Alt+P = customer phone box par focus.
- Ctrl+S = search par focus.
- T = selected cart item ka TAX ON/OFF; D = discount panel; N = note box. (Search box ke andar type karte waqt yeh letters normal type hote hain — wahan Alt+T / Alt+D / Alt+N use karein, wo har jagah chalte hain.)
- Enter = guided flow mein agla step; Escape = modal band.
- Guided Flow (default ON): Customer → Items → Order Type (1/2/3 se chunein) → Pay — sirf Enter dabate jayen, chain khud chalti hai. /pos/customize se ON/OFF hota hai.

## Payment aur Receipt
- Payment methods: Cash aur Card (payment modal mein; keyboard 1 = Cash, 2 = Card). Har method ka apna tax rate ho sakta hai (aam default: cash 16%, card/digital 8%; company ke liye alag rate bhi ho sakta hai).
- Bill ka grand total hamesha poore rupay mein round hota hai (line items 2 decimal tak).
- Receipt 80mm ya 58mm thermal printer par print hoti hai — paper size /pos/receipt-settings ya PRA Settings dono jagah se badlein; print khud printer ki asal width par fit hota hai.
- Receipt par kya kya nazar aaye (dukan ka address, NTN, email, mobile, cashier ka naam, tax, footer note) — sab /pos/receipt-settings se control hota hai.
- PRA receipt aur Local receipt ki settings ALAG ALAG hain — dono ka apna set /pos/receipt-settings par hai.
- "Show Tax" OFF karne se customer copy par Subtotal aur Tax chhup jate hain — sirf grand TOTAL nazar aata hai; items apni asal price par dikhte hain. Lekin PRA ko tax hamesha POORA submit hota hai.
- Receipt ka default style: bold + center logo; company chahe to plain style choose kar sakti hai. Text gehra/bold hai taake saste printers par bhi saaf chape.
- Kisi bhi purane bill ki receipt dobara: sale screen par Alt+R (Reprint) — ya /pos/transactions se bill khol kar receipt/PDF.
- Bill ka share link: /pos/transactions se bill kholein → share link banayen — customer ko WhatsApp waghera par bhej sakte hain.

## Printer aur Hardware (Setup)
- NestPOS kisi bhi aam thermal receipt printer (80mm/58mm) ke saath chalta hai — USB, network ya Bluetooth; printer Windows/browser mein install ho to print ho jayegi.
- Printer setup: (1) printer PC se connect kar ke driver install karein, (2) browser ke print dialog mein wohi printer select karein, (3) /pos/receipt-settings par paper size set karein.
- SILENT printing (bina print dialog ke): Desktop Sync Agent install karein, phir /pos/printer-settings par jayen → Receipt Printer aur KOT Printer list se chunein → Silent Printing ON karein. Ab receipt/KOT seedha printer par jayenge.
- "Printer Settings mein printers ki list khali hai": PC par PURANA agent chal raha hai — /pos/printer-settings → "Agent Setup" → "Download ZIP" se naya agent lein, extract kar ke install.bat chalayen (settings mehfooz rehti hain). 5 minute mein list aa jayegi.
- Barcode scanner: koi bhi USB/Bluetooth scanner jo keyboard ki tarah type karta hai seedha chal jata hai — sale screen ke search box mein scan karein. Alag setup nahi chahiye.
- Cash drawer aksar printer ke kick port se juda hota hai — receipt print par khul jata hai; yeh printer ki apni setting hai.
- Product barcode stickers khud print karne hon: /pos/products/labels — products chunein aur stickers print karein.

## PRA Reporting (Fiscal)
- PRA reporting ON ho to har final bill PRA ko report hota hai aur POS-YYYY-NNNNN fiscal serial milta hai; receipt par PRA fiscal number aur QR aata hai.
- Reporting ke BAGHAIR bane bills (provisional ya reporting-OFF finals) L-series serial par rehte hain.
- Internet na ho ya PRA down ho to bill 'offline' queue mein jata hai aur khud retry hota hai — quota dobara nahi katta.
- PRA se reject bills F11 modal mein aate hain — wahan se Edit & Retry karein (bill theek kar ke dobara bhejein).
- Per-cashier PRA toggle: /pos/team par har cashier ke liye PRA reporting alag ON/OFF ho sakti hai.
- PRA Settings (/pos/pra-settings, sirf admin): PRA POS ID, token, environment, paper size, aur Confidential PIN yahan set hote hain. "Test Connection" se connection check karein.
- Confidential PIN: 4-6 digit PIN jo delete/void jaisi hassas actions ko lock karta hai — PRA Settings se set/remove hota hai; manager override bhi isi PIN se hota hai.
- PRA Connection ke 2 modes: (1) Cloud — server seedha PRA ko bhejta hai; (2) Fiscal Device — dukan ke PC par Desktop Sync Agent bills PRA ko bhejta hai. NAYE PRA registrations ke liye Fiscal Device mode zaroori hai. Yeh setting support/admin set karta hai.
- Invoice Submission Mode (/pos/agent, Cloud mode wali companies): "Agent Sync" = Desktop Agent bills PRA ko bhejta hai; "Direct Production" = server seedha bhejta hai. Direct par switch karne se silent printing BAND NAHI hoti — agent connected rehta hai. Fiscal Device mode mein Direct available nahi (PRA ka rule).
- Desktop Sync Agent: Windows PC par chalta hai; bills PRA ko submit karta hai aur silent printing bhi isi se hoti hai. Download: /pos/agent ya /pos/printer-settings ke Agent Setup se.

## Provisional / Local Bills
- Provisional (local) bill = abhi PRA ko report NAHI hua; L-series number; quota nahi katta.
- Banane ka tareeqa: sale screen par "Save Provisional" button ya F9.
- Dekhne ke tareeqay: sale screen par F10 modal, ya /pos/local-bills portal (export bhi ho sakta hai).
- F10 modal ke andar har local bill par actions: Edit, Delete, aur "Make Final" (promote).
- Promote = local bill ko PRA final banana: F10 modal mein "Make Final" → payment method chunein (1=Cash...) → naya POS fiscal serial milta hai aur monthly quota use hota hai. Promote sirf USI mahine ke andar ho sakta hai.
- Day-close par us din ke local bills company ki standing policy ke mutabiq save ya delete hote hain — policy /pos/customize → Local Billing se (sirf admin).
- PRA ko report ho chuke bills kabhi delete nahi hote — sirf pure local bills wash hote hain.
- Purane (archive kiye) local bills: /pos/archive par nazar aate hain (export bhi).

## Day Close aur Opening Cash
- Opening Cash: din ke shuru mein Dashboard ke "Opening Cash" card par drawer ka cash amount (+ optional note) enter kar ke Save — cashier bhi kar sakta hai; sirf aaj ke liye; din close hone ke baad lock.
- Day-close karne ka tareeqa (manual): /pos/day-close kholein → Z-report ka khulasa dekhein (total bills, gross/net sales, tax, payment breakdown) → cash reconciliation mein GINA HUA cash dalein (variance khud calculate hota hai) → rider cash ka khata dekhein → "Close Day" button dabayen.
- Auto day-close: koi manually na kare to agle din subah 6:00 baje system khud band kar deta hai (/pos/customize se ON/OFF).
- Z-report mein: sales khulasa, pichle din/haftay se comparison, average bill, top products, category-wise sales, hourly chart, PRA submission health (submitted/pending/offline/failed), cash reconciliation, rider summary.
- Z-report A4 PDF aur 80mm thermal dono par print hoti hai; purani Z-reports bhi day-close page se dekhi ja sakti hain.
- Cash reconciliation ka formula: expected cash = opening cash + cash sales − rider ko diya cash + rider se wapas aya cash.

## Products (/pos/products)
- Naya product: /pos/products → Add — naam, price, cost price, tax rate, SKU, barcode, unit, category, image, stock, low-stock threshold.
- Category ke hisab se extra fields bhi hain: Pharmacy (batch, expiry), Kapre (size, color), Electronics (serial/IMEI, warranty), Automotive (part number) waghera.
- Product ON/OFF (inactive) toggle aur "sale screen par dikhao" (show on sale) toggle har product par hai — item search mein na mile to yahan check karein.
- Excel import/export: pehle /pos/products/template se .xlsx template download karein → bhar kar /pos/products par Import karein (barcode/SKU/naam se khud match hota hai). CSV use NA karein — barcode kharab ho jate hain.
- Barcode stickers: /pos/products/labels se print karein.
- Services (agar dukan services bhi deti hai): /pos/services par alag list hai — yeh bhi sale screen ke search mein aati hain.

## Inventory / Stock (/pos/inventory)
- Inventory ON/OFF: /pos/features (POS Features) page se. OFF ho to nav mein grey "OFF" badge; pages POS Features par redirect hote hain.
- Stock adjust karna: /pos/inventory/adjust — product chunein → Add / Remove / Set → reason likhein → save. Har tabdeeli ka record Movements (/pos/inventory/movements) mein rehta hai.
- Low stock: /pos/inventory/low-stock — threshold se neeche items ki list; threshold product par set hota hai.
- Sale par stock khud katta hai; bill delete par wapas aana ("Restock on Void") /pos/customize ki setting hai.
- Deals ka stock deal ke andar ke items se katta hai; recipes wali dishes ka stock ingredients se katta hai.

## Deals (/pos/deals)
- Deal banana: /pos/deals → New Deal → naam, price, description, din chunein (Mon–Sun checkboxes) → items add karein (product + quantity) → save.
- Deal ki price server enforce karta hai — cashier badal nahi sakta.
- Deal sirf apne set kiye dino par sale screen mein aati hai.
- Deals sirf seedhi billing ke liye hain — hold/KOT par nahi ja sakti.

## Restaurant Module (Pro/Unlimited packages)
- Chahiye: Pro ya Unlimited package (ya active trial), phir /pos/features se Restaurant ON karein.
- Order types ke rules: Dine-In = pehle Hold/KOT, khana banne ke baad payment; Takeaway = seedha final bill; Delivery = final ya provisional dono.
- Tables/Floors banana: /pos/restaurant/table-management → "+ Add Floor" (manzil ka naam) → "+ Add Table" (table number + seats).
- Dine-In order: sale screen par order type "Dine In" chunein → table picker khud khulta hai → table select → items dalein → Hold/KOT bhejein → khana ban jaye to F3 se order wapas khol kar payment karein.
- Stations/Counters (KOT routing): /pos/restaurant/kitchen-settings → "+ Add Counter" → naam (jaise "Grill") → printer chunein (Desktop Agent ki list se) → categories tick karein — un categories ke items ka KOT usi counter par jayega.
- KDS (Kitchen Display Screen): /pos/restaurant/kds — kitchen account login karta hai; orders cards mein aate hain; "Preparing" → "Ready" buttons; Clear se hata dein. KOT ka barcode scan kar ke bhi clear ho sakta hai.
- Kitchen account: /pos/team se Kitchen role ka login banayen — yeh sirf KDS dekhta hai, team limit mein nahi ginta.
- Waiter: /pos/waiter portal — waiter apne login se table chunta hai, items dalta hai, cashier select kar ke "Send Order" karta hai. Cashier ko sale screen par bell icon (incoming orders) par badge nazar aata hai — order khol kar payment karein.
- QR Menu / Public Profile: /pos/business-profile → "Enable Public Page" + "Show Menu" ON karein → menu tab mein products add karein (drag se order badlein) → QR code customer ko dikhayen; customer apne phone par menu dekhta hai. "Regenerate Link" se naya link banta hai.
- Recipes / Ingredients: /pos/restaurant/ingredients par kachha maal banayen (naam, unit, cost, min stock; stock adjust bhi yahin) → /pos/restaurant/recipes par har dish ki recipe set karein (kaunsa ingredient kitna lagta hai). Dish bikne par ingredients ka stock khud katta hai.

## Delivery Riders
- Riders banana: /pos/riders — naam, phone, CNIC, vehicle number. "Create Login" se rider ka email+password banayen (rider sirf /pos/rider portal dekhta hai; team limit mein NAHI ginta).
- Rider assign karna: bill BANNE KE BAAD /pos/deliveries board par bill ke saamne "Assign Rider" dropdown se rider chunein (payment modal mein assign nahi hota).
- Delivered mark: rider apne portal se ya cashier board se "Delivered" kare.
- Rider cash settle karna: /pos/deliveries board par rider ke card par "Settle Cash" → jo bills settle ho rahe hain tick karein → "Settle Selected Bills". Partial settlement ho sakta hai.
- Day-close par rider ka poora khata (owed vs settled) nazar aata hai.
- "Delivery Manager" role: sirf deliveries board + settlement tak — free, limit mein nahi ginta.
- "Returned" mark karne se PRA wala bill cancel NAHI hota — yeh sirf andaruni record hai.

## Team / Roles (/pos/team)
- Member add karna: /pos/team → naam, email, phone, password, role chunein → save.
- Roles: Manager = admin jaisa (settings, reports, sab); Cashier = sirf billing. Kitchen, Waiter, Rider, Delivery Manager = mehdood, FREE (limit mein nahi ginte).
- Manager aur Cashier package ki account limit mein ginte hain (Starter 1, Business 5, Pro 10, Unlimited unlimited).
- Cashier ON/OFF toggle bhi team page par hai (band cashier login nahi kar sakta).
- Har cashier ke liye PRA reporting alag ON/OFF karne ka toggle bhi yahin hai.
- Company admin team members ke passwords /pos/team par dekh sakta hai (sirf admin ko nazar aate hain).

## Packages aur Billing (/pos/billing)
- Starter Rs 9,999/saal: 1 team account, 500 final bills/mahina.
- Business Rs 14,999/saal: 5 accounts, 2,000 bills/mahina.
- Pro Rs 24,999/saal: 10 accounts, 3,000 bills/mahina, 2 branches, Restaurant module.
- Unlimited Rs 39,999/saal: sab unlimited.
- Billing sirf saalana hai (6% discount pehle se shamil). Plans ki tafseel /pos/billing par.
- Payment ka saboot (screenshot/slip) /pos/billing se upload karein — admin verify kar ke package activate karta hai.
- Sirf FINAL bills quota mein ginte hain — provisional FREE hain jab tak promote na hon; offline retry dobara nahi ginta. Quota har mahine reset hota hai.

## Tax Settings
- Tax Pricing ke 3 modes (/pos/customize se, sirf admin):
  1. **Standard (Tax Upar Se)**: menu price par tax alag se lagta hai.
  2. **Menu Rate Final — Sab Same**: menu price hi grand total hai, har payment method par same.
  3. **Menu Rate Final — Card Bachat**: menu price cash ke hisab se final; card/digital par thora sasta — receipt par "Card Discount" nazar aata hai.
- Tax rates: default cash 16%, card/digital 8% — company ke liye alag rates support/admin set kar sakta hai.
- Mode badalne se purane bills nahi badalte — sirf naye bills par lagta hai.
- PRA ko tax hamesha poora aur sahi submit hota hai, chahe receipt par dikhaya jaye ya nahi.

## Customers (/pos/customers)
- Saray customers ki list, phone/naam se search, ON/OFF toggle.
- Har customer ki poori history: /pos/customers → customer par click → us ke saray bills; history export/PDF bhi ho sakti hai.
- Customers ka Excel import/export bhi hai (template download kar ke import karein).
- Sale screen se bhi naya customer foran ban jata hai (phone box mein naam+phone likh kar Add).

## Customize POS (/pos/customize) — sirf admin/manager
- POS Ka Style: Full (default) ya Saaf (seedha saada) + kuch fancy styles.
- Theme colors (purple ke ilawa aur themes).
- Guided Keyboard Flow ON/OFF.
- Quick Type Mode ON/OFF.
- Receipt Popup Auto-Close: Kabhi nahi / 5 / 10 / 15 / 30 sec (default 10).
- Local Billing policy: day-close par local bills save ya delete.
- Auto Day-Close ON/OFF (subah 6 baje).
- Tax Pricing mode.
- Inventory se related toggles (restock on void waghera).

## Reports
- /pos/reports: date range ke sath Sales Analytics — charts, payment breakdown, top products, category performance, PDF/CSV export. Profit ka data sirf admin ko.
- Tax Reports (/pos/tax-reports): PRA submitted bills ka record, tabs aur rate filter ke sath; CSV/PDF export. "Local Invoices" tab sirf admin ko.
- /pos/transactions: saray bills ki list — date, payment method, cashier ke filters; bill khol kar receipt/PDF/share link; failed bills par Retry (akela ya bulk); local bills par promote/delete.
- Day Close ki purani Z-reports day-close page se.

## What's New aur Feature Suggestions
- Naye features ki ittila: top-nav ka bell icon + one-time popup (sirf admin/manager).
- Apni tajweez bhejein: top-nav bulb icon → /pos/suggestions (sirf admin/manager; din mein 10 tak). Status wahin nazar aata hai: pending → planned → completed (admin ke note ke sath).
- Madadgar (yeh bot) se bhi masla ya feature request seedha admin team ko bhej sakte hain — bot khulasa bana kar confirm karega, "Haan" par bhej dega aur Ref number milega.

## Bill Reprint (Aaj ke Bills)
- Sale screen par Alt+R (ya Reprint button) — aaj ke SAB bills ki list: PRA walay, Local, Sync Queue, Failed, Provisional.
- Bill par click = receipt foran print (bilkul asal jaisi, koi COPY label nahi).
- Search box se serial, customer naam ya raqam se dhoondein. ↑↓ se select, Enter se print.
- Silent printing ON ho to seedha printer par, warna print window.
- Purane dino ke bills ke liye /pos/transactions use karein.

## PWA / Mobile / Offline
- NestPOS ko phone/tablet/PC par app ki tarah install karein — browser ka "Add to Home Screen" / install icon.
- Offline mode: pages cache hote hain; net wapas aane par offline bills khud sync ho jate hain.
- App khud update hota hai — update par chhota toast aata hai aur app refresh hota hai (bill banate waqt kabhi beech mein refresh nahi hota).

## Aam Masail (Troubleshooting)
- "Bill PRA par nahi ja raha": internet check karein; bill 'offline' queue mein hoga aur khud retry hoga. Fiscal Device mode mein Desktop Agent ka chalta hona zaroori hai (dukan ke PC par).
- "Login nahi ho raha": sahi panel use karein (/pos/login); Forgot Password se reset; 5 ghalat koshishon par thori der lock.
- "Receipt par tax nahi dikh raha": /pos/receipt-settings par "Show Tax" ON karein.
- "Printer poora width use nahi kar raha / kat raha hai": receipt-settings mein paper size (80mm/58mm) check karein, phir printer driver.
- "Item search mein nahi mil raha": /pos/products par check karein — product inactive to nahi, naam/barcode sahi hai? Search har category mein dhoondti hai.
- "Sale screen par products ghayab hain": Products grid ka toggle OFF hai — "Show All Products" dabayen.
- "Bills ki limit khatam ho gayi": package upgrade karein ya filhal provisional bills banayen (baad mein promote).
- "Team member add nahi ho raha": package ki account limit poori ho chuki hai — upgrade karein.
- "Restaurant features nazar nahi aa rahe": Pro ya Unlimited package chahiye, phir /pos/features se ON karein.
- "Dashboard par Opening Cash nazar nahi aa raha": din pehle hi close ho chuka hai — kal subah enter karein.
- "Deal ki price ghalat lag rahi hai": deal ke din check karein — deal sirf apne set kiye dino par chalti hai.
- "KOT kitchen par nahi aa raha": /pos/restaurant/kitchen-settings par station/printer check karein aur Desktop Agent chalta ho.
- "Silent print nahi ho rahi": /pos/printer-settings par Silent Printing ON ho, printer select ho, aur Desktop Agent chalta ho. Setting badalne ke baad sale screen refresh karein.
- "Screen chhoti/bari lag rahi hai": sale screen ke "Fit" menu se size adjust karein.
- "Hold nahi ho raha": Hold sirf Dine-In orders ke liye hai; manual items aur deals hold nahi ho sakte.
- Kisi bhi aur masle ke liye WhatsApp support ya Madadgar se escalation bhej dein.
