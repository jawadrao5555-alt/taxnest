'use strict';
const assert = require('node:assert/strict');
const test = require('node:test');
const crypto = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const https = require('node:https');
const { makeCertificate } = require('../src/local-core/lan-tls-certificate');
const { loadOrCreateTlsIdentity } = require('../src/local-core/lan-tls-identity');
const protector = {
    protect: value => Buffer.from(value),
    unprotect: value => Buffer.from(value),
};

test('native certificate is self-signed RSA-2048 SHA256 and serves trusted TLS', async () => {
    const material = makeCertificate();
    const cert = new crypto.X509Certificate(material.cert);
    assert.equal(cert.ca, false);
    assert.equal(cert.subject, 'CN=NestPOS Local Core');
    assert.equal(cert.issuer, cert.subject);
    assert.equal(cert.publicKey.asymmetricKeyDetails.modulusLength, 2048);
    assert.ok(cert.verify(cert.publicKey));
    assert.ok(cert.checkPrivateKey(crypto.createPrivateKey(material.key)));
    assert.deepEqual(cert.keyUsage, ['1.3.6.1.5.5.7.3.1']);
    const now = Date.now();
    assert.ok(Date.parse(cert.validFrom) < now);
    assert.ok(Date.parse(cert.validTo) > now + 9 * 365 * 86400000);
    const server = https.createServer(material, (req, res) => res.end('TLS ready'));
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
        const body = await new Promise((resolve, reject) => {
            const req = https.get({
                hostname: '127.0.0.1', port: server.address().port,
                ca: material.cert,
                checkServerIdentity: (host, peer) => {
                    assert.equal(peer.fingerprint256, cert.fingerprint256);
                },
            }, res => {
                let body = '';
                res.on('data', chunk => { body += chunk; });
                res.on('end', () => resolve(body));
            });
            req.on('error', reject);
        });
        assert.equal(body, 'TLS ready');
    } finally {
        await new Promise(resolve => server.close(resolve));
    }
});

test('existing PKCS1 wrapped identity retains its certificate, pins and file bytes', () => {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'legacy-agent-tls-'));
    try {
        const generated = makeCertificate();
        const legacy = {
            key: crypto.createPrivateKey(generated.key).export({ type: 'pkcs1', format: 'pem' }),
            cert: generated.cert,
        };
        const file = path.join(dir, 'local-core-lan-tls.bin');
        const original = protector.protect(Buffer.from(JSON.stringify(legacy)));
        fs.writeFileSync(file, original);
        const first = loadOrCreateTlsIdentity({ dataDir: dir, protector });
        const second = loadOrCreateTlsIdentity({ dataDir: dir, protector });
        assert.equal(first.key, legacy.key);
        assert.equal(first.cert, legacy.cert);
        assert.equal(second.spki_sha256, first.spki_sha256);
        assert.deepEqual(fs.readFileSync(file), original);
        fs.writeFileSync(file, 'invalid wrapped material');
        assert.throws(() => loadOrCreateTlsIdentity({ dataDir: dir, protector }), /cannot be unwrapped/);
        assert.equal(fs.readFileSync(file, 'utf8'), 'invalid wrapped material',
            'corrupt saved identity must fail closed, never silently rotate pairing pins');
    } finally {
        fs.rmSync(dir, { recursive: true, force: true });
    }
});
