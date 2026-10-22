'use strict';
/* CI-only end-to-end test. Uses isolated MySQL and authenticated disposable session. */
const {chromium}=require('playwright');
const assert=require('node:assert/strict');
const base='http://127.0.0.1:8872';
const pairs=(process.env.ACCOUNTING_PARTY_PAIRS||'').split(',').map(x=>x.split(':').map(Number));
assert.equal(pairs.length,2);
for (const pair of pairs) assert.ok(pair[0]>0 && pair[1]>0);
(async()=>{
 const browser=await chromium.launch({headless:true});
 try {
  for (const width of [1440,390]) {
   const [customerId,supplierId]=pairs[width===1440?0:1];
   const context=await browser.newContext({viewport:{width,height:850},locale:'fa-IR'});
   await context.addCookies([{name:'PHPSESSID',value:'ci-tabler-browser-123456789',domain:'127.0.0.1',path:'/',sameSite:'Lax',httpOnly:true}]);
   const page=await context.newPage(), errors=[];
   page.on('pageerror',e=>errors.push(e.message));
   await page.goto(base+'/index.php?page=party-statement&kind=customer&id='+customerId,{waitUntil:'domcontentloaded'});
   await page.locator('#acctPartyName').waitFor({state:'visible'});
   await page.waitForFunction(()=>document.querySelector('#acctDebt').textContent.includes('تومان'));
   assert.ok((await page.locator('#acctDebt').innerText()).includes('0'),'no opening customer debt');

   await page.locator('#acctOpening').click();
   await page.locator('#acctAmount').fill('1000000');
   await page.locator('#acctNote').fill('Opening customer debt');
   await page.locator('#acctSubmit').click();
   await page.locator('#accountingDialog').waitFor({state:'detached'});
   await page.waitForFunction(()=>document.querySelector('#acctDebt').textContent.includes('1,000,000'));
   assert.match(await page.locator('#acctDebt').innerText(),/1,000,000/);

   await page.locator('#acctNewPayment').click();
   await page.locator('#acctAmount').fill('3000000');
   await page.locator('#acctReference').fill('POS-CI-TEST');
   await page.locator('#acctSubmit').click();
   await page.locator('#accountingDialog').waitFor({state:'detached'});
   await page.waitForFunction(()=>document.querySelector('#acctCredit').textContent.includes('2,000,000'));

   await page.locator('#acctRefund').click();
   await page.locator('#acctAmount').fill('500000');
   await page.locator('#acctNote').fill('Customer partial refund');
   await page.locator('#acctSubmit').click();
   await page.locator('#accountingDialog').waitFor({state:'detached'});
   await page.waitForFunction(()=>document.querySelector('#acctCredit').textContent.includes('1,500,000'));

   await page.goto(base+'/index.php?page=party-statement&kind=supplier&id='+supplierId,{waitUntil:'domcontentloaded'});
   await page.waitForFunction(()=>document.querySelector('#acctDebt').textContent.includes('تومان'));
   await page.locator('#acctOpening').click();
   await page.locator('#acctAmount').fill('2500000');
   await page.locator('#acctNote').fill('Supplier opening debt');
   await page.locator('#acctSubmit').click();
   await page.locator('#accountingDialog').waitFor({state:'detached'});
   await page.waitForFunction(()=>document.querySelector('#acctDebt').textContent.includes('2,500,000'));
   await page.locator('#acctNewPayment').click();
   await page.locator('#acctAmount').fill('1500000');
   await page.locator('#acctSubmit').click();
   await page.locator('#accountingDialog').waitFor({state:'detached'});
   await page.waitForFunction(()=>document.querySelector('#acctDebt').textContent.includes('1,000,000'));

   for(const [route,selector] of [['factors','sales_invoice'],['purchase-invoice','purchase_invoice']]){
     await page.goto(base+'/index.php?page='+route,{waitUntil:'domcontentloaded'});
     await page.locator('.invoice-new[data-type="'+selector+'"]').click();
     await page.locator('#invInitialPaid').waitFor({state:'visible'});
     assert.equal(await page.locator('#invPaymentStatus').count(),0,'manual payment status removed');
     assert.equal(await page.locator('#invPaymentStatusText').count(),1,'derived status is present');
     assert.equal(await page.locator('#invInitialReference').count(),1,'initial reference can be entered');
     await page.locator('#invInitialPaid').fill('3000000');
     assert.ok((await page.locator('#invPaymentStatusText').innerText()).length>0);
   }
   assert.deepEqual(errors,[],'no client-side exceptions at '+width+'px');
   await context.close();
   console.log('PASS accounting customer/supplier statement and invoice form at '+width+'px');
  }
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
