const http = require('http');
const https = require('https');

const server = http.createServer((req, res) => {
    res.setHeader('Content-Type', 'application/json');
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', 'POST, GET, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Relay-Token');

    if (req.method === 'OPTIONS') { res.writeHead(200); res.end(); return; }
    if (req.method === 'GET') { res.end(JSON.stringify({status:'ok',time:new Date().toISOString()})); return; }
    if (req.method !== 'POST') { res.writeHead(405); res.end(JSON.stringify({error:'Only POST'})); return; }

    // Token comes from the environment — NEVER hardcode it here (this file lives in a public repo).
    const RELAY_TOKEN = process.env.PRA_RELAY_TOKEN || '';
    const token = req.headers['x-relay-token'] || '';
    if (!RELAY_TOKEN || token !== RELAY_TOKEN) { res.writeHead(403); res.end(JSON.stringify({error:'Invalid token'})); return; }

    let body = '';
    req.on('data', chunk => body += chunk);
    req.on('end', () => {
        let data;
        try { data = JSON.parse(body); } catch(e) { res.writeHead(400); res.end(JSON.stringify({error:'Invalid JSON'})); return; }

        const praToken = data._pra_token || '';
        const praUrl = data._pra_url || 'https://ims.pral.com.pk/ims/production/api/Live/PostData';
        delete data._pra_token;
        delete data._pra_url;

        const parsed = new URL(praUrl);
        const payload = JSON.stringify(data);

        console.log(`[${new Date().toISOString()}] Relaying to ${praUrl} (${payload.length} bytes)`);

        const options = {
            hostname: parsed.hostname,
            port: 443,
            path: parsed.pathname,
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': 'Bearer ' + praToken, 'Content-Length': Buffer.byteLength(payload) },
            rejectUnauthorized: false,
            timeout: 30000
        };

        const praReq = https.request(options, praRes => {
            let result = '';
            praRes.on('data', chunk => result += chunk);
            praRes.on('end', () => {
                console.log(`[${new Date().toISOString()}] PRA Response: HTTP ${praRes.statusCode}`);
                res.writeHead(praRes.statusCode || 200);
                res.end(result);
            });
        });

        praReq.on('error', err => {
            console.error(`[${new Date().toISOString()}] PRA Error: ${err.message}`);
            res.writeHead(502);
            res.end(JSON.stringify({relay_error:1, error: err.message}));
        });

        praReq.on('timeout', () => {
            praReq.destroy();
            res.writeHead(502);
            res.end(JSON.stringify({relay_error:1, error:'Connection timed out'}));
        });

        praReq.write(payload);
        praReq.end();
    });
});

server.listen(8080, () => console.log('PRA Relay running on http://localhost:8080'));
