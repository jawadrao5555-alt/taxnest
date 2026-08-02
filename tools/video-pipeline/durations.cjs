// Writes out/<slug>/tts/durations.cjson — { sceneId: seconds } via ffprobe.
const fs = require('fs'); const path = require('path'); const { execSync } = require('child_process');
const slug = process.argv[2];
if (!slug) { console.error('usage: durations.cjs <slug>'); process.exit(1); }
const dir = path.join(__dirname, 'out', slug, 'tts');
const out = {};
for (const f of fs.readdirSync(dir).filter(f => f.endsWith('.mp3'))) {
  const d = parseFloat(execSync(`ffprobe -v error -show_entries format=duration -of csv=p=0 "${path.join(dir, f)}"`).toString());
  out[f.replace(/\.mp3$/, '')] = d;
}
fs.writeFileSync(path.join(dir, 'durations.cjson'), JSON.stringify(out, null, 2));
console.log(out);
