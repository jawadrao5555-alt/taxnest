'use strict';
// A separate namespace: never reuse PRA invoice IDs, callbacks or endpoint settings.
const fs = require('fs');
const path = require('path');
const os = require('os');
const crypto = require('crypto');
const soap = require('./ims-soap');
function localEndpoint(value) {
  try {
    const u = new URL(value);
    return u.protocol === 'http:' && ['localhost', '127.0.0.1'].includes(u.hostname) &&
      /^\d{4,5}$/.test(u.port) && Number(u.port) <= 65535 &&
      u.pathname === '/api/IMSFiscal/GetInvoiceNumberByModel' && !u.username && !u.password && !u.search && !u.hash;
  } catch (_) { return false; }
}
const {resultOnly,describeResponse}=require('./ims-result');
function createBridge({ axios, directory = path.join(os.homedir(), '.taxnest-pra-agent'), log = () => {} }) {
  let busy = false;
  return async function sync(config) {
    if (busy || !config?.deviceUid || !config?.serverUrl || !config?.apiKey) return;
    busy = true;
    try {
      const scope = crypto.createHash('sha256').update(`${config.serverUrl}\n${config.apiKey}\n${config.deviceUid}`).digest('hex');
      const filename = path.join(directory, `pra-ims-result-${scope}.json`);
      const options = { headers: { Authorization: `Bearer ${config.apiKey}` }, timeout: 15000, maxRedirects: 0 };
      const send = async body => axios.post(`${config.serverUrl}/pra-fbr-ims/result`, body, options);
      if (fs.existsSync(filename)) {
        let pending;
        try { pending = JSON.parse(fs.readFileSync(filename, 'utf8')); }
        catch (_) { log('FBR alongside PRA: result file needs review; dispatch stopped.'); return; }
        // Finish the same callback before claiming any other invoice. Never repeat fiscal POST.
        try { await send(pending); fs.unlinkSync(filename); } catch (_) { return; }
      }
      let data;
      try { data = (await axios.post(`${config.serverUrl}/pra-fbr-ims/claim`, { device_uid: config.deviceUid, supported_protocols: ['json','soap'] }, options)).data; }
      catch (_) { return; } // absent/disabled on old servers is normal
      const invoice = data?.invoice;
      if (!invoice) return;
      if (!Number.isSafeInteger(invoice.id) || !/^[a-f0-9]{64}$/.test(invoice.claim || '')) return;
      const body = { id: invoice.id, claim: invoice.claim, device_uid: config.deviceUid, response: null, diagnostic: 'invalid_endpoint' };
      if (localEndpoint(invoice.endpoint) || soap.endpoint(invoice.endpoint)) {
        try {
          const res = soap.endpoint(invoice.endpoint) ? await soap.submit(axios, invoice.endpoint, invoice.payload) : await axios.post(invoice.endpoint, invoice.payload, { timeout: 45000, maxRedirects: 0, headers: { 'Content-Type': 'application/json' } });
          body.response_fields = describeResponse(res.data);
          body.response = resultOnly(res.data, { includeCentral: true });
          body.diagnostic = body.response ? 'response_received' : 'unrecognized_response';
        } catch (error) {
          // Enum only: never persist raw response XML, headers, URLs or credentials.
          body.diagnostic = ['ECONNABORTED','ETIMEDOUT'].includes(error?.code) ? 'transport_timeout'
            : error?.response ? 'http_error' : error instanceof SyntaxError ? 'invalid_json_result'
            : 'unconfirmed_soap_or_transport';
        }
      }
      // Persist only the scoped result (not the source invoice, token or raw response).
      fs.mkdirSync(directory, { recursive: true });
      const tmp = `${filename}.tmp`;
      fs.writeFileSync(tmp, JSON.stringify(body), { mode: 0o600 });
      fs.renameSync(tmp, filename);
      try { await send(body); fs.unlinkSync(filename); }
      catch (_) { log('FBR alongside PRA: fiscal result saved; only the callback will retry.'); }
    } catch (_) { log('FBR alongside PRA: dispatch requires review.'); }
    finally { busy = false; }
  };
}
module.exports = { createBridge, localEndpoint, resultOnly };
