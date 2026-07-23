---
name: POS feature upgrade backlog
description: Owner-deferred NestPOS PRA upgrade ideas (22 Jul 2026) + split-payment tax design options already discussed
---

# NestPOS PRA upgrade backlog (owner: "baad mein dekhenge", 22 Jul 2026)

Owner reviewed this list and deferred ALL of it. Do not build any item until he explicitly picks one.

1. Udhaar / Customer Khata — credit bills, ledger, wasooli record (agent's top pick)
2. Split Payment — one bill, part cash + part card (tax design below)
3. Return / Refund flow
4. WhatsApp Receipt to customer
5. Day-close summary to owner via WhatsApp/SMS
6. Kharcha (expenses) tracking → real profit on day-close
7. Low-stock alert + supplier/purchase module
8. Cashier shift system (shift-wise cash recon)
9. Barcode label printing
10. Loyalty points

Also pending idea: FBR POS has NO What's New popup/bell system (audience hardcoded 'pos', popup only in pos-app layout) — potential port.

## NestPOS Desktop app — "Rasta B" (owner NOTED it, 23 Jul 2026: "jab kaho isi pe kaam start karenge" — do NOT build until he says start)
Grow the existing Electron Desktop Sync Agent into a full "NestPOS Desktop" app:
- ONE installer = POS screen (loads live web app — features deploy server-side, shell rarely updates) + sync agent + silent printing.
- Kiosk mode: launch on boot, full-screen sale screen, no browser chrome.
- Printing: in-app direct to printer — works identically online AND offline (owner specifically asked; answer: online mode printing keeps working, better than browser+agent 3-hop chain). Kitchen-settings KOT routing unchanged.
- Offline: bills queue locally, auto-submit to PRA on reconnect (existing queue/retry infra).
- Auto-update via existing GitHub Releases heartbeat zip-swap (v1.3.0+ mechanism); existing agent installs can upgrade in-place into the desktop app.
- Admin visibility via existing heartbeat (version/online/prints).
- ~60-70% of the foundation (Electron, auto-update, printing, PRA queue, heartbeat) already live.
Related ranked advice given (23 Jul): #1 bot upgrade (live-data answers, voice, WhatsApp Madadgar, screen-aware), #2 Play Store wrapper (TWA/Capacitor), #3 this desktop shell. Owner picked nothing yet.

## Split-payment tax design (discussed, NOT decided)
PRA constraint: tax rate depends on payment method (cash 16% / card 8%) and PRA payload carries ONE payModeCode per invoice — a mixed bill can't be reported as mixed.
- Option 1 (agent recommended, safest): 8% only if 100% card/digital; any cash → whole bill 16%, report cash. Receipt still shows cash/card breakdown.
- Option 2: dominant method decides whole-bill rate (loophole: 51% card halves tax).
- Option 3: auto-split into two bills (cash bill 16% + card bill 8%), each reported separately; 2 receipts, items can't be half-split.
Owner deferred the choice — re-present these options when he returns to split payment.
