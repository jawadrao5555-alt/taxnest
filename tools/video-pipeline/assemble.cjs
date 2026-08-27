// Assemble final MP4s (16:9 + framed 9:16 + framed 1:1) from capture.webm + per-scene TTS.
// Usage: node tools/video-pipeline/assemble.cjs <slug> [title]
const fs = require('fs'); const path = require('path'); const { execSync } = require('child_process');
const slug = process.argv[2];
if (!slug) { console.error('usage: assemble.cjs <slug>'); process.exit(1); }
const OUT = path.join(__dirname, 'out', slug);
const timeline = JSON.parse(fs.readFileSync(path.join(OUT, 'timeline.json'), 'utf8'));
// A voice revision can deliberately extend scene pauses without re-recording
// the real UI actions. Prefer that retimed master when it is present.
const retimedCapture = path.join(OUT, 'capture-retimed.mp4');
const capture = fs.existsSync(retimedCapture) ? retimedCapture : path.join(OUT, 'capture.webm');
const sh = (c) => { console.log('$', c.length > 220 ? c.slice(0, 220) + '…' : c); execSync(c, { stdio: ['ignore', 'inherit', 'inherit'] }); };

// ── 16:9 master: overlay each scene's mp3 at its recorded offset ──
const withAudio = timeline.filter(t => fs.existsSync(path.join(OUT, 'tts', t.id + '.mp3')));
let inputs = `-i "${capture}"`;
let filters = [], mixIns = [];
withAudio.forEach((t, i) => {
  inputs += ` -i "${path.join(OUT, 'tts', t.id + '.mp3')}"`;
  filters.push(`[${i + 1}:a]adelay=${t.audioAtMs}|${t.audioAtMs}[a${i}]`);
  mixIns.push(`[a${i}]`);
});
const captions = fs.existsSync(path.join(OUT, 'captions.srt'))
  ? path.join(OUT, 'captions.srt')
  : path.join(__dirname, 'scenarios', `${slug}.srt`);
if (fs.existsSync(captions)) {
  // Captions are deliberately burned into the master so the social exports
  // inherit them too. Keep a dark strip behind Roman Urdu for readability
  // over both title cards and dense POS screens.
  filters.unshift(`[0:v]subtitles='${captions}':force_style='FontName=DejaVu Sans,FontSize=17,PrimaryColour=&H00FFFFFF,OutlineColour=&H00152A30,BackColour=&H880A4D5C,BorderStyle=3,Outline=1,Shadow=0,Alignment=7,MarginL=42,MarginR=500,MarginV=34'[vout]`);
} else {
  filters.unshift('[0:v]null[vout]');
}
filters.push(`${mixIns.join('')}amix=inputs=${withAudio.length}:normalize=0,loudnorm=I=-16:TP=-1.5:LRA=11[aout]`);
const master = path.join(OUT, `${slug}-16x9.mp4`);
sh(`ffmpeg -y ${inputs} -filter_complex "${filters.join(';')}" -map "[vout]" -map "[aout]" -c:v libx264 -preset veryfast -crf 20 -pix_fmt yuv420p -r 30 -c:a aac -b:a 160k -movflags +faststart "${master}"`);

// ── 9:16 framed version: teal branded canvas, video centered, readable ──
// The branded 1080x1920 background is rendered ONCE via make-bg.cjs (Chromium)
// because drawtext font discovery is unreliable here (globbing /nix/store for
// fonts hangs for minutes) — then it's a cheap overlay-only encode.
const V = path.join(OUT, `${slug}-9x16.mp4`);
const title = process.argv[3] || slug.replace(/-/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()) + ' Tutorial';
sh(`node "${path.join(__dirname, 'make-bg.cjs')}" ${slug} "${title}"`);
sh(`ffmpeg -y -loglevel error -loop 1 -i "${path.join(OUT, 'bg.png')}" -i "${master}" -filter_complex "[1:v]scale=1044:-2[v];[0:v][v]overlay=(W-w)/2:640:shortest=1[out]" -map "[out]" -map 1:a -c:v libx264 -preset veryfast -crf 21 -pix_fmt yuv420p -r 30 -c:a copy -movflags +faststart "${V}"`);

// ── 1:1 framed version (Facebook/Instagram feed): square branded canvas ──
// Same recipe as 9:16 — a 1080x1080 background card rendered once by
// make-bg.cjs, full uncut 16:9 video (1044x~587) centered below the header.
const SQ = path.join(OUT, `${slug}-1x1.mp4`);
sh(`node "${path.join(__dirname, 'make-bg.cjs')}" ${slug} "${title}" 1x1`);
sh(`ffmpeg -y -loglevel error -loop 1 -i "${path.join(OUT, 'bg-1x1.png')}" -i "${master}" -filter_complex "[1:v]scale=1044:-2[v];[0:v][v]overlay=(W-w)/2:330:shortest=1[out]" -map "[out]" -map 1:a -c:v libx264 -preset veryfast -crf 21 -pix_fmt yuv420p -r 30 -c:a copy -movflags +faststart "${SQ}"`);
console.log('✓', master, '\n✓', V, '\n✓', SQ);
