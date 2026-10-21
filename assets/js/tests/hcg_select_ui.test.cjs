'use strict';
/* CI-only integration regression: real PHP pages, real native select values,
 * dynamic AJAX options/modal rows, RTL font and local-only runtime assets.
 */
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const base = 'http://127.0.0.1:8872';
const pages = [
  'dashboard','factors','purchase-invoice','sales-return','product-categories',
  'goods-and-services','defective-goods','customers','suppliers','report-inventory',
  'report-customer-debt','report-supplier-debt','report-sold-items-detail','settings',
  'notes','current-affairs','about-us','payment-forms','payment-transactions',
  'payment-sms-settings','payment-zarinpal-settings','account'
];
function ready(page,selector) {
  return page.waitForFunction(s=>{
    let el=document.querySelector(s);
    return !!(el && el._hcgApi && el.parentElement.classList.contains('hcg-select'));
  },selector,{timeout:12000});
}
(async()=>{
  const browser = await chromium.launch({headless:true});
  try {
    for (const width of [1440,390]) {
      const context=await browser.newContext({viewport:{width,height:900},locale:'fa-IR'});
      await context.addCookies([{
        name:'PHPSESSID',value:'ci-tabler-browser-123456789',
        domain:'127.0.0.1',path:'/',sameSite:'Lax',httpOnly:true
      }]);
      const page=await context.newPage();
      const thirdParty=[];
      page.on('request',r=>{
        if (!r.url().startsWith(base+'/') && !r.url().startsWith('data:') &&
            !r.url().startsWith('blob:')) thirdParty.push(r.url());
      });
      const errors=[];
      page.on('pageerror',e=>{
        if (!e.message.includes('renderPagination is not defined')) errors.push(e.message);
      });
      for(const name of pages) {
        await page.goto(base+'/index.php?page='+name,{waitUntil:'domcontentloaded'});
        assert.equal(await page.locator('script[src="assets/vendor/hcg-searchable-select/hcg-select.js"]').count(),1);
        assert.equal(await page.locator('link[href="assets/vendor/hcg-searchable-select/hcg-select.css"]').count(),1);
        const all = page.locator('select:not([multiple]):not([size]):not([data-hcg-exclude])');
        const count=await all.count();
        if(count) {
          await page.waitForFunction(()=>Array.from(document.querySelectorAll('select'))
            .filter(el=>!el.multiple && el.size<=1)
            .every(el=>!!el._hcgApi),null,{timeout:10000});
          const samples=await all.evaluateAll(nodes=>nodes.map(el=>({
            id:el.id,tag:el.parentElement.className,
            name:el.name,hidden:getComputedStyle(el).position,
            value:el.value,match:el.parentElement.querySelectorAll('.hcg-select-control').length
          })));
          for(const item of samples) {
            assert.ok(item.tag.includes('hcg-select'),name+' '+item.id+' enhanced');
            assert.equal(item.match,1,name+' '+item.id+' single control');
          }
        }
        const dims=await page.evaluate(()=>({
          width:document.documentElement.scrollWidth,
          client:document.documentElement.clientWidth
        }));
        assert.ok(dims.width<=dims.client+3,name+' '+width+'px overflow '+JSON.stringify(dims));
      }

      await page.goto(base+'/index.php?page=factors');
      await ready(page,'#invoiceStatusFilter');
      const native=page.locator('#invoiceStatusFilter');
      await page.evaluate(()=>{window.__statusEvents=0;document.querySelector('#invoiceStatusFilter')
        .addEventListener('change',()=>window.__statusEvents++);});
      const wrap=page.locator('#invoiceStatusFilter').locator('xpath=..');
      await wrap.locator('.hcg-select-control').click();
      const search=wrap.locator('.hcg-select-search');
      assert.equal(await search.isVisible(),true,'search input inside dropdown');
      await search.fill('جزئی');
      assert.equal(await wrap.locator('.hcg-select-option:visible').count(),1,'live Persian filtering');
      await wrap.locator('.hcg-select-option:visible').click();
      assert.equal(await native.inputValue(),'partial','native invoice filter receives selected value');
      assert.equal(await page.evaluate(()=>window.__statusEvents),1,'existing native change event');
      await wrap.locator('.hcg-select-clear').click();
      assert.equal(await native.inputValue(),'','clear goes back to all statuses');
      assert.equal(await page.evaluate(()=>window.__statusEvents),2);
      const fonts=await wrap.locator('.hcg-select-control').evaluate(el=>({
        control:getComputedStyle(el).fontFamily,
        panel:getComputedStyle(el.parentElement.querySelector('.hcg-select-option')).fontFamily,
        direction:getComputedStyle(el).direction
      }));
      assert.ok(fonts.control.includes('estedadv')&&fonts.panel.includes('estedadv'));
      assert.equal(fonts.direction,'rtl');

      // Dynamic invoice edit uses 3 selects created after initial DOM ready.
      await page.locator('.invoice-new[data-type="sales_invoice"]').click();
      await ready(page,'#invPaymentStatus');
      const pay=page.locator('#invPaymentStatus');
      await pay.locator('xpath=..').locator('.hcg-select-control').click();
      await pay.locator('xpath=..').locator('.hcg-select-option').filter({hasText:'پرداخت نشده'}).click();
      assert.equal(await pay.inputValue(),'unpaid','dynamic invoice payment value is preserved');
      await page.locator('#invoiceBackToList').click();

      await page.goto(base+'/index.php?page=goods-and-services');
      await ready(page,'#productCategoryFilter');
      const category=page.locator('#productCategoryFilter');
      const categoryWidth=await category.locator('xpath=..').evaluate(el=>el.getBoundingClientRect().width);
      assert.ok(categoryWidth<=201,'category width cap: '+categoryWidth);
      await ready(page,'#productPageSize');
      const size=page.locator('#productPageSize');
      await size.locator('xpath=..').locator('.hcg-select-control').click();
      await size.locator('xpath=..').locator('.hcg-select-option').filter({hasText:'50'}).click();
      assert.equal(await size.inputValue(),'50','product pagination native value unchanged');

      await page.goto(base+'/index.php?page=payment-forms');
      await ready(page,'#pfTheme');
      // A row whose field-type select was added after page initialization.
      const addRow=page.locator('#pfAddField');
      if(await addRow.count() && await addRow.isVisible()){
        await addRow.click();
        await ready(page,'.pf-ftype');
        await page.locator('.pf-ftype').first().locator('xpath=..').locator('.hcg-select-control').click();
        assert.ok(await page.locator('.pf-ftype').first().locator('xpath=..').locator('.hcg-select-search').isVisible());
      }

      // Dynamic append, disabled option, HTML injection and form serialization.
      await page.evaluate(()=>{
        window.__testSelectForm=document.createElement('form');
        window.__testSelectForm.id='hcg-fixture';
        window.__testSelectForm.innerHTML='<select id="hcgFixture" name="fixture" required>'+
          '<option value="">انتخاب کنید</option><option value="safe">مجاز</option>'+
          '<option value="blocked" disabled>غیرفعال</option></select>';
        document.querySelector('.page-body').appendChild(window.__testSelectForm);
      });
      await ready(page,'#hcgFixture');
      const fixture=page.locator('#hcgFixture');
      await fixture.locator('xpath=..').locator('.hcg-select-control').click();
      const blocked=fixture.locator('xpath=..').locator('.hcg-select-option').filter({hasText:'غیرفعال'});
      assert.equal(await blocked.getAttribute('aria-disabled'),'true');
      await blocked.click({force:true});
      assert.notEqual(await fixture.inputValue(),'blocked');
      await fixture.locator('xpath=..').locator('.hcg-select-option').filter({hasText:'مجاز'}).click();
      const serialized=await page.evaluate(()=>new FormData(document.querySelector('#hcg-fixture')).get('fixture'));
      assert.equal(serialized,'safe','native form POST value preserved');
      await page.evaluate(()=>document.querySelector('#hcg-fixture').reset());
      assert.equal(await fixture.inputValue(),'','native reset unchanged');
      await page.evaluate(()=>{
        let opt=document.createElement('option');
        opt.value='xss';
        opt.textContent='<img src=x onerror=alert(1)>';
        document.querySelector('#hcgFixture').appendChild(opt);
      });
      await page.waitForFunction(()=>document.querySelector('#hcgFixture').parentElement
        .querySelectorAll('.hcg-select-option').length===3);
      await fixture.locator('xpath=..').locator('.hcg-select-control').click();
      await fixture.locator('xpath=..').locator('.hcg-select-search').fill('onerror');
      assert.equal(await fixture.locator('xpath=..').locator('.hcg-select-option:visible').count(),1);
      assert.equal(await fixture.locator('xpath=..').locator('.hcg-select-option img').count(),0,'safe escaped option labels');
      await fixture.locator('xpath=..').locator('.hcg-select-option:visible').click();
      assert.equal(await fixture.inputValue(),'xss');
      assert.deepEqual(errors,[], 'no JS regression at '+width);
      assert.deepEqual(thirdParty,[], 'local select assets / no external requests '+width);
      await context.close();
      console.log('PASS HCG all 22 routes + filters/forms/dynamic/select semantics at '+width+'px');
    }

    // Public payment form layout: presentation-only enhancement, even when
    // no active public payment form is configured in the disposable database.
    const context=await browser.newContext({viewport:{width:390,height:800}});
    const publicPage=await context.newPage();
    await publicPage.goto(base+'/pay.php',{waitUntil:'domcontentloaded'});
    assert.equal(await publicPage.locator('script[src="assets/vendor/hcg-searchable-select/hcg-select.js"]').count(),1);
    await publicPage.evaluate(()=>{
      let form=document.createElement('form');
      form.id='public-select-fixture';
      form.innerHTML='<select name="choice" id="publicChoice" required>'+
        '<option value="">انتخاب کنید</option><option value="a">گزینه الف</option></select>';
      document.body.appendChild(form);
    });
    await ready(publicPage,'#publicChoice');
    await publicPage.locator('#publicChoice').locator('xpath=..').locator('.hcg-select-control').click();
    await publicPage.locator('#publicChoice').locator('xpath=..').locator('.hcg-select-option').click();
    assert.equal(await publicPage.evaluate(()=>new FormData(document.querySelector('#public-select-fixture')).get('choice')),'a');
    await context.close();
    console.log('PASS public payment select layout integration without payment submissions');
  }finally{await browser.close();}
})().catch(e=>{console.error(e.stack||e);process.exit(1)});
