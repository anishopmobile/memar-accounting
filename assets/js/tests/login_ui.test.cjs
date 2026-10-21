'use strict';
/* Visual + behavioral login checks against isolated CI only; production auth remains untouched. */
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const base = process.env.UI_TEST_BASE || 'http://127.0.0.1:8872';
const captchaEnabled = process.env.UI_TEST_CAPTCHA === '1';
(async () => {
  const browser = await chromium.launch({headless:true});
  try {
    for (const width of [1440, 390, 360]) {
      const context = await browser.newContext({viewport:{width,height:850},locale:'fa-IR'});
      const page = await context.newPage();
      const failed = [];
      page.on('pageerror', e => failed.push(e.message));
      const external = [];
      page.on('request', r => {if (!r.url().startsWith(base + '/')) external.push(r.url());});
      const response = await page.goto(base + '/login.php', {waitUntil:'networkidle'});
      assert.equal(response.status(), 200, 'login GET must succeed');
      assert.equal(await page.locator('html').getAttribute('dir'), 'rtl');
      assert.equal(await page.locator('body.tabler-auth').count(), 1);
      assert.equal(await page.locator('.login-brand-name').textContent(), 'حسابداری معمار');
      assert.equal(await page.locator('link[href="assets/vendor/tabler/tabler.rtl.min.css"]').count(),1);
      assert.equal(await page.locator('link[href="assets/css/login-tabler.css"]').count(),1);
      const login = page.locator('form.login-form');
      assert.equal(await login.locator('input[name="csrf_token"]').count(),1);
      assert.equal(await login.locator('input[name="next"]').count(),1);
      assert.equal(await login.locator('input[name="username"][required]').count(),1);
      assert.equal(await login.locator('input[name="password"][required]').count(),1);
      assert.equal(await page.locator('.login-captcha-box').count(),captchaEnabled?1:0);
      if (captchaEnabled) {
        assert.equal(await login.locator('input[name="captcha"]').count(),1);
        const img = page.locator('.login-captcha-box img').first();
        assert.equal(await img.isVisible(),true,'captcha picture displayed');
        assert.ok((await img.evaluate(x=>x.getBoundingClientRect().width))>0);
      }
      const pwd = login.locator('input[name="password"]');
      await pwd.fill('test');
      await page.locator('#togglePassword').click();
      assert.equal(await pwd.getAttribute('type'),'text');
      await page.locator('#togglePassword').click();
      assert.equal(await pwd.getAttribute('type'),'password');
      const widths = await page.evaluate(()=>({
        content:document.documentElement.scrollWidth,
        viewport:document.documentElement.clientWidth
      }));
      assert.ok(widths.content<=widths.viewport+3,'no login horizontal overflow');
      assert.deepEqual(external,[],'no external runtime request from login');
      assert.deepEqual(failed,[],'login no JavaScript runtime errors');
      // Authenticated login is server controlled. Invalid/missing CSRF must never log in.
      const bad = await page.request.post(base + '/login.php',{
        form:{username:'ci_tabler_browser',password:'ci-tabler-test',
          csrf_token:'invalid-for-test',captcha:'wrong'}
      });
      assert.equal(bad.status(),200,'invalid CSRF rejected without redirect');
      assert.ok((await bad.text()).includes('نشست امنیتی نامعتبر'),'CSRF defense intact');
      if (captchaEnabled) {
        const csrf = await login.locator('input[name="csrf_token"]').inputValue();
        const badCaptcha = await page.request.post(base + '/login.php',{
          form:{username:'ci_tabler_browser',password:'ci-tabler-test',csrf_token:csrf,captcha:'wrong'}
        });
        assert.equal(badCaptcha.status(),200,'bad captcha cannot log in');
        assert.ok((await badCaptcha.text()).includes('کد امنیتی اشتباه'),'captcha validation intact');
      }
      await context.close();
      console.log('PASS login screen '+width+'px, captcha '+captchaEnabled+', CSRF + toggle');
    }
  }finally{await browser.close();}
})().catch(err=>{console.error(err.stack||err);process.exit(1);});
