# NestPOS — Mukammal User Guide (Book Content)

> **Nota bene (production ke liye):** Yeh book ka TEXT content hai. Jahan `[SCREENSHOT: ...]` likha hai wahan PDF banate waqt asal screen ki tasveer lagegi. Offline billing aur PRA reporting ke chapters is book mein shamil NAHI hain (owner ki hidayat ke mutabiq).

---

## Baab 1 — NestPOS Kya Hai?

NestPOS aik mukammal Point of Sale (POS) system hai jo har qisam ki dukan ke liye banaya gaya hai — restaurant, cafe, retail store, pharmacy, grocery, salon, wholesale — sab ke liye. Is mein aap ko milta hai:

- Tez tareen billing (keyboard shortcuts ke sath — mouse ki zaroorat hi nahi)
- Products aur inventory ka mukammal intezam
- Restaurant ke liye tables, kitchen (KOT/KDS), waiter aur delivery ka poora system
- Customers ka record aur history
- Rozana ki reports, Z-report aur day-close
- Staff ke roles, hazri aur team management
- Thermal printer, barcode scanner aur cash drawer support
- Mobile/tablet par app ki tarah install (PWA)

NestPOS browser mein chalta hai — koi bhari software install karne ki zaroorat nahi. Computer, laptop, tablet ya mobile — sab par chalta hai.

`[SCREENSHOT: NestPOS dashboard ka overview]`

---

## Baab 2 — Login aur Account

### Login kaise karein
- POS ka login page: **/pos/login**
- Aap **Email, Phone, Username, CNIC ya NTN** — kisi se bhi login kar sakte hain (CNIC/NTN se company ka admin login hota hai).
- Har staff member ka apna login hota hai — cashier, manager, kitchen, waiter, rider sab ka alag.

### Password bhool jayein to
1. Login page par **"Forgot Password"** dabayen.
2. Email par OTP aayega.
3. OTP dal kar naya password set karein.

### Zaroori baatein
- 5 ghalat koshishon par login thori der ke liye lock ho jata hai — kuch minute intezar kar ke dobara koshish karein.
- POS ka user sirf POS panel par login kar sakta hai — kisi aur panel par "Invalid credentials" aayega. Yeh security ke liye hai.
- Har company ka data bilkul alag (isolated) hai — koi doosri dukan ka data nahi dekh sakta.
- Nayi company register hone ke baad admin approval tak sab pages DEKH sakte hain, lekin kaam (bill banana waghera) approval ke baad shuru hota hai.

### Apna profile
**/pos/my-profile** par apna Full Name, Email, Phone, Username badal sakte hain aur password change kar sakte hain (Current Password + New Password + Confirm).

`[SCREENSHOT: Login page]`

---

## Baab 3 — Dashboard

Dashboard (**/pos/dashboard**) aap ki dukan ka control room hai.

### Kya kya nazar aata hai
- **KPI cards**: aaj ki sales, bills ki ginti, tax, net sales
- **Hourly chart**: din ke kis waqt kitni sale hui
- **30 din ka trend** aur **payment method ka breakdown** (Cash/Card)
- Aaj ke **recent bills**
- Din ke shuru mein **"Opening Cash"** card — drawer ka cash enter karne ke liye (tafseel Day Close wale baab mein)

### Business Day ka usool
"Aaj" ke figures **Business Day** ke hisaab se hain: raat 12 baje ke BAAD ki sales (subah 6 tak, jab tak din close na ho) PICHLE din mein ginti hain. Misal: raat 1 baje ki sale 25 tareekh ke totals mein hi rahegi. Dukan raat der tak khuli ho to hisaab kabhi nahi bigarta.

### Kaun kya dekhta hai
- Cashier ko sirf **apne** stats nazar aate hain.
- Admin/Manager ko **poori company** ke.

### Dashboard ka style
**/pos/customize** ke "POS Ka Style" section se style badlein — **"Full — Poora Dashboard"** (default) ya **"Saaf — Simple"** (seedha saada, Roman Urdu mein). "Mazeed styles" ke neeche 5 aur designs bhi hain. Sirf admin/manager badal sakta hai.

`[SCREENSHOT: Dashboard — Full style]`
`[SCREENSHOT: Dashboard — Saaf style]`

---

## Baab 4 — Pehla Bill: Shuru Se Aakhir Tak

Sale screen kholein: Dashboard par **"Nayi Sale"** ya seedha **/pos/invoice/create**.

### Bill banane ke 7 asaan qadam

1. **(Optional) Customer chunein**: customer box mein phone/naam type karein → list se select karein. Match na mile to **"Add as New"** usi dropdown mein aata hai — naam + phone likh kar foran naya customer ban jata hai. Customer select hote hi uski chhoti history nazar aati hai (kitne orders, kitna kharcha, aakhri order kab). **Walk-in customer ke liye yeh step chhor dein** (khali chhor kar Enter).

2. **Items dalein — 3 tareeqay**:
   - (a) Search box mein naam/barcode/SKU type karein aur Enter
   - (b) Product grid par item click karein
   - (c) Barcode scanner se scan karein — exact match foran cart mein chala jata hai

3. **Quantity**: cart row ke qty box par click kar ke number type karein, ya same item dobara add karein, ya cart row select kar ke **+ / −** keys.

4. **(Optional) Discount ya tax**: discount BILL level par lagta hai — cart ke neeche **% Discount** button ya **D** key (Alt+D har jagah chalta hai). Kisi ek item ka tax ON/OFF keyboard se: item select kar ke **T** (ya **Alt+T** = aakhri item) — "NO TAX" ka sabz chip item ke neeche dikhta hai. (Cart ki rows mein ab alag buttons nahi — screen saaf rakhi gayi hai.)

5. **Order type** (restaurant mode mein): **Dine In / Takeaway / Delivery** buttons (guided flow mein keys 1/2/3).
   - **Dine In** chunte hi table picker khul jata hai — arrow keys se table select, Enter.
   - **Delivery** chunein to address picker aata hai (customer ke saved addresses + "Add New Address") aur cart ke upar **"Delivery Charges"** ki patti — wahan raqam likhein; yeh charges bill mein tax-free line ban kar judte hain.

6. **Bill mukammal karne ke raste**:
   - **PAY** button (ya F8) → payment window → **Cash (key 1)** ya **Card (key 2)** → bill FINAL.
   - **One-tap CASH / CARD** buttons (ya Alt+1 / Alt+2) → bill SEEDHA final — koi dobara popup nahi.
   - **Save Provisional** (ya F9) → bill L-series par mehfooz ho jata hai — baad mein final kar sakte hain (tafseel Baab 9 mein).

7. **Receipt popup** khulta hai — **P** = Print, **K** = KOT, **Enter** = nayi sale, **Esc** = band. Popup default 10 second mein khud band ho jata hai (koi key dabane ya mouse le jane se timer ruk jata hai); yeh waqt /pos/customize se badlein.

`[SCREENSHOT: Sale screen — poora layout]`
`[SCREENSHOT: Payment window — Cash/Card]`
`[SCREENSHOT: Receipt popup]`

### Bill par note likhna
- **Poore order ki hidayat**: PAY (F8) dabane par payment window mein **"Bill note"** ka box hai (Cash/Card chunne se pehle) — maslan "kam mirch, alag pack". Yeh note kitchen tak jata hai.
- **Kisi EK item ki alag note**: waiter pad se likhi jati hai (har item ke sath "Note for kitchen" ka box — Baab 14 dekhein), ya bill note mein item ka naam likh kar hidayat dein (maslan "Zinger kam mirch, baqi normal").

---

## Baab 5 — Sale Screen Ki Tafseel

### Layout (naya design, Jul 2026)
- Upar wali patti **do qataron** mein: pehli qatar mein bara **CUSTOMER box** (aur order-type buttons), doosri qatar mein **category dropdown + poori chaurai wala SEARCH box** aur bill ke buttons.
- Sale ke tools **top bar** mein: New Sale, Local (F10), Reprint (Alt+R), sync status aur Switches.
- **"Akhri Bills" patti**: products ke neeche aaj ke aakhri bills ke chips (desktop) — ek click par receipt dobara print.
- **Bara TOTAL band**: cart ke neeche numaya solid patti mein grand total — door se parhna asaan.
- Cart (Current Order) screen ke bilkul upar se shuru hota hai — items ki lambi list bina scroll ke zyada dikhti hai.

### Search ke usool
- Search hamesha **GLOBAL** hai: category dropdown sirf GRID filter karta hai — search box har category ka item dhoondta hai, deals aur services samet.
- Pehle harf ko priority milti hai (jo item aap ke likhe harf se **shuru** ho, wo upar aata hai).
- Barcode/SKU bhi search mein chalta hai.

### Product grid
- Grid **ON/OFF** toggle hai — grid OFF ho aur products ghayab lagein to "Show All Products" dabayen (yeh setting har PC/browser par alag save hoti hai).
- **Apni Grid Khud Tarteeb Dein**: har user (cashier, waiter, manager) apni grid se items chhupa/dikha sakta hai — sirf apni screen par. "Grid Tarteeb" (aankh wala) button → edit mode → item par tap = chhup gaya, dobara tap = wapas. "Sab Wapas Dikhao" = saari chhupi items wapas. **Search par koi asar nahi** — chhupa item bhi search se mil jata hai.

### Simple Mode (Inventory OFF)
- **"Manual"** button: naam + price likh kar ad-hoc item cart mein dalein; "Save Permanent" tick karein to product master mein bhi save.
- Search mein item na mile to naya product **foran** ban sakta hai (quick-create) — price 0 se banta hai aur cart row mein price box khud khul jata hai.
- Cart row ki unit price par click kar ke price edit hoti hai.
- Inventory Tracking ON ho to Manual/quick-create nahi hota — pehle /pos/products par product banayen.

### Quick Type Mode (F7)
"chai 2, samosa 1" jaisi line likhein — poora order khud cart mein aa jata hai. Default BAND hai; admin /pos/customize se ON kare.

### Aur bhi
- **Rush** button: order ko priority mark karta hai — kitchen par laal RUSH ka nishaan.
- **Hold (F5)**: Dine-In order hold karein — baad mein TABLE board se wapas kholein (Baab 13).
- **Send to Kitchen (KOT)**: Dine-In order bina payment ke kitchen bhejta hai, ticket print hota hai.
- **Screen Fit**: "Fit" button se poori screen 80–125% adjust karein (chhoti screens par khud 90%). Har PC par alag save hota hai.
- **Discount limit**: percentage aur Rs. amount dono par lagti hai (default 50%). Limit se zyada discount par **Manager PIN** ka modal khulta hai — PIN dalne par usi bill ke liye override.
- **Saaf style** mein kam-istemaal buttons (Rush, Fit, Keys, Quick) "Mazeed" button ke peechay hote hain — sab features waise hi chalte hain.

`[SCREENSHOT: Grid Tarteeb edit mode]`
`[SCREENSHOT: Manual item / quick-create]`

---

## Baab 6 — Keyboard Shortcuts (Mukammal List)

Tez billing ka raaz — haath keyboard se na uthayen:

| Key | Kaam |
|---|---|
| **F1** | Shortcuts ki madad wali screen |
| **F2** | Search box par focus |
| **F4** | Poora cart khali (confirm poochta hai) |
| **F5** | Order HOLD |
| **F6** / Ctrl+E | Cart mode — aakhri item par focus |
| **F7** | Quick Type modal (agar ON hai) |
| **F8** | PAY — payment window (phir 1 = Cash, 2 = Card) |
| **F9** | Save Provisional |
| **F10** | Local/Provisional bills ka modal |
| **Alt+R** | Reprint (aaj ke bills) |
| **Alt+1 / Alt+2** | One-tap CASH / CARD — bill seedha final |
| **Alt+P** | Customer phone box par focus |
| **Ctrl+S** | Search par focus |
| **Alt+B** | TABLE board (restaurant) |
| **T** | Selected item ka TAX ON/OFF |
| **D** | Bill discount panel |
| **Alt+T / Alt+D** | Tax / Discount — har jagah chalte hain |
| **Enter** | Guided flow mein agla qadam |
| **Esc** | Modal band |

**Cart mode mein**: ↑↓ = row select, + / − = quantity, Delete/Backspace = item hatao.

**F10 modal ke andar**: ↑↓ select, Enter = Make Final, E = Edit, D = Delete (cashier delete nahi kar sakta), Esc = band.

### Guided Keyboard Flow (default ON)
Customer → Items → Order Type (1/2/3) → Pay — **sirf Enter dabate jayen**:
- Khali customer box par Enter = walk-in skip
- Items dal kar khali search par Enter = agla step
- Pay window mein Enter = Cash confirm

/pos/customize se ON/OFF hota hai.

`[SCREENSHOT: F1 shortcuts help screen]`

---

## Baab 7 — Payment aur Receipt

### Payment methods
- **Cash** aur **Card** (payment window mein; keyboard 1 = Cash, 2 = Card).
- Har method ka apna tax rate ho sakta hai (aam default: cash 16%, card/digital 8%) — apne rates **/pos/features** par "Cash Rate (%)" / "Card / Digital Rate (%)" se set karein.
- Bill ka grand total hamesha **poore rupay** mein round hota hai (line items 2 decimal tak).

### Receipt Settings (/pos/receipt-settings)
DO tabs: **"PRA Receipt"** aur **"Local Receipt"** — har tab ke apne toggles:
- Show Address, Show NTN, Show Email, Show Phone/Mobile, Show Cashier Details, Show Tax
- Footer message (toggle + apna text)

Global options:
- **Receipt Paper Size**: 80mm Standard / 58mm Compact
- **PDF Download Paper**: Thermal Roll / A4
- **Bold Receipt Print** toggle
- **Logo Style**: Compact / Large

### Zaroori baatein
- **"Show Tax" OFF** karne se customer copy par Subtotal aur Tax chhup jate hain — sirf grand TOTAL nazar aata hai; items apni asal price par dikhte hain.
- Receipt ka default style: **bold + center logo** — text gehra hai taake saste printers par bhi saaf chape. Company chahe to plain style choose kar sakti hai.
- Restaurant bills par order type ka numaya badge chapta hai — **DINE-IN / TAKE AWAY / DELIVERY** (bold, border ke sath, bilkul upar).
- Download ki hui PDF aam printer par side se kat kar chape to "PDF Download Paper" ko **A4** kar dein.

### Purane bill ki receipt
- Sale screen par **Alt+R** (Reprint) — aaj ke sab bills.
- **/pos/transactions** se koi bhi purana bill khol kar receipt/PDF.
- **Share link**: /pos/transactions se bill kholein → share link banayen — customer ko WhatsApp par bhej sakte hain.

`[SCREENSHOT: Receipt settings — dono tabs]`
`[SCREENSHOT: Thermal receipt ka namoona]`

---

## Baab 8 — Printer, Scanner aur Hardware

### Thermal printer
NestPOS kisi bhi aam thermal receipt printer (80mm/58mm) ke sath chalta hai — USB, network ya Bluetooth.

**Setup ke 3 qadam:**
1. Printer PC se connect kar ke driver install karein.
2. Browser ke print dialog mein wohi printer select karein.
3. /pos/receipt-settings par paper size set karein.

### Silent Printing (bina print popup ke)
1. **Desktop Sync Agent** install karein (Windows).
2. **/pos/printer-settings** par jayen → "Enable Silent Printing" ON.
3. "Bill / Receipt Printer" aur "Kitchen (KOT) Printer" dropdowns se printers chunein (list Agent se aati hai).
4. Ab receipt/KOT seedha printer par jayenge — koi popup nahi.

### Aam masail
- **Printers ki list khali hai**: PC par purana agent chal raha hai — /pos/printer-settings → "Agent Setup" → "Download ZIP" se naya agent lein, extract kar ke install.bat chalayen. 5 minute mein list aa jayegi.
- **Receipt ke aage/peeche lamba khali kaghaz**: Windows mein printer ki Printing Preferences kholein aur paper size A4 se badal kar 80mm Receipt set karein.
- **Bill atak atak ke print hota hai**: Direct/Silent Printing ON karein — masla khatam.

### Barcode scanner
Koi bhi USB/Bluetooth scanner jo keyboard ki tarah type karta hai **seedha chal jata hai** — sale screen ke search box mein scan karein. Alag setup nahi chahiye.

### Cash drawer
Aksar printer ke kick port se juda hota hai — receipt print par khud khul jata hai (yeh printer ki apni setting hai).

### Barcode stickers
**/pos/products/labels** — sticker par naam, price, barcode aur SKU; "Columns" box se sheet ke columns (1–5) set karein aur Print.

`[SCREENSHOT: Printer settings — silent printing]`
`[SCREENSHOT: Barcode labels page]`

---

## Baab 9 — Provisional / Local Bills

Kabhi kabhi bill foran final nahi karna hota — is ke liye **Provisional (Local) bill** hai.

- Provisional bill **L-series** number par banta hai aur monthly bill quota mein NAHI ginta — jab tak final na karein, bilkul FREE hai.
- **Banane ka tareeqa**: sale screen par "Save Provisional" ya **F9** (ya payment window mein P — guided flow ON ho to).

### Dekhna aur sambhalna
- Sale screen par **F10** modal: har bill par Enter = **Make Final**, E = **Edit** (bill wapas sale screen par khulta hai), D = **Delete** (cashier nahi kar sakta).
- **/pos/local-bills** portal: poori list + export.
- Purane (archive kiye) bills: **/pos/archive** (export bhi).

### Final (promote) karna
Promote par method picker khulta hai — **Cash (1)**, **Card (2)**, ya **"Finalize LOCAL" (3 ya L)** = bill final ho jata hai lekin L-series par hi rehta hai. Promote sirf **usi mahine** ke andar ho sakta hai. /pos/transactions par bhi local bill ke saamne button hota hai.

### Day-close policy
Day-close par us din ke local bills company ki policy ke mutabiq **save ya delete** hote hain — policy /pos/customize → **Local Billing** se (sirf admin; provisional aur final dono ke liye alag "Save to record"/"Auto-delete" + customer spend history ka toggle).

`[SCREENSHOT: F10 Local bills modal]`
`[SCREENSHOT: Local Bills portal]`

---

## Baab 10 — Opening Cash aur Day Close

### Opening Cash (din ka aghaz)
Din ke shuru mein Dashboard ke **"Opening Cash"** card par drawer ka cash amount (+ optional note) enter kar ke Save — cashier bhi kar sakta hai. Sirf aaj ke liye; din close hone ke baad lock ho jata hai.

### Day Close (din ka ikhtitam)
**Manual tareeqa:**
1. **/pos/day-close** kholein.
2. Z-report ka khulasa dekhein.
3. Cash reconciliation mein **gina hua cash** dalein — variance khud calculate hota hai.
4. Rider khata dekhein.
5. **"Close Day"** dabayen.

**Auto day-close**: koi manually na kare to agle din subah 6:00 baje system khud band kar deta hai (/pos/customize par toggle).

### Z-Report mein kya kya hota hai
- Total Invoices, Gross Sales, Total Tax, Net Revenue
- Payment breakdown (Cash/Card/Other)
- **Cashier-wise breakdown** table
- **Staff Hazri** — kaun kab aaya/gaya, kitne bills banaye
- Din ke pehle–aakhri invoice numbers
- Hourly chart, top products, discount summary
- Cash reconciliation aur rider summary
- Pichle din/haftay se comparison

**Cash reconciliation ka formula**: expected cash = opening cash + cash sales − rider ko diya cash + rider se wapas aya cash.

Z-report **"Download PDF" (A4)** aur **"Thermal Z-Report" (80mm)** dono par nikalti hai; purani closes day-close page par "View" se.

`[SCREENSHOT: Day-close page — Z-report]`
`[SCREENSHOT: Opening Cash card]`

---

## Baab 11 — Products aur Services

### Naya product (/pos/products → "+ Add Product")
Form ke fields:
- **Product Name** (zaroori), Category, SKU, Description, Barcode
- **Price PKR** (zaroori), Cost Price (profit report ke liye)
- Tax Rate % + **"Tax Exempt"** toggle
- "Sale screen par dikhayein" toggle
- Unit (NOS/KGS/LTR/MTR/PCS/PKT/BOX)
- Opening Stock, Low-Stock Alert At
- Image (No Image / Upload / Auto-fetch)

### Category ke hisaab se extra fields
- **Pharmacy**: Batch Number, Expiry Date, Drug Type
- **Grocery**: Weight Based, Unit Type
- **Kapre/Apparel**: Size, Color, Season
- **Electronics**: Serial Number, Warranty Months, IMEI
- **Automotive**: Part Number

### Product list par
Har product par **Edit, Delete, Active/Inactive** toggle aur **"sale screen par dikhao"** toggle — item search mein na mile to yahan check karein.

### Excel import/export
- "Download Excel Template" (ya "Download Excel (N Products)" — poori list) → Excel mein edit → "Upload & Import" (barcode/SKU/naam se khud match).
- **CSV use NA karein** — barcode kharab ho jate hain.

### Services
Agar dukan services bhi deti hai: **/pos/services** par alag list — yeh bhi sale screen ke search mein aati hain.

`[SCREENSHOT: Products list]`
`[SCREENSHOT: Add Product form]`

---

## Baab 12 — Inventory / Stock

### ON/OFF
Inventory **/pos/features** se ON/OFF hoti hai (ya /pos/customize ka Inventory Tracking toggle). OFF = **Simple Mode** (Manual button + quick-create milta hai). OFF ho to nav mein grey "OFF" badge nazar aata hai.

### Stock ke pages
- **Stock list** (/pos/inventory/stock): search + status filter (All/Low/Out); har product ka stock level bar, min level, average cost, stock value; min level inline edit.
- **Stock adjust** (/pos/inventory/adjust): product chunein → type: **Add (+) / Remove (−) / Set Exact (=)** → quantity → (Add par purchase price) → Reason (New Purchase, Physical Count, Damaged/Expired waghera) → Notes → save.
- **Movements** (/pos/inventory/movements): har tabdeeli ka log — filters: type (Sale/Purchase/Adjustment/Opening), product, date range; har row mein qty +/− aur balance-after.
- **Low stock** (/pos/inventory/low-stock): Critical/Warning/Low ke counts; har row par "Restock" button seedha adjust form kholta hai.

### Usool
- Sale par stock **khud katta** hai; bill delete par wapas aana ("Restock on Void") /pos/customize ki setting hai.
- **Deals** ka stock deal ke andar ke items se katta hai; **recipes** wali dishes ka stock ingredients se katta hai.

`[SCREENSHOT: Stock list]`
`[SCREENSHOT: Stock adjust form]`

---

## Baab 13 — Restaurant Module (Pro/Unlimited)

Restaurant module ke liye **Pro ya Unlimited package** chahiye (ya active trial), phir **/pos/features** se restaurant features ON karein: KOT, Table Management, KDS, Kitchen Notes, Recipes.

### Order types ke rules
- **Dine-In** = pehle Hold/KOT, khana banne ke baad payment
- **Takeaway** = seedha final bill
- **Delivery** = final ya provisional dono

### Tables aur Floors
**/pos/restaurant/table-management** → "+ Add Floor" (naam, jaise Ground Floor) → "+ Add Table" (Table Number jaise T1, Seats 1–50). Table delete = card par × button.

### Dine-In ka poora flow
1. Sale screen par order type **"Dine In"** → table picker khud khulta hai → table select.
2. Table chunte hi order-type pill **"Dine In · T-3"** ban jati hai (teal nishaan) — table badalna ho to pill par dobara click (picker khul jata hai).
3. Items dalein → **"Send to Kitchen" (KOT)** ya **Hold**.
4. Khana ban jaye to **TABLE board (Alt+B)** se table par click → "View / Edit" ya "FINAL karo" se payment.
5. Payment par table **khud free** ho jata hai.

### TABLE Board — restaurant ka control room
Table Management ON ho to sale screen par cart ke neeche patli **"TABLE"** button-patti hoti hai:
- Rangin ginti ke badges: **laal** = order chal raha, **amber** = reserved, **jamni** = waiter tayyar, **"C"** = counter orders, **"H"** = held orders
- **"Rs X chalu"** — tamam khule orders ki kul raqam

Button par click (ya **Alt+B**) = TABLE BOARD popup:
- Har table ka rang: **sabz** = khali, **amber** = reserved, **laal** = order chal raha, **jamni** = waiter ka order tayyar
- Kitni der se order laga hai, kis staff ka order hai, bill ki raqam
- **DER wale orders khud ujagar** hote hain: waiter ka order ~10 min koi na uthaye, table par order ~30 min se chal raha ho, ya reservation ~15 min purani — tile ka rang gehra, ⚠ ka nishaan, waqt ki chip blink karti hai
- Data har ~25 second mein khud taaza hota hai

**Table par click karne se chhota MENU khulta hai** (seedha koi action nahi hota):
- **View / Edit** — order cart mein kholein
- **FINAL karo** — pehle CASH ya CARD ki tasdeeq poochta hai (ghalti se final hone ka masla khatam)
- **KOT Dobara Bhejo** — updated ticket kitchen ko (UPDATED ka nishaan ke sath)
- **⇄ Table Badlein (Shift)** — neeche dekhein
- **Order Cancel + Table Khali** (waiter ke orders cashier cancel nahi kar sakta)
- Khali table par click kar ke **Reserve** bhi kar sakte hain

### Table Shift (table badalna)
Customer table badalna chahe to: TABLE board → table par click → **"⇄ Table Badlein (Shift)"** → sirf **khali** tables ki list aati hai → nayi table chunein, ho gaya.
- Table ka **timer wahi chalta rehta hai** (order kab laga tha)
- KOT dobara print **NAHI** hota (khana wahi hai, sirf jagah badli)
- Reserved ya occupied table par shift nahi ho sakta
- Waiter apne order ka table waiter pad ke "My Orders" se bhi shift kar sakta hai

### Held Orders (bina table)
Bina table walay held orders board popup mein **"Held Orders (bina table)"** ke amber chips mein hote hain. Chip par click = menu: "Bill Kholo / Edit karo", "PAY karo", "KOT Dekho", "↻ KOT Dobara Bhejo", "Order Delete".

### Kitchen Settings (/pos/restaurant/kitchen-settings)
Toggles: **Kitchen Display System (KDS)**, Kitchen Printer, Print KOT on Hold, Dine-In Auto KOT on Table Select, Print Receipt on Pay, **Always Print Full KOT** (order update par poora order print, naye items par NEW ka nishaan).

### KOT Print Style — Paper Saving (27 Jul 2026, naya)
Kitchen Settings par hi ek naya section — KOT ki parchi chhoti karne aur print ki position set karne ke liye:
- **Compact KOT**: chhote fonts, tang spacing — parchi kaafi chhoti ho jati hai.
- **Show Customer Name / "Order by" & Item Count / Barcode / Business Name**: jo cheez kitchen ko nahi chahiye OFF kar dein — parchi aur chhoti. (Barcode OFF karne se KDS ka scan-to-clear band ho jata hai — sirf tab OFF karein jab kitchen ticket scan nahi karti.)
- **Print Position**: default "Left edge" (sab se mehfooz). Print side par aata ho to **Left Margin (mm)** mein 3–5 dalein — print utna right sarak jayega. "Center of paper" SIRF tab chunein jab Windows printer ki paper size 80mm roll set ho — A4/Letter queue par Center se parchi khali nikalti hai.

### Counters / Stations (KOT routing)
"+ Add Counter" → Counter Name (jaise "Grill") → Printer chunein → Product Categories tick karein — un categories ke items ka KOT usi counter par jayega.

### KDS — Kitchen Display System (/pos/restaurant/kds)
- Kitchen account login karta hai (Kitchen role — /pos/team se; team limit mein nahi ginta, sirf KDS dekhta hai).
- Order cards par order number, table, RUSH tag aur timer.
- Buttons: **"Start Preparing" → "Mark Ready" → "Clear"**.
- KOT ka **barcode scan** karne se order khud clear ho jata hai.
- **KDS Auto-Print** ON ho to KDS wali device khud KOT print karti hai aur counter/cashier ka KOT print band ho jata hai (double print se bachao).

### Waiter ke orders cashier tak
Waiter ka order aaye to "TABLE" patti par teal badge + toast. Board mein wo table **jamni** "Order Tayyar" ke sath dikhta hai — click karte hi order cart mein aa jata hai, bas payment karein. Bina table walay waiter orders (Takeaway/Delivery) board ke **"Counter Orders"** section mein. Ek order sirf ek cashier le sakta hai.

### Recipes / Ingredients
- **/pos/restaurant/ingredients**: kachha maal (Name, Unit: kg/g/ltr/ml/pcs, Cost/Unit, Opening Stock, Min Stock).
- **/pos/restaurant/recipes**: product chunein → ingredient + Qty Needed rows → Save Recipe.
- Dish bikne par ingredients ka stock **khud katta** hai.

### QR Menu / Public Profile
**/pos/business-profile** → "Public Page Enabled" ON + "Menu" visible ON → menu builder mein products tick karein → QR code customer ko dikhayen. Har cheez ka apna toggle (Phone, Mobile, Email, Address, NTN, Website, Opening Hours). "Regenerate Link" se naya link.

`[SCREENSHOT: TABLE board popup]`
`[SCREENSHOT: Table menu — FINAL karo / Shift]`
`[SCREENSHOT: KDS screen]`
`[SCREENSHOT: QR menu ka public page]`

---

## Baab 14 — Waiter Pad

Waiter apne login se **/pos/waiter** kholta hai:

1. **Dine In / Take Away** chunein.
2. **"Choose Table"** (Available sabz, Occupied laal).
3. Items search kar ke cart mein dalein — **har item par "Note for kitchen"** ka box hai (item-specific hidayat yahin likhi jati hai).
4. Customer naam/phone (optional).
5. Poore order ki **kitchen note**.
6. Cashier select karein → **"SEND TO CASHIER"**.

- Pehle se bheje order mein **"My Orders" → "Add Items"** se aur items add ho sakte hain.
- "My Orders" se waiter apne order ka **table shift** bhi kar sakta hai.
- Waiter **payment/discount/delete nahi** kar sakta — yeh cashier ka kaam hai.
- Waiter tablet par bhi "Grid Tarteeb" feature hai.

`[SCREENSHOT: Waiter pad]`

---

## Baab 15 — Delivery aur Riders

### Riders banana (/pos/riders)
Naam, phone, CNIC, vehicle number. **"Create Login"** se rider ka email+password banayen — rider sirf apna portal dekhta hai; team limit mein NAHI ginta.

### Delivery ka flow
1. Delivery order ka bill banayen (final ya provisional).
2. **/pos/deliveries** board par **"Assign Rider"** dropdown se rider assign karein (bill banne ke BAAD — payment window mein assign nahi hota).
3. Status buttons: **Dispatch → Delivered → (zaroorat par) Returned**.
4. Rider apne portal (**/pos/rider**) se bhi "Delivered ✓" kar sakta hai.

### Cash settlement (rider ka khata)
- Rider portal par rider ko apne orders, address aur **"Cash to hand over"** ka banner nazar aata hai.
- Settle: /pos/deliveries par rider ke card par **"Settle Cash"** → bills tick karein → note (optional) → "Confirm Settlement". **Partial settlement** bhi ho sakta hai.
- Day-close par rider ka poora khata (owed vs settled) nazar aata hai.

### Delivery Manager role
Sirf deliveries board + settlement tak — free, team limit mein nahi ginta.

**Yaad rahe**: "Returned" mark karne se bill cancel NAHI hota — yeh sirf andaruni record hai.

`[SCREENSHOT: Deliveries board]`
`[SCREENSHOT: Rider portal]`

---

## Baab 16 — Customers

**/pos/customers** par:
- Fields: Name, Phone, Email, Type (Registered/Unregistered), CNIC, NTN, City, Address.
- "+ Add Customer" se banayen; phone/naam se search; Active/Inactive toggle; inline Edit; Delete.
- **History**: customer ke saamne "History" → us ke saray bills (export/PDF bhi).
- **Import/Export**: template download kar ke import karein.
- Sale screen se bhi naya customer foran ban jata hai (phone box → "Add as New").
- Delivery customers ke **saved addresses** bhi save hote hain — agli dafa picker mein aa jate hain.

`[SCREENSHOT: Customers list + history]`

---

## Baab 17 — Team, Roles aur Staff Hazri

### Member add (/pos/team)
Naam, email, phone, password, role → save.

### Roles
- **Manager** = admin jaisa (settings, reports, sab)
- **Cashier** = sirf billing
- **Kitchen, Waiter, Rider, Delivery Manager** = mehdood access, FREE (limit mein nahi ginte)

Manager aur Cashier package ki account limit mein ginte hain (Starter 1, Business 5, Pro 10, Unlimited unlimited).

### Team page par aur kya
- Cashier **ON/OFF** toggle (band cashier login nahi kar sakta).
- Company admin team ke **passwords dekh** sakta hai (sirf admin ko nazar aate hain).

### Staff Hazri (attendance)
**/pos/reports** par "Staff Hazri" button (sirf admin/manager):
- Kaun kab login hua (**First In**), kab tak kaam kiya (**Last Out**), kitni dafa login hua, kitne bills banaye, pehli/aakhri sale ka waqt.
- **Business day** (subah 6 → subah 6) ke hisaab se; date picker se purane din bhi.
- Jo staff logout ka button na dabaye (browser band / bijli) us ke waqt ke sath **\*** ka nishaan (aakhri activity ka waqt).
- Abhi kaam karne wale par sabz **"ON DUTY"** badge.
- Yehi hazri **Day-Close Z-report** (A4 PDF + thermal) mein bhi shamil hoti hai.

`[SCREENSHOT: Team page]`
`[SCREENSHOT: Staff Hazri report]`

---

## Baab 18 — Reports

### Sales Reports (/pos/reports)
- Date range presets (Today, Last 7 Days, This Month waghera) + cashier/staff filter.
- KPIs: Revenue, Bills, Tax, Average Bill, Discounts, Customers (pichle period se % comparison).
- Charts: Category Share, Daily Revenue Trend, Hourly Pattern.
- Category breakdown table product-level tak khulti hai.
- **Profit Estimate/Margin** sirf admin ko (cost price wale products par).
- **"Sales by Waiter"** table — har waiter ke orders, revenue aur average bill.
- PDF/CSV export.

### Tax Reports (/pos/tax-reports)
- Filters: Tax Rate (available rates + Exempt), Period, Payment Method, Customer, custom date range.
- Summary cards: Invoices, Sales Value, Tax, Total.
- "Download CSV" / "Download PDF".

### Transactions (/pos/transactions)
- Saray bills — filters: search (invoice/customer), payment method, date from/to.
- Har bill par: Receipt, Edit/Delete (sirf jab tak bill par sarkari number NA laga ho), share link.

### Day Close ki purani Z-reports
Day-close page se "View".

`[SCREENSHOT: Reports page — KPIs aur charts]`
`[SCREENSHOT: Tax reports]`

---

## Baab 19 — Deals

**/pos/deals** par:
- Deal banana: "+ Add Deal" → Deal Name, Deal Price (PKR), Description (optional), **Active Days** (Mon–Sun checkboxes), Start/End Date (optional), Active toggle → "Add Item" se products + quantity → save.
- Deal ki **price server enforce** karta hai — cashier badal nahi sakta.
- Deal sirf apne set kiye **dino (aur date range)** mein sale screen par aati hai.
- Deals sirf **seedhi billing** ke liye hain — hold/KOT par nahi ja saktin.
- Deal ka stock deal ke andar ke items se katta hai.

`[SCREENSHOT: Deals page]`

---

## Baab 20 — Settings: Features, Customize aur Business Profile

### POS Features (/pos/features) — sirf admin/manager
- **Step 1 — Business Type**: 9 presets (Restaurant, Cafe, Quick Service, Retail, Pharmacy, Salon, Grocery, Wholesale, Hybrid) — chunte hi features ki sifarish set.
- **Step 2 — Feature toggles**: Inventory Tracking, Delivery/Takeaway, Barcode Scanning, Customer Profiles, Service Jobs, Bulk Pricing, Prescription (Pharmacy), Customer Loyalty, Multi-Branch; Restaurant walay (Pro/Unlimited): KOT, Table Management, KDS, Kitchen Notes, Recipes.
- Cashier & Receipt preferences: density (Simple/Standard/Premium), Guided Keyboard Billing, Auto-Print KOT, Allow KOT Reprint.
- Sales Tax Rates: Cash Rate (%) aur Card/Digital Rate (%).
- "Reset" se defaults wapas.

### Customize POS (/pos/customize) — sirf admin/manager
- **POS Ka Style**: Full (default) / Saaf + "Mazeed styles" (5 designs)
- **POS Theme**: 6 rang — Purple, Blue, Emerald, Orange, Midnight, Rose
- Guided Keyboard Billing ON/OFF
- Receipt Popup Auto-Close: Never / 5 / 10 / 15 / 20 / 30 seconds
- KDS Auto-Print ON/OFF
- Quick Type Mode ON/OFF
- **Tax Pricing mode** (Baab 21 dekhein)
- Auto Day-Close (24h) ON/OFF
- Local Billing Policy (save/auto-delete)
- Restock on Void ON/OFF
- Inventory Tracking ON/OFF

### Business Profile (/pos/business-profile)
- Business Logo (upload/remove), Business Name, Owner Name, NTN, Business Activity, Email, Phone, Mobile, Website, Full Address, City.
- Yehi maloomat **receipt par** aati hai (receipt-settings ke toggles ke mutabiq).
- **Public QR Profile** isi page par hai (Baab 13 — QR Menu).

### Terminals (/pos/terminals)
Multi-counter dukano ke liye: Terminal Name, Terminal Code, Location → "Add Terminal". Har terminal Edit/Delete.

`[SCREENSHOT: Features page — business type presets]`
`[SCREENSHOT: Customize page]`

---

## Baab 21 — Tax Pricing Ke 3 Modes

**/pos/customize** se (sirf admin):

1. **Standard (Tax Upar Se / Exclusive)**: menu price par tax alag se lagta hai. Rs 100 ka item + 16% = Rs 116.
2. **Menu Rate Final — Sab Same (Inclusive)**: menu price hi grand total hai — customer wohi deta hai jo menu par likha hai, har payment method par same.
3. **Menu Rate Final — Card Bachat (Card-save)**: menu price cash ke hisaab se final; card/digital par thora sasta — receipt par "Card Discount" nazar aata hai.

### Zaroori baatein
- Tax rates: default cash 16%, card/digital 8% — apne rates /pos/features se.
- Kisi ek product ko tax-free karna ho: product edit → **"Tax Exempt"** toggle ON.
- Mode badalne se **purane bills nahi badalte** — sirf naye bills par lagta hai.

---

## Baab 22 — Packages aur Billing

**/pos/billing** par plans (sirf saalana — 6% discount pehle se shamil):

| Package | Qeemat/saal | Team accounts | Final bills/mahina | Khaas |
|---|---|---|---|---|
| **Starter** | Rs 9,999 | 1 | 500 | — |
| **Business** | Rs 14,999 | 5 | 2,000 | — |
| **Pro** | Rs 24,999 | 10 | 3,000 | 2 branches, Restaurant module |
| **Unlimited** | Rs 39,999 | Unlimited | Unlimited | Sab kuch |

### Payment ka tareeqa
1. Plan chunein.
2. Di gayi bank details (Bank, Title, IBAN) par raqam bhejein.
3. Payment proof form bharein: Package, Amount Paid (PKR), Reference/TID, proof upload (JPG/PNG/PDF).
4. Admin verify kar ke package activate karta hai. Jaldi ho to "Send on WhatsApp" se proof bhej dein.

### Quota ke usool
- Sirf **FINAL bills** quota mein ginte hain — provisional FREE hain jab tak promote na hon.
- Quota har mahine reset hota hai.
- Kitchen/Waiter/Rider/Delivery Manager logins **free** hain — limit sirf Manager+Cashier par.

`[SCREENSHOT: Billing page — plans]`

---

## Baab 23 — Mobile App (PWA)

- NestPOS ko phone/tablet/PC par **app ki tarah install** karein — browser ka "Add to Home Screen" / install icon.
- Sale screen pehli dafa load hone ke baad computer par mehfooz ho jati hai — agli har dafa **turant** khulti hai, slow internet par bhi.
- Products/rates/settings badlein to screen khud taaza ho jati hai; logout par purani copy khud saaf.
- App **khud update** hota hai — update par chhota sa paigham aata hai aur app refresh hota hai (bill banate waqt kabhi beech mein refresh nahi hota).

`[SCREENSHOT: Mobile par NestPOS installed]`

---

## Baab 24 — What's New, Tajaweez aur Madadgar

### What's New
Naye features ki ittila: top-nav ka **bell icon** + one-time popup (sirf admin/manager).

### Feature Suggestions
Apni tajweez: top-nav **bulb icon** → /pos/suggestions (sirf admin/manager; din mein 10 tak). Status: pending → planned → completed (admin ke note ke sath).

### Madadgar — AI Support
Har POS page par neeche **Madadgar ka bubble** — koi bhi sawal Roman Urdu mein poochein:
- Feature kaise use karein, masla kaise hal karein — foran jawab.
- Masla ya feature request **seedha admin team ko** bhi bhej sakte hain — bot khulasa bana kar confirm karega, "Haan" par bhej dega aur Ref number milega.
- WhatsApp support ka option bhi isi bubble mein hai.

`[SCREENSHOT: Madadgar bubble aur chat]`

---

## Baab 25 — Aam Masail (Troubleshooting)

| Masla | Hal |
|---|---|
| Login nahi ho raha | Sahi panel use karein (/pos/login); Forgot Password se reset; 5 ghalat koshishon par thori der lock |
| Item search mein nahi mil raha | Naam **shuru se** likhein ("Chicken Roll" ke liye "chi", sirf "roll" nahi); /pos/products par check karein — inactive to nahi? |
| Sale screen par products ghayab | Grid ka toggle OFF hai — "Show All Products" dabayen |
| Manual button nazar nahi aata | Manual sirf Simple Mode (Inventory OFF) mein hota hai |
| Item par note kaise likhein | Per-item note ka box ab nahi hota — **Bill note** payment window mein hai (PAY/F8 → Cash/Card se pehle); kisi ek item ki note **waiter pad** se |
| Receipt par tax nahi dikh raha | /pos/receipt-settings par "Show Tax" ON karein (PRA/Local tab alag alag hain) |
| Printer poora width use nahi kar raha | Receipt-settings mein paper size check karein, phir printer driver |
| Receipt ke aage/peeche khali kaghaz | Windows printer Preferences mein paper size 80mm Receipt set karein |
| KOT kitchen par nahi aa raha | Kitchen-settings par counter/printer check karein; Desktop Agent chalta ho |
| KOT "sent" lekin print nahi hota | **KDS Auto-Print** check karein — ON ho to KOT sirf KDS device se print hota hai; KDS nahi chalate to OFF karein |
| KOT adhoora aata hai | Kitchen-settings par **"Always Print Full KOT"** ON karein |
| Silent print nahi ho rahi | Printer-settings par Silent ON, printer select, Agent chalta ho; setting badal kar screen refresh |
| Hold nahi ho raha | Hold sirf Dine-In ke liye; manual items aur deals hold nahi hote |
| Discount nahi lag raha | Cashier ki limit lagi hai — Manager PIN se override ya admin limit badhaye |
| Bill edit nahi ho raha | Sarkari number lag chuke bills edit/delete nahi hote (qanooni pabandi); sirf local/provisional edit hote hain |
| Bills ki limit khatam | Package upgrade karein ya filhal provisional banayen |
| Team member add nahi ho raha | Account limit poori — upgrade karein |
| Restaurant features nazar nahi aate | Pro/Unlimited chahiye, phir /pos/features se ON |
| Opening Cash nazar nahi aa raha | Din pehle hi close ho chuka — kal subah enter karein |
| Deal ki price ghalat | Deal ke din/dates check karein — deal sirf apne set dino par chalti hai |
| Screen chhoti/bari lag rahi hai | Sale screen ke "Fit" se size adjust karein |

Kisi bhi aur masle ke liye **Madadgar** se poochein ya WhatsApp support par rabta karein.

---

*— NestPOS Team*
