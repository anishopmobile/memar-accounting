'use strict';
/** Full browser acceptance: settings modal -> auto-receipted new sale ->
 * archived accounting statement -> disabled legacy manual modal. CI only.
 */
const {chromium}=require('playwright');
const fs=require('node:fs');
const assert=require('node:assert/strict');
if(process.env.GITHUB_ACTIONS!=='true'||!process.env.UI_ACCOUNTING_FIXTURE)process.exit(2);
const fixture=JSON.parse(fs.readFileSync(process.env.UI_ACCOUNTING_FIXTURE,'utf8'));
const base='http://127.0.0.1:8872';
(async()=>{
const browser=await chromium.launch({headless:true});
try{
 const context=await browser.newContext({viewport:{width:1366,height:900},locale:'fa-IR'});
 await context.addCookies([{name:'PHPSESSID',value:'ci-tabler-browser-123456789',
  domain:'127.0.0.1',path:'/',httpOnly:true,sameSite:'Lax'}]);
 const page=await context.newPage();
 const errors=[];
 page.on('pageerror',e=>errors.push(e.message));
 page.on('dialog',d=>d.accept());
 await page.goto(base+'/index.php?page=settings');
 await page.locator('[data-target="auto-settlement"]').click();
 await page.locator('#autoSettlementChoose').click();
 await page.locator('#autoSettlementSearch').fill('010101');
 const selectCustomer=page.locator('#autoSettlementCustomerRows .auto-settlement-pick[data-id="'+fixture.customer_id+'"]');
 await selectCustomer.waitFor({state:'visible',timeout:15000});
 await selectCustomer.click();
 assert.match(await page.locator('#autoSettlementSelected').innerText(),/CI-ledger-ui/);
 await page.locator('#autoSettlementEnabled').check();
 const configResponse=page.waitForResponse(r=>r.url().includes('accounting_auto_settlement_settings.php')&&r.request().method()==='POST');
 await page.locator('#autoSettlementSave').click();
 let result=await (await configResponse).json();
 assert.equal(result.success,true,JSON.stringify(result));
 await page.reload();
 await page.locator('[data-target="auto-settlement"]').click();
 await page.waitForFunction(()=>document.querySelector('#autoSettlementEnabled')?.checked===true);
 assert.match(await page.locator('#autoSettlementSelected').innerText(),/CI-ledger-ui/);
 console.log('PASS auto-settlement settings persisted selected customer with searchable modal');

 async function makeSale() {
   await page.goto(base+'/index.php?page=factors');
   await page.locator('.invoice-new[data-type="sales_invoice"]').click();
   await page.locator('#invPickParty').click();
   await page.locator('#invoicePartySearch').fill('010101');
   const customer=page.locator('.party-pick[data-id="'+fixture.customer_id+'"]');
   await customer.waitFor({state:'visible',timeout:15000});
   await customer.click();
   assert.equal((await page.locator('#invPartyId').innerText()).trim(),String(fixture.customer_id));
   await page.locator('#invAddProduct').click();
   await page.locator('#invoiceProductSearch').fill(fixture.product_code);
   const item=page.locator('.product-pick[data-id="'+fixture.product_id+'"]');
   await item.waitFor({state:'visible',timeout:15000});
   await item.click();
   await page.locator('#invoiceProductModal .modal-backdrop').click({position:{x:2,y:2}});
   assert.equal(await page.locator('#invItemsTable tbody tr').count(),1);
   const posted=page.waitForResponse(r=>r.url().includes('invoice_save.php')&&r.request().method()==='POST',{timeout:30000});
   await page.locator('#invoiceFormSave').click();
   const response=await (await posted).json();
   assert.equal(response.success,true,JSON.stringify(response));
   return response.data;
 }
 const auto=await makeSale();
 assert.equal(auto.auto_settled,true,'server confirms new sale automatically settled');
 assert.equal(auto.invoice.payment_status,'paid');
 assert.equal(await page.locator('#accountingModal.open').count(),0,
   'automatic public sale does not open manual accounting modal');
 const autoButton=page.locator('.invoice-accounting[data-id="'+auto.invoice_id+'"]');
 await autoButton.waitFor({state:'visible',timeout:15000});
 await autoButton.click();
 await page.locator('#accountingModal.open').waitFor({state:'visible'});
 assert.ok((await page.locator('#acctContent').innerText()).includes('دریافت خودکار هنگام ثبت فاکتور'),
  'original automatic receipt visible in normal account statement');
 console.log('PASS created invoice auto-payment and optional accounting detail modal');
 await page.goto(base+'/index.php?page=settings');
 await page.locator('[data-target="auto-settlement"]').click();
 await page.locator('#autoSettlementEnabled').uncheck();
 const disable=page.waitForResponse(r=>r.url().includes('accounting_auto_settlement_settings.php')&&r.request().method()==='POST');
 await page.locator('#autoSettlementSave').click();
 result=await (await disable).json();
 assert.equal(result.success,true,JSON.stringify(result));
 const manual=await makeSale();
 assert.equal(manual.auto_settled,false,'server confirms disabled mode');
 assert.equal(manual.invoice.payment_status,'unpaid');
 await page.locator('#accountingModal.open').waitFor({state:'visible',timeout:15000});
 console.log('PASS disabling feature restores manual receipt modal for same customer');
 assert.deepEqual(errors,[],'no JavaScript errors');
 await context.close();
}finally{await browser.close();}
})().catch(e=>{console.error(e.stack||e);process.exit(1)});
