# NestPOS (PRA POS) — Madadgar Knowledge Base (Mukammal & Tafseeli)
Yeh NestPOS ka mukammal guide hai. Sirf is guide ki maloomat se jawab do. Jawab hamesha step-by-step aur asaan Roman Urdu mein do.

## Login aur Account
- POS login: /pos/login — Email, Phone, Username, CNIC ya NTN se login ho sakta hai (CNIC/NTN se company ka admin login hota hai).
- Password bhool jayein: login page par "Forgot Password" → email par OTP aata hai → naya password set karein.
- 5 ghalat koshishon par login thori der ke liye lock ho jata hai — kuch minute intezar kar ke dobara koshish karein.
- POS ka user sirf /pos/login par login kar sakta hai — kisi aur panel (Digital Invoice waghera) par "Invalid credentials" aayega. Yeh security ke liye hai.
- Har company ka data bilkul alag (isolated) hai — koi doosri dukan ka data nahi dekh sakta.
- Nayi company register hone ke baad admin approval tak sab pages DEKH sakte hain lekin kaam (bill banana waghera) approval ke baad hi hota hai.
- Apna profile badalna: /pos/my-profile — Full Name, Email, Phone, Username, aur password change (Current Password + New Password + Confirm).

## Dashboard (/pos/dashboard)
- "Aaj" ke figures Business Day ke hisaab se hain: raat 12 ke baad (subah 6 tak, jab tak din close na ho) ki sales pichle din mein ginti hain — dukan raat der tak khuli ho to raat ki sales usi din ke totals mein rehti hain.
- Dashboard par KPI cards (aaj ki sales, bills, tax, net sales), hourly chart, 30 din ka trend, payment method ka breakdown aur aaj ke recent bills hote hain.
- Cashier ko sirf apne stats nazar aate hain; admin/manager ko poori company ke.
- Din ke shuru mein "Opening Cash" card par drawer ka cash + note enter kar ke Save karein (tafseel Day Close section mein).
- Dashboard ka style: /pos/customize ke "POS Ka Style" section se — "Full — Poora Dashboard" (default) ya "Saaf — Simple" (seedha saada, Roman Urdu); "Mazeed styles" ke neeche 5 purane fancy designs bhi hain. Sirf admin/manager badal sakta hai.

## Sale Screen — Nayi Bill Banana (Dashboard → "Nayi Sale" ya /pos/invoice/create)
Bill banane ka aam tareeqa, shuru se aakhir tak:
1. (Optional) Customer: customer box mein phone/naam type karein → list se select karein. Match na mile to "Add as New" option usi dropdown mein aata hai — naam+phone likh kar foran naya customer ban jata hai. Customer select hote hi uski chhoti history bhi nazar aati hai (kitne orders, kitna kharcha, aakhri order kab). Walk-in customer ke liye yeh step CHHOR dein (khali chhor kar Enter).
2. Items dalein — 3 tareeqay: (a) search box mein naam/barcode/SKU type karein aur Enter, (b) product grid par item click karein, (c) barcode scanner se scan karein — exact match foran cart mein chala jata hai.
3. Quantity: cart row ke qty box par click kar ke number type karein, ya same item dobara add karein, ya cart row select kar ke + / - keys.
4. (Optional) Discount ya tax on/off: cart ki item rows mein ab per-item TAX/Discount ke buttons ya note ka box NAHI hota (26 Jul 2026 se) — discount BILL level par lagta hai (cart ke neeche % Discount button ya D key; Alt+D har jagah chalta hai). Kisi ek item ka TAX ON/OFF sirf keyboard se: item select kar ke T (ya Alt+T = aakhri item) — NO TAX ka sabz chip item ke neeche dikhta hai.
5. Order type chunein (restaurant mode mein): Dine In / Takeaway / Delivery buttons (guided flow mein keys 1/2/3). Dine In chunte hi TABLE PICKER khul jata hai — arrow keys se table select, Enter. Delivery chunein to address picker aata hai (customer ke saved addresses + "Add New Address" inline) aur cart (Current Order) ke upar "Delivery Charges" ki patti — wahan raqam likhein, yeh charges bill mein tax-free line ban kar judte hain.
6. Bill mukammal karne ke 2 raste:
   - "PAY" button (ya F8) → payment modal → Cash (key 1) ya Card (key 2) → bill FINAL ho jata hai (PRA reporting ON ho to PRA ko report hota hai).
   - "Save Provisional" (ya F9) → bill L-series LOCAL/provisional ban jata hai — PRA ko report NAHI hota, quota nahi katta; baad mein promote kar sakte hain.
7. Receipt popup khulta hai — P = Print, K = KOT, Enter = nayi sale, Esc = band. Popup default 10 second mein khud band hota hai (koi bhi key dabane ya mouse le jane se timer ruk jata hai); yeh waqt /pos/customize se badlein (Never/5/10/15/20/30 sec).

Payment modal ki keys: 1 = Cash (foran), 2 = Card (foran), arrow keys se method highlight, Enter = highlighted method confirm, P = Save Provisional (guided flow ON ho to), Esc = band.

Sale screen ki mazeed cheezein:
- Layout (Jul 2026): upar wali patti ab DO qataron (2 rows) mein hai — pehli qatar mein bara CUSTOMER box (aur Table/order-type buttons), doosri qatar mein category dropdown + poori chaurai wala SEARCH box aur bill ke buttons (Pay/Hold waghera). Dono boxes ab pehle se kaafi baray hain, cart panel bhi chaura hai. Cart (Current Order) ab screen ke bilkul UPAR se shuru hota hai — cart ke upar wali khali jagah khatam, items ki lambi list bina scroll ke zyada dikhti hai (25 Jul 2026).
- Search hamesha GLOBAL hai: category dropdown sirf GRID filter karta hai — search box har category ka item dhoondta hai, deals aur services samet. (Category pills ki patti hata di gayi hai — ab sirf dropdown hai, mobile par bhi.)
- Search mein pehle harf ko priority milti hai (jo item aapke likhe harf se shuru ho wo upar).
- Product grid ON/OFF toggle hai — grid OFF ho aur products ghayab lagein to "Show All Products" ya Products toggle dobara ON karein (yeh setting har PC/browser par alag save hoti hai).
- SIMPLE MODE (jab Inventory Tracking OFF ho): "Manual" button nazar aata hai — naam + price likh kar ad-hoc item cart mein dalein; "Save Permanent" tick karein to product master mein bhi save ho jata hai. Search mein item na mile to naya product FORAN ban sakta hai (quick-create) — price 0 se banta hai aur cart row mein price box khud khul jata hai, wahan price likh dein. Cart row ki unit price par click kar ke bhi price edit hoti hai.
- Inventory Tracking ON ho to Manual/quick-create nahi hota — pehle /pos/products par product banayen.
- Quick Type Mode (F7): "chai 2, samosa 1" jaisi line likhein — pura order khud cart mein aa jata hai. DEFAULT BAND hai; admin /pos/customize se ON kare.
- Urgent button (pehle 'Rush' kehlata tha — Aug 2026 mein naam badla): order ko priority mark karta hai — KOT par bara '*** URGENT ***' (saaf kaali likhai) aur kitchen/KDS screen par laal URGENT ka nishan aata hai.
- Hold (F5): order hold karein (Dine-In ke liye) — baad mein TABLE board (Alt+B ya TABLE button) se wapas kholein: table wale orders table ke card par dikhte hain, bina table walay orders board ke "Held Orders (bina table)" amber chips mein. Manual items aur deals hold NAHI ho sakte (sirf seedha bill). (Table Management OFF walon ke liye Held ki purani pill upar top bar mein hi hai.)
- Delivery: Payment First, Then KOT (1 Aug 2026 se): /pos/restaurant/kitchen-settings par "Delivery: Payment First, Then KOT" ka switch hai. ON ho to PROVISIONAL delivery bill save karne par KOT nahi nikalti (kitchen kaam shuru nahi karti) — jab payment confirm ho kar bill FINAL (promote) hota hai, tabhi KOT khud print hoti hai. Final delivery bills (payment saath hi li) par KOT hamesha ki tarah foran nikalti hai. Default OFF.
- "Send to Kitchen" (KOT) button: Dine-In order ko bina payment ke kitchen bhejta hai aur kitchen ticket print hota hai.
- Bill note ka box payment window mein hai (Cash/Card chunne se pehle) — poore order ke liye hidayat likhein (misal: kam mirch, alag pack).
- Screen Fit: sale screen ki upar wali button qatar mein "Fit" button se poori screen 80–125% adjust karein (chhoti screens par khud 90% ho jata hai). Har PC par alag save hota hai. (Saaf style mein Fit "Mazeed" ke peechay hota hai.)
- Discount limit DONO types par lagti hai: percentage bhi aur Rs. amount bhi — amount discount subtotal ke limit% se zyada nahi ho sakta (default 50%). Limit se zyada discount par Manager PIN ka modal khulta hai — PIN dalne par usi bill ke liye override ho jata hai (cart clear hote hi khatam).
- Offline mode: internet chala jaye to bill queue mein save hota hai aur net aane par khud PRA ko chala jata hai.
- Waiter ka order aaye to "Table" button par teal badge lag jata hai aur toast ittila deta hai. TABLE board (Alt+B ya TABLE button) kholein — jis table par waiter ka order tayyar hai woh JAMNI (purple) card "Order Tayyar" ke saath dikhta hai; us par click karte hi order cart mein aa jata hai — bas payment karein, table khud free ho jata hai. Bina table walay waiter orders (Takeaway/Delivery) isi picker mein neeche "Counter Orders" section mein hote hain. Ek order sirf ek cashier le sakta hai — doosra cashier click kare to "order doosre cashier ne le liya" ka paigham aata hai.
- Saaf style ho to kam-istemaal buttons (Urgent, Fit, Keys, Quick) aur guided steps ki patti "Mazeed" button ke peechay hoti hain — Mazeed dabane se dikh jati hain. Saare features waise hi kaam karte hain.

## Keyboard Shortcuts (Sale Screen) — MUKAMMAL LIST
- F1 = shortcuts ki madad wali screen kholo/band karo.
- F2 = search box par focus (search mode).
- F3 = KHATAM (26 Jul 2026): held orders ki alag window ab nahi hai — Table Management ON ho to held orders TABLE board (Alt+B) ke andar hain (F3 dabane par sirf yaad-dihani ka toast aata hai). Table Management OFF ho to Held pill upar top bar mein pehle jaisi hai (bas F3 shortcut nahi chalta, click se kholein).
- F4 = poora cart khali karo (confirm poochta hai).
- F5 = current order HOLD karo.
- F6 = cart mode — cart ke aakhri item par focus (qty/edit ke liye). Ctrl+E bhi yehi karta hai.
- F7 = Quick Type modal (agar company mein ON hai).
- F8 = PAY — payment modal kholo. Phir 1 = Cash, 2 = Card, Enter = confirm.
- F9 = Save Provisional (local bill seedha save).
- F10 = Local/Provisional bills ka modal. Modal ke andar: ↑↓ select, Enter = Make Final (promote), E = Edit (bill wapas sale screen par khulta hai), D = Delete (cashier nahi kar sakta), Esc = band.
- F11 = Failed/offline PRA bills ka modal — Edit & Retry yahin se (bill wapas cart mein aata hai, theek kar ke dobara bhejein).
- Alt+R = Reprint modal (aaj ke bills ki list, click ya Enter = print).
- Alt+1 = CASH one-tap, Alt+2 = CARD one-tap — bill SEEDHA final ho jata hai (koi dobara popup nahi); tax apki tax setting se khud lagta hai (Cash/Card ka apna apna rate). Method chunna ho ya bill note likhni ho to bara PAY (F8) button dabayen — us par Pay window khulti hai.
- Alt+P = customer phone box par focus.
- Ctrl+S = search par focus.
- Cart mode mein: ↑↓ = row select, + / - = quantity, Delete/Backspace = item hatao.
- T = selected cart item ka TAX ON/OFF (NO TAX ka sabz chip item ke neeche dikhta hai); D = bill discount panel kholta hai. (Search box ke andar type karte waqt yeh letters normal type hote hain — wahan Alt+T / Alt+D use karein, wo har jagah chalte hain.) Per-item note ka box hata diya gaya hai (26 Jul 2026) — bill note ab payment window mein hai.
- Enter = guided flow mein agla step; Escape = modal band.
- Guided Flow (default ON): Customer → Items → Order Type (1/2/3) → Pay — sirf Enter dabate jayen: khali customer box par Enter = walk-in skip; items dal kar khali search par Enter = agla step; pay modal mein Enter = Cash confirm. /pos/customize se ON/OFF hota hai.

## Payment aur Receipt
- Payment methods: Cash aur Card (payment modal mein; keyboard 1 = Cash, 2 = Card). Har method ka apna tax rate ho sakta hai (aam default: cash 16%, card/digital 8%; company ke liye alag rate bhi ho sakta hai — /pos/features par "Cash Rate (%)" aur "Card / Digital Rate (%)" fields).
- Bill ka grand total hamesha poore rupay mein round hota hai (line items 2 decimal tak).
- Receipt 80mm ya 58mm thermal printer par print hoti hai — paper size /pos/receipt-settings ya PRA Settings dono jagah se badlein; print khud printer ki asal width par fit hota hai.
- Receipt Settings (/pos/receipt-settings) par DO tabs hain: "PRA Receipt" aur "Local Receipt" — har tab ke apne toggles: Show Address, Show NTN, Show Email, Show Phone/Mobile, Show Cashier Details, Show Tax, aur Footer message (toggle + apna text). Global options: Receipt Paper Size (80mm Standard / 58mm Compact), PDF Download Paper (Thermal Roll / A4), Bold Receipt Print toggle, Logo Style (Compact/Large).
- Agar DOWNLOAD ki hui PDF bill aam (non-thermal) printer par side par shift ho kar kati hui chape, to /pos/receipt-settings par "PDF Download Paper" ko A4 kar dein — bill poore A4 page ke upar-left par seedhi chapegi. Thermal roll wali printing par is se koi asar nahi parta.
- "Show Tax" OFF karne se customer copy par Subtotal aur Tax chhup jate hain — sirf grand TOTAL nazar aata hai; items apni asal price par dikhte hain. Lekin PRA ko tax hamesha POORA submit hota hai.
- Receipt ka default style: bold + center logo; company chahe to plain style choose kar sakti hai. Text gehra/bold hai taake saste printers par bhi saaf chape.
- Restaurant orders ki receipt par order type ka numaya badge chapta hai — DINE-IN, TAKE AWAY ya DELIVERY (bold, border ke saath, bilkul upar). Retail/simple bills par koi badge nahi aata.
- Kisi bhi purane bill ki receipt dobara: sale screen par Alt+R (Reprint) — ya /pos/transactions se bill khol kar receipt/PDF.
- Bill ka share link: /pos/transactions se bill kholein → share link banayen — customer ko WhatsApp waghera par bhej sakte hain.

## Printer aur Hardware (Setup)
- NestPOS kisi bhi aam thermal receipt printer (80mm/58mm) ke saath chalta hai — USB, network ya Bluetooth; printer Windows/browser mein install ho to print ho jayegi.
- Printer setup: (1) printer PC se connect kar ke driver install karein, (2) browser ke print dialog mein wohi printer select karein, (3) /pos/receipt-settings par paper size set karein.
- SILENT printing (bina print dialog ke): Desktop Sync Agent install karein, phir /pos/printer-settings par jayen → "Enable Silent Printing" ON karein → "Bill / Receipt Printer" aur "Kitchen (KOT) Printer" dropdowns se printers chunein (list Agent se aati hai). Ab receipt/KOT seedha printer par jayenge.
- Counter KOT Copy (30 Jul 2026 se): /pos/printer-settings par TEESRA dropdown "Counter KOT Copy Printer" hai — iske sath "Use karna hai" ka tick ON karein to har DINE-IN order ki KOT ki aik poori copy counter wale printer par bhi khud-ba-khud nikalti hai (kitchen wali KOT apni jagah normal print hoti hai). Takeaway/Delivery par yeh copy kabhi nahi nikalti. Order update (naye items) par copy mein bhi wohi naye items aate hain jo kitchen ko gaye. Tick OFF = feature band. Iske liye Desktop Agent aur Silent Printing ON hona zaroori hai.
- "Printer Settings mein printers ki list khali hai": PC par PURANA agent chal raha hai — /pos/printer-settings → "Agent Setup" → "Download ZIP" se naya agent lein, extract kar ke install.bat chalayen (settings mehfooz rehti hain). 5 minute mein list aa jayegi.
- Agar receipt ya KOT ke aage/peeche LAMBA khali kaghaz nikalta hai: Windows mein printer ki Printing Preferences kholein aur paper size A4 se badal kar 80mm Receipt (ya "72mm x Receipt"/"POS80") set kar dein — khali feed khatam ho jayegi. (KOT ka khali patti wala masla — jahan sirf right kinare par 1-2 harf chapte thay — 26 Jul 2026 ko system ki taraf se theek ho chuka hai, iske liye kuch karne ki zaroorat nahi.)
- Barcode scanner: koi bhi USB/Bluetooth scanner jo keyboard ki tarah type karta hai seedha chal jata hai — sale screen ke search box mein scan karein. Alag setup nahi chahiye.
- Cash drawer aksar printer ke kick port se juda hota hai — receipt print par khul jata hai; yeh printer ki apni setting hai.
- Product barcode stickers: /pos/products/labels — sticker par naam, price, barcode aur SKU aata hai; "Columns" box se sheet ke columns (1-5) set karein aur "Print" dabayen.

## PRA Reporting (Fiscal)
- PRA reporting ON ho to har final bill PRA ko report hota hai aur POS-YYYY-NNNNN fiscal serial milta hai; receipt par PRA fiscal number aur QR aata hai.
- Reporting ke BAGHAIR bane bills (provisional ya reporting-OFF finals) L-series serial par rehte hain.
- Internet na ho ya PRA down ho to bill 'offline' queue mein jata hai aur khud retry hota hai — quota dobara nahi katta.
- PRA se reject bills F11 modal mein aate hain — wahan se Edit & Retry karein.
- Per-cashier PRA toggle: /pos/team par har cashier ke liye PRA reporting alag ON/OFF ho sakti hai.
- PRA Settings (/pos/pra-settings, sirf admin) ke fields: Environment (Sandbox/Production), Connection Mode (Cloud API / PRA Fiscal Device), POS Registration ID, Production Token, PRA Proxy URL (optional), Receipt Printer Size (80mm/58mm). "Test Connection" button se connection check karein.
- Confidential PIN: 4-6 digit PIN — PRA Settings page par "Set PIN / Change PIN" se. Yeh PIN limit se zyada discount jaisi hassas actions par manager override ke liye use hota hai. Cashier PIN set/change nahi kar sakta.
- PRA Connection ke 2 modes: (1) Cloud API — server seedha PRA ko bhejta hai; (2) PRA Fiscal Device — dukan ke PC par Desktop Sync Agent bills PRA ko bhejta hai. NAYE PRA registrations ke liye Fiscal Device mode zaroori hai (PRA ka rule).
- Invoice Submission Mode (/pos/agent, Cloud mode wali companies): "Agent Sync" = Desktop Agent bills PRA ko bhejta hai; "Direct Production" = server seedha bhejta hai — "Switch to..." button se badlein. Direct par switch karne se silent printing BAND NAHI hoti — agent connected rehta hai. Fiscal Device mode mein Direct available nahi.
- Agent page (/pos/agent) par: agent ka status (Online/Offline, last seen, version, aaj submit hue bills), Company ID + API Key (masked, Show/Copy buttons, Generate/Regenerate Key), Server URL, aur "Download TaxNest Agent" (Windows).
- Desktop Sync Agent: Windows PC par chalta hai; bills PRA ko submit karta hai aur silent printing bhi isi se hoti hai. Agent khud update ho jata hai (v1.3.0+).
- NestPOS Desktop window (agent v1.5.2+): X se band karne par window poori tarah band NAHI hoti — background mein tayyar rehti hai. Dobara "Open POS" ya Desktop ka NestPOS icon = screen FORAN khulti hai, koi loading nahi. Jab TaxNest ki update aati hai to screen khud aik dafa refresh ho kar naya data le leti hai (bill banate waqt kabhi refresh nahi hoti). Poori tarah band karna ho to tray icon par right-click → Quit Agent.

## Provisional / Local Bills
- Provisional (local) bill = abhi PRA ko report NAHI hua; L-series number; quota nahi katta.
- Banane ka tareeqa: sale screen par "Save Provisional" button ya F9 (ya pay modal mein P — guided flow ON ho to).
- Dekhne ke tareeqay: sale screen par F10 modal, ya /pos/local-bills portal (export bhi ho sakta hai).
- F10 modal ke andar har local bill par actions: Enter = Make Final (promote), E = Edit, D = Delete (cashier delete nahi kar sakta).
- F10 modal mein upar SEARCH box hai (1 Aug 2026): customer ka naam, phone number ya bill number likhein to list foran filter ho jati hai — raat ko bohat se provisional bills ikathay final karne walon ke liye. Modal khulte hi cursor search mein hota hai; ↑↓ se select, Enter = Make Final. FBR POS ke F10 modal mein bhi yehi search hai.
- Promote karne par method picker khulta hai — 3 options: Cash (1), Card (2), ya "Finalize LOCAL — don't send to PRA" (3 ya L) = bill final ho jata hai lekin PRA ko NAHI jata, L-series par hi rehta hai. Esc = cancel.
- Cash/Card se promote = PRA final: naya POS fiscal serial milta hai aur monthly quota use hota hai. Promote sirf USI mahine ke andar ho sakta hai.
- /pos/transactions par bhi local bill ke saamne "Submit to PRA" button hota hai (current month ke bills par).
- Day-close par us din ke local bills company ki standing policy ke mutabiq save ya delete hote hain — policy /pos/customize → Local Billing se (sirf admin; provisional aur final dono ke liye alag "Save to record"/"Auto-delete" options + customer spend history rakhne ka toggle).
- PRA ko report ho chuke bills kabhi delete nahi hote — sirf pure local bills wash hote hain.
- Purane (archive kiye) local bills: /pos/archive par nazar aate hain (export bhi).

## Day Close aur Opening Cash
- Opening Cash: din ke shuru mein Dashboard ke "Opening Cash" card par drawer ka cash amount (+ optional note) enter kar ke Save — cashier bhi kar sakta hai; sirf aaj ke liye; din close hone ke baad lock.
- Day-close karne ka tareeqa (manual): /pos/day-close kholein → Z-report ka khulasa dekhein → cash reconciliation mein GINA HUA cash dalein (variance khud calculate hota hai) → rider khata dekhein → "Close Day" dabayen.
- Auto day-close: koi manually na kare to system khud din band kar deta hai (/pos/customize par "Auto Day-Close (24h)" toggle) — waqt company ke "Din band hone ka waqt" ke mutabiq (default subah 6:00).
- Din band hone ka waqt (30 Jul 2026 se): /pos/day-close page par har company APNA cutoff time set kar sakti hai (maslan raat 4 baje). Iska matlab: raat 12 baje ke baad, cutoff time TAK ke bills pichle din mein ginte hain; auto day-close bhi isi waqt par hota hai. Daily/weekly/monthly reports sab isi hisaab se din ginte hain. PRA/tax record par asal waqt hi jata hai.
- Z-report/day-close page mein kya kya hota hai: Total Invoices (PRA/Local/Offline ka split), Gross Sales, Total Tax, Net Revenue, payment breakdown (Cash/Card/Other), CASHIER-wise breakdown table, Staff Hazri (kaun kab aaya/gaya, kitne bills — 26 Jul 2026 se), din ke pehle-aakhri invoice numbers, PRA submission health (submitted vs failed/offline), hourly chart, top products, discount summary, cash reconciliation, rider summary. Pichle din/haftay se comparison bhi.
- Z-report "Download PDF" (A4) aur "Thermal Z-Report" (80mm) dono par print hoti hai; purani closes bhi day-close page par "View" se dekhein.
- Cash reconciliation ka formula: expected cash = opening cash + cash sales − rider ko diya cash + rider se wapas aya cash.
- **Business Day (Jul 2026 se): raat 12 baje ke BAAD ke bills bhi PICHLE din mein ginte hain** — jab tak din close na ho (company ke set kiye "Din band hone ka waqt" tak — default subah 6 baje). Maslan raat 1 baje ki sale 25 tareekh ke Z-report/day-close, dashboard "Aaj" aur sales reports mein 25 ko hi ginegi, chahay calendar par 26 shuru ho chuki ho. Din close karne ke baad (ya cutoff time ke baad) nayi sale agle din mein jati hai. PRA/tax ko asal waqt hi report hota hai — qanooni record par koi farq nahi.

## Products (/pos/products)
- Naya product: "+ Add Product" → form fields: Product Name (zaroori), Category, SKU, Description, Barcode, Price PKR (zaroori), Cost Price (profit report ke liye), Tax Rate % + "Tax Exempt" toggle, "Sale screen par dikhayein" toggle, Unit (NOS/KGS/LTR/MTR/PCS/PKT/BOX), Opening Stock, Low-Stock Alert At, aur Image (No Image / Upload / Auto-fetch).
- Category ke hisab se extra fields: Pharmacy (Batch Number, Expiry Date, Drug Type), Grocery (Weight Based, Unit Type), Kapre/Apparel (Size, Color, Season), Electronics (Serial Number, Warranty Months, IMEI), Automotive (Part Number) waghera.
- Har product par Edit, Delete, Active/Inactive toggle, aur "sale screen par dikhao" toggle hai — item search mein na mile to yahan check karein.
- Excel import/export: /pos/products par "Download Excel Template" (ya "Download Excel (N Products)" — poori list) → Excel mein edit karein → "Upload & Import" (barcode/SKU/naam se khud match hota hai). CSV use NA karein — barcode kharab ho jate hain.
- Barcode stickers: /pos/products/labels.
- Services (agar dukan services bhi deti hai): /pos/services par alag list — yeh bhi sale screen ke search mein aati hain.

## Inventory / Stock (/pos/inventory)
- Inventory ON/OFF: /pos/features (ya /pos/customize ka Inventory Tracking toggle). OFF ho to nav mein grey "OFF" badge; pages POS Features par redirect hote hain. OFF = Simple Mode (Manual button + quick-create milta hai).
- Stock list (/pos/inventory/stock): search + status filter (All/Low/Out); har product ka stock level bar, min level, average cost, stock value; min level inline edit hota hai.
- Stock adjust: /pos/inventory/adjust — product chunein → type: Add (+) / Remove (−) / Set Exact (=) → quantity → (Add par purchase price) → Reason (New Purchase, Physical Count, Damaged/Expired waghera) → Notes → save.
- Movements (/pos/inventory/movements): har tabdeeli ka log — filters: type (Sale/Purchase/Adjustment/Opening), product, date range; har row mein qty +/- aur balance-after.
- Low stock (/pos/inventory/low-stock): Critical/Warning/Low ke counts; har row par "Restock" button seedha adjust form khol deta hai.
- Sale par stock khud katta hai; bill delete par wapas aana ("Restock on Void") /pos/customize ki setting hai.
- Deals ka stock deal ke andar ke items se katta hai; recipes wali dishes ka stock ingredients se katta hai.

## Deals (/pos/deals)
- Deal banana: "+ Add Deal" → Deal Name, Deal Price (PKR), Description (optional), Active Days (Mon–Sun checkboxes), Start Date / End Date (optional), Active toggle → "Add Item" se products + quantity dalein → save.
- Deal ki price server enforce karta hai — cashier badal nahi sakta.
- Deal sirf apne set kiye dino (aur date range) mein sale screen par aati hai.
- Deals sirf seedhi billing ke liye hain — hold/KOT par nahi ja sakti.

## Restaurant Module (Pro/Unlimited packages)
- Chahiye: Pro ya Unlimited package (ya active trial), phir /pos/features se restaurant features ON karein (KOT, Table Management, KDS, Kitchen Notes, Recipes).
- Order types ke rules: Dine-In = pehle Hold/KOT, khana banne ke baad payment; Takeaway = seedha final bill; Delivery = final ya provisional dono.
- Tables/Floors: /pos/restaurant/table-management → "+ Add Floor" (floor ka naam, jaise Ground Floor) → "+ Add Table" (Table Number jaise T1, Seats 1-50). Table delete = card par × button.
- Dine-In order: sale screen par order type "Dine In" → table picker khud khulta hai → table select → items → "Send to Kitchen" (KOT) ya Hold → khana ban jaye to TABLE board (Alt+B) mein table par click karke "View / Edit" ya "FINAL karo" se payment. Table payment par khud free ho jata hai.
- TABLE BOARD (Jul 2026, naya — 26 Jul 2026 se BUTTON ke andar): Table Management ON ho to sale screen par cart ke NEECHE ek patli "TABLE" button-patti hoti hai — us par rangin ginti ke badges (laal = order chal raha, amber = reserved, jamni = waiter tayyar; "C" wala badge = counter orders) aur "Rs X chalu" (tamam khule orders ki kul raqam) nazar aate hain. Is button par CLICK karein (ya keyboard se Alt+B) to TABLE BOARD ek popup window mein khul jata hai — har table ka rang (sabz = khali, amber = reserved, laal = order chal raha hai, jamni = waiter ka order tayyar), kitni der se order laga hai, kis staff ka order hai aur bill ki raqam. Table par CLICK karne se board band ho kar chhota MENU khulta hai (seedha koi action nahi hota): "View / Edit" (order cart mein kholein), "FINAL karo" (pehle CASH ya CARD ki tasdeeq poochta hai — ghalti se bill final hone ka masla khatam), "KOT Dobara Bhejo", "⇄ Table Badlein (Shift)" aur "Order Cancel + Table Khali" (waiter ke orders cashier cancel nahi kar sakta). Khali table par click karke Reserve bhi kar sakte hain. Board ka data khud har ~25 second mein taaza hota rehta hai (button ke badges bhi). Popup band karne ke liye ESC, bahar click, ya X. (Pehle board cart ke neeche khula rehta tha jis se cart chhota ho jata tha — ab button ke andar hai, cart poori height wapas mil gayi.)
- HELD ORDERS ab BOARD ke andar (26 Jul 2026): F3 wali alag Held window KHATAM. Bina table walay held orders board popup mein "Held Orders (bina table)" ke AMBER chips ban kar aate hain — "TABLE" button-patti aur board ke sar par amber "H" ginti ka badge bhi dikhta hai, aur inki raqam "Rs X chalu" mein shamil hoti hai. Chip par click karein to chhota MENU khulta hai: "Bill Kholo / Edit karo" (order cart mein wapas), "PAY karo" (seedha payment window), "KOT Dekho", "↻ KOT Dobara Bhejo", aur "Order Delete". Table WALE held orders pehle ki tarah table ke card par hi milte hain.
- TABLE BOARD ki behtariyan (Jul 2026 update): (1) DER wale orders khud UJAGAR hote hain — waiter ka order ~10 minute tak koi na uthaye, table par order ~30 minute se chal raha ho, ya reservation ~15 minute purani ho to us tile ka rang GEHRA ho jata hai, ⚠ ka nishaan aata hai aur waqt wali chip blink karti hai; (2) "TABLE" button-patti aur board popup dono par "Rs X chalu" — is waqt tamam khule orders ki KUL raqam nazar aati hai; (3) COUNTER ORDERS: waiter ke bina-table orders (takeaway/delivery) bhi board popup mein alag chips ban kar aate hain — click karke seedha khol kar bill kar sakte hain; button-patti par inka apna "C" badge bhi hota hai.
- Held order mein items badalne ke baad kitchen ko naya ticket "↻ KOT Dobara Bhejo" se jata hai (TABLE board ke table-menu ya bina-table held chip ke menu mein) — ticket par UPDATED ka nishaan hota hai.
- TABLE ke menu mein ORDER KE ITEMS bhi dikhtay hain (28 Jul 2026): table par click karte hi popup mein list aati hai ke kya-kya laga hua hai (qty × item + raqam) — bill kholne ki zaroorat nahi.
- PROOF BILL (28 Jul 2026): TABLE board ke menu mein neela "Proof Bill Print (bina final)" button — customer ko bill dikhane ke liye kachi parchi print hoti hai jis par "PROOF BILL — NOT PAID" likha hota hai. Order table par khula rehta hai, koi final invoice nahi banta, koi serial kharch nahi hota. Payment ke waqt normal "FINAL karo" hi use karein.
- Select Table window ab BARI hai (28 Jul 2026): saari tables ek chart ki tarah zyada columns mein nazar aati hain. Dine-In KOT bhejne ke baad screen khud TABLE chart par wapas aa jati hai — agla order seedha agli table se shuru karein.
- Tables Overview page (/pos/restaurant/tables) ke timers ab LIVE chalte hain (28 Jul 2026) — kitni der se table laga hai, bina refresh ke khud update hota rehta hai.
- Kitchen Settings (/pos/restaurant/kitchen-settings) ke toggles: Kitchen Display System (KDS), Kitchen Printer, Print KOT on Hold, Dine-In Auto KOT on Table Select, Print Receipt on Pay. "Save Kitchen Settings" se save.
- Asaan rasta (1 Aug 2026): /pos/customize hub par "Kitchen & KOT Settings" ka card bhi hai (sirf restaurant-mode companies ko dikhta hai) — wahan se aik click mein isi page par pahunch jayen.
- KOT PRINT par kya dikhe — isi page par alag switches hain: "Customer dikhayein", "Order By (staff naam)", "Barcode dikhayein" (SCAN BARCODE TO CLEAR wala — jo dukan KDS use nahi karti wo isay OFF kar de), aur "Footer dikhayein" (KOT ke neeche business ka naam). Har switch alag hai — sirf naam hatana ho to sirf Footer OFF karein, barcode apni jagah rahega.
- Counters/Stations (KOT routing): kitchen-settings par "+ Add Counter" → Counter Name (jaise "Grill") → Printer chunein (Desktop Agent ki list) → Product Categories tick karein — un categories ke items ka KOT usi counter par jayega.
- KDS (/pos/restaurant/kds): kitchen account login karta hai; order cards par order number, table, URGENT tag aur timer; buttons: "Start Preparing" → "Mark Ready" → "Clear"; upar Refresh, Clear All, Camera Scan aur List/Aggregate view switcher. KOT ka barcode scan karne se order khud clear ho jata hai (scanner active rehta hai).
- Kitchen account: /pos/team se Kitchen role ka login — sirf KDS dekhta hai, team limit mein nahi ginta.
- Waiter (/pos/waiter): waiter apne login se — Dine In/Take Away chunein → "Choose Table" (Available sabz, Occupied laal) → items search kar ke cart mein dalein (har item par note bhi likh sakta hai) → customer naam/phone (optional) → kitchen note → cashier select → "SEND TO CASHIER". Pehle se bheje order mein "My Orders" → "Add Items" se aur items add ho sakte hain. Waiter payment/discount/delete NAHI kar sakta.
- QR Menu / Public Profile: /pos/business-profile → "Public Page Enabled" ON + "Menu" visible ON → menu builder mein products tick karein → QR code customer ko dikhayen. Kya kya public dikhe (Phone, Mobile, Email, Address, NTN, Website, Opening Hours) — har cheez ka apna toggle. "Regenerate Link" se naya link banta hai.
- Recipes / Ingredients: /pos/restaurant/ingredients par kachha maal (Name, Unit: kg/g/ltr/ml/pcs, Cost/Unit, Opening Stock, Min Stock) → /pos/restaurant/recipes par product chunein aur ingredient + Qty Needed rows dalein → Save Recipe. Dish bikne par ingredients ka stock khud katta hai. Ingredients ka stock adjust bhi ingredients page par hota hai.
- **Table Shift / Table Badlein (26 Jul 2026)**: customer table badalna chahe to order ka table shift karein — TABLE board (Alt+B) par table par click → menu mein "⇄ Table Badlein (Shift)" → sirf KHALI tables ki list aati hai, nayi table chunein, ho gaya. Table ka timer wahi chalta rehta hai (order kab laga tha) aur KOT dobara print NAHI hota (kitchen ka khana wahi hai, sirf jagah badli). Reserved ya occupied table par shift NAHI ho sakta. Waiter apne order ka table waiter pad ke "My Orders" se bhi shift kar sakta hai. Table picker mein bhi (cart khali ho to) laal/occupied table par click karne se wahi board menu khul jata hai — wahan se bhi shift kar sakte hain.

## Delivery Riders
- Riders banana: /pos/riders — naam, phone, CNIC, vehicle number. "Create Login" se rider ka email+password banayen (rider sirf /pos/rider portal dekhta hai; team limit mein NAHI ginta).
- Rider assign: bill BANNE KE BAAD /pos/deliveries board par "Assign Rider" dropdown se (payment modal mein assign nahi hota).
- Status buttons board par: Dispatch → Delivered → (zaroorat par) Returned. Rider apne portal se bhi "Delivered ✓" kar sakta hai.
- Rider portal (/pos/rider): rider ko apne orders, address aur "Cash to hand over" (khata) ka banner nazar aata hai.
- Cash settle: /pos/deliveries par rider ke card par "Settle Cash" → jo bills settle ho rahe hain tick karein → note (optional) → "Confirm Settlement". Partial settlement ho sakta hai.
- Day-close par rider ka poora khata (owed vs settled) nazar aata hai.
- "Delivery Manager" role: sirf deliveries board + settlement tak — free, limit mein nahi ginta.
- "Returned" mark karne se PRA wala bill cancel NAHI hota — yeh sirf andaruni record hai.

## Team / Roles (/pos/team)
- Member add: /pos/team → naam, email, phone, password, role → save.
- Roles: Manager = admin jaisa (settings, reports, sab); Cashier = sirf billing. Kitchen, Waiter, Rider, Delivery Manager = mehdood, FREE (limit mein nahi ginte).
- Manager aur Cashier package ki account limit mein ginte hain (Starter 1, Business 5, Pro 10, Unlimited unlimited).
- Cashier ON/OFF toggle team page par hai (band cashier login nahi kar sakta).
- Har cashier ke liye PRA reporting alag ON/OFF karne ka toggle bhi yahin hai.
- Company admin team members ke passwords /pos/team par dekh sakta hai (sirf admin ko nazar aate hain).

## Packages aur Billing (/pos/billing)
- Starter Rs 9,999/saal: 1 team account, 500 final bills/mahina.
- Business Rs 14,999/saal: 5 accounts, 2,000 bills/mahina.
- Pro Rs 24,999/saal: 10 accounts, 3,000 bills/mahina, 2 branches, Restaurant module.
- Unlimited Rs 39,999/saal: sab unlimited.
- Billing sirf saalana hai (6% discount pehle se shamil). Plans ki tafseel /pos/billing par.
- Payment ka tareeqa: plan chunein → di gayi bank details (Bank, Title, IBAN) par raqam bhejein → payment proof form bharein: Package, Amount Paid (PKR), Reference/TID, aur proof upload (JPG/PNG/PDF) → admin verify kar ke package activate karta hai. Jaldi ho to "Send on WhatsApp" button se proof WhatsApp par bhi bhej sakte hain.
- Sirf FINAL bills quota mein ginte hain — provisional FREE hain jab tak promote na hon; offline retry dobara nahi ginta. Quota har mahine reset hota hai.

## Tax Settings
- Tax Pricing ke 3 modes (/pos/customize se, sirf admin):
  1. **Standard (Tax Upar Se / Exclusive)**: menu price par tax alag se lagta hai.
  2. **Menu Rate Final — Sab Same (Inclusive)**: menu price hi grand total hai, har payment method par same.
  3. **Menu Rate Final — Card Bachat (Card-save)**: menu price cash ke hisab se final; card/digital par thora sasta — receipt par "Card Discount" nazar aata hai.
- Tax rates: default cash 16%, card/digital 8% — apne rates /pos/features par "Cash Rate (%)" / "Card / Digital Rate (%)" fields se set karein (ya support se).
- Kisi ek product ko tax-free karna ho: product edit karein → "Tax Exempt" toggle ON.
- Mode badalne se purane bills nahi badalte — sirf naye bills par lagta hai.
- PRA ko tax hamesha poora aur sahi submit hota hai, chahe receipt par dikhaya jaye ya nahi.

## Customers (/pos/customers)
- Fields: Name, Phone, Email, Type (Registered/Unregistered), CNIC, NTN, City, Address. "+ Add Customer" se banayen; phone/naam se search; Active/Inactive toggle; inline Edit; Delete.
- Customers page par UPAR search box hai (1 Aug 2026) — naam, phone, city, CNIC ya NTN likhte hi list filter ho jati hai.
- Har customer ki poori history: customer ke saamne "History" → us ke saray bills (export/PDF bhi).
- Sale screen par bhi (1 Aug 2026): customer select karne par jo "X orders" likha aata hai us par CLICK karein — wahin popup mein us customer ke pichhle orders ki poori history khul jati hai (kab order gaya, kya manga, kitne ka bill) — bill banate hue hi dekh lein, page badalne ki zaroorat nahi.
- Customers ka import/export bhi hai (Export/Import buttons; template download kar ke import karein).
- Sale screen se bhi naya customer foran ban jata hai (phone box mein number likhein → "Add as New").

## POS Features (/pos/features) — sirf admin/manager
- Step 1 — Business Type: 9 presets (Restaurant, Cafe, Quick Service, Retail, Pharmacy, Salon, Grocery, Wholesale, Hybrid) — chunte hi features ki sifarish set ho jati hai.
- Step 2 — Feature toggles: Inventory Tracking, Delivery/Takeaway, Barcode Scanning, Customer Profiles, Service Jobs, Bulk Pricing, Prescription (Pharmacy), Customer Loyalty, Multi-Branch; Restaurant walay (Pro/Unlimited): KOT, Table Management, KDS, Kitchen Notes, Recipes.
- Cashier & Receipt preferences: density (Simple/Standard/Premium), Guided Keyboard Billing, Auto-Print KOT, Allow KOT Reprint.
- Sales Tax Rates (PRA): Cash Rate (%) aur Card/Digital Rate (%).
- "Reset" se features dobara default par aa jate hain.

## Customize POS (/pos/customize) — sirf admin/manager
- Setup shortcuts: Modules & Features, Business Profile, Receipt Display, Printer Settings, PRA Compliance ke links.
- POS Ka Style: Full (default) / Saaf + "Mazeed styles" (5 purane designs).
- POS Theme: 6 rang — Purple, Blue, Emerald, Orange, Midnight, Rose.
- Guided Keyboard Billing ON/OFF.
- Receipt Popup Auto-Close: Never / 5 / 10 / 15 / 20 / 30 seconds.
- KDS Auto-Print ON/OFF: ON ho to KDS screen wali device khud KOT print karti hai aur counter/cashier side ka KOT print BAND ho jata hai (sirf restaurant mode + KDS ON par asar). Agar KOT counter par print chahiye to yeh OFF rakhein.
- Quick Type Mode ON/OFF.
- Tax-Inclusive Pricing: Exclusive / Inclusive / Inclusive (Card-save).
- Auto Day-Close (24h) ON/OFF.
- Local Billing Policy: provisional aur final bills ke liye "Save to record" ya "Auto-delete" + customer spend history rakhne ka toggle.
- Restock on Void ON/OFF.
- Inventory Tracking ON/OFF.

## Business Profile (/pos/business-profile)
- Business Logo upload (ya remove), Business Name, Owner/Proprietor Name, NTN, Business Activity, Email, Phone (Landline), Mobile, Website, Full Address, City.
- Yehi maloomat receipt par aati hai (receipt-settings ke toggles ke mutabiq).
- Public QR Profile isi page par hai (dekhein Restaurant section — QR Menu).

## Terminals (/pos/terminals)
- Terminal add: Terminal Name, Terminal Code, Location → "Add Terminal". Har terminal Edit/Delete ho sakta hai. (Multi-counter dukano ke liye.)

## Reports
- /pos/reports: date range presets (Today, Last 7 Days, This Month waghera) + cashier/staff filter. KPIs: Revenue, Bills, Tax, Average Bill, Discounts, Customers (pichle period se % comparison). Charts: Category Share, Daily Revenue Trend, Hourly Pattern. Category breakdown table product-level tak khulti hai. Profit Estimate/Margin sirf admin ko (cost price wale products par). "Sales by Waiter" table bhi hai — har waiter ke orders, revenue aur average bill (sirf waiter ke bheje hue, settle shuda orders ginte hain). PDF/CSV export.
- Tax Reports (/pos/tax-reports): PRA aur Local tabs; filters: Tax Rate (available rates + Exempt), Period, Payment Method, Customer, custom date range; summary cards (Invoices, Sales Value, Tax, Total); "Download CSV" / "Download PDF". "Local" tab sirf admin ko.
- /pos/transactions: saray bills — filters: search (invoice/customer), payment method, date from/to; tabs: POS (PRA) / Local; status badges: Submitted, Failed, Pending, Offline, Local. Har bill par: Receipt, Edit/Delete (sirf jab tak PRA number NA laga ho), Retry PRA (failed par), Submit to PRA (local, current month). "Sync All (N)" = saray failed ek sath retry.
- Day Close ki purani Z-reports day-close page se.
- **Staff Hazri (26 Jul 2026, sirf admin/manager)**: /pos/reports par "Staff Hazri" button → kaun kab login hua (First In), kab tak kaam kiya (Last Out), kitni dafa login hua, kitne bills banaye aur pehli/aakhri sale ka waqt — business day (subah 6 → subah 6) ke hisab se, date picker se purane din bhi dekhein. Jo staff logout ka button na dabaye (browser band / bijli) us ke waqt ke sath * ka nishaan hota hai (aakhri activity ka waqt). Abhi kaam karne wale par sabz "ON DUTY" badge. Yehi hazri Day-Close Z-report (A4 PDF + thermal) mein bhi shamil hoti hai. Cashier/waiter yeh report NAHI dekh sakte.

## What's New aur Feature Suggestions
- Naye features ki ittila: top-nav ka bell icon + one-time popup (sirf admin/manager).
- Apni tajweez: top-nav bulb icon → /pos/suggestions (sirf admin/manager; din mein 10 tak). Status: pending → planned → completed (admin ke note ke sath).
- Madadgar (yeh bot) se bhi masla ya feature request seedha admin team ko bhej sakte hain — bot khulasa bana kar confirm karega, "Haan" par bhej dega aur Ref number milega.

## Sale Screen ka Naya Design (Jul 2026)
- Sale ke tools ab UPAR top bar mein hain: New Sale, Local (F10), Failed (F11), Reprint (Alt+R), sync status aur Switches — billing area pehle se saaf. (Held pill sirf un dukanon par dikhti hai jahan Table Management OFF hai; Table walon ke held orders TABLE board Alt+B mein hain.)
- Sync pill (top bar): Online (sabz) / Syncing (amber, pending bills ki ginti ke saath) / Offline (laal) — bills ki PRA sync ka live status.
- "Switches" dropdown (top bar) mein 3 quick toggles: PRA Reporting (cashier ke liye sirf status — badalna admin ka kaam), Auto-Print, aur Auto-KOT.
- "Akhri Bills" patti: products ke neeche aaj ke aakhri bills ke chips (desktop par) — ek click par receipt dobara print.
- Bada TOTAL band: cart ke neeche numaya solid band mein grand total — door se parhna asaan.
- One-tap CASH / CARD buttons (ya Alt+1 / Alt+2, 26 Jul 2026 se): ek dabane par bill SEEDHA final ho jata hai — koi dobara popup nahi. Tax apki tax setting se khud lagta hai (Cash aur Card ka apna apna rate, button par total pehle hi sahi dikhta hai). Method baad mein chunna ho ya bill note likhni ho to bara PAY (F8) button — us par Pay window khulti hai.
- Card Bachat mode mein CARD ka kam total pehle hi button par dikh jata hai.
- Customer box aur search ab 2 qataron mein: customer box bara, search poori chorai.
- Saaf cart rows (26 Jul 2026): har item ki qatar mein ab sirf quantity, total aur delete (bin) ka button hai — per-item TAX/Discount buttons aur per-item note ka box hata diya gaya hai. Discount ab sirf bill-level hai (cart ke neeche % Discount button ya D key) aur note payment window mein. Agar kisi purane/recalled bill mein item discount, note ya NO TAX laga ho to wo chhote chips ki shakal mein item ke neeche dikh jata hai (sirf dekhne ke liye).
- Bill note payment window mein hai (26 Jul 2026): Cash/Card chunne se pehle chhota sa "Bill note" ka box — kitchen/order hidayat wahan likhein. Note likhte waqt 1/2/Enter shortcuts nahi chalte; Enter dabane par box se bahar aa jate hain, phir shortcuts wapas chal parte hain.
- Dine In pill par table ka number (26 Jul 2026): table chunne ke baad order-type pill khud "Dine In · T-3" ban jati hai (teal rang ka nishaan) — alag se Table button hata diya gaya, upar wali qatar mazeed saaf. Table badalna ho to Dine In pill par dobara click karein (picker khul jata hai).
- Purane sab shortcuts (F-keys, T/D, guided flow) waise hi chalte hain.

## Apni Grid Khud Tarteeb Dein (Jul 2026)
- Har POS user (cashier, waiter, manager — sab) apni SALE SCREEN ki grid se items chhupa ya dikha sakta hai — sirf apni screen par, doosron par koi asar nahi.
- Kaise: products ke upar wali patti mein "Grid Tarteeb" (aankh wala) button dabayein → edit mode on. Ab kisi bhi item par tap karein = chhup jayega (dhundla ho jayega); dobara tap = wapas aa jayega. "Ho Gaya" se edit mode band.
- "Sab Wapas Dikhao" button = aap ki saari chhupi hui items ek saath wapas.
- Admin ne jo item "sale par na dikhao" kiya ho, user chahe to edit mode mein use apni grid par wapas la sakta hai — ye har user ka apna ikhtiyar hai.
- SEARCH par koi asar nahi: chhupa hua item bhi type karke dhoondein to mil jata hai aur bill mein add ho jata hai.
- Waiter tablet par bhi yehi feature hai ("Grid Tarteeb" button search ke neeche).

## Bill Reprint (Aaj ke Bills)
- Sale screen par Alt+R — aaj ke SAB bills: PRA walay, Local, Sync Queue, Failed, Provisional.
- Naya: products ke neeche "Akhri Bills" patti se bhi ek click par reprint (desktop).
- Bill par click ya Enter = receipt foran print (asal jaisi, koi COPY label nahi). Search se serial/naam/raqam dhoondein, ↑↓ se select.
- Silent printing ON ho to seedha printer par, warna print window.
- Purane dino ke bills: /pos/transactions.

## PWA / Mobile / Offline
- NestPOS ko phone/tablet/PC par app ki tarah install karein — browser ka "Add to Home Screen" / install icon.
- Offline mode: pages cache hote hain; net wapas aane par offline bills khud sync ho jate hain.
- Sale screen (Jul 2026 se): pehli dafa load hone ke baad computer par mehfooz ho jati hai — agli har dafa TURANT khulti hai, slow internet ya net band hone par bhi. Products/rates/settings badlein to screen khud taaza ho jati hai; logout ya user change par purani copy khud saaf ho jati hai. Agar screen purani lage to sirf refresh karein (F5) ya logout/login karein.
- App khud update hota hai — update par chhota toast aata hai aur app refresh hota hai (bill banate waqt kabhi beech mein refresh nahi hota).

## Aam Masail (Troubleshooting)
- "Bill PRA par nahi ja raha": internet check karein; bill 'offline' queue mein hoga aur khud retry hoga. Fiscal Device mode mein Desktop Agent ka chalta hona zaroori hai (dukan ke PC par).
- "Agent install karte hue 'File In Use / open in Electron' ka error": aap zip ko chalte hue agent ke folder par seedha extract kar rahe hain. Hal: (1) error window Cancel karein, (2) system tray mein TaxNest icon par right-click karke Quit karein (NestPOS window bhi band), (3) zip ko Downloads par NAYE folder mein extract karein, (4) us folder mein install.bat par double-click karein — wo purana agent khud band karke install kar dega. Yaad rahe: aik dafa install ke baad naye versions KHUD update hote hain — dobara download/install ki zaroorat nahi.
- "Login nahi ho raha": sahi panel use karein (/pos/login); Forgot Password se reset; 5 ghalat koshishon par thori der lock.
- "Receipt par tax nahi dikh raha": /pos/receipt-settings par "Show Tax" ON karein (PRA aur Local tab alag alag hain — sahi tab dekhein).
- "Printer poora width use nahi kar raha / kat raha hai": receipt-settings mein paper size (80mm/58mm) check karein, phir printer driver.
- "Receipt LEFT side se kat rahi hai (pehla harf ghayab, maslan ITEM ka I)": yeh masla Jul 2026 update mein fix ho chuka hai — sale screen refresh karein (F5) aur naya bill print karke dekhein. Phir bhi kate to Windows printer driver mein paper size "80mm receipt" set karein.
- "Item search mein nahi mil raha": search ab naam ke SHURU se chalti hai — product ka naam shuru se likhein (maslan "Chicken Roll" ke liye "chi" ya "chicken r", sirf "roll" se nahi milega). Barcode/SKU poora ya digits ke saath likhein to woh bhi mil jata hai. Phir bhi na mile to /pos/products par check karein — product inactive to nahi? Search har category mein dhoondti hai.
- "Sale screen par products ghayab hain": Products grid ka toggle OFF hai — "Show All Products" dabayen.
- "Manual button nazar nahi aata": Manual sirf Simple Mode (Inventory Tracking OFF) mein hota hai. Inventory ON ho to pehle /pos/products par product banayen.
- "Bills ki limit khatam ho gayi": package upgrade karein ya filhal provisional bills banayen (baad mein promote).
- "Team member add nahi ho raha": package ki account limit poori ho chuki hai — upgrade karein.
- "Restaurant features nazar nahi aa rahe": Pro ya Unlimited package chahiye, phir /pos/features se ON karein.
- "Dashboard par Opening Cash nazar nahi aa raha": din pehle hi close ho chuka hai — kal subah enter karein.
- "Deal ki price ghalat lag rahi hai": deal ke din/dates check karein — deal sirf apne set kiye dino par chalti hai.
- "KOT kitchen par nahi aa raha": /pos/restaurant/kitchen-settings par counter/printer check karein aur Desktop Agent chalta ho.
- "KOT 'sent' / 'print par bhej diya' ka message aata hai lekin kitchen mein kuch print nahi hota, koi error bhi nahi": SAB SE PEHLE Kitchen Settings mein "KDS Auto-Print" check karein. Yeh ON ho to KOT sirf KDS screen wali device se print hota hai — cashier/counter se bilkul nahi bhejta (double print rokne ke liye). Agar kitchen mein KDS screen chal hi nahi rahi to KOT kahin se bhi print nahi hoga. Hal: (a) KDS istemal nahi karte to /pos/restaurant/kitchen-settings par KDS Auto-Print OFF karein — KOT wapas cashier se kitchen printer par khud print hoga, YA (b) KDS chalayen: /pos/team se Kitchen role ka login banayen aur kitchen ki device par /pos/restaurant/kds khula rakhein.
- "KOT adhoora aata hai / sirf naye items print hote hain": /pos/restaurant/kitchen-settings par "Always Print Full KOT" ON karein — order update par poora order print hoga, naye items par NEW ka nishaan hoga.
- "KOT bohat lamba hai / paper zyada lagta hai / KOT chhota karna hai" (27 Jul 2026, naya): /pos/restaurant/kitchen-settings par "KOT Print Style — Paper Saving" section aa gaya hai. "Compact KOT" ON karein (chhote fonts, tang spacing — parchi kaafi chhoti ho jati hai) aur jo cheezein kitchen ko nahi chahiye unko OFF kar dein: Customer Name, "Order by" wali lines, Barcode (KHAYAL RAHE: barcode OFF karne se KDS ka scan-to-clear kaam nahi karega — sirf tab OFF karein jab kitchen ticket scan nahi karti), aur neeche wala Business Name. Sab OFF + Compact ON = sab se chhoti parchi.
- "KOT print side/kinare par aata hai / print center mein chahiye": /pos/restaurant/kitchen-settings par "KOT Print Style" section mein "Print Position" hai. Pehla hal: "Left Margin (mm)" mein 3-5 dalein — print utna right ho jayega (0 se 30mm tak). Doosra hal: Print Position "Center of paper" karein — LEKIN yeh SIRF tab jab Windows printer ki paper size 80mm roll set ho; agar printer A4/Letter par set hai to Center karne se parchi KHALI nikalegi (print paper se bahar chala jata hai). Shak ho to Left + margin wala tareeqa use karein.
- "Silent print nahi ho rahi": /pos/printer-settings par Silent Printing ON ho, printer select ho, aur Desktop Agent chalta ho. Setting badalne ke baad sale screen refresh karein.
- "Bill atak atak ke / adhoora print hota hai, dobara theek nikalta hai": yeh browser printing ka masla hai. Behtareen hal: Direct Printing ON karein — Desktop Agent chalta ho to sale screen par ek purple card khud aayega ("Direct Printing dastiyab hai") jis se admin aik click mein ON kar sakta hai, ya /pos/printer-settings se manually. Iske baad bill seedha printer par jayega, popup ke bagair. Saath mein Windows printer driver mein "Start printing after last page is spooled" set karna bhi madad karta hai.
- "Sale screen der tak khali/white rehti hai": ab loading screen ("NestPOS load ho raha hai") foran nazar aati hai — slow internet par thora intezar karein, screen khud aa jayegi. Baar baar ho to dukan ka internet check karein.
- "Sale screen bohat der mein khulti hai / loading par atki rehti hai": Jul 2026 update mein yeh bohat tez kar di gayi hai — khaas kar un dukanon par jin ke hazaron customers save hain, aur purane/kamzor PC par bhi. Ek dafa screen refresh (F5) karein taake nayi speed wali screen mil jaye. Phir bhi slow ho to dukan ka internet speed check karein.
- "Kitchen wali parchi (KOT) par QR nahi hota": yeh design hai — KOT sirf kitchen ke liye hoti hai, QR sirf customer ke final bill par aata hai (payment ke baad).
- "Screen chhoti/bari lag rahi hai": sale screen ke "Fit" menu se size adjust karein.
- "Item par note kaise likhein / item note kaha se dein": cashier ki sale screen par PER-ITEM note ka box ab NAHI hota (26 Jul 2026 se hata diya gaya). Poore order ki hidayat ke liye BILL NOTE ka box PAYMENT WINDOW mein hai — bara PAY button (ya F8) dabayen, Cash/Card chunne se pehle "Bill note" ka box milta hai (maslan "kam mirch, alag pack") — yeh note kitchen tak jata hai. Kisi EK item ke liye alag note sirf WAITER pad se likhi ja sakti hai (/pos/waiter — har item ke sath "Note for kitchen" ka box), ya phir bill note mein item ka naam likh kar hidayat dein (maslan "Zinger kam mirch, baqi normal"). Purane/recalled bill mein item note lagi ho to wo item ke neeche amber chip mein sirf DIKHTI hai (edit nahi hoti).
- "Hold nahi ho raha": Hold sirf Dine-In orders ke liye hai; manual items aur deals hold nahi ho sakte.
- "Discount nahi lag raha / limit ka error": cashier ki discount limit lagi hai — manager PIN se override karein ya admin limit badhaye.
- "Bill edit nahi ho raha": PRA number lag chuke bills edit/delete NAHI ho sakte (qanooni pabandi). Sirf local/provisional bills edit hote hain.
- Kisi bhi aur masle ke liye WhatsApp support ya Madadgar se escalation bhej dein.

## Multi-line kitchen notes & bina-receipt promote (Aug 2026)
- Bill/kitchen note ab MULTI-LINE hai: note ke khane mein Enter dabane se nayi line banti hai — har item ka alag note alag line par likhein. KOT par yeh notes number-war (1. 2. 3.) alag alag lines mein chhapte hain; receipt par bhi lines barqarar rehti hain. Note band karne ke liye Esc.
- Provisional bill ko Make Final karte waqt method picker mein neeche checkbox hai: "Receipt print na karein (customer maujood nahi)" — R key se bhi toggle hota hai. Tick karne par receipt AUTO-print nahi hoti (kaghaz bachta hai — delivery ke bills raat ko ikathay final karne walon ke liye); KOT (payment-first walay orders ki) phir bhi nikalti hai, aur receipt popup se manual print ab bhi ho sakta hai. Yeh choice usi computer par yaad rehti hai.

## Payment First, Then KOT — v2: F10 se KOT bhejein (Aug 2026)
- Setting ON ho (Kitchen & KOT Settings) to delivery ke provisional bill par KOT rukti hai. Jaise hi payment confirm ho, cashier F10 (Provisional Bills) kholay — delivery bill par narangi "KOT" button hota hai (ya bill select kar ke K dabayen) — KOT usi waqt kitchen mein chali jati hai. Raat ko Make Final karne ka intezar nahi karna parta.
- Agar F10 se KOT pehle bhej di ho to Make Final par dobara nahi chhapti; agar nahi bheji thi to Make Final par khud nikal jati hai (pehle jaisa).

## Day close aur pending provisional bills — Carry Forward (Aug 2026)
- Sawal: "Auto day-close (misal 6 baje) par jo bills Make Final karna bhool gaye unka kya?" — Customize POS → Local Billing mein provisional bills ki policy ab 3 options: Archive (mehfooz magar F10 se ghayab), Delete (urh jate hain), ya naya **Carry (Agle Din)** — bills F10 mein rahte hain aur agle din Make Final ho sakte hain. Raat-batch delivery walon ke liye Carry best hai.
- Day-close page ab pending provisional bills par surkh warning dikhata hai (agar policy Carry nahi) — pehle F10 se final karein.

## Customer ka purana delivery address delete (Aug 2026)
- Sale screen par delivery customer chun kar address dropdown se koi address select karein — sath surkh ✕ button aata hai, dabane par (confirm ke baad) woh saved address delete ho jata hai. Default address bhi isi tarah hat sakta hai. Customers page ka chakkar nahi lagana parta. Naya address "+ New" se pehle jaisa save hota hai.

## POS Customers page tez (Aug 2026)
- Bahut zyada customers (hazaron) wale shops par POS Customers page ab 100 customers fi page dikhata hai (pagination) — pehle saray ek sath load hote thay aur page atak jata tha. Search ab database se hoti hai: number ya naam likh kar Talash dabayen, kisi bhi page ka customer mil jayega.

## Waiter orders sab se naya oopar (Aug 2026)
- Cashier ki Incoming/Counter orders list mein ab sab se naya waiter order sab se OOPAR dikhta hai. Naye order ki itla (green message + Dine In par ginti ka badge) pehle se aati hai — screen har 20 second mein khud check karti hai.

## Order Sound — naye waiter order par awaz (Aug 2026)
- Restaurant mode mein cashier screen ke Switches menu mein "Order Awaz" ka switch hai (default ON). Naya waiter order aate hi chhoti si ghanti bajti hai (green message aur badge ke sath). Har counter/device apni awaz alag on/off kar sakta hai — yeh setting sirf usi computer par lagoo hoti hai.

## Waiter tablet se bhi Table Shift (Aug 2026)
- Waiter tablet ke 'Choose Table' mein occupied (surkh) table par tap karein — us table ka order kisi KHALI table par shift ho jata hai, chahe order cashier (desktop) ne lagaya ho ya kisi aur waiter ne. Timer sath chalta hai, KOT dobara print nahi hota. Apne orders 'My Orders' se pehle ki tarah bhi shift ho sakte hain.
