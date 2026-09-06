#!/usr/bin/env python3
"""Build the Roman Urdu ASS caption track for the FBR POS pharmacy-mode tutorial.

Timing is derived from timeline.json (scene audio offsets) + tts/durations.cjson
(real narration length), so the captions never drift from the voice track.
"""
import json
import os

HERE = os.path.dirname(os.path.abspath(__file__))   # tools/video-pipeline/scenarios
OUT = os.path.abspath(os.path.join(HERE, '..', 'out', 'fbr-pharmacy'))

timeline = json.load(open(os.path.join(OUT, 'timeline.json')))
durations = json.load(open(os.path.join(OUT, 'tts', 'durations.cjson')))

LINES = {
    's01_intro': [
        "Assalam o Alaikum! Medical store ki billing aam dukan jaisi nahi hoti.",
        "Har dawa ka batch, har batch ki expiry, customer salt ka naam leta hai.",
        "Kuch dawaein nuskhe ke baghair nahi di ja saktin.",
        "FBR POS ka Pharmacy Mode yehi sab sambhalta hai — aur har bill FBR ko bhi report hota hai.",
        "Aaj poora chakkar dekhte hain.",
    ],
    's02_agenda': [
        "Pehle nai dawa add karenge, phir distributor ka maal batch + expiry ke saath stock mein.",
        "Phir counter par bill: salt se search, batch chunna, khuli goliyan, nuskha record.",
        "Aakhir mein expired maal, distributor claim aur pharmacy reports.",
    ],
    's03_login': [
        "Demo dukan: Shifa Medical Store. Login karte hi dashboard samne hai.",
        "Aaj ki sale, kitne bill bane, kitne FBR par jama ho chuke.",
        "Upar FBR ka switch ON hai — dukan live reporting kar rahi hai.",
    ],
    's04_product_form': [
        "Pehla kaam: nai dawa. Products → New Product.",
        "Naam Augmentin 625, qeemat 520.",
        "Pharmacy mode mein neeche Medicine Details ka hissa aata hai.",
        "Salt ka naam, strength, form, company aur shelf likhein.",
        "Salt ka naam hi woh cheez hai jisse counter par search chalegi.",
    ],
    's05_product_rx_save': [
        "Neeche do zaroori tick hain.",
        "Prescription Required — nuskhe ke baghair bill nahi hoga.",
        "Allow Loose Sale — khuli goliyan bhi bik sakengi; ek patti mein kitni goliyan, likh dein.",
        "Dawa tax se exempt hai — pehle se chuna hua. Save.",
    ],
    's06_stock_receive': [
        "Distributor se maal aaya hai — Stock & Purchase par jayen.",
        "Distributor chunein, dawa search karein, tadaad aur khareed rate likhein.",
        "Do khaane sab se aham: Batch Number aur Expiry.",
        "Yehi counter par, expiry alert mein aur distributor claim mein kaam aayenge.",
        "Receive Stock — maal usi batch ke naam stock mein aa gaya.",
    ],
    's07_salt_search': [
        "Asal imtihan: counter. Customer kehta hai 'Paracetamol de do' — salt ka naam.",
        "Bas 'paracet' likhein — Panadol, Panadol Extra, Calpol Syrup, sab samne.",
        "Har ek ke neeche salt, strength, company aur shelf number.",
        "Naya larka bhi counter par dawa dhoondne mein der nahi lagayega.",
    ],
    's08_batch_picker': [
        "Panadol chunte hain. Line par 'Auto batch' — pehle expire hone wala batch khud niklega.",
        "Khud batch chunna ho to isi par click karein.",
        "Dono batch, har ek ki expiry aur bacha hua maal. Purana batch chun lete hain.",
    ],
    's09_expired_loose': [
        "Ab Brufen. Ek batch EXPIRED hai — halka ho kar lock; click hi nahi hoga.",
        "Expired dawa ghalti se bhi bill mein nahi ja sakti. Cancel.",
        "Customer ko sirf 4 goliyan chahiyein — Loose dabayein, 4 likhein, Apply.",
        "Line par 4 units aur qeemat khud hisaab se ban gayi.",
    ],
    's10_rx': [
        "Aakhir mein nai dawa Augmentin — cart par laal 'Rx needed' aa gaya.",
        "Cash dabayen to bill nahi banta; system pehle nuskha poochta hai.",
        "Doctor ka naam, mareez ka naam, chahein to nuskhe ki tasveer. Save.",
        "Ab yeh bill ke saath record hai aur Prescription Register mein milega.",
    ],
    's11_pay': [
        "Cash — Alt+1. Payment complete.",
        "Upar 'Reporting to FBR', kuch second mein 'FBR Verified'.",
        "Customer ko FBR invoice number aur QR code milega.",
        "Receipt par har dawa ke neeche batch aur expiry bhi chhapi hai.",
    ],
    's12_batches': [
        "Dukan ke peeche ka kaam: Settings → Batch & Expiry Stock.",
        "Har batch ek line — kitna bacha, kab tak chalega.",
        "Tabs: 90 din mein expire hone wale, aur Expired.",
        "Quarantine = shelf se hata kar alag rakho; Write Off = nuqsan maan kar stock se nikalo.",
        "Calpol ko quarantine karte hain — wajah likhein, Confirm.",
    ],
    's13_claim': [
        "Expired maal ka paisa distributor se wapas lena hota hai — aksar kaghazon mein gum.",
        "Expiry Claims kholen — expired / near-expiry batch upar tayyar milte hain.",
        "Batch tick karein, distributor chunein, Create Claim.",
        "Claim number ban gaya — print kar ke distributor ke bande ko thama dein.",
        "Paisa ya maal wapas mile to yahin Settled kar dein.",
    ],
    's14_reports': [
        "Pharmacy Reports — sab ek jagah.",
        "Near Expiry: agle 90 din mein kaunsa maal nikalna zaroori hai, kitni qeemat phansi hai.",
        "Batch Valuation: shelf par kul kitne rupay ka maal para hai.",
        "Prescription Register: kis doctor ke nuskhe par kis mareez ko kaunsi dawa, kis batch se.",
        "Fast & Slow Movers: kaunsi dawa roz bikti hai, kaunsi mahinon se pari hai.",
    ],
    's15_khata': [
        "Medical store ki ek aur haqeeqat: udhaar.",
        "Clinic aur purane customers ki khata list — kis par kitna baqi, kitne din purana.",
        "Ek button se WhatsApp yaad-dehani; wasooli aaye to yahin darj karein.",
        "Din ke aakhir mein Day Close — cash, udhaar aur FBR ka poora hisaab ek kaghaz par.",
    ],
    's16_cta': [
        "Yeh tha FBR POS ka Pharmacy Mode.",
        "Salt se search, batch aur expiry ka pakka hisaab, khuli goliyon ki sale, nuskhe ka record,",
        "distributor claim — aur har bill FBR par.",
        "taxnest.pk par apni pharmacy ka trial aaj hi shuru karein. Shukriya!",
    ],
}

HEADER = """[Script Info]
; Compact Roman Urdu overlay for the Pharmacy tutorial.
ScriptType: v4.00+
PlayResX: 384
PlayResY: 288
ScaledBorderAndShadow: yes
YCbCr Matrix: None

[V4+ Styles]
Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding
Style: Caption,DejaVu Sans,5,&H00FFFFFF,&H00FFFFFF,&H00152A30,&H00000000,0,0,0,0,100,100,0,0,1,0.4,0.5,9,110,10,12,1

[Events]
Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text
"""


def ts(seconds: float) -> str:
    if seconds < 0:
        seconds = 0.0
    h = int(seconds // 3600)
    m = int((seconds % 3600) // 60)
    s = seconds % 60
    return f"{h}:{m:02d}:{s:05.2f}"


events = []
for scene in timeline:
    sid = scene['id']
    lines = LINES.get(sid)
    if not lines:
        continue
    start = scene['audioAtMs'] / 1000.0
    dur = float(durations.get(sid, 0))
    if dur <= 0:
        continue
    # Weight each caption by its length so long lines stay on screen longer.
    weights = [max(len(t), 1) for t in lines]
    total = sum(weights)
    cursor = start
    for text, w in zip(lines, weights):
        span = dur * (w / total)
        events.append((cursor, cursor + span, text))
        cursor += span

with open(os.path.join(HERE, 'fbr-pharmacy.ass'), 'w', encoding='utf-8') as fh:
    fh.write(HEADER)
    for a, b, text in events:
        fh.write(f"Dialogue: 0,{ts(a)},{ts(b)},Caption,,0,0,0,,{text}\n")

print(f"wrote {len(events)} caption events")
