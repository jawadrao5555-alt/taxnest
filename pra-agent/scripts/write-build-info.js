// Generated only by packaging hooks: startup must never manufacture a build date.
const fs = require('fs');
const path = require('path');
const { createBuildInfo } = require('../src/build-info');
function writeBuildInfo(root = path.resolve(__dirname, '..'), now = new Date()) {
  const version = JSON.parse(fs.readFileSync(path.join(root, 'package.json'), 'utf8')).version;
  const info = createBuildInfo(version, now);
  fs.writeFileSync(path.join(root, 'build-info.json'), JSON.stringify(info, null, 2) + '\n');
  return info;
}
if (require.main === module) console.log('Agent packaging date generated for v' + writeBuildInfo().version);
module.exports = { writeBuildInfo };
