'use strict';

/*
 * Harness-only Electron safeStorage adapter. Loaded with NODE_OPTIONS=--require
 * and guarded by an explicit profile/key pair, so it cannot become a packaged
 * application fallback. The wrapping key exists only in the test process
 * environment and AES-GCM authenticates both ciphertext and profile identity.
 */
const crypto = require('crypto');
const Module = require('module');

const profile = process.env.PRA_HARNESS_SAFE_STORAGE_PROFILE;
const keyHex = process.env.PRA_HARNESS_SAFE_STORAGE_KEY;
if (!profile || !keyHex || !/^[a-f0-9]{64}$/i.test(keyHex)) {
  throw new Error('Electron safeStorage test shim requires an isolated profile and 32-byte key');
}
const key = Buffer.from(keyHex, 'hex');
const aad = Buffer.from('pra-electron-safe-storage-test\0' + profile);
const magic = Buffer.from('PRATEST1');
const shim = {
  isEncryptionAvailable: () => true,
  encryptString(value) {
    const nonce = crypto.randomBytes(12);
    const cipher = crypto.createCipheriv('aes-256-gcm', key, nonce);
    cipher.setAAD(aad);
    const ciphertext = Buffer.concat([cipher.update(String(value), 'utf8'), cipher.final()]);
    return Buffer.concat([magic, nonce, cipher.getAuthTag(), ciphertext]);
  },
  decryptString(value) {
    const bytes = Buffer.from(value);
    if (bytes.length < 36 || !bytes.subarray(0, 8).equals(magic)) {
      throw new Error('invalid harness safeStorage ciphertext');
    }
    const decipher = crypto.createDecipheriv('aes-256-gcm', key, bytes.subarray(8, 20));
    decipher.setAAD(aad);
    decipher.setAuthTag(bytes.subarray(20, 36));
    return Buffer.concat([decipher.update(bytes.subarray(36)), decipher.final()]).toString('utf8');
  },
};

const originalLoad = Module._load;
let electronProxy = null;
Module._load = function (request, parent, isMain) {
  const loaded = originalLoad.apply(this, arguments);
  if (request !== 'electron' || !loaded || process.type !== 'browser') return loaded;
  if (!electronProxy) electronProxy = new Proxy(loaded, {
    get(target, property, receiver) {
      return property === 'safeStorage' ? shim : Reflect.get(target, property, receiver);
    },
  });
  return electronProxy;
};