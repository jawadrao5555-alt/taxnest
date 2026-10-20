# Synthetic tutorial review recordings

These recordings are raw screen captures, not narrated or published tutorials.
They contain only reserved synthetic accounts and disposable company data.
No production login, customer records, provider calls or publishing keys are
used. Desktop 1366px and mobile 390px are recorded for each selected journey.

After merge, owner runs **Record synthetic POS tutorials** on `main` with the
exact main SHA. The workflow provisions the existing isolated MariaDB/Chrome
fixture and records Top Selling, WhatsApp optional auto-send, Rider map and
PRA notifications. Every workflow assertion must pass before the review
artifact is uploaded. The recording manifest lists source SHA, hashes, sizes
and viewport, marked `OWNER_REVIEW_REQUIRED`. Failed flows are not publishable
success videos. Regular PR CI produces a two-viewport Top Selling preview to
test this path; routine full acceptance remains unrecorded.

Review the video and pair it with the following narration/captions. Trim idle
setup/login segments before distribution. Do not represent synthetic GPS or
fake-provider acceptance as real phone tracking or WhatsApp delivery. Owner
approval and a separate publishing operation are required; no tutorial DB or
public video file is changed by the recording workflow.

## Roman Urdu narration outline

### Top Selling
“Dashboard par Top Selling mein aaj ke business day ki pehli items nazar aati
hain. View All par poori list kholain. Date range choose karein, phir item par
click karke uske orders aur quantity dekhein. Yeh item subtotal hai; bill-level
discount aur tax ke baad ka net collection nahi.”

### WhatsApp optional auto-send
“WhatsApp Bill aapke package mein available ho to apne Meta credentials aur
approved English template configure karein. Auto-send aapki marzi se ON ya OFF
hai. Naye final bill par valid customer number zaroori hai. Sent ka matlab
provider acceptance hai; delivered/read callback se delivery ki tasdeeq hoti
hai. Is demo mein real customer ko message nahi bheja gaya.”

### Rider map
“Rider Live Tracking package eligibility aur rider phone permissions ke saath
kaam karti hai. Map par last captured location aur uski age dekhein. Offline ya
background restrictions ki wajah se purani pin ko current position na samjhein.
Yahan synthetic location demo hai; real phone testing alag acceptance hai.”

### Notifications
“Apne system aur business category ki notification kholain. Successful read
save hone par NEW aur unread count update hote hain. Network error aaye to
retry karein; bina save ke notification read nahi mani jati.”

## Evidence and remaining work

Source path/opt-in/symlink unit checks and JS/shell/YAML syntax pass locally.
PHP/Chrome unavailable locally; PR CI must produce a real preview and pass all
desktop/mobile journeys. Recordings still require editorial review, audio,
captions and public publishing approval. Actual videos are not claimed ready
merely because this workflow exists.
