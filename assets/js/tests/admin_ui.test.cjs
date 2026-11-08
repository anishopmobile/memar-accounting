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
    for (const [width,height] of [[1440,900],[1280,800],[1100,850],[1025,768],[1024,768],[768,1024],[390,844],[360,800]]) {
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
        if (width > 1024) {
          const navBox = await page.locator('#appSidebar').boundingBox();
          const contentBox = await page.locator('.main-content').boundingBox();
          assert.ok(navBox.width >= width * .95 && navBox.height < 105,
            id + ' one-row full-width navbar: ' + JSON.stringify(navBox));
          const navRows = await page.locator('#appSidebar').evaluate(el => {
            const parts = ['.brand-panel','.main-nav','.user-chip','.logout-form'];
            return parts.map(sel=>{
              const r = el.querySelector(sel).getBoundingClientRect();
              return {name:sel,mid:r.top+r.height/2,left:r.left,right:r.right};
            });
          });
          const basemid = navRows[0].mid;
          assert.ok(navRows.every(row=>Math.abs(row.mid-basemid)<24),
            id+' brand/menu/account/logout same navbar row: '+JSON.stringify(navRows));
          const navFlow = await page.locator('#appSidebar .main-nav').evaluate(el=>getComputedStyle(el).flexWrap);
          assert.equal(navFlow,'nowrap',id+' prevent navbar wrapping');
          assert.equal(await page.locator('#appSidebar .main-nav > a[href="?page=notes"]').count(),0);
          assert.equal(await page.locator('#appSidebar .main-nav > a[href="?page=defective-goods"]').count(),0);
          assert.equal(await page.locator('#appSidebar .main-nav > .nav-dropdown').filter({
            has:page.locator('a[href="?page=payment-forms"]')
          }).count(),1,'settings owns payment menu');
          assert.ok(contentBox.width >= width * .92,
            id + ' wide content area');
          if (id === 'dashboard') {
            const item = page.locator('.nav-dropdown').filter({
              has: page.locator('.nav-sub-link[href="?page=purchase-invoice"]')
            });
            await item.locator('.nav-dropdown-toggle').click();
            assert.equal(await item.locator('.nav-dropdown-menu').isVisible(), true,
              'top navbar dropdown must open without hiding submenu');
            await item.locator('.nav-dropdown-toggle').click();
            assert.equal(await item.locator('.nav-dropdown-menu').isVisible(), false,
              'top navbar dropdown must close');
            const settings = page.locator('.nav-dropdown').filter({
              has:page.locator('.nav-sub-link[href="?page=notes"]')
            });
            await settings.locator('.nav-dropdown-toggle').click();
            assert.equal(await settings.locator('.nav-sub-link[href="?page=notes"]').isVisible(),
              true,'notes under Settings');
            await settings.locator('.nav-subgroup summary').click();
            assert.equal(await settings.locator('.nav-sub-link[href="?page=payment-forms"]').isVisible(),
              true,'payment forms nested under Settings');
            await settings.locator('.nav-subgroup summary').click();
            await settings.locator('.nav-dropdown-toggle').click();
          }
        }
        const bars = await page.locator('.page-actions:visible').evaluateAll(items =>
          items.map(el => ({
            height: el.getBoundingClientRect().height,
            direction: getComputedStyle(el).direction
          })));
        for (const bar of bars) {
          assert.equal(bar.direction, 'rtl', id + ' toolbar direction');
          assert.ok(bar.height < 150, id + ' toolbar maximum two rows');
        }
        const actionRows = await page.locator('.page-actions:visible > .toolbar-actions')
          .evaluateAll(items => items.map(el => ({
            height: el.getBoundingClientRect().height,
            direction: getComputedStyle(el).direction
          })));
        for (const group of actionRows) {
          assert.ok(group.height < 60, id + ' actions must stay in one horizontal row');
          assert.equal(group.direction, 'rtl', id + ' actions group right-to-left');
        }
        const filters = await page.locator('.filter-panel:visible').evaluateAll(items =>
          items.map(el => ({
            direction: getComputedStyle(el).direction,
            wrap: getComputedStyle(el).flexWrap
          })));
        for (const filter of filters) {
          assert.equal(filter.direction, 'rtl', id + ' filter RTL');
          assert.equal(filter.wrap, 'nowrap', id + ' filter one row');
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
        if (id === 'notes') {
          // CRUD must continue working with both absolute desktop coordinates and mobile grid.
          await page.locator('#createNoteButton').click();
          const note = page.locator('#notesBoard .sticky-note').last();
          await note.waitFor({state:'visible',timeout:5000});
          await note.locator('.sticky-note-title').fill('آزمون رابط فارسی');
          await note.locator('.sticky-note-content').fill('متن آزمایشی یادداشت');
          await note.locator('.sticky-note-save').click();
          await note.locator('.sticky-note-status.success').waitFor({state:'visible',timeout:5000});
          page.once('dialog', dialog => dialog.accept());
          await note.locator('.sticky-note-delete').click();
          await note.waitFor({state:'detached',timeout:5000});
        }
        if (id === 'dashboard') {
          assert.equal(await page.locator('.quick-action-card[href]').count(), 4);
          assert.equal(await page.locator('.metric-card-new').count(), 4);
          const dashboard = await page.evaluate(() => {
            const boxes = selector => Array.from(document.querySelectorAll(selector))
              .map(el=>el.getBoundingClientRect());
            return {
              quick:boxes('.quick-actions > .quick-action-card'),
              metric:boxes('.metrics-grid > .metric-card-new'),
              panels:boxes('.dashboard-insights > .panel'),
              stock:boxes('.dashboard-low-stock-row > .panel')
            };
          });
          assert.equal(dashboard.panels.length,2,'chart and last invoices share one wrapper');
          assert.equal(dashboard.stock.length,1,'one full-width low-stock panel');
          assert.ok(dashboard.stock[0].top >= dashboard.panels[0].bottom - 3,
            'low stock panel renders beneath insights');
          if (width > 1024) {
            assert.ok(dashboard.quick.every(b=>Math.abs(b.top-dashboard.quick[0].top)<3),
              'four quick actions share one row');
            assert.ok(dashboard.metric.every(b=>Math.abs(b.top-dashboard.metric[0].top)<3),
              'four financial metrics share one row');
            assert.ok(Math.abs(dashboard.panels[0].top-dashboard.panels[1].top)<3,
              'chart alongside latest invoices');
          }
        }
        if (id === 'about-us') {
          assert.equal(await page.locator('#about-product-title').textContent(),'حسابداری معمار');
          assert.equal(await page.locator('a[href="mailto:hmd.naeimi@gmail.com"]').count(),1);
          assert.equal(await page.locator('.about-contact-actions a[href^="mailto:"]').count(),1);
        }
        if (id === 'notes' || id === 'payment-forms' || id === 'payment-transactions' ||
            id === 'payment-sms-settings' || id === 'payment-zarinpal-settings') {
          assert.equal(await page.locator('.nav-dropdown').filter({
            has:page.locator('.nav-sub-link[href="?page=notes"]')
          }).count(),1,'notes and payment navigation remains under settings');
        }
        if (id === 'defective-goods') {
          assert.equal(await page.locator('.nav-dropdown').filter({
            has:page.locator('.nav-sub-link[href="?page=defective-goods"]')
          }).count(),1,'defective products remain discoverable under invoices');
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
        errors.length = 0;
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
