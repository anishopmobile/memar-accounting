'use strict';
/** End-to-end DOM, HCG-select and HTTP accounting verification on disposable CI. */
const {chromium}=require('playwright');
const assert=require('node:assert/strict');
const fs=require('node:fs');
if(!process.env.GITHUB_ACTIONS || !process.env.UI_ACCOUNTING_FIXTURE) {
  console.error('CI and fixture required');process.exit(2);
}
const fixture=JSON.parse(fs.readFileSync(process.env.UI_ACCOUNTING_FIXTURE,'utf8'));
const base='http://127.0.0.1:8872';
const cookie={name:'PHPSESSID',value:'ci-tabler-browser-123456789',domain:'127.0.0.1',path:'/',httpOnly:true,sameSite:'Lax'};
const browserErrors=[];
async function getStatement(page,kind,id) {
  const result=await page.evaluate(async (opts)=>{
    const p=new URLSearchParams({action:'statement',party_kind:opts.kind,party_id:String(opts.id)});
    const r=await fetch('assets/php/accounting_ledger_action.php?'+p,{credentials:'same-origin'});
    return r.json();
  },{kind,id});
  assert.equal(result.success,true,JSON.stringify(result));
  return result.data;
}
async function openInvoice(page,route,id) {
  await page.goto(base+'/index.php?page='+route,{waitUntil:'domcontentloaded'});
  const btn=page.locator('.invoice-accounting[data-id="'+id+'"]');
  await btn.waitFor({state:'visible',timeout:15000});
  await btn.click();
  await page.locator('#accountingModal.open').waitFor({state:'visible',timeout:10000});
  await page.waitForFunction(()=>!!document.querySelector('#acctPaymentRows .acctCashAccount')?.value &&
    !!document.querySelector('#acctDateDisplay')?.value && !!document.querySelector('#acctDate')?.value,
    null,{timeout:10000});
  assert.equal(await page.locator('#acctDateDisplay').getAttribute('readonly'),'readonly','Jalali picker is read-only like invoice dates');
  assert.match(await page.locator('#acctDateDisplay').inputValue(),/^\d{4}\/\d{2}\/\d{2}$/,'visible date is Jalali');
  assert.match(await page.locator('#acctDate').inputValue(),/^\d{4}-\d{2}-\d{2}$/,'hidden date is Gregorian ISO');
  assert.ok(await page.locator('#bd-main-acctDateDisplay').count(),'existing kamaDatepicker is attached');
}
async function submitBatch(page,amounts) {
  if(amounts.length>1) {
    for(let x=1;x<amounts.length;x++)await page.locator('#acctAddPayment').click();
  }
  const fields=page.locator('#acctPaymentRows .acctPartAmount');
  assert.equal(await fields.count(),amounts.length);
  for(let i=0;i<amounts.length;i++)await fields.nth(i).fill(String(amounts[i]));
  await page.waitForFunction(()=>{
    const btn=document.getElementById('acctSubmit');
    return !!btn && !btn.disabled;
  },null,{timeout:10000});
  const responseWait=page.waitForResponse(r=>r.url().includes('accounting_ledger_action.php')
    && r.request().postData()?.includes('action=payment_batch'),{timeout:20000});
  await page.locator('#acctSubmit').click();
  const response=await responseWait,body=await response.json();
  assert.equal(response.status(),200,JSON.stringify(body));
  assert.equal(body.success,true,JSON.stringify(body));
  await page.waitForFunction(()=>document.querySelector('#acctContent .acctPaymentRow')!==null);
  return body;
}
(async ()=>{
 const browser=await chromium.launch({headless:true});
 try {
   const context=await browser.newContext({viewport:{width:1440,height:900},locale:'fa-IR'});
   await context.addCookies([cookie]);
   const page=await context.newPage();
   page.on('pageerror',e=>browserErrors.push(e.message));
   await openInvoice(page,'factors',fixture.sales_invoice_id);
   const invoiceDateText=await page.locator('#acctContent .action-table').first().locator('tbody tr td').nth(1).innerText();
   assert.match(invoiceDateText,/^[۰-۹]{4}\/[۰-۹]{2}\/[۰-۹]{2}$/,'invoice dates are displayed in Jalali');
   // Prevent regression: internal kamaDatepicker month/year dropdowns stay
   // native despite the global searchable-select MutationObserver.
   const initialJalali=await page.locator('#acctDateDisplay').inputValue();
   const initialYear=Number(initialJalali.split('/')[0]);
   const month=page.locator('#bd-month-acctDateDisplay');
   const year=page.locator('#bd-year-acctDateDisplay');
   assert.equal(await month.getAttribute('class'),'bd-month','month stays native');
   assert.equal(await year.getAttribute('class'),'bd-year','year stays native');
   assert.equal(await page.locator('#bd-main-acctDateDisplay .hcg-select').count(),0,'calendar has no HCG wrapper');
   await page.locator('#acctDateDisplay').click();
   await month.selectOption('6'); // شهریور
   assert.equal(await month.inputValue(),'6','calendar month changes');
   await page.locator('#bd-main-acctDateDisplay .day-10').click();
   assert.equal(await page.locator('#acctDateDisplay').inputValue(),initialYear+'/06/10',
      'choosing شهریور 10 must not revert to the previous month');
   const selectedGregorianDate=await page.locator('#acctDate').inputValue();
   assert.match(selectedGregorianDate,/^\d{4}-\d{2}-\d{2}$/);
   await page.locator('#acctDateDisplay').click();
   await year.selectOption(String(initialYear-1));
   await month.selectOption('6');
   await page.locator('#bd-main-acctDateDisplay .day-10').click();
   assert.equal(await page.locator('#acctDateDisplay').inputValue(),(initialYear-1)+'/06/10',
      'selecting a different year persists through day selection');
   await page.locator('#acctDateDisplay').click();
   await year.selectOption(String(initialYear));
   await month.selectOption('6');
   await page.locator('#bd-main-acctDateDisplay .day-10').click();
   assert.equal(await page.locator('#acctDateDisplay').inputValue(),initialYear+'/06/10');
   assert.equal(await page.locator('#acctDate').inputValue(),selectedGregorianDate,
      'same Jalali date must roundtrip to identical database date');
   await submitBatch(page,[3000000]);
   let statement=await getStatement(page,'customer',fixture.customer_id);
   assert.equal(statement.payments[0].date,selectedGregorianDate,'Jalali picker posts Gregorian date to database');
   let invoice=statement.invoices.find(i=>i.id===fixture.sales_invoice_id);
   assert.equal(invoice.remaining,'2000000.00','partial receipt: 5m - 3m');
   await openInvoice(page,'factors',fixture.sales_invoice_id);
   const historyDate=await page.locator('#acctContent .action-table').nth(1).locator('tbody tr td').first().innerText();
   assert.match(historyDate,/^[۰-۹]{4}\/[۰-۹]{2}\/[۰-۹]{2}$/,'payment history is Jalali');
   await page.locator('#acctClose').click();
   await openInvoice(page,'factors',fixture.sales_invoice_id);
   await submitBatch(page,[1000000,1000000]);
   statement=await getStatement(page,'customer',fixture.customer_id);
   invoice=statement.invoices.find(i=>i.id===fixture.sales_invoice_id);
   assert.equal(invoice.remaining,'0.00','mixed cash/card settles second 2m');
   assert.equal(statement.payments.length,3,'three individually auditable sales receipts');
   console.log('PASS sales partial payment and multi-method closeout');

   // The supplier has a documented 70m opening debt.
   await page.goto(base+'/index.php?page=suppliers');
   const opening=await page.evaluate(async id=>{
     const p=new URLSearchParams({action:'opening_balance',csrf_token:window.APP_CSRF_TOKEN,
       party_kind:'supplier',party_id:String(id),amount:'70000000',note:'CI documented previous balance'});
     const r=await fetch('assets/php/accounting_ledger_action.php',{method:'POST',body:p,credentials:'same-origin'});
     return r.json();
   },fixture.supplier_id);
   assert.equal(opening.success,true,JSON.stringify(opening));
   await openInvoice(page,'purchase-invoice',fixture.purchase_invoice_id);
   await submitBatch(page,[15000000,20000000]);
   statement=await getStatement(page,'supplier',fixture.supplier_id);
   invoice=statement.invoices.find(i=>i.id===fixture.purchase_invoice_id);
   assert.equal(invoice.remaining,'0.00');
   assert.equal(statement.balance,'50000000.00','70m old + 15m new - 35m paid = 50m');
   assert.equal(statement.unallocated_credit,'20000000.00');
   console.log('PASS 35m supplier remittance with 15m invoice settlement plus 20m old debt');

   // Real refund UI: keeps Jalali date, selected cash account and the
   // same idempotency token when a request must be retried.
   await openInvoice(page,'factors',fixture.sales_invoice_id);
   await submitBatch(page,[50000]); // cash without invoice allocation
   await openInvoice(page,'factors',fixture.sales_invoice_id);
   await page.locator('#acctRefund').waitFor({state:'visible'});
   const refundDate=await page.locator('#acctDate').inputValue();
   const refundDialogs=async dialog=>{
     const msg=dialog.message();
     if(msg.includes('مبلغ استرداد'))await dialog.accept('20000');
     else if(msg.includes('دلیل استرداد'))await dialog.accept('Refund approved for audit');
     else if(msg.includes('روش استرداد'))await dialog.accept('cash');
     else if(msg.includes('شناسه حساب'))await dialog.accept(dialog.defaultValue());
     else await dialog.dismiss();
   };
   page.on('dialog',refundDialogs);
   const refundWait=page.waitForResponse(response=>response.url().includes('accounting_ledger_action.php') &&
       response.request().postData()?.includes('action=refund'),{timeout:20000});
   await page.locator('#acctRefund').click();
   const refundResp=await refundWait;
   const refundBody=await refundResp.json();
   page.off('dialog',refundDialogs);
   assert.equal(refundResp.status(),200,JSON.stringify(refundBody));
   assert.equal(refundBody.success,true,JSON.stringify(refundBody));
   statement=await getStatement(page,'customer',fixture.customer_id);
   const refundedPayment=statement.payments.find(p=>p.id===refundBody.data.payment_id);
   assert.equal(refundedPayment.direction,'out','customer refund is physical cash outflow');
   assert.equal(refundedPayment.date,refundDate,'refund persisted the selected Gregorian-backed Jalali date');
   assert.equal(statement.balance,'-30000.00','50k unallocated credit minus 20k refund');
   console.log('PASS refund UI: date, selected account, audited method and exact balance');
   // Exported customer/supplier reports must use each party's actual id
   // to resolve the ledger balance; exporting zero for every party is unsafe.
   const customerExcel=await page.request.get(base+'/assets/php/report_customer_debt_export.php');
   assert.equal(customerExcel.status(),200,'authenticated customer debt Excel');
   const customerRows=((await customerExcel.text()).match(/<Row>[\s\S]*?<\/Row>/g)||[]);
   const customerReportRow=customerRows.find(row=>row.includes('010101'));
   assert.ok(customerReportRow && customerReportRow.includes('-30,000'),
     'customer debt Excel must include actual negative credit (not zero)');
   const supplierExcel=await page.request.get(base+'/assets/php/report_supplier_debt_export.php');
   assert.equal(supplierExcel.status(),200,'authenticated supplier debt Excel');
   const supplierRows=((await supplierExcel.text()).match(/<Row>[\s\S]*?<\/Row>/g)||[]);
   const supplierReportRow=supplierRows.find(row=>row.includes('020202'));
   assert.ok(supplierReportRow && supplierReportRow.includes('50,000,000'),
     'supplier debt Excel must include actual 50m debt (not zero)');
   console.log('PASS customer/supplier debt Excel matches individual ledger balances');


   // Mobile: open the actual statement and ensure payment rows remain operable.
   await page.setViewportSize({width:390,height:844});
   await openInvoice(page,'factors',fixture.sales_invoice_id);
   const box=await page.locator('#accountingModal .modal-content').boundingBox();
   assert.ok(box && box.width<=390 && box.x>=-1,'mobile modal stays within viewport');
   assert.equal(await page.locator('.acctPaymentRow').count(),1);
   await page.locator('#acctAddPayment').click();
   assert.equal(await page.locator('.acctPaymentRow').count(),2);
   await page.locator('.acctRemovePart').last().click();
   assert.equal(await page.locator('.acctPaymentRow').count(),1);
   assert.deepEqual(browserErrors,[],'browser runtime errors');
   console.log('PASS mobile accounting layout, add/remove combined methods, no JS errors');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
