# NestPOS (PRA POS) — Madadgar Knowledge Base (Mukammal)
Yeh NestPOS ka mukammal guide hai. Sirf is guide ki maloomat se jawab do.

## Login aur Account
- POS login: /pos/login — Email, Phone, Username, CNIC ya NTN se login ho sakta hai (CNIC/NTN se company admin login hota hai).
- Password bhool jayein: login page par "Forgot Password" → email par OTP aata hai → naya password set karein.
- 5 ghalat koshishon par login thori der ke liye lock ho jata hai — kuch minute intezar kar ke dobara koshish karein.
- POS ka user sirf /pos/login par login kar sakta hai — kisi aur panel (Digital Invoice waghera) par "Invalid credentials" aayega. Yeh security ke liye hai.
- Har company ka data bilkul alag (isolated) hai — koi doosri dukan ka data nahi dekh sakta.
- Nayi company register hone ke baad admin approval tak sab pages DEKH sakte hain lekin kaam (bill banana waghera) approval ke baad hi hota hai.

## Dashboard
- Dashboard par KPI cards (aaj ki sales, bills, waghera), Opening Cash entry, aur quick links hote hain.
- Dashboard ka style badalna ho: /pos/customize ke "POS Ka Style" section se — "Full" (mukammal) ya "Saaf" (seedha saada, Roman Urdu) — aur kuch mazeed fancy styles bhi hain. Sirf admin/manager badal sakta hai.
- Theme colors bhi /pos/customize se badalte hain (purple ke ilawa aur bhi themes).

## Sale Screen (Nayi Bill / Invoice)
- Sale screen kholne ke liye: Dashboard par "Nayi Sale" ya menu se POS/Invoice Create.
- Item add karne ke 3 tareeqay: (1) search box mein naam/barcode/SKU type karein, (2) product grid se click karein, (3) barcode scanner se scan karein — scan exact match foran cart mein daal deta hai (chahe koi category filter lagi ho).
- Search mein pehle harf ko priority milti hai: jo items aap ke likhe harf se shuru hote hain wo sab se upar aate hain.
- Category dropdown se items filter hote hain; search us waqt selected category ke andar hoti hai — item na mile to category "All" par rakhein.
- Product grid ON/OFF toggle bhi hai — grid OFF ho to search se hi kaam chalta hai.
- Quantity badhane ke liye cart row mein qty par click kar ke type karein, ya same item dobara add karein.
- Item hatane ke liye cart row ke delete (X) par click karein.
- Har cart row par per-item TAX/NO TAX toggle, Discount aur Note ka option hai.
- Manual entry: agar item inventory mein nahi (ya inventory OFF hai) to naam + price khud type kar ke bill mein daal sakte hain — yeh bhi normal bill ki tarah process hota hai.
- Quick Type Mode (F7): "chai 2, samosa 1" type karein aur pura order seedha cart mein aa jata hai — khaane-peene ki dukaano ke liye behtareen. Yeh feature DEFAULT BAND hai; admin Customize POS (/pos/customize) se "Quick Type Mode" toggle ON kar sakta hai. Toggle OFF ho to sale screen par Quick button aur F7 dono kaam nahi karte.
- Customer select karna OPTIONAL hai — walk-in customer ke liye chhor dein.
- Screen Fit: action bar mein "Fit" dropdown se poori screen ka size 80–125% adjust karein (chhoti screens par khud 90% ho jata hai).
- Receipt popup: payment ke baad receipt ka popup khulta hai — print karein ya khud band karein; yeh khud gayab nahi hota taake receipt miss na ho.
- Saaf style (Jul 2026): agar company ka POS style "Saaf — Simple" ho to sale screen bhi saada look mein khulti hai — teal rang, bade search box ke saath. Kam-istemaal buttons (Rush, Fit, Keys, Quick) "Mazeed" button ke peechay chhupe hote hain — Mazeed dabane se dikh jate hain. SAARE features (shortcuts, hold, kitchen, local bills) waise hi kaam karte hain.
- Discount limit DONO types par lagti hai (Jul 2026): percentage bhi aur Rs. amount bhi — amount discount subtotal ke limit% se zyada nahi ho sakta (default 50%). Manager PIN override se limit barh sakti hai.

## Keyboard Shortcuts (Sale Screen)
- Guided Flow (default ON): sirf Enter dabate jayen — search → item → qty → payment tak seedha chain chalta hai. Customer step optional hai.
- Enter = agla step; Escape = modal band.
- F3 = Dine-In table picker (restaurant mode).
- F6 = Waiter/held orders (restaurant mode).
- F8 = QR menu / public profile.
- F10 = Local (provisional) bills ka modal.
- F11 = Failed/offline bills ka modal (Edit & Retry yahin se).
- T = Tax toggle, D = Discount, N = Note — cart ke selected item par (jab focus kisi input mein na ho). Alt+T / Alt+D / Alt+N kahin se bhi chal jate hain.

## Payment aur Receipt
- Payment methods: Cash, Card, Digital — har method ka apna tax rate ho sakta hai (aam default: cash 16%, card/digital 8%; company ke liye alag rate bhi set ho sakta hai).
- Bill ka grand total hamesha poore rupay mein round hota hai (line items 2 decimal tak rehte hain).
- Receipt 80mm ya 58mm thermal printer par print hoti hai — paper size /pos/receipt-settings ya PRA Settings dono jagah se badal sakte hain; print khud printer ki asal width par fit hota hai.
- Receipt par kya kya nazar aaye (dukan ka address, NTN, email, mobile, cashier ka naam, tax, footer note) — sab /pos/receipt-settings se control hota hai.
- PRA receipt aur Local receipt ki settings ALAG ALAG hain — dono ka apna set /pos/receipt-settings par hai.
- "Show Tax" OFF karne se customer copy par Subtotal aur Tax chhup jate hain — sirf grand TOTAL nazar aata hai; items apni asal (as-entered) price par dikhte hain. Lekin PRA ko tax hamesha POORA submit hota hai.
- Receipt ka default style: bold + center logo. Company chahe to plain style bhi choose kar sakti hai.

## PRA Reporting (Fiscal)
- PRA reporting ON ho to har final bill PRA ko report hota hai aur usay POS-YYYY-NNNNN fiscal serial milta hai; receipt par PRA ka fiscal number aur QR aata hai.
- Reporting ke BAGHAIR banaye gaye bills (provisional ya reporting-OFF finals) L-series serial par rehte hain.
- Internet na ho ya PRA ka system down ho to bill 'offline' queue mein chala jata hai aur khud-ba-khud retry hota hai — quota dobara nahi katta.
- PRA se reject hone wale bills F11 modal mein aate hain — wahan se Edit & Retry karein.
- Per-cashier PRA toggle: company admin har cashier ke liye PRA reporting alag se ON/OFF kar sakta hai (kuch cashiers report karein, kuch na karein).
- PRA Connection ke 2 modes hain: (1) Cloud — server seedha PRA ko bhejta hai; (2) Fiscal Device — dukan ke PC par Desktop Sync Agent install hota hai jo bills PRA ko bhejta hai. NAYE PRA registrations ke liye Fiscal Device mode zaroori hota hai. Yeh setting support/admin set karta hai.
- Desktop Sync Agent: Windows PC par chalta hai, server se bills utha kar PRA ko submit karta hai; silent printing (bina print dialog ke receipt/KOT print) bhi isi se hoti hai.

## Provisional / Local Bills
- Provisional (local) bill = abhi PRA ko report NAHI hua; L-series number milta hai; quota bhi nahi katta.
- Local bills F10 modal mein ya Local Bills Portal mein nazar aate hain.
- Promote = local bill ko PRA final banana — sirf USI mahine ke andar ho sakta hai; naya POS fiscal serial milta hai aur monthly quota use hota hai.
- Day-close par us din ke local bills company ki standing policy ke mutabiq save ya delete hote hain — policy /pos/customize → Local Billing se set hoti hai (sirf admin).
- PRA ko report ho chuke bills kabhi delete nahi hote — sirf pure local bills wash hote hain.

## Day Close aur Opening Cash
- Din ke shuru mein Dashboard par "Opening Cash" (drawer mein jitna cash rakha) enter karein — cashier bhi kar sakta hai; sirf aaj ke liye; din close hone ke baad lock ho jata hai.
- Day-close 2 tarah hota hai: manually (Reports/Day Close se) ya raat 12 baje khud-ba-khud.
- Day-close par Z-report milti hai: sales ka khulasa, pichle dino se comparison, top products, hourly chart, PRA submission health, cash reconciliation — A4 PDF aur 80mm thermal print dono.
- Cash reconciliation: expected cash = opening cash + cash sales − rider ko diya cash + rider se wapas aya cash; ginti dal kar variance khud calculate hota hai.

## Inventory / Products
- Products: /pos/products — naam, price, barcode, SKU, category, stock.
- Excel import/export: bulk products .xlsx file se import/export karein (CSV use NA karein — barcode kharab ho jate hain). Pehle export kar ke template dekh lein.
- Inventory ON/OFF: POS Features page se. OFF ho to nav mein grey "OFF" badge nazar aata hai aur pages POS Features par redirect hote hain.
- Inventory dashboard mein Stock, Movements aur Low-Stock pages hain; sale par stock khud katta hai.
- Deals: din ke hisab se deals banayen (jaise "Lunch Deal sirf Mon-Fri") — deal ki price server enforce karta hai, stock deal ke andar ke items se katta hai. Deals sirf billing ke liye hain (hold/KOT par nahi).

## Restaurant Module (Pro/Unlimited packages)
- Restaurant features ke liye Pro ya Unlimited package (ya active trial) chahiye, phir POS Features se ON karein.
- Order types aur unke rules: Dine-In = pehle Hold/KOT, khana ban’ne ke baad payment; Takeaway = seedha final bill; Delivery = final ya provisional dono ho sakte hain.
- Dine-In: F3 se table picker khulta hai — table choose karein, order KOT par jata hai.
- KOT (Kitchen Order Ticket) counter/station ke hisab se route hota hai — har station ka apna printer/screen ho sakta hai.
- Kitchen account: alag login jo KDS (Kitchen Display Screen) par orders dekhta hai aur ready mark karta hai.
- Waiter tablets: waiter apne login se orders le sakta hai (F6 se held orders).
- QR Menu: public QR profile se customer apne phone par menu dekh sakta hai (F8).

## Delivery Riders
- Riders /pos/deliveries board se manage hote hain — rider ki assignment BILL BANNE KE BAAD board se hoti hai (payment modal mein nahi).
- Rider ka cash khata: rider ko diye gaye cash bills, partial settlement, aur day-close par poori reconciliation.
- Rider ka apna alag login hota hai (sirf apna rider portal dekhta hai) — team limit mein NAHI ginta.
- "Delivery Manager" role: sirf deliveries board aur settlement tak mehdood — yeh bhi free hai, limit mein nahi ginta.
- "Returned" mark karne se PRA wala bill wapas/cancel NAHI hota — yeh sirf andaruni record hai.

## Team / Roles
- Team page: /pos/team — Manager ya Cashier add karein.
- Manager = admin jaisa (settings, reports, sab kuch); Cashier = sirf billing (settings/reports nahi).
- Kitchen, Waiter, Rider, Delivery Manager roles team limit mein NAHI ginte (free hain) lekin apne apne kaam tak mehdood hain.
- Manager aur Cashier package ki account limit mein ginte hain (Starter 1, Business 5, Pro 10, Unlimited unlimited).
- Company admin team members ke passwords /pos/team par dekh sakta hai (sirf admin ko nazar aate hain).

## Packages (Annual — saalana)
- Starter Rs 9,999/saal: 1 team account, 500 final bills/mahina.
- Business Rs 14,999/saal: 5 accounts, 2,000 bills/mahina.
- Pro Rs 24,999/saal: 10 accounts, 3,000 bills/mahina, 2 branches, Restaurant module.
- Unlimited Rs 39,999/saal: sab unlimited.
- Billing sirf saalana hai (6% discount pehle se shamil).
- Sirf FINAL bills quota mein ginte hain — provisional bills FREE hain jab tak promote na hon; offline retry dobara nahi ginta.
- Quota har mahine reset hota hai. Limit barhani ho to package upgrade karein — admin se rabta karein ya WhatsApp support.

## Tax Settings
- Tax Pricing ke 3 modes hain (/pos/customize se, sirf admin):
  1. **Standard (Tax Upar Se)**: menu price par tax alag se lagta hai.
  2. **Menu Rate Final — Sab Same**: menu price hi grand total hai, har payment method par same.
  3. **Menu Rate Final — Card Bachat**: menu price cash ke hisab se final; card/digital par thora sasta parta hai — receipt par "Card Discount" nazar aata hai.
- Tax rates: default cash 16%, card/digital 8% — company ke liye alag rates support/admin set kar sakta hai.
- Mode badalne se purane bills nahi badalte — sirf naye bills par lagta hai.
- PRA ko tax hamesha poora aur sahi submit hota hai, chahe receipt par tax dikhaya jaye ya nahi.

## Customize POS (/pos/customize)
- POS Ka Style: dashboard ka style (Full ya Saaf + fancy styles). Saaf chunne par dashboard ke saath sale screen bhi saada teal look mein aa jati hai.
- Theme colors.
- Local Billing policy (day-close par local bills save ya delete).
- Tax Pricing mode.
- Yeh page sirf admin/manager ke liye hai.

## Reports
- /pos/reports: date range ke sath Sales Analytics — charts, payment method breakdown, top products, PDF export.
- Tax Reports alag page par: PRA submitted bills ka record, tabs aur rate filter ke sath. "Local Invoices" tab sirf admin ko nazar aata hai.
- Profit ka data sirf admin ko nazar aata hai.
- Day Close ki purani Z-reports bhi dekhi ja sakti hain.

## What's New aur Feature Suggestions
- Naye features ki ittila: top-nav ka bell icon + one-time popup (sirf admin/manager ko nazar aata hai).
- Apni tajweez (suggestion) bhejein: top-nav ka bulb icon → /pos/suggestions (sirf admin/manager; din mein 10 tak).
- Tajweez ka status wahin nazar aata hai: pending → planned → completed (admin ka note bhi).
- Madadgar (yeh bot) se bhi masla ya feature request seedha admin team ko bhej sakte hain — bot khulasa bana kar confirm karega, "Haan" par bhej dega aur Ref number milega.

## PWA / Mobile / Offline
- NestPOS ko phone/tablet/PC par app ki tarah install kar sakte hain — browser ka "Add to Home Screen" / install icon.
- Offline mode: pages cache hote hain; internet wapas aane par offline bills khud sync ho jate hain.
- App khud update hota hai — update aane par chhota toast nazar aata hai aur app refresh ho jata hai (bill banate waqt kabhi beech mein refresh nahi hota).

## Aam Masail (Troubleshooting)
- "Bill PRA par nahi ja raha": internet check karein; bill 'offline' queue mein hoga aur khud retry hoga. Fiscal Device mode mein Desktop Agent ka chalta hona zaroori hai (dukan ke PC par).
- "Login nahi ho raha": sahi panel use karein (/pos/login); password reset ke liye Forgot Password; 5 ghalat koshishon par thori der lock.
- "Receipt par tax nahi dikh raha": /pos/receipt-settings par "Show Tax" ON karein.
- "Printer poora width use nahi kar raha / kat raha hai": receipt-settings mein paper size (80mm/58mm) check karein.
- "Item search mein nahi mil raha": category filter "All" par rakhein; product ka naam/barcode /pos/products par check karein.
- "Bills ki limit khatam ho gayi": package upgrade karein ya filhal provisional bills banayen (baad mein promote).
- "Team member add nahi ho raha": package ki account limit poori ho chuki hai — upgrade karein.
- "Restaurant features nazar nahi aa rahe": Pro ya Unlimited package chahiye, phir POS Features se ON karein.
- "Dashboard par Opening Cash nazar nahi aa raha": din pehle hi close ho chuka ho to lock ho jata hai — kal subah enter karein.
- "Deal ki price ghalat lag rahi hai": deal ke din/waqt check karein — deal sirf apne set kiye dino par chalti hai.
- "KOT kitchen par nahi aa raha": station/printer ki setting check karein aur Desktop Agent (silent printing) chalta ho.
- "Screen chhoti/bari lag rahi hai": sale screen ke "Fit" dropdown se size adjust karein.
- Kisi bhi aur masle ke liye WhatsApp support ya Madadgar se escalation bhej dein.
