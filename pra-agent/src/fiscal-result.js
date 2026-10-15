'use strict';

function fiscalNumber(value) {
  if (typeof value !== 'string') return null;
  const number = value.trim();
  return /^[A-Za-z0-9-]{6,100}$/.test(number) && /[0-9]/.test(number) ? number : null;
}

function parseFiscalResult(data) {
  if (!data || typeof data !== 'object' || Array.isArray(data)) return { accepted: false, number: null };
  const keys = ['InvoiceNumber', 'FBRInvoiceNumber', 'invoiceNumber'];
  const supplied = keys.filter(key => data[key] != null && String(data[key]).trim() !== '');
  const numbers = supplied.map(key => fiscalNumber(data[key]));
  // Some older IMS builds return the number in Response. Only an identifier,
  // never prose, is compatible with that legacy contract.
  const number = supplied.length
    ? (numbers.every(value => value && value === numbers[0]) ? numbers[0] : null)
    : (typeof data.Response === 'string' && /^(?:[0-9]{6}|[A-Z][A-Z-]*-[0-9]{3,}$)/.test(data.Response.trim()) ? fiscalNumber(data.Response) : null);
  return { accepted: String(data.Code ?? data.code ?? '') === '100' && !!number, number };
}

module.exports = { fiscalNumber, parseFiscalResult };
