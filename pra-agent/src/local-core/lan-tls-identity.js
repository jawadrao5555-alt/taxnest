'use strict';

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const forge = require('node-forge');

function atomicWrite(file, bytes) {
    fs.mkdirSync(path.dirname(file), { recursive: true, mode: 0o700 });
    const tmp = file + '.tmp';
    const fd = fs.openSync(tmp, 'w', 0o600);
    try {
        fs.writeFileSync(fd, bytes);
        fs.fsyncSync(fd);
    } finally {
        fs.closeSync(fd);
    }
    fs.renameSync(tmp, file);
    try { fs.chmodSync(file, 0o600); } catch (e) {}
}

function makeCertificate() {
    const keys = forge.pki.rsa.generateKeyPair(2048);
    const cert = forge.pki.createCertificate();
    cert.publicKey = keys.publicKey;
    cert.serialNumber = crypto.randomBytes(16).toString('hex').replace(/^0/, '1');
    cert.validity.notBefore = new Date(Date.now() - 5 * 60 * 1000);
    cert.validity.notAfter = new Date(Date.now() + 10 * 365 * 24 * 60 * 60 * 1000);
    const cn = [{ name: 'commonName', value: 'NestPOS Local Core' }];
    cert.setSubject(cn);
    cert.setIssuer(cn);
    cert.setExtensions([
        { name: 'basicConstraints', cA: false, critical: true },
        { name: 'keyUsage', digitalSignature: true, keyEncipherment: true, critical: true },
        { name: 'extKeyUsage', serverAuth: true },
        { name: 'subjectKeyIdentifier' },
    ]);
    cert.sign(keys.privateKey, forge.md.sha256.create());
    return {
        key: forge.pki.privateKeyToPem(keys.privateKey),
        cert: forge.pki.certificateToPem(cert),
    };
}

function pins(certPem) {
    const x509 = new crypto.X509Certificate(certPem);
    const spki = x509.publicKey.export({ type: 'spki', format: 'der' });
    return {
        spki_sha256: crypto.createHash('sha256').update(spki).digest('base64'),
        cert_sha256: crypto.createHash('sha256').update(x509.raw).digest('base64'),
    };
}

/**
 * The wrapper is deliberately injected by main.js. This module stays pure Node
 * and tests can supply a deterministic wrapper without loading Electron.
 */
function loadOrCreateTlsIdentity(options) {
    const opts = options || {};
    const file = path.join(opts.dataDir, 'local-core-lan-tls.bin');
    if (!opts.protector || typeof opts.protector.protect !== 'function' ||
        typeof opts.protector.unprotect !== 'function') {
        throw new Error('OS credential protector is required');
    }
    let material;
    if (fs.existsSync(file)) {
        try {
            material = JSON.parse(opts.protector.unprotect(fs.readFileSync(file)).toString('utf8'));
        } catch (e) {
            throw new Error('Local Core TLS identity cannot be unwrapped');
        }
    } else {
        material = makeCertificate();
        const wrapped = opts.protector.protect(Buffer.from(JSON.stringify(material)));
        if (!Buffer.isBuffer(wrapped) || !wrapped.length) throw new Error('Local Core TLS identity wrapping failed');
        atomicWrite(file, wrapped);
    }
    if (!material || !material.key || !material.cert) throw new Error('Local Core TLS identity is invalid');
    return Object.assign({ key: material.key, cert: material.cert }, pins(material.cert));
}

module.exports = { loadOrCreateTlsIdentity, pins };