'use strict';
/* 22-page browser smoke, run only in isolated CI MySQL + local PHP server.
   Exercises desktop/mobile layouts and a legacy invoice service modal. */
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const pages = [
  'dashboard','factors','purchase-invoice','sales-return','product-categories',
  'goods-and-services','defective-goods','customers','suppliers','report-inventory',
  'report-customer-debt','report-supplier-debt','report-sold-items-detail',
  'settings','notes','current-affairs','about-us','payment-forms',
  'payment-transactions','payment-sms-settings','payment-zarinpal-settings','account'
];
const base = process.env.UI_TEST_BASE || 'http://127.0.0.1:8872';
const cookie = process.env.UI_TEST_SESSION || 'ci-tabler-browser-123456789';
(async () => {
  const browser = await chromium.launch({ headless: true });
  let checked = 0;
  try {
    for (const [width,height] of [[1440,900],[390,844],[768,1024]]) {
      const context = await browser.newContext({ viewport: {width,height}, locale:'fa-IR' });
      await context.addCookies([{
        name:'PHPSESSID', value:cookie, domain:'127.0.0.1', path:'/',
        httpOnly:true, sameSite:'Lax'
      }]);
      const page = await context.newPage();
      const errors = [];
      page.on('pageerror', e => errors.push(e.message));
      for (const id of pages) {
        const response = await page.goto(base + '/index.php?page=' + id, {
          waitUntil: 'domcontentloaded', timeout: 30000
        });
        assert.equal(response.status(), 200, id + ' status at ' + width);
        assert.equal(await page.locator('html').getAttribute('dir'), 'rtl', id + ' RTL');
        assert.equal(await page.locator('body').getAttribute('id'), id, id + ' route');
        // About page intentionally hides the global page-header in its legacy design.
        if (id !== 'about-us') {
          assert.equal(await page.locator('.page-header h1').isVisible(), true, id + ' heading');
        } else {
          assert.ok(await page.locator('.about-page h1').count() > 0, 'about page content');
        }
        const overflow = await page.evaluate(() => ({
          doc: document.documentElement.scrollWidth,
          win: document.documentElement.clientWidth
        }));
        assert.ok(overflow.doc <= overflow.win + 3,
          id + ' horizontal page overflow (' + width + '): ' + JSON.stringify(overflow));
        if (id === 'notes' && width <= 1024) {
          const display = await page.locator('#notesBoard').evaluate(el => getComputedStyle(el).display);
          assert.equal(display, 'grid', 'notes use responsive layout');
        }
        if (id === 'dashboard') {
          assert.equal(await page.locator('.quick-action-card[href]').count(), 4);
        }
        if (width <= 1024 && id === 'dashboard') {
          await page.locator('#sidebarOpen').click();
          assert.equal(await page.locator('body.sidebar-open').count(), 1, 'drawer opened');
          await page.locator('#sidebarClose').waitFor({state:'visible', timeout:3000});
          assert.equal(await page.locator('#sidebarClose').isVisible(), true, 'drawer close visible');
          await page.keyboard.press('Escape');
          assert.equal(await page.locator('body.sidebar-open').count(), 0, 'Escape closes drawer');
        }
        if (id === 'factors') {
          await page.locator('.invoice-new[data-type="sales_invoice"]').click();
          await page.locator('#invAddService').click();
          assert.equal(await page.locator('#invoiceServiceModal.open').count(), 1,
            'legacy service modal still opens');
          await page.locator('#invoiceServiceModal [data-close]').last().click();
          assert.equal(await page.locator('#invoiceServiceModal.open').count(), 0,
            'legacy service modal still closes');
        }
        assert.equal(errors.length, 0, id + ' JS errors at ' + width + ': ' + errors.join(' | '));
        checked++;
        console.log('OK ' + id + ' at ' + width + 'px');
      }
      await context.close();
    }
    console.log('PASS ' + checked + ' responsive page/browser cases');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error.stack || error); process.exit(1); });
