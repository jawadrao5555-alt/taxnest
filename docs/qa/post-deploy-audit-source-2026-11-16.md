Audit 16 November 2026 ko live admin, Manage as Company, public website aur deployed commit ke source par kiya gaya. School ki alag workspace ab maujood hai, lekin commercial rollout aur franchise demonstrations se pehle neeche diye functional, security aur wording gaps address karne chahiye.

Production commit: `cccb857ab71d0f9814b1b1bae5dc5cb29c04e207`. [Successful production deployment](https://github.com/jawadrao5555-alt/taxnest/actions/runs/43795056772). Yeh findings PR #393 ke baad ki live state ki hain; #394 ka unmerged code live assume nahi kiya.

Koi live bill, payment, closing, setting, category, record deletion, fiscal submission, merge ya deploy nahi kiya. Manage as Company sirf inspection ke liye use hua. Customer amounts, personal details aur credentials is report mein reproduce nahi kiye.

# Sab se pehle kya theek karna hai

| Priority | Finding | Agla kaam |
|---|---|---|
| Franchise launch se pehle | Application GitHub repository **public** hai. | Private application repository ka plan, public release/download dependencies ka inventory, separate artifact distribution aur secret-history scan. Visibility is audit mein change nahi ki. |
| High | Franchise login ke inspected application path mein rate limiting aur existing two-step system ka coverage nahi. | Throttle, two-step verification, session revocation aur role-level negative tests. Edge/WAF protection alag verify ho. |
| High | School Bank/Card recording mein payment reference field nahi; fee heads mein default rates nahi. | References, fee rates aur receipts/history mein consistent representation. |
| High | Public FBR landing har package mein live fiscal number/QR ka wada karti hai; School/Hospital OFF hain. | Product/category-specific promises, pricing text aur printing claims correct karo. |
| High | PRA Features screen par reproducible JavaScript dependency-label error. | Callback binding fix, no-console-error browser regression aur purchased extras preserve. |
| Medium | School dashboard, setup, history aur admin usage abhi incomplete/confusing hain. | Local collections, due balance, campus context, clear menus aur reconciled reports. |
| Medium | Videos aur demo experience naye School/Hospital workflows ko represent nahi karte. | Category demos aur versioned, reviewed tutorials ka separate completion plan. |

# Live aur code ki coverage

| Area | Is audit mein kya verify hua | Kya abhi pass nahi keh sakte |
|---|---|---|
| Ali Garh School 58 | Manage as Company, School dashboard, fee form, settings, existing receipt/history, team, printing preview, billing, day-close screen | Nayi payment, lost-response/concurrency ka live test, closing, real printing aur mobile acceptance |
| Hospital standalone | Deployed source mein patient/service billing, partial payments, references, history, receipts, dashboard aur fiscal guards | Hospital-category ka verified live account identify nahi hua; runtime acceptance pending |
| PRA QA company | Category read-only, category features, purchased extras, Customize POS, Tax Integrations | Actual payment/fiscal/physical print; Features page mein error mila |
| FBR retail QA company | Retail workspace, category profile, preserved Pharmacy extra, Customize POS | Live payment/fiscal transmission aur device acceptance |
| DI | Established company dashboard aur owner-only Madadgar recorded-totals screen; incomplete-onboarding account ka redirect | Real invoice/import/packing/payment flow ki fresh end-to-end acceptance |
| Admin | Dashboard, company detail/usage, plans, franchise/distributor, expenses, bin, diagnostics discovery, read-only reporting setup | Financial writes, restore/permanent delete, external read-only client connection |
| Website | FBR landing, signup, product guide, tutorials inventory/sample, downloads | Har video ki poori narration/frame review; actual installer signature/device test |

# School standalone ki findings

| Page | Live observation | Required improvement |
|---|---|---|
| School Dashboard | School-specific shell aur 3 cards: cash collections, receipt count, day-close state. | Total collections, bank/card breakdown, outstanding aur named active campus do. Cash ko total collection na samjha jaye. |
| Dashboard and settings | Setup checklist nazar nahi aayi; important links “School workspace and settings” ke andar hain. | Guided setup: identity, campus/team, fee heads/rates, printer preview; clear persistent navigation. |
| Fee heads and campuses | Tuition/Admission/Exam ke label aur active state hain; rate input nahi. | Default rate, optional period/campus applicability aur future-only effective changes. Existing receipts unchanged. |
| Collect Fee | Student reference/name lookup, fee month, multi-head lines, payable/paid/balance aur partial payment controls hain. | Multi-head mode mein single-head control bhi reh jata hai; form ko ek clear mode do. |
| Collect Fee payment | Cash/Card/Bank choices hain; Bank select karne par reference input nahi. School controller mein bhi reference contract nahi mila. | Payment reference record, validation, display aur changed-data retry policy define karo. |
| Student accounts | Source query latest 30 matching accounts deti hai, pagination nahi. Reference suggestions bhi bounded hain. | Search plus pagination; purane outstanding accounts list se chhup na jayen. Yeh record deletion ka finding nahi. |
| Receipts / Reprint | Existing saved receipt open aur reference search kaam karta hai. Date, search aur fee-month controls hain. | Date range, payment method, fee head aur cashier filters; export agar agreed scope mein ho. |
| Receipts page | Permanent day-close warning receipt history par bhi prominent hai. | Closing warning ko close action ke paas rakho; receipt history par relevant instructions. |
| Team and campus access | Roles aur campus controls hain, lekin “Main shop” wording dikh rahi hai. Koi campus configured nahi. | “Main campus” ya explicit unassigned-campus wording; historical scope preserve. |
| Profile and receipt printing | School identity/contact/logo, paper width, preview aur preferences available; receipt context/head/period/method/campus/cashier visible. | Actual device/printer acceptance baqi. Current School flow browser print hai; silent Agent printing assume na ho. |
| Cash Day Close | Counted cash, variance reason, handover, confirmation aur immutable/no-reopen notice hain. | Cash versus non-cash clarity aur contextual guidance. Koi closing execute nahi ki. |
| Billing and Help | School billing page opens; Help leads to suggestion box. | Plans par School-relevant limits/features; actual help guide/tutorials/support entry. |

Normal School flow mein retail cart, kitchen aur rider main navigation nahi mili. School workspace basic/plain hai aur repeated fiscal/technical notices zyada jagah lete hain. Old retail archive ab bhi secondary menu mein hai. Is audit mein us invoice ya archive ko delete nahi kiya.

Code evidence: [School controller](https://github.com/jawadrao5555-alt/taxnest/blob/cccb857ab71d0f9814b1b1bae5dc5cb29c04e207/app/Http/Controllers/FbrPosSchoolFeeController.php), [fee settings](https://github.com/jawadrao5555-alt/taxnest/blob/cccb857ab71d0f9814b1b1bae5dc5cb29c04e207/resources/views/fbr-pos/school-fees/settings.blade.php), [dashboard](https://github.com/jawadrao5555-alt/taxnest/blob/cccb857ab71d0f9814b1b1bae5dc5cb29c04e207/resources/views/fbr-pos/school-fees/dashboard.blade.php), [layout](https://github.com/jawadrao5555-alt/taxnest/blob/cccb857ab71d0f9814b1b1bae5dc5cb29c04e207/resources/views/fbr-pos/school-fees/layout.blade.php).

# School screen ka actual screenshot

Fee-head settings mein label/status controls hain, rate control nahi; campus setup abhi empty hai. Yeh screenshot amounts, student details ya credentials expose nahi karta.

![Ali Garh School fee heads and campus settings](library-file:libfile_fde3_f-8d5e867f32588191ad665b1a3b16bda0_7ff47c16735081919b6ac31ceef2aca3#display=full)

# Hospital ki position

Deployed source mein patient reference/name, service catalogue/default rates, multiple services, partial payments, bank/card reference, HI-number/date/status history, paid/balance, receipts aur closing components maujood hain. Hospital dashboard cash/card/bank ko alag represent karta hai.

Active company inventory inspect hui, lekin Hospital-category ka verified live account identify nahi hua. Is liye Hospital ko live accepted ya complete certify nahi kiya. Next verification isolated synthetic Hospital account/staging mein honi chahiye. Full clinical HMIS, lab, admissions aur School ERP ko standalone POS completion mein count na kiya jaye.

Code evidence: [Hospital form](https://github.com/jawadrao5555-alt/taxnest/blob/cccb857ab71d0f9814b1b1bae5dc5cb29c04e207/resources/views/fbr-pos/hospital/index.blade.php), [settings](https://github.com/jawadrao5555-alt/taxnest/blob/cccb857ab71d0f9814b1b1bae5dc5cb29c04e207/resources/views/fbr-pos/hospital/settings.blade.php), [dashboard](https://github.com/jawadrao5555-alt/taxnest/blob/cccb857ab71d0f9814b1b1bae5dc5cb29c04e207/resources/views/fbr-pos/hospital/dashboard.blade.php), [history](https://github.com/jawadrao5555-alt/taxnest/blob/cccb857ab71d0f9814b1b1bae5dc5cb29c04e207/resources/views/fbr-pos/hospital/history.blade.php).

# PRA FBR retail aur DI

**PRA category lock sahi direction mein hai.** Customize POS par category switch nahi mila; Features par Restaurant read-only hai. “Added for you” extras alag label se dikh rahe hain. QA account ke Salon/Workshop, Barcode ya Prescription extras ko sirf category mismatch samajh kar remove nahi karna; purchased/grandfathered rights preserve hone chahiye.

**Reproduced PRA error:** Features screen ke dependency badges par `Cannot read properties of undefined` for kitchen/inventory/customer_profile. Deployed template mein `flagDeps(f).map(flagLabel)` hai aur `flagLabel` `this.flagMeta` use karta hai. Callback context lose hone ka code path observed error se match karta hai. [Source lines 202 and 232](https://github.com/jawadrao5555-alt/taxnest/blob/cccb857ab71d0f9814b1b1bae5dc5cb29c04e207/resources/views/pos/feature-settings.blade.php#L202). Fix ko fresh browser run se verify karna hoga.

PRA Customize text tax ko hamesha PRA tak pohanchne ka impression deta hai, jabke inspected QA integration OFF/sandbox thi. Local numbering copy daily reset aur never-reuse ke bare mein inconsistent hai. Yeh wording gap hai; duplicate-number bug prove nahi hua.

PRA aur FBR Customize Caller ID text “Play Protect block nahi hoga” ka blanket claim karta hai. Downloads page blockage par support ka kehta hai. Actual signed-build/device evidence ke mutabiq wording consistent honi chahiye.

FBR retail QA workspace retail profile par khuli aur Pharmacy extra alag preserved tha. Tax Integrations mein PRA, FBR POS aur DI cards alag hain; FBR POS enable karne par new DI dispatch band hone aur history preserve rehne ki explanation hai.

DI established account mein owner-only **Madadgar recorded totals** screen khuli. Incomplete-onboarding account mein same entry onboarding par laut aayi; isko universal failure nahi kehna. Fresh DI stock/import/packing ya payment execute nahi ki.

# Admin packages aur lifecycle

| Area | Finding | Action |
|---|---|---|
| School company detail | Generic Usage and Revenue retail figures aur separate School local collection report ka meaning clearly aligned nahi. | Local fees, old retail, fiscal submissions aur platform subscription money ko distinctly label aur reconcile karo. |
| Company controls | School account par generic POS production/POS ID/Agent setup controls ab bhi nazar aate hain. | Category-aware controls; blocked fiscal setup ko clear status ke saath rakho. Credentials reveal nahi kiye. |
| Company Usage | School/Hospital billed principal, received payments, outstanding aur saved-close visibility exists. Numeric company/campus ID fields aur many irrelevant columns UX ko mushkil banate hain. | Searchable selectors, category filters, reconciled drill-down, unknown ko zero se alag. |
| FBR plan admin | Numeric Starter allowance 2,000 bills/unlimited products, stored text 500 bills/100 products. Business numeric unlimited, text 2,000 bills/500 products. | Stored copy, actual entitlements, signup, checkout aur marketing ek contract se render hon. Existing purchased rights change na hon. |
| Signup | School/Hospital selection aur hydrated School local/OFF notice present. 72-hour versioned terms visible. | Is notice ko missing na samjho. Legacy trial terms aur clone-flow copy ko policy version ke mutabiq disclose karo. |
| Expenses | Record-only admin ledger aur full-reversal flow visible. | Payment execution assume na ho. Koi entry/reversal nahi banayi. |
| Bin | Binned duplicate/product record aur retention hold visible. | Restore/delete lifecycle staging par prove karo; live delete/restore test nahi hua. |
| Diagnostics | Live Ops entry PRA-focused hai; agent offline warnings visible. | School/Hospital/DI discovery aur health scope clear; offline agent ko working print proof na samjho. |
| Read-only reports | Connection/OAuth setup page available, approved connection configured nahi. | Feature code aur connected reporting client ki readiness alag report karo. |

Admin financial quantities is report mein reproduce nahi ki gayin. [Local collections reporting contract](https://github.com/jawadrao5555-alt/taxnest/blob/cccb857ab71d0f9814b1b1bae5dc5cb29c04e207/docs/qa/local-collections-admin-reporting.md) aur [23-source register](https://github.com/jawadrao5555-alt/taxnest/blob/cccb857ab71d0f9814b1b1bae5dc5cb29c04e207/docs/qa/consolidated-23-source-register-2026-11-15.md) ko follow-up acceptance se reconcile karna hoga; 23 programmes ko complete declare nahi kiya.

# Franchise distribution aur code protection

**Application repository public hai.** GitHub metadata `private:false` / `visibility:public` report karta hai; Laravel/controllers/views aur Agent source accessible hain. Is waqt code confidentiality ka sab se seedha issue yeh hai. Kisi ne code copy kiya hai ya secrets leak hue hain, yeh audit ne establish nahi kiya.

Recommended sequence: application repository/private access review; public CI/release/download dependencies identify; source se separate public binary/artifact delivery; current tree aur git history secret scan. Private karne se pehle download/update routes break na hon. Already-public copies retract hone ki guarantee nahi. Is audit ne visibility ya permissions change nahi ki.

Live admin mein **Franchises** aur **Distributors** alag modules hain. Distributors page admin-managed referrals ke liye hai, portal ya company-data access ke liye nahi. Franchise portal assigned companies, revenue/commission aur review request ka limited flow hai; admin final package/payment activation karta hai.

Source mein company queries franchise ID se scoped aur commission rules subscription package snapshot se tied hain; additions/trials/shop sales se commission nahi banana chahiye. Relevant negative tests repository mein hain, is audit mein unko rerun ya franchise-role session se exercise nahi kiya.

**Login hardening gap:** inspected franchise POST login route/controller mein application-level throttle/RateLimiter nahi mila. Shared two-step panel list mein franchise included nahi. Middleware active-franchise status check karta hai. Edge/WAF controls verify nahi hue. Add rate limit, two-step coverage, password update validation, session revocation, cross-franchise ID denial aur audit trail tests.

Agent package proprietary marked hai lekin Electron distribution mein JS files package hoti hain; inspected Windows build config mein `signExecutable:false` hai. Actual released executable ki signature/hash/reputation is audit mein check nahi hui. Code hiding ko licensing, server-side authorization aur limited access ka substitute na banaya jaye.

Sources: [franchise contract](https://github.com/jawadrao5555-alt/taxnest/blob/cccb857ab71d0f9814b1b1bae5dc5cb29c04e207/docs/ops/franchise-program.md), [login](https://github.com/jawadrao5555-alt/taxnest/blob/cccb857ab71d0f9814b1b1bae5dc5cb29c04e207/app/Http/Controllers/Franchise/FranchiseAuthController.php), [two-step panels](https://github.com/jawadrao5555-alt/taxnest/blob/cccb857ab71d0f9814b1b1bae5dc5cb29c04e207/app/Services/TwoStepLogin.php#L12), [scope middleware](https://github.com/jawadrao5555-alt/taxnest/blob/cccb857ab71d0f9814b1b1bae5dc5cb29c04e207/app/Http/Middleware/FranchiseAuth.php), [commission service](https://github.com/jawadrao5555-alt/taxnest/blob/cccb857ab71d0f9814b1b1bae5dc5cb29c04e207/app/Services/FranchiseCommissionService.php), [Agent package](https://github.com/jawadrao5555-alt/taxnest/blob/cccb857ab71d0f9814b1b1bae5dc5cb29c04e207/pra-agent/package.json).

# Website videos aur demo accounts

**Public promise mismatch:** [FBR landing](https://taxnest.pk/fbr-pos-landing) hero Tier-1 retailers ke liye likha hai. “Included in every package” ke neeche live FBR submission, fiscal number/QR on every receipt, inventory/riders/attendance aur silent printing universal dikhte hain. Same page ki School/Hospital OFF caveat is broad promise ko resolve nahi karti. Category-specific cards aur eligible/verified reporting status chahiye.

[Product guide](https://taxnest.pk/product-guide) FBR ko goods retail workflow se explain karta hai; School/Hospital standalone workflow absent hai. Healthcare ERP guide configured clinical/lab/admission workflows describe karta hai; standalone Hospital billing aur full clinical ERP ki delivered capability ko separately demonstrate aur label karna hoga.

Tutorial inventory: **43 records, 42 published flags, 27 public-visible flags**. Public catalogue mein 26 PRA/NestPOS aur 1 FBR introduction mila. Inspected records mein caption path, release version aur reviewed-on metadata empty; category options mein School/Hospital nahi. Dedicated School/Hospital tutorials nahi mile.

Package/Billing ke 49-second video ka playback aur ek old-interface frame sample hua. **Tamam 43 videos ki complete narration/frame review nahi hui**; sab ko obsolete kehna durust nahi. Priority review/reshoot: registration/category/package, School fee and receipt, Hospital service/partial payment, day-close, printing/Agent, fiscal status, distributor/franchise demos. Har clip ke liye current release, audience, reviewed date, captions/transcript aur screen timestamps record hon.

Synthetic recording workflow ka hona published, narrated, reviewed tutorial ka proof nahi. [Recording workflow notes](https://github.com/jawadrao5555-alt/taxnest/blob/cccb857ab71d0f9814b1b1bae5dc5cb29c04e207/docs/ops/synthetic-tutorial-recording.md).

Public guide “Request a demo” ko contact page par bhejta hai; category-specific interactive demo chooser observed public pages par nahi mila. Local-only `/demo-login/{role}` development shortcut ko production demo login na banaya jaye. Isolated synthetic product/category demos, limited roles, safe reset, expiry, no fiscal/payment/messaging dispatch aur no live customer data chahiye. Existing test companies production demo-isolation proof nahi.

# Recommended School flow

**Dashboard → Students and balances → Collect Fee → Receipt/Reprint → History → Day Close.**

Settings mein **Fee heads and rates, Campus and team, School identity and printing, Package and billing, Help** hon. Active campus aur Exit Manage as Company hamesha clear rahein. Bank/card ko “record payment” ke taur par label karo; transaction reference aur method receipt/history mein consistent ho. Compact fiscal-OFF badge rakho, detailed explanation Help/Tax status mein.

Hospital ke liye isi tarah **Dashboard → Patients → Services and rates → Create Bill → Record Payment → Receipt/History → Day Close**, clinical modules ka promise kiye baghair.

Retail/PRA existing routes aur purchased extras ko preserve karte hue category-specific navigation aur wording build ho. Old retail archive general School workflow ka central feature na bane; data disposal alag scoped task ho.

# Dot ko dene ke liye command

PR #393 ke deployed code ko base bana kar is audit ke reproducible gaps ka bounded follow-up draft PR tayyar karo. #394 ki real fiscal activation ko separate hi rakho. Pehle latest main aur deployed SHA compare karo; jo fix already aa chuki ho dobara mat banao.

1. School fee-head rates, bank/card reference contract, multi-head form ambiguity, account pagination/search, dashboard totals/outstanding/campus, history filters, guided setup, Help aur Main shop wording complete karo. Existing receipts, balances, retry keys aur closing snapshots preserve hon.

2. PRA Features ka callback/context error reproduce aur fix karo. Category company-side read-only rahe; authorized SaaS admin hi valid category/product changes kare. Purchased/grandfathered extras remove na hon.

3. Admin local-collection versus retail/fiscal/subscription figures reconcile aur label karo. Searchable company/campus selectors aur category-aware controls do. Actual package limits, stored feature copy, registration, checkout aur public pages consistent karo; existing entitlements aur policy versions preserve.

4. Public FBR/ERP promises category-wise actual capability se match karo. School/Hospital default fiscal OFF rahein; OFF receipts par fiscal number/QR na fabricate ho. #394 official route/sandbox acceptance ke baghair reporting ON nahi.

5. Franchise login throttle, two-step/session controls aur scoped access ki code gaps fix/test karo. Repository visibility change ke liye source/download/CI dependencies aur exact owner action ki separate reviewable recommendation do; visibility khud change na karo.

6. Isolated synthetic Hospital aur franchise demo sessions se role/campus/company isolation verify karo. Demo architecture aur tutorial inventory ke liye explicit delivered-versus-next-scope list do; full ERP/HMIS ko complete claim na karo.

7. Changed flows ke desktop/mobile screenshots, no-console-error checks, native MariaDB concurrency/preservation, duplicate retry, changed-data denial, method-reference integrity, closing immutability aur PRA/retail/DI regressions final exact HEAD par verify karo. Bin/restore/delete sirf staging mein.

8. Har finding ke liye fixed/already-delivered/pending/external-blocker aur exact evidence do. Required checks aur visual acceptance complete hone tak draft rakho. Koi live bill/payment/setting/deletion, merge, relay ya deploy mat karo.

Videos ke full narration/frame audit, recordings/captions, real device acceptance, backup restore, CCI import aur full ERP ko is bounded fixes PR mein silently completed mat count karo.

# Verification ki boundaries

Mobile viewport override expected dimensions par apply nahi hua; actual mobile live layout pass nahi kaha ja sakta. Saved School settings screenshot desktop evidence hai. Later public-page screenshot capture reliable nahi tha, is liye usko evidence image ke taur par include nahi kiya.

Role-specific Hospital/franchise login, real cash/payment retry, printer/device, actual backup restore, external fiscal sandbox, installer signature aur full secret-history scan pending hain. Read-only audit se in actions ki success infer nahi ki.

Live forms/settings save nahi kiye. Last browser state mein admin panel Plans page par open tha; automatic return-to-dashboard navigation timeout hui. Additional receipt preview aur public guide tabs open reh sakte hain.
