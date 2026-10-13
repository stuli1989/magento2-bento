// Run: node tests/js/cart-diff.test.js
'use strict';
const assert = require('assert');
const d = require('../../src/BentoTracking/view/frontend/web/js/cart-diff.js');

const cart = (items, subtotal) => ({ items, subtotalAmount: subtotal });
const item = (sku, qty, price = 500) => ({ product_sku: sku, qty, product_price_value: price, product_id: 1, product_name: sku });

let n = 0;
function t(name, fn) { fn(); n++; console.log('ok', name); }

t('identical carts → null', () => {
    assert.strictEqual(d.diff(d.snapshot(cart([item('A', 1)])), d.snapshot(cart([item('A', 1)]))), null);
});
t('new sku → added', () => {
    assert.strictEqual(d.diff(d.snapshot(cart([item('A', 1)])), d.snapshot(cart([item('A', 1), item('B', 1)]))), 'added');
});
t('sku gone → removed', () => {
    assert.strictEqual(d.diff(d.snapshot(cart([item('A', 1), item('B', 2)])), d.snapshot(cart([item('A', 1)]))), 'removed');
});
t('qty change → qty', () => {
    assert.strictEqual(d.diff(d.snapshot(cart([item('A', 1)])), d.snapshot(cart([item('A', 3)]))), 'qty');
});
t('empty → first item is added', () => {
    assert.strictEqual(d.diff({}, d.snapshot(cart([item('A', 1)]))), 'added');
});
t('emptied cart → removed', () => {
    assert.strictEqual(d.diff(d.snapshot(cart([item('A', 1)])), d.snapshot(cart([]))), 'removed');
});
t('rupee display matches server', () => {
    assert.strictEqual(d.rupees(1488.6), '₹1,488.60');
    assert.strictEqual(d.rupees(0.5), '₹0.50');
    assert.strictEqual(d.rupees(100000), '₹1,00,000.00');
    assert.strictEqual(d.rupees(12345678.9), '₹1,23,45,678.90');
});
t('payload paise + display + counts', () => {
    const p = d.payload(cart([item('A', 2, 500), item('B', 1, 488.6)], '1488.60'), 'added');
    assert.strictEqual(p.subtotal, 148860);
    assert.strictEqual(p.subtotal_display, '₹1,488.60');
    assert.strictEqual(p.item_count, 3);
    assert.strictEqual(p.cart.items[1].product_price, 48860);
    assert.strictEqual(p.cart.items[1].product_price_display, '₹488.60');
    assert.strictEqual(p.change, 'added');
});
console.log(`${n} passed`);
