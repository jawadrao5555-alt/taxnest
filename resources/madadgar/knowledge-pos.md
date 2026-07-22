# NestPOS (PRA POS) — Madadgar Knowledge Base
Yeh NestPOS ka mukammal guide hai. Sirf is guide ki maloomat se jawab do.

## Login aur Panels
- POS login: /pos/login — Email, Phone, Username, CNIC ya NTN se login ho sakta hai.
- Password bhool jayein to login page par "Forgot Password" se email OTP ke zariye reset hota hai.
- Har company ka data bilkul alag (isolated) hai — koi doosri dukan ka data nahi dekh sakta.

## Sale Screen (Nayi Bill / Invoice)
- Sale screen kholne ke liye: Dashboard par "Nayi Sale" ya menu se POS/Invoice Create.
- Item add karne ke tareeqay: (1) search box mein naam/barcode/SKU type karein, (2) product grid se click karein, (3) barcode scanner se scan karein — scan hamesha exact match foran cart mein daal deta hai.
- Search mein pehla harf priority: jo items aap ke likhe harf se shuru hote hain wo sab se upar aate hain.
- Category dropdown se items filter kar sakte hain.
- Quantity badhane ke liye cart row mein qty par click kar ke type karein, ya same item dobara add karein.
- Item hatane ke liye cart row ke delete (X) par click karein.
- Per-item TAX/NO TAX toggle har cart row par maujood hai.
- Quick Type: agar inventory OFF hai to item ka naam + price khud type kar ke bill banayen.
- Manual entry wale items bhi normal bill ki tarah process hote hain.

## Keyboard Shortcuts (Sale Screen)
- Enter = Guided Flow mein agla step (search → qty → payment).
- F3 = Dine-In table picker (restaurant mode mein).
- F6 = Waiter/held orders (restaurant mode).
- F8 = QR menu/public profile se related.
- F10 = Local (provisional) bills modal.
- F11 = Failed/offline bills modal.
- T = cart item par Tax toggle, D = Discount, N = Note (jab focus input mein na ho). Alt+T/D/N kahin se bhi.
- Screen Fit: action bar mein "Fit" dropdown se screen ka size 80–125% adjust karein.

## Payment aur Receipt
- Payment methods: Cash, Card, Digital (har method ka apna tax rate ho sakta hai — cash aam tor par 16%, card/digital 8%, company override ho sakta hai).
- Bill total hamesha poore rupay mein round hota hai.
- Payment ke baad receipt popup khulta hai — print ya dismiss karein; popup khud band nahi hota.
- Receipt 80mm ya 58mm thermal printer par print hoti hai — paper size /pos/receipt-settings ya PRA Settings se badlein.
- Receipt par kya kya nazar aaye (address, NTN, email, mobile, cashier, tax, footer) — sab /pos/receipt-settings se control hota hai.
- "Show Tax" OFF karne se customer copy par sirf grand TOTAL nazar aata hai (tax chhup jata hai) — lekin PRA ko tax poora submit hota hai.

## PRA Reporting (Fiscal)
- PRA reporting ON ho to har final bill PRA ko report hota hai aur POS-YYYY-NNNNN fiscal serial milta hai.
- Reporting ke bina banaye gaye bills L-series serial par rehte hain.
- Internet na ho ya PRA down ho to bill 'offline' queue mein chala jata hai aur khud-ba-khud retry hota hai — dobara charge nahi hota.
- Per-cashier PRA toggle bhi hai — company admin har cashier ke liye alag ON/OFF kar sakta hai.
- Naye PRA registrations ko "Fiscal Device" mode chahiye hota hai: Desktop Sync Agent dukan ke PC par install hota hai jo bills PRA ko bhejta hai. Yeh setting admin/support se set hoti hai.
- Failed bills F11 modal mein nazar aate hain — wahan se Edit & Retry kar sakte hain.

## Provisional / Local Bills
- Provisional (local) bill = abhi PRA ko report NAHI hua; baad mein promote kar sakte hain.
- Local bills F10 modal ya Local Bills Portal mein milte hain.
- Promote = usi mahine ke andar, naya POS serial milta hai aur monthly quota use hota hai.
- Day-close par local bills company policy ke mutabiq save ya delete hote hain (Customize POS → Local Billing, sirf admin).

## Day Close aur Opening Cash
- Din ke shuru mein Dashboard par "Opening Cash" (drawer ka amount) enter karein — cashier bhi kar sakta hai; din close hone ke baad lock ho jata hai.
- Day-close manually karein ya raat 12 baje khud ho jata hai.
- Z-report milti hai: sales ka khulasa, top products, hourly chart, cash reconciliation (variance), PDF + thermal print.
- Cash reconciliation: expected cash = opening + cash sales − rider out + rider in; ginti dal kar variance check karein.

## Inventory / Products
- Products: /pos/products — naam, price, barcode, SKU, category, stock.
- Excel import/export: bulk products .xlsx file se import karein (CSV nahi — barcode kharab ho jate hain).
- Inventory ON/OFF: POS Features se. OFF ho to bhi nav mein grey badge nazar aata hai.
- Stock, movements, low-stock pages inventory dashboard mein hain.
- Deals: day-based deals banayen — deal price server enforce karta hai, stock deal ke items se katta hai.

## Restaurant Module (Pro/Unlimited packages)
- Dine-In: F3 se table picker; order Hold/KOT hota hai, baad mein pay.
- KOT kitchen/station par route hota hai; Kitchen account KDS screen par orders dekhta hai.
- Waiter tablets: waiter apne orders le sakta hai (F6).
- Takeaway = direct final bill. Delivery = final ya provisional.
- QR menu: public QR profile se customer menu dekh sakta hai.

## Delivery Riders
- Riders: /pos/deliveries board se manage hotay hain — rider assign BILL BANNE KE BAAD board se hota hai.
- Rider ka cash khata: rider ko diye bills, partial settlement, day-close par reconciliation.
- Rider login alag hota hai (sirf apna portal dekhta hai), team limit mein nahi ginta.
- "Returned" bill PRA se wapas nahi hota — sirf internal record hai.

## Team / Roles
- Team page: /pos/team — Manager ya Cashier add karein.
- Manager = admin jaisa (settings, reports, sab); Cashier = sirf billing.
- Kitchen, Waiter, Rider, Delivery Manager roles limit mein NAHI ginte (free).
- Team members ki tadaad package par depend karti hai (Starter 1, Business 5, Pro 10, Unlimited unlimited).

## Packages (Annual)
- Starter Rs 9,999/saal: 1 team account, 500 bills/mahina.
- Business Rs 14,999/saal: 5 accounts, 2,000 bills/mahina.
- Pro Rs 24,999/saal: 10 accounts, 3,000 bills/mahina, 2 branches, Restaurant module.
- Unlimited Rs 39,999/saal: sab unlimited.
- Sirf FINAL bills quota mein ginte hain — provisional free hain jab tak promote na hon; offline retry dobara nahi ginta.
- Bills ki limit mahina khatam hone par reset hoti hai. Limit barhani ho to package upgrade karein.

## Tax Settings
- Tax Pricing 3 modes (Customize POS): (1) Standard — tax menu price ke UPAR lagta hai; (2) Menu Rate Final "Sab Same" — menu price hi grand total hai har payment method par; (3) Menu Rate Final "Card Bachat" — menu price cash ke hisab se final, card/digital par thora sasta (Card Discount receipt par nazar aata hai).
- Tax rates: default cash 16%, card/digital 8% — company ke liye alag rate admin/support set kar sakta hai.

## Customize POS
- /pos/customize: dashboard style (Full ya Saaf — simple style), theme colors, Local Billing policy, tax pricing mode, receipt options waghera.
- "Saaf" style = seedha saada dashboard Roman Urdu ke sath.

## Reports
- /pos/reports: date range ke sath sales analytics, breakdowns, PDF export.
- Tax Reports alag page par — PRA submitted bills ka record.
- Profit sirf admin ko nazar aata hai.

## What's New aur Suggestions
- Naye features ki ittila: top-nav bell icon + one-time popup (sirf admin/manager).
- Apni tajweez bhejein: top-nav bulb icon → /pos/suggestions (sirf admin/manager; din mein 10 tak).
- Tajweez ka status wahan nazar aata hai: pending → planned → completed.

## PWA / Mobile
- NestPOS ko phone/tablet par install kar sakte hain (browser ka "Add to Home Screen").
- Offline mode: pages cache hote hain, bills offline queue ho kar sync hote hain.
- App khud update hota hai — update aane par chhota sa toast nazar aata hai.

## Aam Masail (Troubleshooting)
- "Bill PRA par nahi ja raha": internet check karein; bill 'offline' queue mein hoga aur khud retry hoga. Fiscal Device mode mein Desktop Agent chalta hona chahiye.
- "Login nahi ho raha": sahi panel use karein (POS ka user /pos/login par hi ja sakta hai); 5 ghalat koshishon par thori der ke liye lock hota hai.
- "Receipt par tax nahi dikh raha": /pos/receipt-settings par "Show Tax" ON karein.
- "Printer poora width use nahi kar raha": receipt-settings mein paper size (80mm/58mm) check karein.
- "Item search mein nahi mil raha": category filter "All" par rakhein; product ka naam/barcode products page par check karein.
- "Bills ki limit khatam ho gayi": package upgrade karein ya provisional bills use karein (promote baad mein).
- "Team member add nahi ho raha": package ki account limit poori ho chuki hai — upgrade karein.
- "Restaurant features nazar nahi aa rahe": Pro ya Unlimited package chahiye, phir POS Features se ON karein.
