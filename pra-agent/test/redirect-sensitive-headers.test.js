'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const vm = require('node:vm');
const redirects = require(process.env.TAXNEST_REDIRECT_TEST_PACKAGE || 'follow-redirects');
const listen = server => new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const close = server => new Promise(resolve => server.close(resolve));

test('cross-realm custom credentials are removed before a cross-origin redirect', async () => {
  let seen;
  const destination = http.createServer((req, res) => { seen = req.headers; res.end('fixture'); });
  const source = http.createServer((req, res) => {
    res.writeHead(302, { Location: `http://127.0.0.1:${destination.address().port}/destination` }); res.end();
  });
  await listen(destination); await listen(source);
  try {
    for (const sensitiveHeaders of [['X-API-Key'], vm.runInNewContext("['X-API-Key']")]) {
      await new Promise((resolve, reject) => {
        const req = redirects.http.get({ protocol: 'http:', hostname: '127.0.0.1', port: source.address().port, path: '/source',
          headers: { 'X-API-Key': 'fictional-key', 'X-Harmless': 'keep' }, sensitiveHeaders },
        res => { res.resume(); res.on('end', resolve); });
        req.on('error', reject); req.setTimeout(3000, () => req.destroy(new Error('fixture timeout')));
      });
      assert.equal(seen['x-api-key'], undefined);
      assert.equal(seen['x-harmless'], 'keep');
    }
  } finally { await close(source); await close(destination); }
});
