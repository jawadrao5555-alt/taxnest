import {mkdirSync, lstatSync, realpathSync} from 'node:fs';
import {dirname, join} from 'node:path';

export const tutorialJourneys = ['restaurant-top-selling', 'whatsapp-optional-autosend', 'rider-realtime-map', 'notification-pra'];
export function recordingDirectory(flag, fixturePath, requested) {
  if (!flag || flag === '0') return null;
  if (flag !== '1' || !requested.length || requested.some(name => !tutorialJourneys.includes(name))) {
    throw new Error('Tutorial recording requires explicit supported synthetic journeys');
  }
  if (!/^\/tmp\/taxnest-rc-browser-[0-9]+(?:-[A-Za-z0-9_.-]+)?\/safe-runtime\/browser-state\/fixture\.json$/.test(fixturePath)) {
    throw new Error('Tutorial recording requires exact disposable fixture');
  }
  const directory = join(dirname(fixturePath), 'tutorial-videos');
  mkdirSync(directory, {recursive: true});
  if (!lstatSync(directory).isDirectory() || realpathSync(directory) !== directory) throw new Error('Unsafe recording directory');
  return directory;
}
