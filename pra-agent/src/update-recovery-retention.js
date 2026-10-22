'use strict';
const fs = require('node:fs');
const path = require('node:path');

// This is a dedicated per-executable subtree, never a general userData/TEMP
// cleanup. Keep two completed rollback copies plus the journal-referenced one.
// Failed, incomplete, unreadable and symlink-bearing attempts are left alone.
function pruneCompletedRecovery(root, protectedRecoveryPath) {
  root = path.resolve(root);
  if (!/^[a-f0-9]{20}$/.test(path.basename(root)) || path.basename(path.dirname(root)) !== 'update-recovery')
    throw new Error('retention requires the dedicated per-install recovery root');
  const same = (a,b) => process.platform === 'win32' ? a.toLowerCase()===b.toLowerCase() : a===b;
  const result = {removed:[],retained:[]};
  if (!fs.existsSync(root)) return result;
  if (fs.lstatSync(root).isSymbolicLink() || fs.lstatSync(path.dirname(root)).isSymbolicLink())
    return {...result,reason:'linked-root'};
  // Windows TEMP/userData may use a legitimate 8.3 alias (RUNNER~1). Compare
  // canonical directories, while refusing junction roots and foreign journals.
  root=fs.realpathSync(root);
  let protectedName;
  if (protectedRecoveryPath) {
    let folder;
    try {folder=fs.realpathSync(path.dirname(path.resolve(protectedRecoveryPath)));}
    catch (_) {return {...result,reason:'missing-journal'};}
    if (!same(path.dirname(folder),root)) return {...result,reason:'foreign-journal'};
    protectedName=path.basename(folder);
  }
  const hasLink = folder => fs.readdirSync(folder,{withFileTypes:true}).some(entry=>{
    const file=path.join(folder,entry.name),stat=fs.lstatSync(file);
    return stat.isSymbolicLink() || (stat.isDirectory() && hasLink(file));
  });
  const completed=[];
  for(const name of fs.readdirSync(root)) {
    const folder=path.join(root,name);
    try {
      if(!/^\d{13}$/.test(name)||name===protectedName||!fs.lstatSync(folder).isDirectory()||hasLink(folder)) {
        result.retained.push(name);continue;
      }
      const file=path.join(folder,'apply-update.result');
      if(!fs.lstatSync(file).isFile()||!['installed','recovered_old_version','recovered_before_copy'].includes(fs.readFileSync(file,'utf8').trim())) {
        result.retained.push(name);continue;
      }
      completed.push(name);
    } catch (_) {result.retained.push(name);}
  }
  completed.sort((a,b)=>Number(b)-Number(a));
  result.retained.push(...completed.slice(0,2));
  for(const name of completed.slice(2)) {
    try {fs.rmSync(path.join(root,name),{recursive:true,force:false});result.removed.push(name);}
    catch (_) {result.retained.push(name);}
  }
  return result;
}
module.exports={pruneCompletedRecovery};
