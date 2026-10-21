const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const src = fs.readFileSync(__dirname + '/currency.js', 'utf8');
for (const code of ['TOMAN','IRR']) {
  const context = { window: {APP_CURRENCY:code} };
  vm.runInNewContext(src, context);
  const C=context.window.Currency;
  assert.equal(C.format('10000'),code==='IRR'?'100,000 ریال':'10,000 تومان');
  assert.equal(C.toBase(C.fromBase('10000')),'10000');
  assert.equal(C.toBase(C.fromBase('0.01')),'0.01');
  assert.equal(C.format('-5000'),code==='IRR'?'-50,000 ریال':'-5,000 تومان');
  assert.equal(C.fromBase('999999999999.99'),code==='IRR'?'9999999999999.9':'999999999999.99');
}
// Currency is presentation-only: repeated toggles never mutate the raw base value.
for (const input of ['0', '-5000', '0.01', '10000', '4465000', '999999999999.99']) {
  const a = {window:{APP_CURRENCY:'IRR'}};
  vm.runInNewContext(src, a);
  const b = {window:{APP_CURRENCY:'TOMAN'}};
  vm.runInNewContext(src, b);
  assert.equal(a.window.Currency.toBase(a.window.Currency.fromBase(input)), input);
  assert.equal(b.window.Currency.toBase(b.window.Currency.fromBase(input)), input);
}
const context={window:{APP_CURRENCY:'IRR'}};
vm.runInNewContext(src, context);
for (const bad of ['NaN', '1,23', '', '1e30', '12.34567']) {
  assert.throws(()=>context.window.Currency.toBase(bad));
}
assert.equal(context.window.Currency.toBase('۱٬۰۰۰٬۰۰۰'), '100000');
assert.equal(context.window.Currency.format('-5000'), '-50,000 ریال');
console.log('PASS 29 JS assertions');
