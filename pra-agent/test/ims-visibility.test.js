'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { imsMode } = require('../src/ims-visibility');

const connected = (product_type, fields = {}) => ({
  running: true, connected: true, serverInfo: { product_type, ...fields },
});

test('FBR company sees IMS preparation in both fiscal and cloud mode; PRA direct stays hidden', () => {
  assert.equal(imsMode(connected('fbrpos', { fbr_connection_mode: 'fiscal_device' })), 'fbr');
  assert.equal(imsMode(connected('fbrpos', { fbr_connection_mode: 'cloud' })), 'fbr');
  assert.equal(imsMode(connected('pos', { pra_connection_mode: 'cloud' })), null);
});

test('PRA fiscal device sees PRA IMS, never FBR installer; unknown or disconnected stays hidden', () => {
  assert.equal(imsMode(connected('pos', { pra_connection_mode: 'fiscal_device' })), 'pra');
  assert.equal(imsMode(connected('other', { fbr_connection_mode: 'fiscal_device' })), null);
  assert.equal(imsMode({ ...connected('fbrpos', { fbr_connection_mode: 'fiscal_device' }), connected: false }), null);
  assert.equal(imsMode({ running: true, connected: true, serverInfo: { name: 'legacy Agent response' } }), null);
});

test('FBR identity without a mode still exposes preparation, never inferred from a port or company ID', () => {
  assert.equal(imsMode(connected('fbrpos')), 'fbr');
  assert.equal(imsMode({running:true, connected:true, serverInfo:{id:101}}), null);
  assert.equal(imsMode({...connected('fbrpos'), running:false}), null);
});
