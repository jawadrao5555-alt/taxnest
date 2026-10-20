import {readFileSync, writeFileSync, statSync} from 'node:fs';
import {createHash} from 'node:crypto';
import {resolve, join} from 'node:path';
import {pathToFileURL} from 'node:url';

const sha256 = bytes => createHash('sha256').update(bytes).digest('hex');
// Invoked only AFTER APK identity, signing-lineage and Firebase guards pass.
export function betaManifest(source, directory, root = process.cwd()) {
  if (!/^[a-f0-9]{40}$/.test(source)) throw new Error('Full lowercase source SHA required');
  const apps = [
    ['rider-app', 'taxnest-rider.apk', 'pk.taxnest.rider'],
    ['caller-app', 'taxnest-caller.apk', 'pk.taxnest.callerid'],
    ['caller-app', 'taxnest-caller-plus.apk', 'pk.taxnest.callerid'],
  ];
  return {
    schema_version: 1, product: 'taxnest-android-release', source_sha: source,
    build_inputs_sha256: sha256(JSON.stringify({
      signer_sha256: '490d5c3bae13abb212bfe2a33abac66e8387e623cc0ecd724c5fdd8ffb72245b',
      rider_firebase_sha256: sha256(readFileSync(join(root, 'rider-app/app/google-services.json'))),
      gradle: '8.11.1', java: '17',
    })),
    built_at: new Date().toISOString(), rollout_status: 'OWNER_PHONE_TEST_REQUIRED',
    artifacts: apps.map(([module, name, applicationId]) => {
      const gradle = readFileSync(join(root, module, 'app/build.gradle'), 'utf8');
      const version = gradle.match(/^\s*versionName\s+"(\d+\.\d+\.\d+)"/m)?.[1];
      const code = Number(gradle.match(/^\s*versionCode\s+(\d+)/m)?.[1]);
      if (!version || !Number.isSafeInteger(code) || code < 1) throw new Error('Invalid source version');
      const file = join(directory, name), size = statSync(file).size;
      if (!size) throw new Error('Empty artifact');
      return {name, package: applicationId, version, version_code: code, size, sha256: sha256(readFileSync(file))};
    }),
  };
}
if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  const [source, directory] = process.argv.slice(2);
  writeFileSync(join(directory, 'beta-manifest.json'), JSON.stringify(betaManifest(source, directory), null, 2) + '\n');
}
