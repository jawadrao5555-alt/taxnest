# NestPOS Print Bridge
Chrome/Edge Manifest V3 extension for the existing Hotel receipt popup. This is an optional authenticated queue bridge, not a standalone Windows printer driver.

## Install
Download this folder, open chrome://extensions (or edge://extensions), turn on Developer mode, choose Load unpacked, and select this folder. Refresh the TaxNest Hotel page. No API key, printer credential, native helper, or additional permission is requested.

## Routing
- Healthy scoped Agent: the normal Agent queue is preferred, even when this extension is installed.
- Extension present and Agent not reported healthy: the bridge submits the same scoped, CSRF-protected queue request; the server determines whether printing is possible. It never bypasses the selected counter.
- No Agent: silent printing cannot happen; an explicit server rejection allows normal browser Print.
- Lost/uncertain response: retain the original UUID, do not submit through another transport or automatically print a second copy.
- A4 uses normal browser Print. Saved paper/printer preferences remain authoritative.

The extension does not submit PRA invoices, refund payments, accept arbitrary HTML or printer commands, access other sites, or store credentials. It uses the existing tenant-scoped Hotel job endpoint and durable deduplication.

Supported site origins are exactly https://taxnest.pk and https://www.taxnest.pk; frames are excluded. Installation alone is not proof of an available Agent or a physical printed receipt.

Official platform references: https://developer.chrome.com/docs/extensions/reference/api/printing (ChromeOS), https://developer.chrome.com/docs/extensions/develop/concepts/native-messaging (native desktop companions).
