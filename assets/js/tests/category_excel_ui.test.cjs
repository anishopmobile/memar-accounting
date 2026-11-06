'use strict';
/**
 * Category Excel browser integration: real authenticated HTTP/CSRF/XLS import.
 * Executed only against the disposable CI MySQL schema.
 */
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
if (process.env.GITHUB_ACTIONS !== 'true') process.exit(2);
const base = 'http://127.0.0.1:8872';

function xls(rows) {
    const escapeXml = v => String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    const lines = [
        ['کد گروه', 'نام گروه', 'کد گروه مادر'],
        ...rows
    ];
    const xml = '\ufeff<?xml version="1.0" encoding="UTF-8"?>'
        + '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" '
        + 'xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'
        + '<Worksheet ss:Name="Categories"><Table>'
        + lines.map(row => '<Row>' + row.map(value =>
            '<Cell><Data ss:Type="String">' + escapeXml(value) + '</Data></Cell>').join('') + '</Row>').join('')
        + '</Table></Worksheet></Workbook>';
    return { name: 'category-ci.xls', mimeType: 'application/vnd.ms-excel', buffer: Buffer.from(xml, 'utf8') };
}
(async () => {
    const browser = await chromium.launch({headless: true});
    try {
        const context = await browser.newContext({viewport:{width:1366,height:900}, locale:'fa-IR'});
        await context.addCookies([{name:'PHPSESSID', value:'ci-tabler-browser-123456789',
            domain:'127.0.0.1', path:'/', httpOnly:true, sameSite:'Lax'}]);
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror',e => errors.push(e.message));
        page.on('dialog',d => d.accept());
        const root = 'ci-excel-category-1106';
        const child = root + '-1';
        const second = root + '-2';
        await page.goto(base + '/index.php?page=product-categories');
        assert.ok(await page.locator('#openCategoryImportModal').count(), 'missing category import button');

        async function preview(rows, mode) {
            await page.locator('#openCategoryImportModal').click();
            await page.locator('#categoryImportFile').setInputFiles(xls(rows));
            if (mode === 'update_existing') {
                await page.locator('#categoryImportModal input[value="update_existing"]').check();
            }
            const wait = page.waitForResponse(r => r.url().includes('/category_import.php') &&
                r.request().method() === 'POST');
            await page.locator('#categoryImportPreview').click();
            const response = await wait;
            const raw = await response.text();
            assert.equal(response.status(),200,'preview returned non-200 HTTP status: '+raw.slice(0,150));
            assert.equal(raw.charCodeAt(0),123,'preview emitted extra bytes before JSON');
            const data = JSON.parse(raw);
            assert.equal(data.success,true,raw);
            await page.locator('#categoryImportPreviewTable tbody tr').first().waitFor({state:'visible'});
            return data.data;
        }
        async function submit() {
            const wait = page.waitForResponse(r => r.url().includes('/category_import.php') &&
                r.request().method() === 'POST');
            await page.locator('#categoryImportSubmit').click();
            const response = await wait;
            const body = await response.json();
            assert.equal(response.status(), 200, JSON.stringify(body));
            assert.equal(body.success, true, JSON.stringify(body));
            await page.locator('#categoryImportModal.open').waitFor({state:'hidden'});
            return body.data;
        }

        // Child listed first must be inserted after its parent.
        let data = await preview([[child, 'گروه زیرمجموعه', root],[root,'نام اولیه','']]);
        assert.equal(data.new,2);
        assert.equal(data.invalid,0);
        const first = await submit();
        assert.equal(first.inserted,2);
        await page.locator('.category-row[data-code="'+root+'"]').waitFor({state:'visible'});
        const rootRow = page.locator('.category-row[data-code="'+root+'"]');
        const parentId = await rootRow.getAttribute('data-id');
        assert.equal(await page.locator('.category-row[data-code="'+child+'"]').getAttribute('data-parent-id'),parentId);

        // Skip duplicates; insert other rows without changing existing name.
        data = await preview([[root,'نام اصلاحی',''],[second,'گروه جدید دوم',root]],'skip_duplicates');
        assert.equal(data.duplicates,1);
        assert.equal(data.new,1);
        assert.equal((await submit()).skipped,1);
        await page.locator('.category-row[data-code="'+second+'"]').waitFor({state:'visible'});
        assert.equal(await page.locator('.category-row[data-code="'+root+'"]').getAttribute('data-name'),'نام اولیه');

        // Update duplicates: only the name of the existing row changes.
        data = await preview([[root,'نام اصلاحی','']], 'update_existing');
        assert.equal(data.updates,1);
        assert.equal((await submit()).updated,1);
        await page.waitForFunction(code => document.querySelector('.category-row[data-code="'+code+'"]')?.getAttribute('data-name')==='نام اصلاحی',root);
        assert.equal(await page.locator('.category-row[data-code="'+root+'"]').getAttribute('data-id'),parentId);
        assert.equal(await page.locator('.category-row[data-code="'+child+'"]').getAttribute('data-parent-id'),parentId);
        assert.deepEqual(errors,[], 'browser JS errors');
        console.log('PASS categories XLS preview pure JSON, skip/update, hierarchy and stable IDs');
        await context.close();
    } finally {
        await browser.close();
    }
})().catch(e => { console.error(e.stack || e); process.exit(1); });
