'use strict';
function posBrowserUrl(serverUrl) {
  const configured = new URL(serverUrl);
  if (!['https:', 'http:'].includes(configured.protocol)) throw new Error('Unsupported POS server URL');
  // Open only the configured origin's fixed POS entry, never credentials,
  // paths, fragments or query parameters supplied by the settings renderer.
  return configured.origin + '/pos/invoice/create';
}
module.exports = { posBrowserUrl };
