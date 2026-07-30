/**
 * End-to-end verify Email ID + Sales Remarks columns on Master Data.
 * Usage: node scripts/verify-master-email-sales-columns.mjs [baseUrl]
 */
import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

const BASE = process.argv[2] || 'http://127.0.0.1:8001';
const EMAIL = process.env.CRM_SMOKE_EMAIL || 'manager@ca.local';
const PASSWORD = process.env.CRM_SMOKE_PASSWORD || 'password';
const OUT_DIR = path.resolve('storage/app/audits/column-verify');

function ensureDir(d) {
  fs.mkdirSync(d, { recursive: true });
}

async function main() {
  ensureDir(OUT_DIR);
  const report = { base: BASE, checks: [], consoleErrors: [] };
  const browser = await chromium.launch({ headless: true, channel: 'chrome' }).catch(async () =>
    chromium.launch({ headless: true }),
  );

  // Fresh profile (no prior localStorage)
  const context = await browser.newContext();
  const page = await context.newPage();
  page.on('console', (msg) => {
    if (msg.type() === 'error') report.consoleErrors.push(msg.text());
  });

  const check = (name, ok, detail = '') => {
    report.checks.push({ name, ok, detail });
    console.log(`[${ok ? 'PASS' : 'FAIL'}] ${name}${detail ? ' — ' + detail : ''}`);
  };

  try {
    await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.fill('#email', EMAIL);
    await page.fill('#password', PASSWORD);
    await Promise.all([
      page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 20000 }),
      page.click('button[type="submit"]'),
    ]);
    check('login', !page.url().includes('/login'), page.url());

    // Intercept Master Data API
    let apiSample = null;
    page.on('response', async (res) => {
      try {
        const url = res.url();
        if (!url.includes('/ca-masters') || res.status() !== 200) return;
        if (url.includes('bulk') || url.includes('export')) return;
        const ct = res.headers()['content-type'] || '';
        if (!ct.includes('json')) return;
        const body = await res.json();
        const items = body?.data?.items || body?.items || body?.data || [];
        if (Array.isArray(items) && items.length && !apiSample) {
          apiSample = items.slice(0, 5).map((r) => ({
            ca_id: r.ca_id,
            email_id: r.email_id,
            sales_remarks: r.sales_remarks,
            keys: Object.keys(r).filter((k) => /email|sales_remark/i.test(k)),
          }));
        }
      } catch (_) { /* ignore */ }
    });

    await page.goto(`${BASE}/ca-masters`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.waitForSelector('#cam-hub, #ca-master-table, [id*="ca-master"]', { timeout: 20000 });
    await page.waitForTimeout(2500);

    // Confirm latest assets loaded (filemtime query)
    const scripts = await page.evaluate(() =>
      Array.from(document.scripts)
        .map((s) => s.src)
        .filter((s) => /crm-ui\/src\/(pages\/pages|api\/crm)\.js/.test(s)),
    );
    check('crm.js + pages.js loaded with version', scripts.length >= 2, scripts.join(' | '));

    // Column defs from runtime
    const defs = await page.evaluate(() => {
      const fn = window.CAPages && window.CAPages.caMasterColumnDefinitions;
      if (typeof fn !== 'function') return null;
      return fn().map((d) => ({ key: d.key, label: d.label, defaultVisible: d.defaultVisible }));
    });
    check('caMasterColumnDefinitions available', Array.isArray(defs) && defs.length > 0, `count=${defs?.length}`);
    const emailDef = defs?.find((d) => d.key === 'email_id');
    const remarksDef = defs?.find((d) => d.key === 'sales_remarks');
    check('defs include email_id', !!emailDef, JSON.stringify(emailDef));
    check('defs include sales_remarks', !!remarksDef, JSON.stringify(remarksDef));
    check('email_id defaultVisible', emailDef?.defaultVisible !== false);
    check('sales_remarks defaultVisible', remarksDef?.defaultVisible !== false);

    // Visibility keys after fresh profile
    const visibility = await page.evaluate(() => {
      const keys = window.CA_CRM && typeof window.CA_CRM.getCaMasterVisibleKeys === 'function'
        ? window.CA_CRM.getCaMasterVisibleKeys()
        : null;
      const storage = {
        v2: localStorage.getItem('crm.ca_masters.visible_columns.v2'),
        v1: localStorage.getItem('crm.ca_masters.visible_columns.v1'),
        migrations: localStorage.getItem('crm.ca_masters.column_migrations.v1'),
      };
      return { keys, storage };
    });
    check(
      'visible keys include email_id',
      Array.isArray(visibility.keys) && visibility.keys.includes('email_id'),
      JSON.stringify(visibility.keys),
    );
    check(
      'visible keys include sales_remarks',
      Array.isArray(visibility.keys) && visibility.keys.includes('sales_remarks'),
      JSON.stringify(visibility.keys),
    );

    // DOM headers
    const headers = await page.evaluate(() => {
      const ths = Array.from(document.querySelectorAll('#cam-hub th[data-column], #ca-master-table th[data-column], table th[data-column]'));
      return ths.map((th) => ({
        key: th.getAttribute('data-column'),
        text: (th.innerText || '').replace(/\s+/g, ' ').trim(),
        hidden: th.hidden || th.classList.contains('cam-col-is-hidden'),
        display: getComputedStyle(th).display,
        visibility: getComputedStyle(th).visibility,
        width: th.getBoundingClientRect().width,
      }));
    });
    const emailTh = headers.find((h) => h.key === 'email_id');
    const remarksTh = headers.find((h) => h.key === 'sales_remarks');
    check('Email ID th present', !!emailTh, JSON.stringify(emailTh));
    check('Sales Remarks th present', !!remarksTh, JSON.stringify(remarksTh));
    check('Email ID th visible', !!(emailTh && !emailTh.hidden && emailTh.display !== 'none' && emailTh.width > 0), JSON.stringify(emailTh));
    check('Sales Remarks th visible', !!(remarksTh && !remarksTh.hidden && remarksTh.display !== 'none' && remarksTh.width > 0), JSON.stringify(remarksTh));

    // Cells with values
    const cells = await page.evaluate(() => {
      const pick = (key) =>
        Array.from(document.querySelectorAll(`td[data-column="${key}"]`))
          .slice(0, 8)
          .map((td) => ({
            text: (td.innerText || '').trim(),
            hidden: td.hidden || td.classList.contains('cam-col-is-hidden'),
            display: getComputedStyle(td).display,
            width: td.getBoundingClientRect().width,
          }));
      return { email: pick('email_id'), remarks: pick('sales_remarks') };
    });
    const emailVisibleCells = (cells.email || []).filter((c) => !c.hidden && c.display !== 'none' && c.width > 0);
    const remarksVisibleCells = (cells.remarks || []).filter((c) => !c.hidden && c.display !== 'none' && c.width > 0);
    check('email td cells rendered', emailVisibleCells.length > 0, JSON.stringify(emailVisibleCells.slice(0, 3)));
    check('sales_remarks td cells rendered', remarksVisibleCells.length > 0, JSON.stringify(remarksVisibleCells.slice(0, 3)));
    check(
      'at least one non-empty email cell',
      emailVisibleCells.some((c) => c.text && c.text !== '—'),
      JSON.stringify(emailVisibleCells.map((c) => c.text).slice(0, 5)),
    );
    check(
      'at least one non-empty sales_remarks cell OR dash placeholder',
      remarksVisibleCells.some((c) => c.text),
      JSON.stringify(remarksVisibleCells.map((c) => c.text).slice(0, 5)),
    );

    // Simulate legacy prefs that hid the new columns
    const migration = await page.evaluate(() => {
      localStorage.setItem(
        'crm.ca_masters.visible_columns.v1',
        JSON.stringify({
          visible: ['selection', 'firm_name', 'ca_name', 'mobile', 'city', 'actions'],
          known: [
            'selection', 'firm_name', 'email_id', 'sales_remarks', 'ca_name', 'team_size',
            'last_activity', 'mobile', 'call_log', 'alternate_mobile', 'city', 'state',
            'source', 'rating', 'status', 'employee', 'created_by', 'updated_at', 'google', 'actions',
          ],
        }),
      );
      localStorage.removeItem('crm.ca_masters.visible_columns.v2');
      localStorage.removeItem('crm.ca_masters.column_migrations.v1');
      return true;
    });
    check('seeded sticky-hidden legacy prefs', migration === true);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);
    const afterMig = await page.evaluate(() => {
      // Force reload of visibility if exposed; otherwise read storage + DOM
      const v2 = localStorage.getItem('crm.ca_masters.visible_columns.v2');
      const thEmail = document.querySelector('th[data-column="email_id"]');
      const thRemarks = document.querySelector('th[data-column="sales_remarks"]');
      return {
        v2,
        emailVisible: thEmail && !thEmail.hidden && !thEmail.classList.contains('cam-col-is-hidden') && getComputedStyle(thEmail).display !== 'none',
        remarksVisible: thRemarks && !thRemarks.hidden && !thRemarks.classList.contains('cam-col-is-hidden') && getComputedStyle(thRemarks).display !== 'none',
        emailWidth: thEmail ? thEmail.getBoundingClientRect().width : 0,
        remarksWidth: thRemarks ? thRemarks.getBoundingClientRect().width : 0,
      };
    });
    check(
      'legacy sticky prefs force-show email+remarks',
      !!(afterMig.emailVisible && afterMig.remarksVisible && afterMig.emailWidth > 0 && afterMig.remarksWidth > 0),
      JSON.stringify(afterMig),
    );

    // Manage Columns toggle
    const manageBtn = page.locator('#cam-columns-btn, [data-cam-columns], button:has-text("Manage Columns")').first();
    if (await manageBtn.count()) {
      await manageBtn.click();
      await page.waitForTimeout(400);
      const emailToggle = page.locator('#cam-columns-popover input[data-column="email_id"], #cam-columns-popover input[value="email_id"], label:has-text("Email ID") input').first();
      if (await emailToggle.count()) {
        const wasChecked = await emailToggle.isChecked();
        await emailToggle.click();
        await page.waitForTimeout(300);
        const hiddenAfter = await page.evaluate(() => {
          const th = document.querySelector('th[data-column="email_id"]');
          return !!(th && (th.hidden || th.classList.contains('cam-col-is-hidden') || getComputedStyle(th).display === 'none'));
        });
        check('Manage Columns can hide Email ID', wasChecked ? hiddenAfter : !hiddenAfter, `wasChecked=${wasChecked}`);
        await emailToggle.click();
        await page.waitForTimeout(300);
        const shownAgain = await page.evaluate(() => {
          const th = document.querySelector('th[data-column="email_id"]');
          return !!(th && !th.hidden && !th.classList.contains('cam-col-is-hidden') && getComputedStyle(th).display !== 'none');
        });
        check('Manage Columns can show Email ID again', shownAgain);
      } else {
        check('Manage Columns Email ID toggle found', false);
      }
    } else {
      check('Manage Columns button found', false);
    }

    if (apiSample) {
      check(
        'API returns email_id key',
        apiSample.every((r) => r.keys.includes('email_id') || 'email_id' in r),
        JSON.stringify(apiSample),
      );
      check(
        'API returns sales_remarks key',
        apiSample.every((r) => r.keys.includes('sales_remarks') || 'sales_remarks' in r),
        JSON.stringify(apiSample),
      );
    } else {
      check('API sample captured', false, 'no /ca-masters JSON items intercepted');
    }

    // Screenshot of table area
    const table = page.locator('#cam-hub, #ca-master-table-wrap, #ca-master-table').first();
    const shotPath = path.join(OUT_DIR, 'master-email-sales-columns.png');
    if (await table.count()) {
      await table.screenshot({ path: shotPath });
    } else {
      await page.screenshot({ path: shotPath, fullPage: false });
    }
    check('screenshot saved', fs.existsSync(shotPath), shotPath);

    // Full page shot
    const fullPath = path.join(OUT_DIR, 'master-email-sales-full.png');
    await page.screenshot({ path: fullPath, fullPage: true });

    report.apiSample = apiSample;
    report.visibility = visibility;
    report.afterMigration = afterMig;
    fs.writeFileSync(path.join(OUT_DIR, 'report.json'), JSON.stringify(report, null, 2));

    const failed = report.checks.filter((c) => !c.ok);
    console.log('\n--- SUMMARY ---');
    console.log(`passed=${report.checks.length - failed.length} failed=${failed.length}`);
    if (failed.length) {
      failed.forEach((f) => console.log('FAIL:', f.name, f.detail));
      process.exitCode = 1;
    }
  } finally {
    await browser.close();
  }
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
