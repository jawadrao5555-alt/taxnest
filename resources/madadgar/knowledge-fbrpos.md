# Nest FBR POS — Madadgar Knowledge Base
Yeh Nest FBR POS (FBR-integrated Point of Sale) ka guide hai. Sirf is guide ki maloomat se jawab do. Jawab hamesha step-by-step aur asaan Roman Urdu mein do.

## Login aur Account
- FBR POS login: /fbr-pos/login — Email, Phone, Username, CNIC ya NTN se login ho sakta hai (CNIC/NTN se company ka admin login hota hai).
- Password bhool jayein: login page par "Forgot Password" → email par OTP aata hai → naya password set karein.
- 5 ghalat koshishon par login thori der ke liye lock ho jata hai — kuch minute intezar kar ke dobara koshish karein.
- FBR POS ka user sirf /fbr-pos/login par login kar sakta hai — kisi aur panel (NestPOS ya Digital Invoice) par "Invalid credentials" aayega. Yeh security ke liye hai.
- Har company ka data bilkul alag (isolated) hai — koi doosri dukan ka data nahi dekh sakta.
- Apna profile badalna: /fbr-pos/my-profile — Full Name, Email, Phone, Username, aur password change (Current Password + New Password + Confirm).

## FBR Integration — POS ID, Token, Sandbox/Production (/fbr-pos/settings)
- Nest FBR POS har FINAL bill FBR ke IMS (Integrated Management System, SRO 1279) ko report karta hai — receipt par FBR ka fiscal invoice number aur QR code aata hai.
- FBR Settings sirf admin khol sakta hai: /fbr-pos/settings.
- POS Registration ID aur Token FBR se milte hain jab aap apna POS FBR ke saath register karte hain. Yeh dono settings page par dalne hote hain.
- Environment do hain: SANDBOX (testing — bills asli report NAHI hote) aur PRODUCTION (asli reporting). Dukan chalani ho to Production chunein; sandbox sirf test ke liye hai.
- Connection Mode do hain: "Cloud API" (purane registered POS IDs ke liye) aur "Fiscal Device / Local Service" — NAYE FBR POS registrations ke liye Fiscal Device mode zaroori hai (FBR ka local IMS service usi computer par chalta hai, is ke liye Desktop Agent install hota hai — download /fbr-pos/settings ke Agent section se).
- Settings save karne ke baad "Test Connection" button se check karein ke FBR se rabta theek hai.
- FBR Reporting ka toggle bhi settings mein hai — OFF ho to bills FBR ko report nahi hote (sirf local record). Qanooni taqaza poora karne ke liye ON rakhein.
- "Resource forbidden" ya token error aaye to matlab token/environment ka mel nahi — check karein ke Production token Production environment ke saath hai aur POS ID wohi hai jo FBR ne di.

## Fail Queue — Jo Bills FBR ko Report Nahi Huay (/fbr-pos/fail-queue)
- Agar internet ya FBR ka system down ho to bill save ho jata hai magar FBR ko report nahi ho pata — aise bills FAIL QUEUE mein aate hain: /fbr-pos/fail-queue.
- Dashboard/top bar par fail queue ka badge dikhata hai ke kitne bills pending/failed hain.
- Fail queue par har bill ke saamne "Retry" button hai; sab ek saath dobara bhejne ke liye "Retry All" dabayen.
- CONFIG ERROR wale bills ka matlab FBR settings mein masla hai (ghalat POS ID/token/environment) — pehle /fbr-pos/settings par "Fix Settings" kar ke Test Connection karein, phir retry.
- Agar kisi bill ke data mein masla ho (misal: ghalat HS code) to us bill ke saamne "Edit & Retry" se data theek kar ke dobara bhej sakte hain.
- Internet wapas aane par system khud bhi retry karta hai — magar fail queue roz check karna achhi aadat hai take koi bill report hue baghair na reh jaye.

## Tax Asaan Verification
- Har FBR-reported receipt par FBR ka invoice number aur QR code hota hai.
- Customer FBR ki "Tax Asaan" mobile app se QR scan kar ke (ya invoice number dal kar) verify kar sakta hai ke bill FBR ko report hua hai.
- Verify na ho to pehle check karein ke bill ka FBR status "Submitted" hai (/fbr-pos/transactions par) — pending/failed bill Tax Asaan par nahi milega; fail queue se retry karein.
- Sandbox environment ke bills Tax Asaan par kabhi nahi milte — asli verification sirf Production mein hoti hai.

## Dashboard (/fbr-pos/dashboard)
- "Aaj" ke figures Business Day ke hisaab se hain: raat 12 ke baad (subah 6 tak, jab tak din close na ho) ki sales pichle din mein ginti hain.
- Dashboard par aaj ki sales, bills, tax, payment breakdown aur recent bills hote hain; fail queue ka alert bhi yahan dikhta hai.
- Cashier ko sirf apne stats nazar aate hain; admin/manager ko poori company ke.

## Sale Screen — Nayi Bill Banana (/fbr-pos/create)
- Items dalein — 3 tareeqay: (a) search box mein naam/barcode/SKU type kar ke Enter, (b) product grid par item click, (c) barcode scanner se scan — exact match foran cart mein chala jata hai.
- Customer (optional): customer box mein phone/naam type karein → select karein; match na mile to "Add as New" se foran naya customer ban jata hai. Walk-in ke liye khali chhor dein.
- Quantity: cart row ke qty box par click kar ke number type karein, ya same item dobara add karein.
- Discount BILL level par lagta hai — cart ke neeche % Discount; limit se zyada discount par Manager PIN chahiye hota hai.
- Payment: Cash ya Card chun kar bill FINAL karein — final hote hi bill FBR ko report hota hai aur receipt par FBR number + QR aata hai.
- Provisional bill: "Save Provisional" — bill save hota hai magar FBR ko report NAHI hota. Baad mein provisional list (F10) ya /fbr-pos/transactions se "Make Final" (promote) kar sakte hain — tabhi FBR ko jata hai.
- Offline mode: internet chala jaye to bill queue mein save hota hai aur net aane par khud FBR ko chala jata hai — double report nahi hota.
- Barcode scanner ke liye alag setup nahi chahiye — koi bhi USB/Bluetooth scanner jo keyboard ki tarah type karta hai seedha chal jata hai.

## Receipts aur Printing
- Receipt settings: /fbr-pos/receipt-settings — dukan ka naam/logo, paper size (80mm ya 58mm), footer note waghera.
- Printer lagana: printer PC se connect kar ke driver install karein, browser ke print dialog mein printer select karein, aur receipt-settings par paper size set karein. Har aam thermal printer chalta hai.
- Silent printing (bina print dialog ke): Desktop Agent install karein aur printer settings mein Silent Printing ON kar ke Bill/KOT printers chunein. Setting badalne ke baad sale screen refresh (F5) karein.
- Purani receipt dobara print: /fbr-pos/transactions par bill khol kar Receipt/PDF.

## Day Close (/fbr-pos/day-close)
- Din band karne ke liye /fbr-pos/day-close kholein — din ka khulasa (sales, tax, payment breakdown) nazar aayega, cash gin kar reconcile karein aur "Close Day" dabayen.
- Purani closes isi page par milti hain — PDF (A4) aur Thermal print dono hain.
- Auto Day-Close (24h) ka option bhi hai — ON ho to din khud band ho jata hai; warna roz khud close karna parta hai.
- Din close karne se pehle fail queue check kar lein — behtar hai ke sab bills FBR ko report ho chuke hon.

## {items}, Stock aur Customers
- {items}: /fbr-pos/products — naya {item}, price, barcode/SKU, tax settings. Excel import/template bhi hai (/fbr-pos/products/import).
- Stock/Inventory: /fbr-pos/stock — purchase entry, suppliers, corrections, movements, minimum level alerts. Inventory tracking ON ho to bill banate waqt stock khud katta hai.
- Customers: /fbr-pos/customers — customer list, history, export.
- Khata (udhaar): /fbr-pos/khata — customer ka udhaar ledger aur wasooli. Khata sirf admin/manager dekh sakta hai.
- Munafa (profit): /fbr-pos/munafa — cost price set ho to item-wise munafa report.

## Reports
- Sales reports: /fbr-pos/reports — date range, CSV export, analytics PDF.
- Tax reports (FBR): /fbr-pos/tax-reports — FBR-reported bills ka tax khulasa, CSV/PDF export.
- Hazri (attendance): /fbr-pos/reports/hazri — staff ke login/kaam ke ghantay (sirf admin).

## Team (/fbr-pos/team)
- Company admin team members banata hai: Manager (sab kuch except kuch admin cheezein), Cashier (sirf billing), waghera.
- Member ko OFF (deactivate) karna ho to team page par toggle hai.
- Team members ke passwords admin team page par dekh sakta hai.

## Billing aur Package (/fbr-pos/billing)
- Apna package, mahana bill limit aur expiry /fbr-pos/billing par nazar aati hai.
- Mahana bill quota mein provisional bills bhi ginte hain (FBR POS mein).
- Package upgrade ya renewal ke liye billing page se payment proof upload hota hai — ya TaxNest team se WhatsApp par rabta karein.

## Mobile App aur Notifications
- Nest FBR POS ki mobile app (Android) se bhi panel chalta hai — wohi login.
- App notifications bhej sakti hai — misal: fail queue mein bills jama hone ka alert aur day close ki yaad-dihani (admin/manager ko).

## Tutorials aur Madad
- Video tutorials: /fbr-pos/tutorials.
- Mazeed madad ke liye Madadgar chat ya WhatsApp support istemal karein.
- Koi bug/kharabi ya naye feature ki demand ho to Madadgar mein batayen — team tak pohncha di jayegi. Admin/manager /fbr-pos/suggestions par bhi tajweez bhej sakte hain.
