'use strict';

// Only certificate encoding lives here. Key generation and signing use Node's
// OpenSSL-backed crypto; no JavaScript RSA verification implementation is used.
const crypto = require('crypto');

function der(tag, value) {
    const bytes = Buffer.isBuffer(value) ? value : Buffer.concat(value);
    let length;
    if (bytes.length < 128) length = Buffer.from([bytes.length]);
    else {
        const hex = bytes.length.toString(16);
        const size = Buffer.from(hex.length % 2 ? '0' + hex : hex, 'hex');
        length = Buffer.concat([Buffer.from([0x80 | size.length]), size]);
    }
    return Buffer.concat([Buffer.from([tag]), length, bytes]);
}
const sequence = (...values) => der(0x30, values);
const oid = (hex) => der(0x06, Buffer.from(hex, 'hex'));
const bool = (value) => der(0x01, Buffer.from([value ? 0xff : 0]));
const octet = (bytes) => der(0x04, bytes);
const bitString = (bytes, unused = 0) => der(0x03, Buffer.concat([Buffer.from([unused]), bytes]));

function integer(bytes) {
    // DER INTEGERs are signed: explicitly prefix positive high-bit values.
    return der(0x02, bytes[0] & 0x80 ? Buffer.concat([Buffer.from([0]), bytes]) : bytes);
}
function time(date) {
    const year = date.getUTCFullYear();
    const digits = date.toISOString().replace(/[-:]/g, '').slice(0, 15).replace('T', '');
    return der(year >= 2050 ? 0x18 : 0x17,
        Buffer.from((year >= 2050 ? digits : digits.slice(2)) + 'Z'));
}
function pem(label, bytes) {
    return '-----BEGIN ' + label + '-----\n' +
        bytes.toString('base64').match(/.{1,64}/g).join('\n') +
        '\n-----END ' + label + '-----\n';
}
function makeCertificate() {
    const keys = crypto.generateKeyPairSync('rsa', { modulusLength: 2048 });
    const spki = keys.publicKey.export({ type: 'spki', format: 'der' });
    // sha256WithRSAEncryption (1.2.840.113549.1.1.11) + NULL parameters.
    const signatureAlgorithm = sequence(oid('2a864886f70d01010b'), der(0x05, Buffer.alloc(0)));
    const name = sequence(der(0x31, [
        sequence(oid('550403'), der(0x0c, Buffer.from('NestPOS Local Core'))),
    ]));
    const serial = crypto.randomBytes(16);
    serial[0] |= 1; // nonzero serial, canonical DER INTEGER encoding
    const now = Date.now();
    const extension = (id, critical, value) => sequence(
        oid(id), ...(critical ? [bool(true)] : []), octet(value));
    const extensions = der(0xa3, [
        sequence(
            extension('551d13', true, sequence()), // basicConstraints: CA=false
            extension('551d0f', true, bitString(Buffer.from([0xa0]), 5)),
            extension('551d25', false, sequence(oid('2b06010505070301'))), // serverAuth
        ),
    ]);
    const tbs = sequence(
        der(0xa0, [integer(Buffer.from([2]))]), // X.509 v3
        integer(serial), signatureAlgorithm, name,
        sequence(time(new Date(now - 5 * 60 * 1000)),
            time(new Date(now + 10 * 365 * 24 * 60 * 60 * 1000))),
        name, spki, extensions,
    );
    const signature = crypto.sign('sha256', tbs, keys.privateKey);
    const cert = pem('CERTIFICATE', sequence(tbs, signatureAlgorithm, bitString(signature)));
    const x509 = new crypto.X509Certificate(cert);
    if (!x509.verify(keys.publicKey) || !x509.checkPrivateKey(keys.privateKey)) {
        throw new Error('Generated Local Core TLS certificate is invalid');
    }
    return {
        key: keys.privateKey.export({ type: 'pkcs8', format: 'pem' }),
        cert,
    };
}
module.exports = { makeCertificate };
