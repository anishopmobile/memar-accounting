'use strict';
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const base = 'http://127.0.0.1:8872';
const cookie = {name:'PHPSESSID',value:'ci-tabler-browser-123456789',domain:'127.0.0.1',path:'/',sameSite:'Lax',httpOnly:true};
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAV4BAX6b7A8AAAAASUVORK5CYII=','base64');
(async function () {
  const browser = await chromium.launch({headless:true});
  try {
    const context = await browser.newContext({viewport:{width:1440,height:900},locale:'fa-IR'});
    await context.addCookies([cookie]);
    const page = await context.newPage();
    await page.goto(base+'/index.php?page=goods-and-services');
    assert.equal(await page.locator('#productCount').count(),0,'product count removed from toolbar');
    const toolbar = await page.locator('#productCategoryFilter').evaluate(el=>({
      width:el.getBoundingClientRect().width, max:getComputedStyle(el).maxWidth
    }));
    assert.ok(toolbar.width<=201,'category filter must not exceed 200px: '+JSON.stringify(toolbar));
    assert.equal(await page.locator('#goods-and-services .filter-panel > :last-child').getAttribute('class'),
      'inventory-value-last','inventory worth should be last');
    assert.equal(await page.locator('#inventoryValue').count(),1,'inventory worth remains');
    const row = page.locator('tr.product-row[data-barcode="CI128ABC123"]').first();
    await row.waitFor({state:'visible',timeout:12000});
    const id = await row.getAttribute('data-id');
    assert.ok(/^\d+$/.test(id),'fixture product ID');
    await row.locator('.print-barcode').click();
    assert.equal(await page.locator('#barcodePrintModal.open').count(),1);
    assert.equal(await page.locator('#barcodePrintQty').inputValue(),'7','stock-based initial qty');
    assert.equal(await page.locator('#barcodePrintArea .barcode-label').count(),7);
    const qty = page.locator('#barcodePrintQty');
    await qty.fill('3');
    assert.equal(await page.locator('#barcodePrintArea .barcode-label').count(),3);
    assert.ok((await page.locator('#downloadBarcodePdfBtn').getAttribute('href')).includes('qty=3&mode=download'));
    await page.evaluate(()=> {
      window.__barcodeUrl = '';
      window.open = function(url,target){window.__barcodeUrl = url+'|'+target;return {};};
    });
    await page.locator('#printBarcodeBtn').click();
    const printUrl = await page.evaluate(()=>window.__barcodeUrl);
    assert.ok(printUrl.includes('mode=print|_blank'),'clean print document opened from click');
    const printed = await page.request.get(base+'/assets/php/product_barcode_print.php?id='+id+'&qty=3&mode=print');
    assert.equal(printed.status(),200);
    const html = await printed.text();
    assert.equal((html.match(/class="barcode-document-label"/g)||[]).length,3);
    assert.ok(html.includes('<table class="barcode-document-table"'));
    assert.ok(html.includes('@page { size: A4'));
    assert.ok(!html.includes('app-shell')&&!html.includes('modal-content'),
      'print view contains no hidden dashboard or modal to generate blank pages');
    for (const count of [1,3,27]) {
      const pdf = await page.request.get(base+'/assets/php/product_barcode_print.php?id='+id+'&qty='+count+'&mode=download',
        {timeout:90000});
      const bytes = await pdf.body();
      assert.equal(pdf.status(),200,'pdf qty '+count+' status '+bytes.toString('utf8',0,250));
      assert.ok(bytes.subarray(0,5).equals(Buffer.from('%PDF-')),'mPDF output PDF header');
      assert.ok(bytes.length > 2500,'nonempty generated barcode document '+count);
      assert.ok((pdf.headers()['content-disposition']||'').includes('attachment'),'download rather than inline');
      console.log('PASS mPDF barcode PDF '+count+' label(s), '+bytes.length+' bytes');
    }
    for (const bad of ['0','1001','abc','-4','2.5']) {
      const response = await page.request.get(base+'/assets/php/product_barcode_print.php?id='+id+'&qty='+encodeURIComponent(bad)+'&mode=download');
      assert.equal(response.status(),400,'invalid label count '+bad);
    }
    assert.equal((await page.request.get(base+'/assets/php/product_barcode_print.php?id=999999999&qty=1&mode=download')).status(),404);
    const other = await browser.newContext();
    const denied = await other.request.get(base+'/assets/php/product_barcode_print.php?id='+id+'&qty=1&mode=download');
    assert.ok(denied.url().includes('login.php'),'barcode requires authentication');
    await other.close();
    console.log('PASS print isolation, download and authenticated validation');
    await page.goto(base+'/index.php?page=settings');
    await page.locator('#brandSettingsForm').waitFor();
    const original = (await (await page.request.get(base+'/assets/php/store_settings.php')).json()).data.settings || {};
    let csrf = await page.locator('#brandSettingsForm input[name="csrf_token"]').inputValue();
    const blocked = await page.request.post(base+'/assets/php/app_brand_settings.php',
      {form:{display_mode:'logo',csrf_token:'definitely-invalid'}});
    assert.equal(blocked.status(),403,'brand save CSRF gate');
    const invalid = await page.request.post(base+'/assets/php/app_brand_settings.php',
      {form:{display_mode:'script',csrf_token:csrf}});
    assert.equal(invalid.status(),422,'brand-mode allowlist');

    async function saveMode(mode, upload) {
      await page.locator('#brandSettingsForm input[name="display_mode"][value="'+mode+'"]').check();
      if (upload) {
        await page.locator('#brandLogoImage').setInputFiles({
          name:'test-logo.png',mimeType:'image/png',buffer:png
        });
      }
      const responses = page.waitForResponse(r=>r.url().endsWith('/assets/php/app_brand_settings.php')
        && r.request().method()==='POST', {timeout:14000});
      await page.locator('#brandSettingsSave').click();
      const resp = await responses;
      const body = await resp.json();
      assert.equal(resp.status(),200,'brand save status '+JSON.stringify(body));
      assert.equal(body.success,true,'brand save '+JSON.stringify(body));
      await page.waitForLoadState('load');
      await page.reload({waitUntil:'domcontentloaded'});
      const state = await (await page.request.get(base+'/assets/php/app_brand_settings.php')).json();
      assert.equal(state.data.display_mode,mode);
      return state.data;
    }
    const stacked = await saveMode('logo_name',true);
    assert.ok(/^assets\/uploads\/brand_[0-9a-f]{32}\.png$/.test(stacked.logo_path));
    assert.equal(await page.locator('.brand-panel.brand-mode-logo_name .brand-logo').count(),1);
    assert.equal(await page.locator('.brand-panel .brand-title').textContent(),'حسابداری معمار');
    await saveMode('logo',false);
    assert.equal(await page.locator('.brand-panel.brand-mode-logo .brand-logo').count(),1);
    assert.equal(await page.locator('.brand-panel .brand-title').count(),0);
    await saveMode('name',false);
    assert.equal(await page.locator('.brand-panel.brand-mode-name .brand-title').count(),1);
    assert.equal(await page.locator('.brand-panel .brand-logo').count(),0);
    const after = (await (await page.request.get(base+'/assets/php/store_settings.php')).json()).data.settings || {};
    assert.equal(after.logo_path||'',original.logo_path||'','invoice/store logo not touched');
    console.log('PASS immutable app name, 3 navbar display modes, safe uploaded logo, store logo isolation');

    for (const width of [1440,768,390]) {
      await page.setViewportSize({width,height:850});
      await page.goto(base+'/index.php?page=defective-goods');
      const cards = await page.locator('#defRegCard, #defReportCard').count();
      assert.equal(cards,2,'defective card form and report intact');
      const reg = await page.locator('#defRegCard .form-grid').evaluate(el=>{
        const r=el.getBoundingClientRect();
        return {display:getComputedStyle(el).display,columns:getComputedStyle(el).gridTemplateColumns.split(' ').length,width:r.width};
      });
      assert.equal(reg.display,'grid');
      assert.equal(reg.columns,12,'12-column responsive form');
      const overflow = await page.evaluate(()=>document.documentElement.scrollWidth-document.documentElement.clientWidth);
      assert.ok(overflow<=3,'defective layout avoids page horizontal overflow at '+width);
      assert.equal(await page.locator('#defSubmit').isVisible(),true);
      assert.equal(await page.locator('#defReportTable').count(),1);
      console.log('PASS defective-goods '+width+'px');
    }
    await context.close();
  } finally { await browser.close(); }
})().catch(e=>{console.error(e.stack||e);process.exit(1)});
