// Run: node tests/js/bento-uuid.test.js
'use strict';
const assert = require('assert');
const u = require('../../src/BentoTracking/view/frontend/web/js/bento-uuid.js');

let n = 0;
function t(name, fn) { fn(); n++; console.log('ok', name); }

t('reads bento_uuid from the query string', () => {
    assert.strictEqual(u.fromSearch('?utm_source=bento&bento_uuid=9b2f0c1e-4a5b-4c3d-8e9f-0a1b2c3d4e5f'), '9b2f0c1e-4a5b-4c3d-8e9f-0a1b2c3d4e5f');
    assert.strictEqual(u.fromSearch('?bento_uuid=abcdef12&x=1'), 'abcdef12');
});
t('missing or junk → null', () => {
    assert.strictEqual(u.fromSearch(''), null);
    assert.strictEqual(u.fromSearch('?utm_source=bento'), null);
    assert.strictEqual(u.fromSearch('?bento_uuid=abc'), null);
    assert.strictEqual(u.fromSearch('?bento_uuid=%3Cscript%3E'), null);
    assert.strictEqual(u.fromSearch('?bento_uuid=%E0%A4%A'), null, 'bad percent-encoding never throws');
    assert.strictEqual(u.fromSearch('?xbento_uuid=abcdef12'), null);
});
t('cookie string matches the server-side name, lasts 90 days, first-party Lax', () => {
    assert.strictEqual(u.COOKIE, 'al_bento_uuid');
    assert.strictEqual(u.cookieString('abcdef12', true), 'al_bento_uuid=abcdef12; Max-Age=7776000; Path=/; SameSite=Lax; Secure');
    assert.strictEqual(u.cookieString('abcdef12', false), 'al_bento_uuid=abcdef12; Max-Age=7776000; Path=/; SameSite=Lax');
});
t('init writes the cookie only when a uuid is present', () => {
    const doc = { cookie: '' };
    u.run({ search: '?bento_uuid=abcdef12', protocol: 'https:' }, doc);
    assert.strictEqual(doc.cookie, 'al_bento_uuid=abcdef12; Max-Age=7776000; Path=/; SameSite=Lax; Secure');
    const doc2 = { cookie: '' };
    u.run({ search: '?q=paint', protocol: 'https:' }, doc2);
    assert.strictEqual(doc2.cookie, '');
});
t('module is callable as the RequireJS init (phtml calls module(config))', () => {
    assert.strictEqual(typeof u, 'function');
});

console.log(n + ' passed');
