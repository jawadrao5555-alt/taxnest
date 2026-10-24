import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../../resources/views/pos/universal.blade.php', import.meta.url), 'utf8');
function method(name) {
  const start = source.search(new RegExp('^        (?:async |get )?' + name + '\\(', 'm'));
  assert(start >= 0, name);
  return source.slice(start, source.indexOf('\n        },', start) + 11).trim();
}
function fixture() {
  const ui = vm.runInNewContext('({' + ['addToCart', 'toggleItemNote', 'historicalMixedPayment', 'restoreFulfillmentCart', 'hasDeliveryPortion', 'hasMixedService', '_localCoreHold', 'payModalRiderCash', 'heldOrderEstimate'].map(method).join('\n') + '})', {
    window: { TXT: {}, NestPosLocal: { heldOrder: { hold() { throw Error('must not silently print mixed service'); } } } }, setTimeout() {},
  });
  Object.assign(ui, { cart: [], typeFlowGate: true, orderType: 'dine_in', fulfillmentMode: null, animateQty() {}, scrollToCartItem() {}, showToast() {}, removeDeliveryCharge() {} });
  return ui;
}
const pizza = { id: 1, type: 'product', name: 'Fixture pizza', price: 500 };

test('ordinary product clicks create an unlabelled single-type bill without service portions', () => {
  const ui = fixture(); ui.fulfillmentMode = 'delivery'; // stale browser state cannot reactivate removed controls
  ui.addToCart(pizza); ui.addToCart(pizza);
  assert.equal(ui.cart.length, 1); assert.equal(ui.cart[0].quantity, 2);
  assert.equal(ui.cart[0].fulfillment, null); assert.equal(ui.orderType, 'dine_in');
});
test('Parcel note stays on exactly its quantity; later product clicks use a clean row', () => {
  const ui = fixture(); ui.addToCart(pizza); ui.cart[0].special_notes = 'Parcel';
  ui.addToCart(pizza); ui.addToCart(pizza);
  assert.equal(ui.cart.length, 2); assert.equal(ui.cart[0].quantity, 1);
  assert.equal(ui.cart[0].special_notes, 'Parcel'); assert.equal(ui.cart[1].quantity, 2);
  assert.equal(ui.cart[1].special_notes, '');
  assert.equal(ui.cart.reduce((n, i) => n + i.quantity * i.unit_price, 0), 1500);
});
test('new additions do not change already sent food or inherit its kitchen notes', () => {
  const ui = fixture(); ui.addToCart(pizza); const sent=ui.cart[0]; sent.kot_sent_quantity=1;
  ui.addToCart(pizza); assert.equal(ui.cart.length,2); assert.equal(sent.quantity,1);
  ui.cart[1].special_notes='Parcel'; ui.toggleItemNote(0);
  assert.equal(sent._showNote,undefined); assert.equal(sent.special_notes,'');
});
test('ordinary Delivery stays a separate bill and does not expose mixed collection choices', () => {
  const ui = fixture(); ui.orderType='delivery'; ui.addToCart(pizza);
  assert.equal(ui.cart[0].fulfillment,null); assert.equal(ui.hasDeliveryPortion(),true);
  assert.equal(ui.historicalMixedPayment(),false);
  ui.cart.push({...ui.cart[0],fulfillment:'dine_in'});
  assert.equal(ui.historicalMixedPayment(),true); // saved historical mixed record keeps settlement compatibility
});
test('recall consolidates only matching stable portions and retains their sent quantities', () => {
  const ui = fixture(); const rows = ui.restoreFulfillmentCart([
    { line_uid: 'serve', fulfillment: 'dine_in', quantity: 1, unit_price: 500, kot_printed_at: '2026-10-24' },
    { line_uid: 'serve', fulfillment: 'dine_in', quantity: 1, unit_price: 500 },
    { line_uid: 'parcel', fulfillment: 'takeaway', quantity: 1, unit_price: 500 },
  ]);
  assert.equal(rows.length, 2); assert.equal(rows[0].quantity, 2); assert.equal(rows[0].kot_sent_quantity, 1); assert.equal(rows[1].quantity, 1);
});
test('older Local Core cannot silently turn mixed kitchen instructions into one order type', async () => {
  const ui = fixture(); ui.addToCart(pizza); ui.cart.push({...ui.cart[0],fulfillment:'delivery'});
  const result = await ui._localCoreHold(); assert.equal(result.success, false); assert.equal(ui.cart.length, 2);
});

test('payment modal quotes the same discounted taxable delivery share as the server', () => {
  const ui = fixture();
  Object.assign(ui, { heldOrders: [], payingHeldOrderId: null, deliveryCollectionMode: 'rider', payMethodIndex: 0,
    taxInclusive: false, taxRules: { cash: 16 }, discountAmount: 22, payModalTotal: 227,
    getItemTotal(item) { return item.subtotal; },
    cart: [{ fulfillment: 'dine_in', subtotal: 100 }, { fulfillment: 'delivery', subtotal: 100 },
      { fulfillment: 'delivery', subtotal: 20, is_tax_exempt: true, skip_kitchen: true }] });
  assert.equal(ui.payModalRiderCash, 122.51);
  ui.deliveryCollectionMode = 'counter'; assert.equal(ui.payModalRiderCash, 0);
  ui.deliveryCollectionMode = 'rider'; ui.payMethodIndex = 1; assert.equal(ui.payModalRiderCash, 0);
});

test('recalled held bill keeps an explicit zero-percent tax when quoting counter and rider amounts', () => {
  const ui = fixture();
  const bill = { id: 42, order_type: 'dine_in', discount_amount: 0, items: [
    { fulfillment: 'dine_in', subtotal: 100 }, { fulfillment: 'takeaway', subtotal: 200 }, { fulfillment: 'delivery', subtotal: 100 }] };
  Object.assign(ui, { heldOrders: [bill], payingHeldOrderId: 42, deliveryCollectionMode: 'rider', payMethodIndex: 0,
    taxInclusive: false, taxRules: { cash: 0, card: 0 }, taxRate: 16 });
  assert.equal(ui.heldOrderEstimate('cash'), 400);
  assert.equal(ui.heldOrderEstimate('card'), 400);
  ui.payModalTotal = ui.heldOrderEstimate('cash');
  assert.equal(ui.payModalRiderCash, 100);
  assert.equal(ui.payModalTotal - ui.payModalRiderCash, 300);
});
