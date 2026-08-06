#!/usr/bin/env python3
# DI Promo — final video assembler.
# Reads TTS MP3s, B-roll webm takes, title card PNGs and music bed;
# outputs a single ~58s mp4 with VO + music mix.
#
# Usage (from repo root):
#   python3 scripts/video/di-promo/di-assemble.py
#   DI_OUT_DIR=/custom/path python3 scripts/video/di-promo/di-assemble.py
#
# Inputs (all under DI_OUT_DIR, default .local/video-studio):
#   di-promo/tts/seg01.mp3 … seg12.mp3   (pre-generated ElevenLabs TTS)
#   di-promo/cards/hook.png brand.png compliance.png features.png cta.png end.png
#   di-promo/take.webm                    (authenticated B-roll recording)
#   di-promo/register-take.webm           (unauthenticated /register B-roll, optional)
#   di-promo/timeline.json                (marks written by di-record.js)
#   di-promo/register-timeline.json       (marks for register-take.webm)
#   promo/music72.mp3                     (shared 72s music bed)
#
# Output: di-promo/di-promo.mp4  (gitignored — large binary)
#
# Duration target: 58–80 s.  End card gets a 6.2 s hold so total ≥ 58 s.
#
# Segment structure:
#   p01 card  hook        seg01
#   p02 card  brand       seg02
#   p03 clip  R1→R1END    seg03  (register B-roll or brand-card fallback)
#   p04 clip  C1→C1END    seg04  (dashboard)
#   p05 clip  C2→C3       seg05  (invoice create)
#   p06 clip  MO→ME       seg06  (submit-to-FBR modal spinner)
#   p07 clip  C4→C4END    seg07  (submitted invoice: FBR # + QR)
#   p08 card  compliance  seg08
#   p09 clip  C5→END      seg09  (invoice list + dashboard pan — 2 sub-clips concat)
#   p10 card  features    seg10
#   p11 card  cta         seg11
#   p12 card  end         seg12

import json, os, subprocess, sys

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
OUT_BASE   = os.environ.get('DI_OUT_DIR',
             os.path.normpath(os.path.join(SCRIPT_DIR, '../../../.local/video-studio')))

D        = os.path.join(OUT_BASE, 'di-promo')
TTS      = [os.path.join(D, f'tts/seg{i:02d}.mp3') for i in range(1, 13)]
CARDS    = os.path.join(D, 'cards')
TAKE     = os.path.join(D, 'take.webm')
REG_TAKE = os.path.join(D, 'register-take.webm')
MUSIC    = os.path.join(OUT_BASE, 'promo/music72.mp3')

os.makedirs(os.path.join(D, 'pieces'), exist_ok=True)


def check_files():
    missing = []
    for f in TTS:
        if not os.path.exists(f): missing.append(f)
    for name in ['hook', 'brand', 'compliance', 'features', 'cta', 'end']:
        p = os.path.join(CARDS, f'{name}.png')
        if not os.path.exists(p): missing.append(p)
    if not os.path.exists(TAKE):  missing.append(TAKE)
    if not os.path.exists(MUSIC): missing.append(MUSIC)
    # REG_TAKE is optional — falls back to brand card if absent
    if missing:
        print('MISSING FILES:', missing)
        sys.exit(1)


def dur_of(f):
    return float(subprocess.check_output(
        ['ffprobe', '-v', 'error', '-show_entries', 'format=duration',
         '-of', 'csv=p=0', f]).decode().strip())


def run(args):
    result = subprocess.run(args, capture_output=True)
    if result.returncode != 0:
        print('ffmpeg FAIL:', ' '.join(str(a) for a in args[:6]))
        print(result.stderr.decode()[-800:])
        sys.exit(1)


def card_piece(out, png, dur, fade_in=False, fade_out=False):
    n = round(dur * 30)
    vf = (f"zoompan=z='min(1+0.0015*on,1.05)':d={n}"
          f":x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':s=1280x720:fps=30"
          ",format=yuv420p")
    if fade_in:  vf += ',fade=t=in:st=0:d=0.5'
    if fade_out: vf += f',fade=t=out:st={max(0, dur-0.9):.2f}:d=0.9'
    run(['ffmpeg', '-y', '-v', 'error', '-i', png,
         '-vf', vf, '-t', f'{dur:.3f}',
         '-c:v', 'libx264', '-crf', '20', '-preset', 'medium', out])


def clip_piece(out, start, src_span, dur, src=None):
    src = src or TAKE
    speed = max(0.5, min(2.5, src_span / dur))
    run(['ffmpeg', '-y', '-v', 'error', '-i', src,
         '-vf', (f'trim=start={start:.3f}:end={start+src_span:.3f},'
                 f'setpts=(PTS-STARTPTS)/{speed:.4f},fps=30,'
                 'scale=1280:720,format=yuv420p'),
         '-an', '-t', f'{dur:.3f}',
         '-c:v', 'libx264', '-crf', '20', '-preset', 'medium', out])


def concat_clips(out, *clips):
    list_file = out + '.list.txt'
    with open(list_file, 'w') as f:
        for c in clips:
            f.write(f"file '{os.path.abspath(c)}'\n")
    run(['ffmpeg', '-y', '-v', 'error', '-f', 'concat', '-safe', '0',
         '-i', list_file, '-c', 'copy', out])
    os.unlink(list_file)


check_files()

# ── TTS durations ─────────────────────────────────────────────────────────────
D_raw = [dur_of(f) for f in TTS]
GAP   = 0.22
durs  = [d + GAP for d in D_raw]
# End card: extended hold so the assembled total stays ≥ 58 s.
# With 12 segments (GAP=0.22 each) and raw total ~49.5 s → base = ~52.2 s.
# Adding 6.2 s to the end card brings total to ~58.1 s.
durs[11] = D_raw[11] + 6.2
print('TTS raw durs:', [f'{d:.2f}' for d in D_raw])

# ── Timeline marks ────────────────────────────────────────────────────────────
tl_path = os.path.join(D, 'timeline.json')
if os.path.exists(tl_path):
    TL = {m['name']: m['t'] for m in json.load(open(tl_path))}
else:
    print('WARNING: timeline.json missing — using fallback clip offsets')
    TL = {}


def tget(name, fallback=0.0):
    return TL.get(name, fallback)


P = os.path.join(D, 'pieces') + os.sep

# ── Build pieces ──────────────────────────────────────────────────────────────
print('Building p01 (hook card)…')
card_piece(P+'p01.mp4', os.path.join(CARDS, 'hook.png'), durs[0], fade_in=True)

print('Building p02 (brand card)…')
card_piece(P+'p02.mp4', os.path.join(CARDS, 'brand.png'), durs[1])

# p03 — /register B-roll (unauthenticated recording) or brand-card fallback.
reg_tl_path = os.path.join(D, 'register-timeline.json')
reg_tl = {}
if os.path.exists(reg_tl_path):
    reg_tl = {m['name']: m['t'] for m in json.load(open(reg_tl_path))}

if os.path.exists(REG_TAKE) and reg_tl:
    r1       = reg_tl.get('R1',    0.5)
    r1end    = reg_tl.get('R1END', r1 + 5.0)
    p03_span = max(2.0, r1end - r1 - 0.3)
    print(f'Building p03 (register B-roll, span={p03_span:.1f}s)…')
    clip_piece(P+'p03.mp4', r1, p03_span, durs[2], src=REG_TAKE)
else:
    print('Building p03 (brand card fallback — register-take.webm absent)…')
    card_piece(P+'p03.mp4', os.path.join(CARDS, 'brand.png'), durs[2])

# p04 — dashboard: C1 → C1END
c1       = tget('C1', 12.0)
c1e      = tget('C1END', c1 + 6.0)
p04_span = max(1.5, c1e - c1 - 0.3)
print(f'Building p04 (dashboard, span={p04_span:.1f}s)…')
clip_piece(P+'p04.mp4', c1, p04_span, durs[3])

# p05 — invoice create: C2 → C3
c2       = tget('C2', 22.0)
c3       = tget('C3', 33.0)
p05_span = max(2.0, c3 - c2 - 0.3)
print(f'Building p05 (invoice create, span={p05_span:.1f}s)…')
clip_piece(P+'p05.mp4', c2, p05_span, durs[4])

# p06 — submit-to-FBR modal spinner: MODAL_OPEN → MODAL_END
mo       = tget('MODAL_OPEN', tget('DRAFT_OPEN', 40.0) + 2.0)
me       = tget('MODAL_END',  mo + 3.0)
p06_span = max(1.5, me - mo - 0.2)
print(f'Building p06 (submit modal, span={p06_span:.1f}s)…')
clip_piece(P+'p06.mp4', mo, p06_span, durs[5])

# p07 — submitted invoice show: C4 → C4END (FBR number + QR code)
c4       = tget('C4',    52.0)
c4e      = tget('C4END', c4 + 6.0)
p07_span = max(2.0, c4e - c4 - 0.3)
print(f'Building p07 (invoice show QR, span={p07_span:.1f}s)…')
clip_piece(P+'p07.mp4', c4, p07_span, durs[6])

print('Building p08 (compliance card)…')
card_piece(P+'p08.mp4', os.path.join(CARDS, 'compliance.png'), durs[7])

# p09 — invoice list + dashboard pan: C5 → END (2 sub-clips concat to one piece)
c5        = tget('C5', 65.0)
c6        = tget('C6', 72.0)
end       = tget('END', c6 + 6.0)
q         = durs[8] / 2
p09a_span = max(1.0, min(c6 - c5 - 0.5, q * 1.6))
p09b_span = max(1.0, min(end - c6 - 0.5, q * 1.6))
print(f'Building p09a (invoice list, span={p09a_span:.1f}s)…')
clip_piece(P+'p09a.mp4', c5, p09a_span, q)
print(f'Building p09b (dashboard pan, span={p09b_span:.1f}s)…')
clip_piece(P+'p09b.mp4', c6, p09b_span, q)
print('Concatenating p09…')
concat_clips(P+'p09.mp4', P+'p09a.mp4', P+'p09b.mp4')

print('Building p10 (features card)…')
card_piece(P+'p10.mp4', os.path.join(CARDS, 'features.png'), durs[9])

print('Building p11 (cta card)…')
card_piece(P+'p11.mp4', os.path.join(CARDS, 'cta.png'), durs[10])

print('Building p12 (end card — extended hold + fade-out)…')
card_piece(P+'p12.mp4', os.path.join(CARDS, 'end.png'), durs[11], fade_out=True)

# ── Concatenate all pieces ─────────────────────────────────────────────────────
pieces    = ['p01', 'p02', 'p03', 'p04', 'p05', 'p06',
             'p07', 'p08', 'p09', 'p10', 'p11', 'p12']
list_path = P + 'list.txt'
with open(list_path, 'w') as f:
    for p in pieces:
        f.write(f"file '{os.path.abspath(P+p+'.mp4')}'\n")
print('Concatenating all pieces…')
run(['ffmpeg', '-y', '-v', 'error', '-f', 'concat', '-safe', '0',
     '-i', list_path, '-c', 'copy', P+'video.mp4'])

# ── Compute VO placement (piece start times) ──────────────────────────────────
starts, t = [], 0.0
for p in pieces:
    starts.append(t)
    t += dur_of(P + p + '.mp4')
total_video = t
print(f'Total video duration: {total_video:.2f}s')

seg_map = {
    'p01': 0,  'p02': 1,  'p03': 2,  'p04': 3,
    'p05': 4,  'p06': 5,  'p07': 6,  'p08': 7,
    'p09': 8,  'p10': 9,  'p11': 10, 'p12': 11,
}
piece_starts = dict(zip(pieces, starts))

# ── Build VO + music mix ───────────────────────────────────────────────────────
# Input 0 = video.mp4 (video only), Input 1 = MUSIC, Inputs 2..13 = TTS segments.
vo_inputs, vo_filters, vo_labels = [], [], []
for i, (p, seg_idx) in enumerate(seg_map.items()):
    seg_start = piece_starts[p]
    vo_inputs.extend(['-i', TTS[seg_idx]])
    vo_filters.append(
        f'[{i+2}:a]adelay={int(seg_start*1000)}|{int(seg_start*1000)}[vo{i}]')
    vo_labels.append(f'[vo{i}]')

n_vo         = len(vo_inputs) // 2   # 12
music_vol    = 0.12
filter_complex = ';'.join(vo_filters)
filter_complex += (
    f';[1:a]volume={music_vol}[mu]'
    ';' + ''.join(vo_labels)
    + f'[mu]amix=inputs={n_vo+1}:duration=longest:normalize=0[aout]'
)

out_mp4 = os.path.join(D, 'di-promo.mp4')
print('Mixing audio + assembling final mp4…')
run(['ffmpeg', '-y', '-v', 'error',
     '-i', P+'video.mp4', '-i', MUSIC]
    + vo_inputs
    + ['-filter_complex', filter_complex,
       '-map', '0:v', '-map', '[aout]',
       '-c:v', 'copy', '-c:a', 'aac', '-b:a', '192k',
       '-t', f'{total_video:.3f}',
       out_mp4])

final_dur = dur_of(out_mp4)
print(f'DONE — {out_mp4}  duration={final_dur:.1f}s')
if not (58 <= final_dur <= 80):
    print(f'WARNING: duration {final_dur:.1f}s outside target 58–80 s — check TTS/end-hold')
