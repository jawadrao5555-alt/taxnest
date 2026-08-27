#!/usr/bin/env python3
"""Build the Roman Urdu ASS caption track for the deals tutorial.

Timing is derived from timeline.json (scene audio offsets) + tts/durations.cjson
(real narration length), so the captions never drift from the voice track.
"""
import json
import os

HERE = os.path.dirname(os.path.abspath(__file__))   # tools/video-pipeline/scenarios
OUT = os.path.abspath(os.path.join(HERE, '..', 'out', 'deals'))

timeline = json.load(open(os.path.join(OUT, 'timeline.json')))
durations = json.load(open(os.path.join(OUT, 'tts', 'durations.cjson')))

LINES = {
    's01_intro': [
        "Assalam o Alaikum! Aaj NestPOS mein Deals seekhte hain.",
        "Deal yani kai items ka combo — ek fixed qeemat par.",
        "Do qism ki deals hoti hain: Regular aur Special.",
        "Deal banana, handle karna aur bechna — teeno dekhenge.",
    ],
    's02_deals_page': [
        "Customize mein se Deals ka page kholen.",
        "Yahan aap ki tamam deals ki list rehti hai.",
        "Har deal par items, qeemat, din aur status ka badge.",
    ],
    's03_new_deal_basic': [
        "Nai deal ke liye upar wala form bharein.",
        "Naam: Student Deal — qeemat: 499 rupay.",
        "Description mein likhein deal mein kya milega.",
    ],
    's04_type_days': [
        "Deal type chunein — Regular deal rozana chalti hai.",
        "Neeche din ke buttons se tay karein deal kin dinon chale.",
        "Misal ke taur par sirf Monday aur Tuesday.",
        "Koi din na chunein to deal har roz chalegi.",
    ],
    's05_items': [
        "Ab batayein deal mein milega kya.",
        "Pehli item: Chicken Biryani.",
        "Add Item daba kar doosri item: Soft Drink.",
        "Har item ki tadaad bhi likh sakte hain.",
    ],
    's05b_cashier_choice': [
        "Customer ki choice ke liye Cashier choice add karein.",
        "Naam likhein: Apni Drink Chunein — quantity: 2.",
        "Neeche tamam allowed drinks select karein.",
        "Fixed items aur choice groups ek deal mein saath chal sakte hain.",
    ],
    's06_save': [
        "Bas, Add Deal daba dein.",
        "Student Deal tayyar hokar list mein aa gayi.",
        "Sirf Mon aur Tue chuna tha — unhi dinon sale screen par aayegi.",
    ],
    's07_special_deal': [
        "Doosri qism: Special deal — sirf khaas waqt ki offer.",
        "Karahi Night sirf Friday, Saturday aur Sunday chalti hai.",
        "Raat 7 baje se 11:30 baje tak.",
        "Din mein 20 deals ki limit bhi lagi hai.",
        "Waqt guzarte hi deal sale screen se khud ghayab.",
    ],
    's08_edit_delete': [
        "Bani hui deal ko handle karna bhi asaan hai.",
        "All par wapas aayen aur deal ke samne Edit dabayein.",
        "Naam, qeemat, items aur din yahan se badal jate hain.",
        "Active ka nishan hata dein to deal sale screen se hat jayegi.",
        "Magar record mein mehfooz rehti hai.",
    ],
    's09_delete_note': [
        "Deal ki zaroorat na rahe to Delete daba dein.",
        "Delete karne se puranay bills par koi asar nahi parta.",
        "Har bill deal ke items ki copy khud mehfooz rakhta hai.",
    ],
    's10_sale_grid': [
        "Ab bechte hain — sale screen par aayen.",
        "Category mein Deals chun lein.",
        "Tamam chalti hui deals samne aa jati hain.",
    ],
    's11_choice_sell': [
        "Customer ne Family Deal mangwai? Card par click karein.",
        "Drink choice ho to pehle picker khulta hai.",
        "Customer ki drink chunein aur Add Deal to Cart dabayein.",
        "Fixed aur chosen dono items cart aur receipt mein saaf dikhte hain.",
    ],
    's12_choice_pay': [
        "Ab payment — wahi ek button, CASH.",
        "Receipt par fixed aur chosen items alag alag likhe aate hain.",
        "Stock aur recipe usi chosen product ka khud-ba-khud minus hota hai.",
        "Qeemat system khud lagata hai — ghalat rate ya choice mumkin nahi.",
    ],
    's13_outro': [
        "Ek baar deal banayen, din aur waqt tay karein.",
        "Phir rozana ek click mein bech dein.",
        "Restaurant mode aur Reports ki videos bhi dekhein. Shukriya!",
    ],
}

HEADER = """[Script Info]
; Compact Roman Urdu overlay for the Deals tutorial.
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

with open(os.path.join(HERE, 'deals.ass'), 'w', encoding='utf-8') as fh:
    fh.write(HEADER)
    for a, b, text in events:
        fh.write(f"Dialogue: 0,{ts(a)},{ts(b)},Caption,,0,0,0,,{text}\n")

print(f"wrote {len(events)} caption events")
