const {chromium} = require('playwright');
const {pathToFileURL} = require('node:url');
const path = require('node:path');
const fs = require('node:fs');
(async () => {
  const previewPath = path.resolve(process.argv[2] || 'APERCU-GIT-SYNC.html');
  const browser = await chromium.launch({headless: true, ...(process.env.GIT_SYNC_BROWSER ? {channel: process.env.GIT_SYNC_BROWSER} : {})});
  const page = await browser.newPage({viewport: {width: 1500, height: 1100}, deviceScaleFactor: 1});
  await page.goto(pathToFileURL(previewPath).href);
  await page.screenshot({path: path.join(path.dirname(previewPath), 'APERCU-GIT-SYNC.png'), fullPage: true});
  console.log('Desktop overflow:', await page.evaluate(() => document.documentElement.scrollWidth > innerWidth));
  await page.setViewportSize({width: 390, height: 844});
  await page.screenshot({path: path.join(path.dirname(previewPath), 'APERCU-MOBILE.png'), fullPage: true});
  console.log('Mobile overflow:', await page.evaluate(() => document.documentElement.scrollWidth > innerWidth));
  const app = await browser.newPage({viewport: {width: 1500, height: 1100}});
  const html = fs.readFileSync(previewPath, 'utf8');
  await app.route('https://git-sync.test/**', async route => {
    if (route.request().url().includes('/Statuses')) {
      return route.fulfill({contentType: 'application/json', body: JSON.stringify({
        jobs: [
          {id: 1, status: 'error', message: '<img src=x onerror=alert(1)>', last_attempt: '2026-10-28T12:00:00Z', next_run_at: null, auto_pull: true},
          {id: 2, status: 'success', message: 'Terminé.', last_attempt: '2026-10-28T12:00:00Z', next_run_at: null, auto_pull: false}
        ], counts: {success: 1, error: 1}
      })});
    }
    return route.fulfill({contentType: 'text/html', body: html});
  });
  await app.goto('https://git-sync.test/preview');
  await app.locator('#gs-editor input[name=name]').fill('Saisie conservée');
  await app.addScriptTag({path: path.join(__dirname, '../pluginsync/public/app.js')});
  await app.waitForFunction(() => document.querySelector('[data-job-id="1"] [data-job-status]').textContent === 'Erreur');
  if (await app.locator('#gs-editor input[name=name]').inputValue() !== 'Saisie conservée') throw Error('Draft lost');
  if (await app.locator('[data-job-message] img').count()) throw Error('Unsafe log message insertion');
  if (await app.locator('[data-job-id="2"] button.gs-button-primary').isDisabled()) throw Error('Completed button still disabled');
  await app.evaluate(() => {
    window.toggleSubmissions = 0;
    document.querySelector('[data-job-id="1"] .gs-auto-form').addEventListener('submit', () => window.toggleSubmissions++);
  });
  await app.locator('[data-job-id="1"] .gs-switch').click();
  if (await app.evaluate(() => window.toggleSubmissions) !== 1) throw Error('Checkbox not saved');
  console.log('Browser checks: live status, safe text, enabled actions, preserved draft, automatic checkbox OK');
  await browser.close();
})().catch(error => {console.error(error); process.exit(1);});
